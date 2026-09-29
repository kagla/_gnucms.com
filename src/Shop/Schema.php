<?php

declare(strict_types=1);

namespace GnuCms\Shop;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;

/**
 * 쇼핑몰 표. 코어 스키마(Db\Schema::migrateShop())가 부른다. 모듈 시절의 표 이름을 그대로 쓰므로
 * 그때 만든 데이터를 넘겨받는다. 멱등이다 — 표는 IF NOT EXISTS, 칸은 없을 때만, 인덱스는 없을 때만.
 *
 * 이 파일이 바뀌면 코어 스키마 도장(Db\Schema::stamp())도 바뀐다. 판 번호를 올리지 않아도
 * 기존 사이트에서 갱신이 한 번 더 돈다.
 */
final class Schema
{
    public const TABLES = ['yc_settings', 'yc_categories', 'yc_products', 'yc_product_categories', 'yc_product_images',
        'yc_option_groups', 'yc_options', 'yc_stock_log', 'yc_orders', 'yc_order_items', 'yc_order_history', 'yc_order_notes',
        'yc_order_refunds', 'yc_settlements', 'yc_product_feedback'];

    public const ORDER_COLUMNS = [
        'default_address' => 'SMALLINT NOT NULL DEFAULT 0',
        'taxable_amount' => 'BIGINT NOT NULL DEFAULT 0',
        'supply_amount' => 'BIGINT NOT NULL DEFAULT 0',
        'vat_amount' => 'BIGINT NOT NULL DEFAULT 0',
        'tax_free_amount' => 'BIGINT NOT NULL DEFAULT 0',
    ];

    public const ORDER_ITEM_COLUMNS = ['tax_free' => 'SMALLINT NOT NULL DEFAULT 0'];

    /** 결제 칸. 새 설치는 CREATE 문에, 결제 이전에 만든 yc_orders 에는 addColumn() 이 넣는다. */
    public const PAYMENT_COLUMNS = [
        'payment_method' => 'VARCHAR(20) NOT NULL DEFAULT \'\'',
        'payment_provider' => 'VARCHAR(32) NOT NULL DEFAULT \'\'',
        'payment_id' => 'VARCHAR(32) NOT NULL DEFAULT \'\'',
        'payment_environment' => 'VARCHAR(8) NOT NULL DEFAULT \'\'',
        'payment_revision' => 'VARCHAR(32) NOT NULL DEFAULT \'\'',
        'paid_at' => 'BIGINT NOT NULL DEFAULT 0',
        'paid_amount' => 'BIGINT NOT NULL DEFAULT 0',
        'refunded_amount' => 'BIGINT NOT NULL DEFAULT 0',
        'payment_detail' => 'VARCHAR(2000) NOT NULL DEFAULT \'\'',
        'pay_by' => 'BIGINT NOT NULL DEFAULT 0',
    ];

    /** 30판: 이벤트·기획전 분류를 메뉴에서 감춘다. 새 설치는 CREATE 문에, 그 전에 만든 yc_categories 에는 addColumn() 이 넣는다. */
    public const CATEGORY_COLUMNS = ['menu_hidden' => 'SMALLINT NOT NULL DEFAULT 0'];

