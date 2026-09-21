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
    'type_labels' => Settings::TYPE_LABELS, 'types' => Settings::TYPE_LABELS, 'settings' => $settings,
    'categories' => [1 => ['label' => '의류', 'text' => '의류', 'title' => '슬러그 의류 · 번호 1'],
        2 => ['label' => '의류 > 셔츠', 'text' => '의류 > 셔츠', 'title' => '슬러그 셔츠 · 번호 2'],
        3 => ['label' => '가을 기획전', 'text' => '가을 기획전', 'title' => '슬러그 가을-기획전 · 번호 3']]];
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
    $values += ['shipping_fee' => '0', 'shipping_free_minimum' => '0', 'order_notice' => $settings['order_notice'],
        'main_best_source' => 'category', 'main_best_source_category_id' => '3',
        'main_categories' => [['id' => '3', 'columns' => '4', 'rows' => '1'], ['id' => '2', 'columns' => '3', 'rows' => '2']]];
    echo $view->fetch('admin/settings', ['page' => 'settings', 'values' => $values, 'errors' => [], 'notice' => '', 'banner_image_url' => '',
        'banner_modes' => HomeBanner::MODES, 'banner_products' => [['id' => 1, 'code' => 'P1', 'name' => '가을 상품']]] + $globals);
}
