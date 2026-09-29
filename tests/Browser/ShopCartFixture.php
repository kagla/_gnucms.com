<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnuCms\Shop\Settings;
use GnuCms\Tests\Support\AdminViewFixture;

$view = AdminViewFixture::view('/cms')->forShop();
$items = [];
foreach (['화이트 / S', '화이트 / M'] as $index => $label) {
    $items[] = ['key' => '10:' . (101 + $index), 'product_id' => 10, 'code' => 'SHIRT', 'image' => null,
        'name' => '옵션 셔츠', 'label' => $label, 'kind' => 'select', 'error' => '', 'quantity' => 1, 'available' => 5, 'total' => 39000];
}
array_splice($items, 1, 0, [['key' => '10:201', 'product_id' => 10, 'code' => 'SHIRT', 'image' => null,
    'name' => '옵션 셔츠', 'label' => '선물 포장', 'kind' => 'extra', 'error' => '', 'quantity' => 2, 'available' => 5, 'total' => 4000]]);
$items[] = ['key' => '20:0', 'product_id' => 20, 'code' => 'CUP', 'image' => null,
    'name' => '머그컵', 'label' => '', 'kind' => 'base', 'error' => '', 'quantity' => 1, 'available' => 5, 'total' => 25000];
$items[] = ['key' => '20:202', 'product_id' => 20, 'code' => 'CUP', 'image' => null,
    'name' => '머그컵', 'label' => '메시지 카드', 'kind' => 'extra', 'error' => '', 'quantity' => 3, 'available' => 5, 'total' => 0];
echo $view->fetch('cart', [
    'url' => '/cms/shop', 'page' => 'cart', 'admin' => true, 'admin_url' => '/cms/admin/shop', 'menu' => [],
    'type_labels' => Settings::TYPE_LABELS,
    'settings' => ['main' => array_fill_keys(Settings::TYPES, ['use' => true])],
    'cart_count' => 3, 'quote' => ['items' => $items, 'errors' => [], 'subtotal' => 107000, 'shipping_fee' => 0, 'cod_fee' => 0, 'total' => 107000],
]);