    public static function migrate(Connection $db): void
    {
        $bin = ' COLLATE utf8mb4_bin';
        $definitions = [
            'yc_orders' => 'id {AUTO_PK}, number VARCHAR(32)' . $bin . ' NOT NULL UNIQUE,
                checkout_key VARCHAR(64) NOT NULL UNIQUE, owner_key VARCHAR(64) NOT NULL, user_id BIGINT NOT NULL,
                default_address SMALLINT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL,
                buyer_name VARCHAR(100) NOT NULL, email VARCHAR(191) NOT NULL, phone VARCHAR(30) NOT NULL,
                recipient VARCHAR(100) NOT NULL, recipient_phone VARCHAR(30) NOT NULL, postcode VARCHAR(10) NOT NULL,
                address VARCHAR(250) NOT NULL, address_detail VARCHAR(250) NOT NULL, delivery_note VARCHAR(500) NOT NULL,
                subtotal BIGINT NOT NULL, shipping_fee BIGINT NOT NULL, cod_fee BIGINT NOT NULL, total BIGINT NOT NULL,
                taxable_amount BIGINT NOT NULL DEFAULT 0, supply_amount BIGINT NOT NULL DEFAULT 0,
                vat_amount BIGINT NOT NULL DEFAULT 0, tax_free_amount BIGINT NOT NULL DEFAULT 0,
                shipping_detail {TEXT} NOT NULL, order_notice {TEXT} NOT NULL,
                carrier VARCHAR(100) NOT NULL, tracking_number VARCHAR(100) NOT NULL,
                created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, ' . self::paymentColumnSql(),
            'yc_order_items' => 'id {AUTO_PK}, order_id BIGINT NOT NULL, product_id BIGINT NOT NULL, option_id BIGINT NULL,
                kind VARCHAR(8) NOT NULL, product_code VARCHAR(20) NOT NULL, product_name VARCHAR(250) NOT NULL,
                option_label VARCHAR(350) NOT NULL, image VARCHAR(100) NOT NULL,
                unit_price BIGINT NOT NULL, quantity INTEGER NOT NULL, total BIGINT NOT NULL,
                tax_free SMALLINT NOT NULL DEFAULT 0',
            'yc_order_history' => 'id {AUTO_PK}, order_id BIGINT NOT NULL, status VARCHAR(20) NOT NULL,
                actor VARCHAR(100) NOT NULL, note VARCHAR(500) NOT NULL, created_at BIGINT NOT NULL',
            'yc_order_notes' => 'id {AUTO_PK}, order_id BIGINT NOT NULL, actor VARCHAR(100) NOT NULL,
                note VARCHAR(500) NOT NULL, created_at BIGINT NOT NULL, occurred_at BIGINT NOT NULL DEFAULT 0,
                after_history_id BIGINT NOT NULL DEFAULT 0',
            'yc_order_refunds' => 'id {AUTO_PK}, order_id BIGINT NOT NULL, payment_provider VARCHAR(32) NOT NULL DEFAULT \'\',
                refund_key VARCHAR(100)' . $bin . ' NOT NULL, transaction_id VARCHAR(191) NOT NULL DEFAULT \'\',
                amount BIGINT NOT NULL, taxable_amount BIGINT NOT NULL DEFAULT 0, supply_amount BIGINT NOT NULL DEFAULT 0,
                vat_amount BIGINT NOT NULL DEFAULT 0, tax_free_amount BIGINT NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT \'succeeded\', reason VARCHAR(500) NOT NULL, actor VARCHAR(100) NOT NULL,
                created_at BIGINT NOT NULL',
            'yc_settlements' => 'id {AUTO_PK}, provider VARCHAR(32) NOT NULL, environment VARCHAR(8) NOT NULL DEFAULT \'live\',
                merchant_id VARCHAR(64) NOT NULL DEFAULT \'\', order_id BIGINT NULL, payment_id VARCHAR(191) NOT NULL,
                transaction_key VARCHAR(191)' . $bin . ' NOT NULL, kind VARCHAR(16) NOT NULL,
                amount BIGINT NOT NULL, fee_supply BIGINT NOT NULL DEFAULT 0, fee_vat BIGINT NOT NULL DEFAULT 0,
                payout_amount BIGINT NOT NULL, sold_date DATE NOT NULL, payout_date DATE NULL,
                source VARCHAR(20) NOT NULL DEFAULT \'csv\', raw_hash CHAR(64) NOT NULL, imported_at BIGINT NOT NULL',
            // 후기만 review_user_id 를 채운다. NULL 인 문의는 여러 건을 허용하고 UNIQUE 인덱스로 후기 중복을 막는다.
            'yc_product_feedback' => 'id {AUTO_PK}, product_id BIGINT NOT NULL, user_id BIGINT NOT NULL,
                review_user_id BIGINT NULL, kind VARCHAR(10) NOT NULL, author VARCHAR(100) NOT NULL,
                title VARCHAR(150) NOT NULL, content {TEXT} NOT NULL, rating SMALLINT NOT NULL DEFAULT 0,
                is_private SMALLINT NOT NULL DEFAULT 0, reply {TEXT} NOT NULL, reply_actor VARCHAR(100) NOT NULL DEFAULT \'\',
                replied_at BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL',
            'yc_settings' => 'id VARCHAR(32) PRIMARY KEY, payload {TEXT} NOT NULL',
            'yc_categories' => self::categoriesDefinition($bin),
            'yc_products' => 'id {AUTO_PK}, code VARCHAR(20)' . $bin . ' NOT NULL UNIQUE, slug VARCHAR(200)' . $bin . ' NOT NULL UNIQUE, category_id BIGINT NOT NULL,
                name VARCHAR(250) NOT NULL, summary {TEXT} NOT NULL,
                description {TEXT} NOT NULL, description_text {TEXT} NOT NULL, list_price BIGINT NOT NULL DEFAULT 0, price BIGINT NOT NULL,
                tax_free SMALLINT NOT NULL DEFAULT 0, active SMALLINT NOT NULL DEFAULT 1,
                sold_out SMALLINT NOT NULL DEFAULT 0, stock INTEGER NOT NULL DEFAULT 0,
                stock_alert INTEGER NOT NULL DEFAULT 0, buy_min INTEGER NOT NULL DEFAULT 0,
                buy_max INTEGER NOT NULL DEFAULT 0, phone_inquiry SMALLINT NOT NULL DEFAULT 0, shipping_type SMALLINT NOT NULL DEFAULT 0,
                shipping_method SMALLINT NOT NULL DEFAULT 0, shipping_fee BIGINT NOT NULL DEFAULT 0, shipping_free_minimum BIGINT NOT NULL DEFAULT 0,
                shipping_per_qty INTEGER NOT NULL DEFAULT 0,
                info_group VARCHAR(50) NOT NULL DEFAULT \'\', info_values {TEXT} NOT NULL, memo {TEXT} NOT NULL, hit INTEGER NOT NULL DEFAULT 0,
                sold_qty INTEGER NOT NULL DEFAULT 0, review_count INTEGER NOT NULL DEFAULT 0, review_avg DECIMAL(2,1) NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0,
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
            'yc_stock_log' => 'id {AUTO_PK}, product_id BIGINT NOT NULL, option_id BIGINT NULL, delta INTEGER NOT NULL, kind VARCHAR(20) NOT NULL,
                reference VARCHAR(100) NOT NULL, actor VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL',
        ];
        foreach ($definitions as $table => $definition) {
            $db->execute('CREATE TABLE IF NOT EXISTS ' . $db->table($table) . ' (' . strtr($definition, $db->dialect()->typeMap()) . ')' . $db->dialect()->tableSuffix());
        }
        // 40판: 관련상품 기능과 기존 관계 데이터를 함께 제거한다. 재실행해도 안전하다.
        $db->execute('DROP TABLE IF EXISTS ' . $db->table('yc_product_relations'));
        self::removeRelatedSettings($db);
        // 41판: 단일 판매자 운영에 맞춰 상품별 판매자 메일과 상세 위·아래 HTML을 제거한다.
        foreach (['seller_email', 'head_html', 'tail_html'] as $column) self::dropColumn($db, 'yc_products', $column);
        // 42판: 실제 적립·사용 기능이 없는 상품 포인트 설정과 저장 데이터를 제거한다.
        foreach (['point_type', 'point', 'supply_point'] as $column) self::dropColumn($db, 'yc_products', $column);
        foreach (self::PAYMENT_COLUMNS + self::ORDER_COLUMNS as $column => $definition) self::addColumn($db, 'yc_orders', $column, $definition);
        foreach (self::ORDER_ITEM_COLUMNS as $column => $definition) self::addColumn($db, 'yc_order_items', $column, $definition);
        // 45판 이전 주문에는 면세 스냅샷이 없으므로 기존 결제 총액을 과세 금액으로 보존한다.
        $orders = $db->table('yc_orders');
        $db->execute('UPDATE ' . $orders . ' SET taxable_amount = total, supply_amount = FLOOR(total * 10 / 11), '
            . 'vat_amount = total - FLOOR(total * 10 / 11) WHERE total > 0 AND taxable_amount = 0 AND supply_amount = 0 AND vat_amount = 0 AND tax_free_amount = 0');
        self::addColumn($db, 'yc_order_notes', 'occurred_at', 'BIGINT NOT NULL DEFAULT 0');
        self::addColumn($db, 'yc_order_notes', 'after_history_id', 'BIGINT NOT NULL DEFAULT 0');
        self::migrateMemberOrders($db);
        $db->execute("UPDATE " . $db->table('yc_orders') . " SET payment_provider = 'inicis' WHERE payment_provider = '' AND payment_id <> '' AND payment_method IN ('card', 'easy_pay', 'bank_transfer', 'virtual_account', 'mobile')");
        // 28판 초안에서 잠깐 있었던 칸. 편집기 사진은 categories/<id> 폴더로 구분하므로 필요 없다.
        self::dropColumn($db, 'yc_categories', 'image_key');
        self::migrateCategoryTree($db, $bin);
        // 트리 갱신이 표를 다시 만든 뒤에 둔다 — 그렇게 만들어진 표에도 이 칸이 있어야 한다.
        foreach (self::CATEGORY_COLUMNS as $column => $definition) self::addColumn($db, 'yc_categories', $column, $definition);
        // 44판: 실제 할인 기능 없이 남아 있던 상품·분류 쿠폰 허용 칸과 이전 이름을 제거한다.
        foreach (['yc_products', 'yc_categories'] as $table) {
            self::dropColumn($db, $table, 'coupon');
            self::dropColumn($db, $table, 'no_coupon');
        }
        // 46판: 신청·발송 기능 없이 남아 있던 재입고 알림 허용 값을 제거한다.
        self::dropColumn($db, 'yc_products', 'restock_notify');
        // 47판: 상품정보고시와 중복되고 표시·검색에만 쓰이던 기본 정보 칸을 제거한다.
        foreach (['maker', 'origin', 'brand', 'model'] as $column) self::dropColumn($db, 'yc_products', $column);
        // 48판: 환불을 건별 원장으로 보존하고 PG 정산 자료를 공통 형식으로 적재한다.
        self::backfillRefundLedger($db);
        self::migrateDisplayFlags($db);
        $indexes = ['yc_cat_parent' => ['yc_categories', 'parent_id'], 'yc_cat_order' => ['yc_categories', 'sort_order'],
            'yc_cat_path' => ['yc_categories', 'path'],
            'yc_prod_category' => ['yc_products', 'category_id'], 'yc_prod_name' => ['yc_products', 'name'],
            'yc_prod_order' => ['yc_products', 'sort_order'], 'yc_prod_updated' => ['yc_products', 'updated_at'],
            'yc_prod_price' => ['yc_products', 'price'], 'yc_pc_category' => ['yc_product_categories', 'category_id'],
            'yc_img_product' => ['yc_product_images', 'product_id'], 'yc_opt_product' => ['yc_options', 'product_id'],
            'yc_stock_product' => ['yc_stock_log', 'product_id'],
            'yc_order_user' => ['yc_orders', 'user_id'], 'yc_order_status' => ['yc_orders', 'status'],
            'yc_order_created' => ['yc_orders', 'created_at'], 'yc_order_pay_by' => ['yc_orders', 'pay_by'],
            'yc_order_paid' => ['yc_orders', 'paid_at'],
            'yc_order_payment' => ['yc_orders', 'payment_id'],
            'yc_oi_order' => ['yc_order_items', 'order_id'],
            'yc_oi_product' => ['yc_order_items', 'product_id'], 'yc_oi_option' => ['yc_order_items', 'option_id'],
            'yc_history_order' => ['yc_order_history', 'order_id'],
            'yc_notes_order' => ['yc_order_notes', 'order_id'],
            'yc_refund_order' => ['yc_order_refunds', 'order_id'],
            'yc_refund_created' => ['yc_order_refunds', 'created_at'],
            'yc_settlement_provider' => ['yc_settlements', 'provider'],
            'yc_settlement_order' => ['yc_settlements', 'order_id'],
            'yc_settlement_sold' => ['yc_settlements', 'sold_date'],
            'yc_settlement_payout' => ['yc_settlements', 'payout_date'],
            'yc_feedback_product' => ['yc_product_feedback', 'product_id'],
            'yc_feedback_user' => ['yc_product_feedback', 'user_id'],
            'yc_feedback_created' => ['yc_product_feedback', 'created_at']];
        foreach ($indexes as $index => [$table, $column]) {
            if (!self::indexExists($db, $table, $index)) $db->execute('CREATE INDEX ' . $db->index($index) . ' ON ' . $db->table($table) . ' (' . $db->q($column) . ')');
        }
        $uniqueIndexes = ['yc_cat_slug' => ['yc_categories', ['slug']], 'yc_cat_legacy_code' => ['yc_categories', ['legacy_code']],
            'yc_feedback_review' => ['yc_product_feedback', ['product_id', 'review_user_id']],
            'yc_refund_key' => ['yc_order_refunds', ['order_id', 'refund_key']],
            'yc_settlement_transaction' => ['yc_settlements', ['provider', 'environment', 'merchant_id', 'transaction_key']]];
        foreach ($uniqueIndexes as $index => [$table, $columns]) {
            if (!self::indexExists($db, $table, $index)) {
                $db->execute('CREATE UNIQUE INDEX ' . $db->index($index) . ' ON ' . $db->table($table)
                    . ' (' . implode(', ', array_map($db->q(...), $columns)) . ')');
            }
        }
    }

