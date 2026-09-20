<?php

declare(strict_types=1);

namespace GnuCms\Tests\YoungCart;

use GnuCms\Modules\YoungCart\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class SchemaTest extends YoungCartTestCase
{
    /** Schema::install() 의 DDL 클로저가 만드는 인덱스 목록과 그대로 맞춰 둔다. */
    private const INDEXES = ['yc_cat_parent' => 'yc_categories', 'yc_cat_order' => 'yc_categories',
        'yc_prod_category' => 'yc_products', 'yc_prod_name' => 'yc_products',
        'yc_prod_order' => 'yc_products', 'yc_prod_updated' => 'yc_products',
        'yc_prod_price' => 'yc_products', 'yc_pc_category' => 'yc_product_categories',
        'yc_img_product' => 'yc_product_images', 'yc_opt_product' => 'yc_options',
        'yc_rel_related' => 'yc_product_relations', 'yc_stock_product' => 'yc_stock_log',
        'yc_order_user' => 'yc_orders', 'yc_order_status' => 'yc_orders', 'yc_order_created' => 'yc_orders',
        'yc_oi_order' => 'yc_order_items', 'yc_oi_product' => 'yc_order_items', 'yc_oi_option' => 'yc_order_items', 'yc_history_order' => 'yc_order_history'];

    #[DataProvider('connectionProvider')]
    public function testInstallIsIdempotentAndRegistersTables(array $config): void
    {
        $this->setupShop($config, false);
        self::assertFalse($this->shop->ready());
        $this->shop->install();
        self::assertTrue($this->shop->ready());
        $this->shop->install();
        self::assertTrue($this->shop->ready());
        $schema = $this->shop->schema();
        foreach (Schema::TABLES as $table) self::assertTrue($schema->exists($table), $table);
        self::assertSame(12, count(Schema::TABLES));
        self::assertSame([], array_diff(Schema::TABLES, $schema->backupTables()));
        $status = $schema->status(Schema::KEY);
        self::assertSame('ready', $status['state']);
        self::assertSame(2, (int) $status['schema_version']);
        self::assertSame(19, count(self::INDEXES));
        $this->assertIndexesExist();
        $id = $this->shop->store->insert('yc_categories', ['code' => '10', 'parent_id' => null, 'depth' => 1, 'name' => '의류', 'sort_order' => 0,
            'active' => 1, 'no_coupon' => 0, 'head_html' => '', 'tail_html' => '', 'list_columns' => 3, 'list_rows' => 5,
            'image_width' => 200, 'image_height' => 0, 'extra' => '[]', 'created_at' => 1, 'updated_at' => 1]);
        self::assertSame('의류', $this->shop->store->get('yc_categories', $id)['name']);
        self::assertNull($this->shop->store->find('yc_categories', $id + 1));
        $this->shop->store->logStock(1, null, -2, 'admin', 'test', 'tester');
        self::assertSame(-2, (int) $this->shop->store->selectOne('SELECT delta FROM ' . $this->shop->store->table('yc_stock_log'))['delta']);

        // PackageSchema::current() 가 참이면 install() 이 그냥 반환해 DDL 클로저를 다시
        // 태우지 않는다. 기록된 버전을 되돌려 클로저를 두 번째로 실행시키고, 이미 존재하는
        // 테이블·인덱스를 다시 만나도(IF NOT EXISTS, 존재 확인 후 생략) 문제없이 끝나는지
        // 확인한다. 이 경로가 MySQL 에서는 information_schema.statistics 분기를 태운다.
        $db = $this->app->db();
        $db->update('extension_schemas', ['schema_version' => 0], 'package_key = :key', ['key' => Schema::KEY]);
        Schema::install($schema);
        self::assertTrue($this->shop->ready());
        $status = $schema->status(Schema::KEY);
        self::assertSame(2, (int) $status['schema_version']);
        foreach (Schema::TABLES as $table) self::assertTrue($schema->exists($table), $table);
        self::assertSame(12, count(Schema::TABLES));
        self::assertSame(19, count(self::INDEXES));
        $this->assertIndexesExist();
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
