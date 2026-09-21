<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnuCms\Shop\Settings;
use GnuCms\Tests\Support\AdminViewFixture;

$view = AdminViewFixture::view('/cms')->forShop();
$items = [];
foreach (['화이트 / S', '화이트 / M'] as $index => $label) {
    $items[] = ['key' => '10:' . (101 + $index), 'product_id' => 10, 'code' => 'SHIRT', 'image' => null,
        'name' => '옵션 셔츠', 'label' => $label, 'kind' => 'select', 'error' => '', 'quantity' => 1, 'total' => 39000];
}
echo $view->fetch('cart', [
    'url' => '/cms/shop', 'page' => 'cart', 'admin' => true, 'admin_url' => '/cms/admin/shop', 'menu' => [],
    'type_labels' => Settings::TYPE_LABELS,
    'settings' => ['main' => array_fill_keys(Settings::TYPES, ['use' => true])],
    'cart_count' => 2, 'quote' => ['items' => $items, 'errors' => [], 'subtotal' => 78000, 'shipping_fee' => 0, 'cod_fee' => 0, 'total' => 78000],
]);
