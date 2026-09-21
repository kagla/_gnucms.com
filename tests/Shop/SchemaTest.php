<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Shop\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class SchemaTest extends ShopTestCase
{
    /** Schema::migrate() 가 만드는 인덱스 목록과 그대로 맞춰 둔다. */
    private const INDEXES = ['yc_cat_parent' => 'yc_categories', 'yc_cat_order' => 'yc_categories',
        'yc_cat_path' => 'yc_categories', 'yc_cat_slug' => 'yc_categories', 'yc_cat_legacy_code' => 'yc_categories',
        'yc_prod_category' => 'yc_products', 'yc_prod_name' => 'yc_products',
        'yc_prod_order' => 'yc_products', 'yc_prod_updated' => 'yc_products',
        'yc_prod_price' => 'yc_products', 'yc_pc_category' => 'yc_product_categories',
        'yc_img_product' => 'yc_product_images', 'yc_opt_product' => 'yc_options',
        'yc_rel_related' => 'yc_product_relations', 'yc_stock_product' => 'yc_stock_log',
        'yc_order_user' => 'yc_orders', 'yc_order_status' => 'yc_orders', 'yc_order_created' => 'yc_orders',
        'yc_order_pay_by' => 'yc_orders', 'yc_order_payment' => 'yc_orders',
        'yc_oi_order' => 'yc_order_items', 'yc_oi_product' => 'yc_order_items', 'yc_oi_option' => 'yc_order_items', 'yc_history_order' => 'yc_order_history'];

    #[DataProvider('connectionProvider')]
    public function testMigrateIsIdempotentAndCreatesEveryTableAndIndex(array $config): void
    {
        $this->setupShop($config);
        $db = $this->app->db();
        Schema::migrate($db);
        Schema::migrate($db);
        foreach (Schema::TABLES as $table) self::assertNotNull($db->selectOne('SELECT COUNT(*) AS c FROM ' . $db->table($table)), $table);
        self::assertSame(12, count(Schema::TABLES));
        self::assertSame(24, count(self::INDEXES));
        $this->assertIndexesExist();
        $id = $this->shop->store->insert('yc_categories', ['slug' => '의류', 'path' => '/1/', 'legacy_code' => null, 'parent_id' => null, 'depth' => 1, 'name' => '의류', 'sort_order' => 0,
            'active' => 1, 'no_coupon' => 0, 'head_html' => '', 'tail_html' => '', 'list_columns' => 3, 'list_rows' => 5,
            'image_width' => 200, 'image_height' => 0, 'extra' => '[]', 'created_at' => 1, 'updated_at' => 1]);
        self::assertSame('의류', $this->shop->store->get('yc_categories', $id)['name']);
        self::assertNull($this->shop->store->find('yc_categories', $id + 1));
        $this->shop->store->logStock(1, null, -2, 'admin', 'test', 'tester');
        self::assertSame(-2, (int) $this->shop->store->selectOne('SELECT delta FROM ' . $this->shop->store->table('yc_stock_log'))['delta']);
    }

    /** 결제 이전에 만든 주문 표에도 결제 칸이 생긴다. */
    #[DataProvider('connectionProvider')]
    public function testMigrateAddsThePaymentColumnsToAPrePaymentOrdersTable(array $config): void
    {
        $this->setupShop($config);
        $db = $this->app->db();
        // pay_by·payment_id 는 인덱스가 있어 SQLite 가 컬럼을 바로 지우지 못한다 — 결제 이전에는 그 인덱스도 없었으므로 먼저 지운다.
        foreach (['yc_order_pay_by', 'yc_order_payment'] as $index) {
            $db->execute('DROP INDEX ' . $db->index($index) . ($db->dialect()->name() === 'mysql' ? ' ON ' . $db->table('yc_orders') : ''));
        }
        foreach (array_keys(Schema::PAYMENT_COLUMNS) as $column) {
            $db->execute('ALTER TABLE ' . $db->table('yc_orders') . ' DROP COLUMN ' . $column);
        }
        Schema::migrate($db);
        $this->assertIndexesExist();
        $db->execute('INSERT INTO ' . $db->table('yc_orders') . ' (number, checkout_key, owner_key, user_id, guest_password, status, buyer_name, email, phone, recipient, recipient_phone, postcode, address, address_detail, delivery_note, subtotal, shipping_fee, cod_fee, total, shipping_detail, order_notice, carrier, tracking_number, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, 0, 1, ?, ?, ?, ?, 1, 1)',
            ['N1', str_repeat('a', 64), str_repeat('b', 64), '', 'pending', '이름', 'a@b.c', '010', '받는분', '010', '04524', '주소', '', '', '[]', '', '', '']);
        $row = $db->selectOne('SELECT payment_method, payment_id, pay_by, paid_at, refunded_amount, payment_detail FROM ' . $db->table('yc_orders') . " WHERE number = 'N1'");
        self::assertSame(['', '', 0, 0, 0, ''], [$row['payment_method'], $row['payment_id'], (int) $row['pay_by'], (int) $row['paid_at'], (int) $row['refunded_amount'], $row['payment_detail']]);
    }

    /** 30판: 분류의 메뉴 숨김 칸. 없던 표에는 migrate() 가 넣는다. */
    #[DataProvider('connectionProvider')]
    public function testMigrateAddsTheMenuHiddenColumnToCategories(array $config): void
    {
        $this->setupShop($config);
        $db = $this->app->db();
        $db->execute('ALTER TABLE ' . $db->table('yc_categories') . ' DROP COLUMN menu_hidden');
        Schema::migrate($db);
        Schema::migrate($db);
        self::assertSame(0, (int) $this->category('의류')['menu_hidden']);
        self::assertSame(1, (int) $this->category('기획전', null, ['menu_hidden' => '1'])['menu_hidden']);
    }

    /** 29판: 2자 코드 계층을 부모 id 트리로. 옛 표를 새 모양으로 바꾸고 slug·path·legacy_code 를 채운다. 두 번 돌려도 같다. */
    #[DataProvider('connectionProvider')]
    public function testMigrateTurnsCodedCategoriesIntoATree(array $config): void
    {
        $this->setupShop($config);
        $db = $this->app->db();
        // 28판 모양(code 있음, slug·path 없음)의 표를 손으로 만든다.
        foreach (['yc_cat_parent', 'yc_cat_order', 'yc_cat_path', 'yc_cat_slug', 'yc_cat_legacy_code'] as $index) {
            $db->execute('DROP INDEX ' . ($db->dialect()->name() === 'mysql' ? $db->index($index) . ' ON ' . $db->table('yc_categories') : $db->index($index)));
        }
        $db->execute('DROP TABLE ' . $db->table('yc_categories'));
        $bin = $db->dialect()->name() === 'mysql' ? ' COLLATE utf8mb4_bin' : '';
        $db->execute('CREATE TABLE ' . $db->table('yc_categories') . ' (' . strtr('id {AUTO_PK}, code VARCHAR(10)' . $bin . ' NOT NULL UNIQUE, parent_id BIGINT NULL, depth SMALLINT NOT NULL,
            name VARCHAR(100) NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, active SMALLINT NOT NULL DEFAULT 1, no_coupon SMALLINT NOT NULL DEFAULT 0,
            head_html {TEXT} NOT NULL, tail_html {TEXT} NOT NULL, list_columns SMALLINT NOT NULL, list_rows SMALLINT NOT NULL, image_width INTEGER NOT NULL,
            image_height INTEGER NOT NULL, extra {TEXT} NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL', $db->dialect()->typeMap()) . ')' . $db->dialect()->tableSuffix());
        $common = ['sort_order' => 0, 'active' => 1, 'no_coupon' => 0, 'head_html' => '', 'tail_html' => '', 'list_columns' => 3, 'list_rows' => 5, 'image_width' => 200, 'image_height' => 0, 'extra' => '[]', 'created_at' => 1, 'updated_at' => 1];
        $top = $db->insert('yc_categories', ['code' => '10', 'parent_id' => null, 'depth' => 1, 'name' => '의류'] + $common);
        $child = $db->insert('yc_categories', ['code' => '1010', 'parent_id' => $top, 'depth' => 2, 'name' => '셔츠'] + $common);
        $grand = $db->insert('yc_categories', ['code' => '101010', 'parent_id' => $child, 'depth' => 3, 'name' => '반팔 셔츠'] + $common);
        $dup = $db->insert('yc_categories', ['code' => '20', 'parent_id' => null, 'depth' => 1, 'name' => '의류'] + $common);
        Schema::migrate($db);
        Schema::migrate($db);
        $rows = [];
        foreach ($db->select('SELECT * FROM ' . $db->table('yc_categories') . ' ORDER BY id') as $row) $rows[(int) $row['id']] = $row;
        self::assertArrayNotHasKey('code', $rows[(int) $top]);
        self::assertSame(['의류', '/' . $top . '/', 1, '10'], [$rows[(int) $top]['slug'], $rows[(int) $top]['path'], (int) $rows[(int) $top]['depth'], $rows[(int) $top]['legacy_code']]);
        self::assertSame(['셔츠', '/' . $top . '/' . $child . '/', 2, '1010'], [$rows[(int) $child]['slug'], $rows[(int) $child]['path'], (int) $rows[(int) $child]['depth'], $rows[(int) $child]['legacy_code']]);
        self::assertSame(['반팔-셔츠', '/' . $top . '/' . $child . '/' . $grand . '/', 3], [$rows[(int) $grand]['slug'], $rows[(int) $grand]['path'], (int) $rows[(int) $grand]['depth']]);
        self::assertSame('의류-2', $rows[(int) $dup]['slug']);
        $this->assertIndexesExist();
        self::assertNull($this->shop->categories->byLegacyCode('99'));
        self::assertSame((int) $child, (int) $this->shop->categories->byLegacyCode('1010')['id']);
        // 갱신이 중간에 끊겨 path 가 빈 행이 남으면(MySQL 은 ALTER·UPDATE 가 하나씩 확정된다) 다음 갱신이 마저 채운다.
        // 이때 다 채워진 행은 건드리지 않는다 — 손으로 고친 슬러그(공개 주소)가 이름에서 다시 만들어지면 안 된다.
        $db->execute('UPDATE ' . $db->table('yc_categories') . ' SET slug = ? WHERE id = ?', ['custom', $grand]);
        $db->execute('UPDATE ' . $db->table('yc_categories') . " SET path = '', slug = ? WHERE id = ?", ['c' . $child, $child]);
        Schema::migrate($db);
        $healed = $db->selectOne('SELECT slug, path FROM ' . $db->table('yc_categories') . ' WHERE id = ?', [$child]);
        self::assertSame(['셔츠', '/' . $top . '/' . $child . '/'], [$healed['slug'], $healed['path']]);
        $kept = $db->selectOne('SELECT slug, path FROM ' . $db->table('yc_categories') . ' WHERE id = ?', [$grand]);
        self::assertSame(['custom', '/' . $top . '/' . $child . '/' . $grand . '/'], [$kept['slug'], $kept['path']]);
    }

    private function assertIndexesExist(): void
    {
        $db = $this->app->db();
        foreach (self::INDEXES as $index => $table) {
            $physical = $db->prefix() . $index;
            $exists = match ($db->dialect()->name()) {
                'sqlite' => $db->selectOne("SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?", [$physical]),
                'mysql' => $db->selectOne('SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$db->tableName($table), $physical]),
            };
            self::assertNotNull($exists, $index);
        }
    }
}
