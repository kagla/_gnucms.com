<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/modules/youngcart/autoload.php';

use GnuCms\Modules\YoungCart\HomeBanner;
use GnuCms\Modules\YoungCart\Settings;
use GnuCms\Tests\Support\AdminViewFixture;
use GnuCms\Tests\YoungCart\HomeBannerTest;
use GnuCms\Tests\YoungCart\ImagesTest;

$view = AdminViewFixture::view('/cms')->forExtension('youngcart', dirname(__DIR__, 2) . '/modules/youngcart/templates');
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
