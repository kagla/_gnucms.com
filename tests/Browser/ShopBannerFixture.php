<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnuCms\Shop\HomeBanner;
use GnuCms\Shop\Settings;
use GnuCms\Tests\Shop\HomeBannerTest;
use GnuCms\Tests\Shop\ImagesTest;
use GnuCms\Tests\Support\AdminViewFixture;

$view = AdminViewFixture::view('/cms')->forShop();
$settings = Settings::defaults();
$globals = ['url' => '/cms/shop', 'public_url' => '/cms/shop', 'admin_url' => '/cms/admin/shop', 'admin' => true, 'menu' => [], 'cart_count' => 0,
    'type_labels' => Settings::TYPE_LABELS, 'types' => Settings::TYPE_LABELS, 'settings' => $settings];
if (($argv[1] ?? 'admin') === 'home') {
    $settings['banner']['title'] = "새로운 계절의 특별한 발견\n" . str_repeat('LongTitle', 6);
    $settings['banner']['use'] = ($argv[2] ?? '') !== 'hidden';
    $settings['main']['new']['use'] = false;
    $settings['main']['discount']['use'] = false;
    $picture = 'data:image/png;base64,' . base64_encode((string) ImagesTest::png(80, 80)->getStream());
    echo $view->fetch('index', ['page' => 'index', 'settings' => $settings, 'blocks' => [],
        'banner' => ['settings' => $settings['banner'], 'image' => $picture, 'caption' => str_repeat('가을 상품 ', 20), 'alt' => '가을 상품', 'image_url' => '/cms/shop/search', 'button_url' => '/cms/shop/search']] + $globals);
} else {
    $values = HomeBannerTest::form();
    foreach (HomeBanner::defaults() as $key => $value) $values['banner_' . $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    $values += ['shipping_fee' => '0', 'shipping_free_minimum' => '0', 'order_notice' => $settings['order_notice']];
    echo $view->fetch('admin/settings', ['page' => 'settings', 'values' => $values, 'errors' => [], 'notice' => '', 'banner_image_url' => '',
        'banner_modes' => HomeBanner::MODES, 'banner_products' => [['id' => 1, 'code' => 'P1', 'name' => '가을 상품']]] + $globals);
}