    /** 이전 버전의 주문별 환불 누계를 한 건의 이전 원장으로 남긴다. */
    private static function backfillRefundLedger(Connection $db): void
    {
        $orders = $db->table('yc_orders');
        $refunds = $db->table('yc_order_refunds');
        $db->execute('INSERT INTO ' . $refunds . ' (order_id, payment_provider, refund_key, transaction_id, amount, '
            . 'taxable_amount, supply_amount, vat_amount, tax_free_amount, status, reason, actor, created_at) '
            . 'SELECT o.id, o.payment_provider, CONCAT(\'legacy-\', o.id), \'\', o.refunded_amount, '
            . '(o.refunded_amount - LEAST(o.refunded_amount, FLOOR(o.tax_free_amount * o.refunded_amount / GREATEST(o.total, 1)))), '
            . 'CASE WHEN o.taxable_amount > 0 THEN FLOOR(o.supply_amount * '
            . '(o.refunded_amount - LEAST(o.refunded_amount, FLOOR(o.tax_free_amount * o.refunded_amount / GREATEST(o.total, 1)))) / o.taxable_amount) ELSE 0 END, '
            . 'CASE WHEN o.taxable_amount > 0 THEN (o.refunded_amount - LEAST(o.refunded_amount, FLOOR(o.tax_free_amount * o.refunded_amount / GREATEST(o.total, 1)))) '
            . '- FLOOR(o.supply_amount * (o.refunded_amount - LEAST(o.refunded_amount, FLOOR(o.tax_free_amount * o.refunded_amount / GREATEST(o.total, 1)))) / o.taxable_amount) ELSE 0 END, '
            . 'LEAST(o.refunded_amount, FLOOR(o.tax_free_amount * o.refunded_amount / GREATEST(o.total, 1))), '
            . '\'legacy\', \'기존 누적 환불\', \'migration\', o.updated_at FROM ' . $orders . ' o '
            . 'WHERE o.refunded_amount > 0 AND NOT EXISTS (SELECT 1 FROM ' . $refunds . ' r WHERE r.order_id = o.id)');
    }

