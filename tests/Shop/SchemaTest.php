<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Shop\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class SchemaTest extends ShopTestCase
{
    /** Schema::migrate() 가 만드는 인덱스 목록과 그대로 맞춰 둔다. */
    private const INDEXES = ['yc_cat_parent' => 'yc_categories', 'yc_cat_order' => 'yc_categories',
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
        self::assertSame(21, count(self::INDEXES));
        $this->assertIndexesExist();
        $id = $this->shop->store->insert('yc_categories', ['code' => '10', 'parent_id' => null, 'depth' => 1, 'name' => '의류', 'sort_order' => 0,
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

    /** 28판 초안에서 잠깐 있었던 yc_categories.image_key 는 지운다 — 편집기 사진은 categories/<id> 폴더로 구분한다. */
    #[DataProvider('connectionProvider')]
    public function testMigrateDropsTheShortLivedImageKeyColumnFromCategories(array $config): void
    {
        $this->setupShop($config);
        $db = $this->app->db();
        $db->execute('ALTER TABLE ' . $db->table('yc_categories') . ' ADD COLUMN image_key VARCHAR(32) NOT NULL DEFAULT \'\'');
        Schema::migrate($db); Schema::migrate($db);
        self::assertArrayNotHasKey('image_key', $this->category('의류'));
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
