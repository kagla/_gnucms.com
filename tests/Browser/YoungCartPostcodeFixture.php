<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnuCms\Tests\Support\AdminViewFixture;

$view = AdminViewFixture::view('/cms')->forExtension('youngcart', dirname(__DIR__, 2) . '/modules/youngcart/templates');
?>
<!doctype html><html lang="ko" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/vendor/daisyui/daisyui.css"><link rel="stylesheet" href="/themes/default/theme.css"><link rel="stylesheet" href="/themes/default/youngcart.css">
<title>주문서 주소 검색 검증</title></head><body>
<main class="yc-shop" style="max-width:600px;margin:16px auto;padding:16px"><form method="post" action="/cms/shop/checkout" class="yc-form-stack">
<?= $view->fetch('_postcode', ['input' => ['postcode' => '12345'], 'errors' => []]) ?>
<label class="yc-field">주소<input class="input" id="yc-address" name="address" value="이전 주소" required></label>
<label class="yc-field">상세주소<input class="input" id="yc-address_detail" name="address_detail" value="101호"></label>
<label class="yc-field">받는 분<input class="input" id="yc-recipient" name="recipient" value="받는사람"></label>
<button type="submit">검증용 제출</button>
</form></main><script src="/themes/default/youngcart-postcode.js"></script></body></html>