    /** 40판: 기존 쇼핑몰 설정 JSON에서 관련상품 표시 옵션을 지운다. */
    private static function removeRelatedSettings(Connection $db): void
    {
        $row = $db->selectOne('SELECT payload FROM ' . $db->table('yc_settings') . " WHERE id = 'settings'");
        if ($row === null) return;
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload) || !array_key_exists('related', $payload)) return;
        unset($payload['related']);
        $db->update('yc_settings', ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)], 'id = :id', ['id' => 'settings']);
    }

    /**
     * 31판: 진열 깃발을 없앤다. 히트·추천·인기 깃발이 켜진 상품은 메뉴 숨김 분류로 옮겨 보존하고,
     * 신상품·할인은 규칙이 대신한다. 옮긴 분류는 설정의 migrated_types 에 남겨 옛 유형 주소가 그리로 가게 한다.
     */
    private static function migrateDisplayFlags(Connection $db): void
    {
        $products = $db->table('yc_products'); $categories = $db->table('yc_categories'); $links = $db->table('yc_product_categories');
        // 상품 옮기기는 깃발이 아직 있을 때만. 칸 삭제는 아래에서 갱신마다 확인한다(다섯 칸 중간에 끊긴 이전도 마저 끝낸다).
        if (self::columnExists($db, 'yc_products', 'is_hit')) {
            $db->transaction(function () use ($db, $products, $categories, $links): void {
                $now = time();
                $recorded = self::recordedTypes($db);
                $moved = [];
                foreach (['is_hit' => ['hit', '히트상품'], 'is_recommended' => ['recommend', '추천상품'], 'is_popular' => ['popular', '인기상품']] as $flag => [$type, $name]) {
                    $ids = array_map('intval', array_column($db->select('SELECT id FROM ' . $products . ' WHERE ' . $flag . ' = 1 ORDER BY id'), 'id'));
                    if ($ids === []) continue;
                    // 지난 이전이 만든 분류가 아직 있으면 그것에 마저 건다. 칸 삭제가 실패해(ALTER 권한·잠금)
                    // 갱신이 또 여기로 와도 히트상품-2, -3 이 생기지 않게 — 자료 옮기기를 DDL 과 따로 멱등하게 둔다.
                    $categoryId = isset($recorded[$type]) && $db->selectOne('SELECT id FROM ' . $categories . ' WHERE id = ?', [$recorded[$type]]) !== null ? $recorded[$type] : 0;
                    if ($categoryId === 0) {
                        $slug = $name;
                        for ($n = 2; $db->selectOne('SELECT id FROM ' . $categories . ' WHERE slug = ?', [$slug]) !== null; $n++) $slug = $name . '-' . $n;
                        $categoryId = (int) $db->insert('yc_categories', ['parent_id' => null, 'depth' => 1, 'name' => $name, 'slug' => $slug, 'path' => '', 'legacy_code' => null,
                            'sort_order' => 0, 'active' => 1, 'menu_hidden' => 1, 'head_html' => '', 'tail_html' => '', 'list_columns' => 4, 'list_rows' => 5,
                            'image_width' => 200, 'image_height' => 0, 'extra' => '[]', 'created_at' => $now, 'updated_at' => $now]);
                        $db->update('yc_categories', ['path' => '/' . $categoryId . '/'], 'id = :id', ['id' => $categoryId]);
                    }
                    foreach ($ids as $productId) {
                        if ($db->selectOne('SELECT 1 AS x FROM ' . $links . ' WHERE product_id = ? AND category_id = ?', [$productId, $categoryId]) !== null) continue;
                        $slot = (int) $db->selectOne('SELECT COALESCE(MAX(slot), 0) AS s FROM ' . $links . ' WHERE product_id = ?', [$productId])['s'] + 1;
                        $db->insert('yc_product_categories', ['product_id' => $productId, 'category_id' => $categoryId, 'slot' => max(2, $slot)]);
                    }
                    $moved[$type] = $categoryId;
                }
                if ($moved !== []) self::recordMigratedTypes($db, $moved);
            });
        }
        // MySQL DDL은 스스로 확정되므로 트랜잭션 밖에서 지운다. 없는 칸은 dropColumn() 이 건너뛴다.
        foreach (['is_hit', 'is_recommended', 'is_new', 'is_popular', 'is_discount'] as $column) self::dropColumn($db, 'yc_products', $column);
    }

    /** 지난 이전이 적어 둔 묶음별 분류 id. 설정이 없거나 깨졌으면 빈 배열이다. */
    private static function recordedTypes(Connection $db): array
    {
        $row = $db->selectOne('SELECT payload FROM ' . $db->table('yc_settings') . " WHERE id = 'settings'");
        $payload = $row === null ? null : json_decode((string) $row['payload'], true);
        $types = is_array($payload) && is_array($payload['migrated_types'] ?? null) ? $payload['migrated_types'] : [];
        $recorded = [];
        foreach ($types as $type => $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) $recorded[(string) $type] = (int) $id;
        }
        return $recorded;
    }

    /** 옛 유형 주소(/shop/type?t=hit)가 어느 분류로 갔는지 설정에 적어 둔다. 이름·슬러그가 겹쳐 -2 가 붙어도 찾을 수 있게. */
    private static function recordMigratedTypes(Connection $db, array $moved): void
    {
        $table = $db->table('yc_settings');
        $row = $db->selectOne('SELECT payload FROM ' . $table . " WHERE id = 'settings'");
        if ($row === null) {
            $db->insert('yc_settings', ['id' => 'settings', 'payload' => json_encode(['migrated_types' => $moved], JSON_UNESCAPED_UNICODE)]);
            return;
        }
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) $payload = [];
        $payload['migrated_types'] = $moved + (is_array($payload['migrated_types'] ?? null) ? $payload['migrated_types'] : []);
        $db->update('yc_settings', ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)], 'id = :id', ['id' => 'settings']);
    }

    private static function indexExists(Connection $db, string $table, string $index): bool
    {
        $physical = $db->prefix() . $index;
        return $db->selectOne('SELECT index_name FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?', [$db->tableName($table), $physical]) !== null;
    }

    /**
     * 분류 표. 부모 id 트리: path 는 /1/5/12/ 처럼 조상부터 자기까지의 id, slug 는 공개 주소, legacy_code 는 29판 이전의 2자 코드(새 분류는 NULL).
     * slug·path 의 기본값 '' 는 29판 갱신이 MySQL 에 붙이는 칸과 모양을 맞춘 것이다 — 새로 깐 곳과 갱신한 곳의 표가 같아야 한다.
     */
    private static function categoriesDefinition(string $bin): string
    {
        return 'id {AUTO_PK}, parent_id BIGINT NULL, depth SMALLINT NOT NULL, name VARCHAR(100) NOT NULL,
            slug VARCHAR(200)' . $bin . " NOT NULL DEFAULT '', path VARCHAR(255) NOT NULL DEFAULT '', legacy_code VARCHAR(10)" . $bin . ' NULL,
            sort_order INTEGER NOT NULL DEFAULT 0, active SMALLINT NOT NULL DEFAULT 1,
            menu_hidden SMALLINT NOT NULL DEFAULT 0,
            head_html {TEXT} NOT NULL, tail_html {TEXT} NOT NULL, list_columns SMALLINT NOT NULL, list_rows SMALLINT NOT NULL,
            image_width INTEGER NOT NULL, image_height INTEGER NOT NULL, extra {TEXT} NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL';
    }

    /**
     * 29판: 2자 코드 계층 → 부모 id 트리. slug 칸이 없으면 표를 새 모양으로 바꾸고, 그다음 남은 뒷정리를 한다.
     * MySQL 은 ALTER 와 UPDATE 가 하나씩 확정되므로 갱신이 중간에 끊길 수 있다 — 그래서 뒷정리(옛 code 칸 버리기,
     * path 가 빈 행 채우기)는 갱신마다 상태를 보고 필요할 때만 하며, 끊긴 갱신은 다음 요청이 마저 끝낸다.
     */
    private static function migrateCategoryTree(Connection $db, string $bin): void
    {
        $table = $db->table('yc_categories');
        if (!self::columnExists($db, 'yc_categories', 'slug')) {
            // MySQL 은 DDL 을 스스로 확정하므로 트랜잭션으로 묶지 않는다(묶으면 커밋이 "열린 트랜잭션 없음" 으로 터진다).
            $db->execute('ALTER TABLE ' . $table . ' ADD COLUMN slug VARCHAR(200)' . $bin . ' NOT NULL DEFAULT \'\', ADD COLUMN path VARCHAR(255) NOT NULL DEFAULT \'\', ADD COLUMN legacy_code VARCHAR(10)' . $bin . ' NULL');
        }
        // 옛 코드는 legacy_code로만 남는다.
        if (self::columnExists($db, 'yc_categories', 'code')) {
            $db->execute('UPDATE ' . $table . ' SET legacy_code = code WHERE legacy_code IS NULL');
            self::dropColumn($db, 'yc_categories', 'code');
        }
        if ((int) $db->selectOne('SELECT COUNT(*) AS c FROM ' . $table . " WHERE path = ''")['c'] > 0) {
            $db->transaction(function () use ($db): void { self::fillCategoryTree($db); });
        }
    }

    /**
     * 부모 사슬로 path·depth 를, 이름으로 slug 를 채운다(겹치면 -2, -3…, 이름에서 못 만들면 c<id>).
     * 채우는 것은 path 가 빈 행뿐이다 — 이미 채워진 행의 slug 는 공개 주소라 이름에서 다시 만들면 안 된다.
     * 부모는 모든 행에서 찾고, 겹침은 그대로 두는 행의 slug 까지 세어 피한다.
     */
    private static function fillCategoryTree(Connection $db): void
    {
        $table = $db->table('yc_categories');
        $rows = [];
        foreach ($db->select('SELECT id, parent_id, name, slug, path FROM ' . $table . ' ORDER BY id') as $row) $rows[(int) $row['id']] = $row;
        $paths = [];
        $walking = [];
        $pathOf = static function (int $id) use (&$pathOf, &$paths, &$walking, $rows): string {
            if (isset($paths[$id])) return $paths[$id];
            $parent = $rows[$id]['parent_id'];
            $walking[$id] = true;
            // 들여온 자료의 부모 사슬이 고리를 이루면(a→b→a) 고리를 만난 행을 뿌리로 보고 끊는다 — 끝없이 되돌지 않게.
            $above = $parent === null || !isset($rows[(int) $parent]) || isset($walking[(int) $parent]) ? '/' : $pathOf((int) $parent);
            unset($walking[$id]);
            return $paths[$id] = $above . $id . '/';
        };
        $taken = [];
        foreach ($rows as $row) if ((string) $row['path'] !== '' && (string) $row['slug'] !== '') $taken[(string) $row['slug']] = true;
        foreach ($rows as $id => $row) {
            if ((string) $row['path'] !== '') continue;
            $base = Input::slug((string) $row['name'], 'c' . $id);
            $slug = $base;
            for ($n = 2; isset($taken[$slug]); $n++) $slug = $base . '-' . $n;
            $taken[$slug] = true;
            $path = $pathOf($id);
            $db->execute('UPDATE ' . $table . ' SET slug = ?, path = ?, depth = ? WHERE id = ?', [$slug, $path, substr_count($path, '/') - 1, $id]);
        }
    }

    private static function paymentColumnSql(): string
    {
        $parts = [];
        foreach (self::PAYMENT_COLUMNS as $column => $definition) $parts[] = $column . ' ' . $definition;
        return implode(', ', $parts);
    }

    /** 기존 비회원 주문은 보존한다. 새 주문은 서비스에서 회원 ID를 필수로 받는다. */
    private static function migrateMemberOrders(Connection $db): void
    {
        $column = $db->selectOne('SELECT IS_NULLABLE AS nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$db->tableName('yc_orders'), 'user_id']);
        if (($column['nullable'] ?? '') === 'YES'
            && $db->selectOne('SELECT id FROM ' . $db->table('yc_orders') . ' WHERE user_id IS NULL LIMIT 1') === null) {
            $db->execute('ALTER TABLE ' . $db->table('yc_orders') . ' MODIFY COLUMN user_id BIGINT NOT NULL');
        }
        self::dropColumn($db, 'yc_orders', 'guest_password');
    }

    /** 칸이 있는지 본다. 이름은 이 파일의 상수로만 들어온다. */
    private static function columnExists(Connection $db, string $table, string $column): bool
    {
        try {
            $db->selectOne('SELECT ' . $column . ' FROM ' . $db->table($table) . ' LIMIT 1');
        } catch (DomainError) {
            return false;
        }
        return true;
    }

    /** 기존 표의 칸을 지운다. 없으면 아무것도 하지 않는다. */
    private static function dropColumn(Connection $db, string $table, string $column): void
    {
        if (!self::columnExists($db, $table, $column)) return;
        $db->execute('ALTER TABLE ' . $db->table($table) . ' DROP COLUMN ' . $column);
    }

    /** 기존 표에 칸을 더한다. 이미 있으면 아무것도 하지 않는다 — 코어 Schema::addColumnIfMissing() 과 같은 방식. */
    private static function addColumn(Connection $db, string $table, string $column, string $definition): void
    {
        if (self::columnExists($db, $table, $column)) return;
        $db->execute('ALTER TABLE ' . $db->table($table) . ' ADD COLUMN ' . $column . ' ' . strtr($definition, $db->dialect()->typeMap()));
    }
}
