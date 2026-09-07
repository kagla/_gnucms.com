<?php

declare(strict_types=1);

namespace GnuCms\Tests\YoungCart;

use GnuCms\Modules\YoungCart\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class SchemaTest extends YoungCartTestCase
{
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
        self::assertSame(9, count(Schema::TABLES));
        self::assertSame([], array_diff(Schema::TABLES, $schema->backupTables()));
        $status = $schema->status(Schema::KEY);
        self::assertSame('ready', $status['state']);
        self::assertSame(1, (int) $status['schema_version']);
        $id = $this->shop->store->insert('yc_categories', ['code' => '10', 'parent_id' => null, 'depth' => 1, 'name' => '의류', 'sort_order' => 0,
            'active' => 1, 'no_coupon' => 0, 'head_html' => '', 'tail_html' => '', 'list_columns' => 3, 'list_rows' => 5,
            'image_width' => 200, 'image_height' => 0, 'extra' => '[]', 'created_at' => 1, 'updated_at' => 1]);
        self::assertSame('의류', $this->shop->store->get('yc_categories', $id)['name']);
        self::assertNull($this->shop->store->find('yc_categories', $id + 1));
        $this->shop->store->logStock(1, null, -2, 'admin', 'test', 'tester');
        self::assertSame(-2, (int) $this->shop->store->selectOne('SELECT delta FROM ' . $this->shop->store->table('yc_stock_log'))['delta']);
    }
}
