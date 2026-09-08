<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/modules/youngcart/autoload.php';

use GnuCms\Modules\YoungCart\Catalog\Options;
use GnuCms\Tests\Support\AdminViewFixture;

$count = (int) ($argv[1] ?? 3);
$rows = [
    [101, '빨강', 'S', '면', 500, 3, 1],
    [102, '빨강', 'S', '실크', 1500, 0, 1],
    [103, '빨강', 'M', '면', 0, 4, 0],
    [104, '파랑', 'L', '실크', -1000, 2, 1],
    [105, '파랑', 'L', '면', 0, 4, 1],
    [106, '품절색', 'L', '면', 0, 0, 1],
    [107, '0', 'S', '면', 0, 1, 1],
    [108, '__proto__', 'S', '면', 0, 1, 1],
];
if ($count < 3) $rows = $count === 0 ? [] : [[101, '0', $count === 2 ? 'S' : '', '', 500, 3, 1]];
$options = [
    'select_groups' => array_slice(['색상', '사이즈', '재질'], 0, $count),
    'select' => array_map(static fn (array $row): array => array_combine(['id', 'value1', 'value2', 'value3', 'price', 'stock', 'active'], $row), $rows),
    'extra_groups' => ['포장'],
    'extra' => [['id' => 201, 'value1' => '포장', 'value2' => '선물 포장', 'value3' => '', 'price' => 2000, 'stock' => 4, 'active' => 1]],
];
$product = ['id' => 10, 'price' => 10000, 'stock' => 10, 'buy_min' => 1, 'buy_max' => 10, 'options' => $options];
$view = AdminViewFixture::view('/cms')->forExtension('youngcart', dirname(__DIR__, 2) . '/modules/youngcart/templates');
?>
<!doctype html>
<html lang="ko" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/vendor/daisyui/daisyui.css"><link rel="stylesheet" href="/themes/default/theme.css"><link rel="stylesheet" href="/themes/default/youngcart.css">
<title>단계별 옵션 브라우저 검증</title></head><body>
<main class="yc-shop" style="max-width:600px;margin:16px auto;padding:16px">
<?= $view->fetch('_options', ['product' => $product, 'options_json' => Options::pageJson($product, $options), 'url' => '/cms/shop', 'csrf_token' => 'browser-test-csrf']) ?>
</main><script src="/themes/default/youngcart.js"></script></body></html>
