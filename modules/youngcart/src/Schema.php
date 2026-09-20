<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart;

use GnuCms\Db\Connection;
use GnuCms\Extension\PackageSchema;

final class Schema
{
    public const KEY = 'modules/youngcart';
    public const VERSION = 2;
    public const TABLES = ['yc_settings', 'yc_categories', 'yc_products', 'yc_product_categories', 'yc_product_images',
        'yc_option_groups', 'yc_options', 'yc_product_relations', 'yc_stock_log', 'yc_orders', 'yc_order_items', 'yc_order_history'];

    public static function install(PackageSchema $schema): void
    {
        $schema->install(self::KEY, self::VERSION, self::TABLES, static function (Connection $db): void {
            $bin = $db->dialect()->name() === 'mysql' ? ' COLLATE utf8mb4_bin' : '';
            $definitions = [
                'yc_orders' => 'id {AUTO_PK}, number VARCHAR(32)' . $bin . ' NOT NULL UNIQUE,
                    checkout_key VARCHAR(64) NOT NULL UNIQUE, owner_key VARCHAR(64) NOT NULL, user_id BIGINT NULL,
                    guest_password VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL,
                    buyer_name VARCHAR(100) NOT NULL, email VARCHAR(191) NOT NULL, phone VARCHAR(30) NOT NULL,
                    recipient VARCHAR(100) NOT NULL, recipient_phone VARCHAR(30) NOT NULL, postcode VARCHAR(10) NOT NULL,
                    address VARCHAR(250) NOT NULL, address_detail VARCHAR(250) NOT NULL, delivery_note VARCHAR(500) NOT NULL,
                    subtotal BIGINT NOT NULL, shipping_fee BIGINT NOT NULL, cod_fee BIGINT NOT NULL, total BIGINT NOT NULL,
                    shipping_detail {TEXT} NOT NULL, order_notice {TEXT} NOT NULL,
                    carrier VARCHAR(100) NOT NULL, tracking_number VARCHAR(100) NOT NULL,
                    created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL',
                'yc_order_items' => 'id {AUTO_PK}, order_id BIGINT NOT NULL, product_id BIGINT NOT NULL, option_id BIGINT NULL,
                    kind VARCHAR(8) NOT NULL, product_code VARCHAR(20) NOT NULL, product_name VARCHAR(250) NOT NULL,
                    option_label VARCHAR(350) NOT NULL, image VARCHAR(100) NOT NULL,
                    unit_price BIGINT NOT NULL, quantity INTEGER NOT NULL, total BIGINT NOT NULL',
                'yc_order_history' => 'id {AUTO_PK}, order_id BIGINT NOT NULL, status VARCHAR(20) NOT NULL,
                    actor VARCHAR(100) NOT NULL, note VARCHAR(500) NOT NULL, created_at BIGINT NOT NULL',
                'yc_settings' => 'id VARCHAR(32) PRIMARY KEY, payload {TEXT} NOT NULL',
                'yc_categories' => 'id {AUTO_PK}, code VARCHAR(10)' . $bin . ' NOT NULL UNIQUE, parent_id BIGINT NULL, depth SMALLINT NOT NULL,
                    name VARCHAR(100) NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, active SMALLINT NOT NULL DEFAULT 1,
                    no_coupon SMALLINT NOT NULL DEFAULT 0, head_html {TEXT} NOT NULL, tail_html {TEXT} NOT NULL,
                    list_columns SMALLINT NOT NULL, list_rows SMALLINT NOT NULL, image_width INTEGER NOT NULL, image_height INTEGER NOT NULL,
                    extra {TEXT} NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL',
                'yc_products' => 'id {AUTO_PK}, code VARCHAR(20)' . $bin . ' NOT NULL UNIQUE, slug VARCHAR(200)' . $bin . ' NOT NULL UNIQUE, category_id BIGINT NOT NULL,
                    name VARCHAR(250) NOT NULL, maker VARCHAR(100) NOT NULL DEFAULT \'\', origin VARCHAR(100) NOT NULL DEFAULT \'\',
                    brand VARCHAR(100) NOT NULL DEFAULT \'\', model VARCHAR(100) NOT NULL DEFAULT \'\', summary {TEXT} NOT NULL,
                    description {TEXT} NOT NULL, description_text {TEXT} NOT NULL, list_price BIGINT NOT NULL DEFAULT 0, price BIGINT NOT NULL,
                    point_type SMALLINT NOT NULL DEFAULT 0, point INTEGER NOT NULL DEFAULT 0, supply_point INTEGER NOT NULL DEFAULT 0,
                    tax_free SMALLINT NOT NULL DEFAULT 0, seller_email VARCHAR(191) NOT NULL DEFAULT \'\', active SMALLINT NOT NULL DEFAULT 1,
                    no_coupon SMALLINT NOT NULL DEFAULT 0, sold_out SMALLINT NOT NULL DEFAULT 0, stock INTEGER NOT NULL DEFAULT 0,
                    stock_alert INTEGER NOT NULL DEFAULT 0, restock_notify SMALLINT NOT NULL DEFAULT 0, buy_min INTEGER NOT NULL DEFAULT 0,
                    buy_max INTEGER NOT NULL DEFAULT 0, phone_inquiry SMALLINT NOT NULL DEFAULT 0, shipping_type SMALLINT NOT NULL DEFAULT 0,
                    shipping_method SMALLINT NOT NULL DEFAULT 0, shipping_fee BIGINT NOT NULL DEFAULT 0, shipping_free_minimum BIGINT NOT NULL DEFAULT 0,
                    shipping_per_qty INTEGER NOT NULL DEFAULT 0, head_html {TEXT} NOT NULL, tail_html {TEXT} NOT NULL,
                    info_group VARCHAR(50) NOT NULL DEFAULT \'\', info_values {TEXT} NOT NULL, memo {TEXT} NOT NULL, hit INTEGER NOT NULL DEFAULT 0,
                    sold_qty INTEGER NOT NULL DEFAULT 0, review_count INTEGER NOT NULL DEFAULT 0, review_avg DECIMAL(2,1) NOT NULL DEFAULT 0,
                    is_hit SMALLINT NOT NULL DEFAULT 0, is_recommended SMALLINT NOT NULL DEFAULT 0, is_new SMALLINT NOT NULL DEFAULT 0,
                    is_popular SMALLINT NOT NULL DEFAULT 0, is_discount SMALLINT NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0,
                    extra {TEXT} NOT NULL, version INTEGER NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL',
                'yc_product_categories' => 'product_id BIGINT NOT NULL, category_id BIGINT NOT NULL, slot SMALLINT NOT NULL,
                    PRIMARY KEY (product_id, slot), UNIQUE (product_id, category_id)',
                'yc_product_images' => 'id {AUTO_PK}, product_id BIGINT NOT NULL, filename VARCHAR(100) NOT NULL, sort_order SMALLINT NOT NULL DEFAULT 0',
                'yc_option_groups' => 'product_id BIGINT NOT NULL, kind VARCHAR(8) NOT NULL, position SMALLINT NOT NULL, name VARCHAR(100) NOT NULL,
                    PRIMARY KEY (product_id, kind, position)',
                'yc_options' => 'id {AUTO_PK}, product_id BIGINT NOT NULL, kind VARCHAR(8) NOT NULL,
                    value1 VARCHAR(100)' . $bin . ' NOT NULL DEFAULT \'\', value2 VARCHAR(100)' . $bin . ' NOT NULL DEFAULT \'\',
                    value3 VARCHAR(100)' . $bin . ' NOT NULL DEFAULT \'\', price BIGINT NOT NULL DEFAULT 0, stock INTEGER NOT NULL DEFAULT 0,
                    stock_alert INTEGER NOT NULL DEFAULT 0, active SMALLINT NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (product_id, kind, value1, value2, value3)',
                'yc_product_relations' => 'product_id BIGINT NOT NULL, related_id BIGINT NOT NULL, sort_order SMALLINT NOT NULL DEFAULT 0,
                    PRIMARY KEY (product_id, related_id)',
                'yc_stock_log' => 'id {AUTO_PK}, product_id BIGINT NOT NULL, option_id BIGINT NULL, delta INTEGER NOT NULL, kind VARCHAR(20) NOT NULL,
                    reference VARCHAR(100) NOT NULL, actor VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL',
            ];
            foreach ($definitions as $table => $definition) {
                $db->execute('CREATE TABLE IF NOT EXISTS ' . $db->table($table) . ' (' . strtr($definition, $db->dialect()->typeMap()) . ')' . $db->dialect()->tableSuffix());
            }
            $indexes = ['yc_cat_parent' => ['yc_categories', 'parent_id'], 'yc_cat_order' => ['yc_categories', 'sort_order'],
                'yc_prod_category' => ['yc_products', 'category_id'], 'yc_prod_name' => ['yc_products', 'name'],
                'yc_prod_order' => ['yc_products', 'sort_order'], 'yc_prod_updated' => ['yc_products', 'updated_at'],
                'yc_prod_price' => ['yc_products', 'price'], 'yc_pc_category' => ['yc_product_categories', 'category_id'],
                'yc_img_product' => ['yc_product_images', 'product_id'], 'yc_opt_product' => ['yc_options', 'product_id'],
                'yc_rel_related' => ['yc_product_relations', 'related_id'], 'yc_stock_product' => ['yc_stock_log', 'product_id'],
                'yc_order_user' => ['yc_orders', 'user_id'], 'yc_order_status' => ['yc_orders', 'status'],
                'yc_order_created' => ['yc_orders', 'created_at'], 'yc_oi_order' => ['yc_order_items', 'order_id'],
                'yc_oi_product' => ['yc_order_items', 'product_id'], 'yc_oi_option' => ['yc_order_items', 'option_id'],
                'yc_history_order' => ['yc_order_history', 'order_id']];
            foreach ($indexes as $index => [$table, $column]) {
                $physical = $db->prefix() . $index;
                $exists = match ($db->dialect()->name()) {
                    'sqlite' => $db->selectOne("SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?", [$physical]),
                    'mysql' => $db->selectOne('SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$db->tableName($table), $physical]),
                };
                if ($exists === null) $db->execute('CREATE INDEX ' . $db->index($index) . ' ON ' . $db->table($table) . ' (' . $db->q($column) . ')');
            }
        });
    }
}
