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
        // 결제 이전 스키마를 재현하므로 결제 인덱스도 먼저 지운다.
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
                'mysql' => $db->selectOne('SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$db->tableName($table), $physical]),
            };
            self::assertNotNull($exists, $index);
        }
    }

    /** 31판: 깃발 다섯 개를 없앤다. 히트·추천·인기가 켜진 상품은 메뉴 숨김 분류로 옮기고, 상품이 없는 깃발은 분류를 만들지 않는다. 두 번 돌려도 같다. */
    #[DataProvider('connectionProvider')]
    public function testMigrateMovesDisplayFlagsIntoHiddenCategories(array $config): void
    {
        $this->setupShop($config);
        $db = $this->app->db();
        foreach (['is_hit', 'is_recommended', 'is_new', 'is_popular', 'is_discount'] as $c) $db->execute('ALTER TABLE ' . $db->table('yc_products') . ' ADD COLUMN ' . $c . ' SMALLINT NOT NULL DEFAULT 0');
        $cat = $this->category('의류');
        $a = $this->product(['category_id' => (string) $cat['id'], 'code' => 'A']); $b = $this->product(['category_id' => (string) $cat['id'], 'code' => 'B']);
        $db->execute('UPDATE ' . $db->table('yc_products') . ' SET is_hit = 1, is_new = 1 WHERE id = ?', [(int) $a['id']]);
        $db->execute('UPDATE ' . $db->table('yc_products') . ' SET is_hit = 1, is_popular = 1 WHERE id = ?', [(int) $b['id']]);
        $this->category('히트상품'); // 슬러그가 이미 쓰이는 경우 → 히트상품-2
        Schema::migrate($db); Schema::migrate($db);
        self::assertArrayNotHasKey('is_hit', $this->shop->products->get((int) $a['id']));
        $hit = $this->shop->categories->bySlug('히트상품-2'); $popular = $this->shop->categories->bySlug('인기상품');
        self::assertNotNull($hit); self::assertSame(1, (int) $hit['menu_hidden']); self::assertNull($hit['parent_id']);
        self::assertSame('히트상품', $hit['name']);
        self::assertNull($this->shop->categories->bySlug('추천상품'), '켜진 상품이 없는 깃발은 분류를 만들지 않는다');
        // 대표 분류(slot 1)는 그대로, 깃발 분류는 다음 slot 으로 붙는다.
        self::assertSame([1 => (int) $cat['id'], 2 => (int) $hit['id']], array_map(static fn (array $c): int => (int) $c['id'], $this->shop->products->get((int) $a['id'])['categories']));
        self::assertSame([1 => (int) $cat['id'], 2 => (int) $hit['id'], 3 => (int) $popular['id']], array_map(static fn (array $c): int => (int) $c['id'], $this->shop->products->get((int) $b['id'])['categories']));
        // 숨김 분류이므로 메뉴에는 나오지 않지만 분류 목록에서는 상품이 보인다.
        self::assertNotContains('히트상품-2', array_column($this->shop->categories->children(null, true, true), 'slug'), '메뉴에는 나오지 않는다');
        // 옛 유형 주소가 찾아갈 수 있게 어느 분류로 옮겼는지 설정에 남는다(슬러그가 아니라 id 로).
        $migrated = $this->shop->settings->all()['migrated_types'];
        self::assertSame((int) $hit['id'], $migrated['hit']);
        self::assertSame((int) $popular['id'], $migrated['popular']);
        self::assertArrayNotHasKey('recommend', $migrated, '분류를 만들지 않은 깃발은 기록도 없다');
        // 이전 기록은 설정을 저장해도 남는다.
        $this->shop->settings->save(HomeBannerTest::form());
        self::assertSame($migrated, $this->shop->settings->all()['migrated_types']);
        self::assertSame(['B', 'A'], array_column($this->shop->listing->category($hit, '', '', 1)['items'], 'code'));
    }

    /**
     * 31판: 칸 삭제가 듣지 않아 갱신이 또 돌아도 분류는 늘지 않는다. 지난 이전이 만든 분류를 다시 쓴다 —
     * ALTER 권한이 없는 MySQL·잠금 대기로 DROP COLUMN 이 실패하면 다음 요청이 또 여기로 온다.
     */
    #[DataProvider('connectionProvider')]
    public function testMigrateReusesTheCategoriesAnEarlierRunCreated(array $config): void
    {
        $this->setupShop($config);
        $db = $this->app->db();
        $flags = function () use ($db): void {
            foreach (['is_hit', 'is_recommended', 'is_new', 'is_popular', 'is_discount'] as $c) $db->execute('ALTER TABLE ' . $db->table('yc_products') . ' ADD COLUMN ' . $c . ' SMALLINT NOT NULL DEFAULT 0');
            $db->execute('UPDATE ' . $db->table('yc_products') . ' SET is_hit = 1');
        };
        $flags();
        $cat = $this->category('의류');
        $a = $this->product(['category_id' => (string) $cat['id'], 'code' => 'A']); $b = $this->product(['category_id' => (string) $cat['id'], 'code' => 'B']);
        $db->execute('UPDATE ' . $db->table('yc_products') . ' SET is_hit = 1');
        Schema::migrate($db);
        $hit = $this->shop->categories->bySlug('히트상품');
        self::assertNotNull($hit);
        // 칸 삭제가 실패한 갱신을 흉내 낸다: 깃발이 그대로 남은 채 다음 요청이 온다.
        $flags();
        Schema::migrate($db);
        self::assertSame(['히트상품'], array_column($db->select('SELECT slug FROM ' . $db->table('yc_categories') . " WHERE name = '히트상품' ORDER BY id"), 'slug'), '분류를 또 만들지 않는다');
        self::assertSame((int) $hit['id'], $this->shop->settings->all()['migrated_types']['hit'], '기록도 그대로다');
        foreach ([$a, $b] as $product) {
            self::assertSame([1 => (int) $cat['id'], 2 => (int) $hit['id']],
                array_map(static fn (array $c): int => (int) $c['id'], $this->shop->products->get((int) $product['id'])['categories']), '상품도 한 번만 걸린다');
        }
    }
}
