<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Options;
use GnuCms\Shop\ProductInfo;
use GnuCms\Tests\Support\AdminViewFixture;

$base = $argv[2] ?? '/cms';
$view = AdminViewFixture::view($base)->forShop();
if (($argv[1] ?? '') === 'combine') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    try {
        $draft = Options::draft($input, Options::rows($input['options'] ?? []));
        echo json_encode(['status' => 200, 'body' => [
            'html' => $view->fetch('admin/_option_combinations', ['options_rows' => $draft['rows']]),
            'message' => count($draft['rows']) . '개 조합을 만들었습니다. 가격·재고를 확인한 뒤 상품 저장을 눌러 주세요.',
        ]], JSON_THROW_ON_ERROR);
    } catch (DomainError $e) {
        echo json_encode(['status' => $e->status(), 'body' => ['error' => ['message' => array_values($e->details())[0] ?? $e->getMessage()]]], JSON_THROW_ON_ERROR);
    }
    exit;
}

$editing = ($argv[1] ?? '') === 'edit';
echo $view->fetch('admin/product_form', [
    'id' => $editing ? 10 : null, 'product' => $editing ? ['code' => 'BROWSER1'] : null,
    'page' => $editing ? 'products/edit' : 'products/new', 'admin_url' => $base . '/admin/shop', 'public_url' => $base . '/shop',
    'public_view_url' => $editing ? $base . '/shop/item?id=BROWSER1' : '',
    'values' => ['code' => 'BROWSER1', 'name' => '', 'price' => '', 'stock' => '4', 'stock_alert' => '2', 'version' => '0', 'image_key' => 'tmp/' . str_repeat('a', 32)],
    'categories' => [1 => ['text' => '의류', 'title' => '의류']], 'images' => [], 'options_rows' => [], 'extras_rows' => [], 'relations' => [],
    'errors' => [], 'notice' => '', 'info_groups' => ProductInfo::GROUPS,
    'apply_fields' => ['active' => '판매가능', 'no_coupon' => '쿠폰제외', 'point' => '포인트', 'tax_free' => '과세', 'shipping' => '배송비',
        'buy' => '구매수량', 'html' => '상세 위·아래 HTML', 'seller_email' => '판매자 메일', 'phone_inquiry' => '전화문의'],
]);
