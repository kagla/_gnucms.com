<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Options;
use GnuCms\Shop\Catalog\Products;
use GnuCms\Shop\Input;
use GnuCms\Shop\ProductInfo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class ProductFormController extends AdminBase
{
    private const COOKIES = ['category_id' => 'yc_last_category', 'maker' => 'yc_last_maker', 'origin' => 'yc_last_origin'];

    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, $page);
        $input = $data['input'];
        $id = $page === 'products/edit' ? Input::id($input['id'] ?? '') : null;
        $product = $id === null ? null : $this->service->products->get($id);
        if ($request->getMethod() !== 'POST') {
            if (($input['saved'] ?? '') === '1') $data['notice'] = '상품을 저장했습니다.';
            return $this->form($request, $response, $data, $product === null ? $this->defaults($request) : $this->values($product), $product);
        }
        $action = $input['action'] ?? '';
        try {
            if ($action === 'combine') {
                $draft = Options::draft($input, array_merge($product['options']['select'] ?? [], Options::rows($input['options'] ?? [])));
                $values = $input;
                for ($i = 1; $i <= Options::MAX_GROUPS; $i++) $values['option_group'][$i] = $draft['groups'][$i - 1] ?? '';
                $values['options'] = $draft['rows'];
                $data['notice'] = count($draft['rows']) . '개 조합을 만들었습니다. 가격·재고를 확인한 뒤 상품 저장을 눌러 주세요.';
                return $this->form($request, $response, $data, $values, $product);
            }
            if ($action !== 'save') throw DomainError::validation(['action' => '작업을 확인해 주세요.']);
            $files = $request->getUploadedFiles()['images'] ?? [];
            $files = is_array($files) ? array_values($files) : [$files];
            $saved = $this->service->products->save($input, array_filter($files, static fn ($f) => $f instanceof UploadedFileInterface), $id, $data['actor']);
            $response = $this->redirect($response, $data['admin_url'] . '/products/edit?id=' . $saved . '&saved=1');
            foreach (self::COOKIES as $field => $cookie) {
                $value = is_string($input[$field] ?? null) ? mb_substr($input[$field], 0, 100, 'UTF-8') : '';
                if ($value !== '') $response = $response->withAddedHeader('Set-Cookie', $cookie . '=' . rawurlencode($value) . '; Max-Age=2678400; Path=' . ($data['base'] === '' ? '/' : $data['base']) . '; SameSite=Lax; HttpOnly');
            }
            return $response;
        } catch (DomainError $e) {
            if ($e->status() === 404) throw $e;
            $data['errors'] = $e->details() ?: [$e->getMessage()];
            return $this->form($request, $response->withStatus($e->status()), $data, $input, $product);
        }
    }

    private function defaults(ServerRequestInterface $request): array
    {
        $cookies = $request->getCookieParams();
        $values = ['code' => (string) time(), 'name' => '', 'category_id' => '', 'category2_id' => '', 'category3_id' => '', 'maker' => '', 'origin' => '', 'brand' => '', 'model' => '',
            'summary' => '', 'description' => '', 'list_price' => '0', 'price' => '', 'point_type' => '0', 'point' => '0', 'supply_point' => '0', 'tax_free' => '0', 'seller_email' => '',
            'active' => '1', 'no_coupon' => '0', 'sold_out' => '0', 'stock' => '0', 'stock_alert' => '0', 'restock_notify' => '0', 'buy_min' => '0', 'buy_max' => '0', 'phone_inquiry' => '0',
            'shipping_type' => '0', 'shipping_method' => '0', 'shipping_fee' => '0', 'shipping_free_minimum' => '0', 'shipping_per_qty' => '0', 'head_html' => '', 'tail_html' => '',
            'info_group' => '', 'info' => [], 'memo' => '', 'sort_order' => '0', 'option_group' => [1 => '', 2 => '', 3 => ''], 'option_values' => [1 => '', 2 => '', 3 => ''],
            'options' => [], 'extras' => [], 'relations' => '', 'extra_label' => [], 'extra_value' => [], 'version' => '0'];
        foreach (Products::TYPES as $type) $values[$type] = '0';
        foreach (self::COOKIES as $field => $cookie) if (is_string($cookies[$cookie] ?? null)) $values[$field] = mb_substr($cookies[$cookie], 0, 100, 'UTF-8');
        return $values;
    }

    /** 저장된 상품을 폼 입력 이름으로 편다. */
    private function values(array $product): array
    {
        $values = [];
        foreach ($product as $key => $value) if (is_scalar($value) || $value === null) $values[$key] = (string) $value;
        $values['category_id'] = (string) ($product['categories'][1]['id'] ?? '');
        $values['category2_id'] = (string) ($product['categories'][2]['id'] ?? '');
        $values['category3_id'] = (string) ($product['categories'][3]['id'] ?? '');
        $values['info'] = $product['info'];
        $values['option_group'] = [1 => $product['options']['select_groups'][0] ?? '', 2 => $product['options']['select_groups'][1] ?? '', 3 => $product['options']['select_groups'][2] ?? ''];
        $values['option_values'] = [1 => '', 2 => '', 3 => ''];
        // 저장된 조합의 순서대로 값을 복원한다. 품절·미사용 옵션과 문자열 "0"도 편집 대상이다.
        for ($i = 1; $i <= Options::MAX_GROUPS; $i++) {
            $items = array_column($product['options']['select'], 'value' . $i);
            $values['option_values'][$i] = implode(',', array_unique(array_filter($items, static fn (string $value): bool => $value !== '')));
        }
        $values['options'] = $product['options']['select'];
        $values['extras'] = $product['options']['extra'];
        $values['relations'] = implode(',', array_column($product['relations'], 'id'));
        foreach ($product['extra'] as $index => $field) { $values['extra_label'][$index + 1] = $field['label']; $values['extra_value'][$index + 1] = $field['value']; }
        return $values;
    }

    private function form(ServerRequestInterface $request, ResponseInterface $response, array $data, array $values, ?array $product): ResponseInterface
    {
        $data['id'] = $product === null ? null : (int) $product['id'];
        $data['product'] = $product;
        $data['values'] = $values;
        $data['options_rows'] = Options::rows($values['options'] ?? []);
        $data['extras_rows'] = Options::rows($values['extras'] ?? []);
        $data['images'] = $product['images'] ?? [];
        $data['categories'] = $this->service->categories->options();
        $data['info_groups'] = ProductInfo::GROUPS;
        $data['types'] = ['is_hit' => '히트', 'is_recommended' => '추천', 'is_new' => '최신', 'is_popular' => '인기', 'is_discount' => '할인'];
        $data['apply_fields'] = ['types' => '유형', 'active' => '판매가능', 'no_coupon' => '쿠폰제외', 'point' => '포인트', 'tax_free' => '과세', 'shipping' => '배송비', 'buy' => '구매수량', 'html' => '상세 위·아래 HTML', 'seller_email' => '판매자 메일', 'phone_inquiry' => '전화문의'];
        $relationIds = array_filter(array_map('intval', explode(',', (string) ($values['relations'] ?? ''))));
        $data['relations'] = [];
        foreach ($relationIds as $relatedId) { $row = $this->service->products->find($relatedId); if ($row !== null) $data['relations'][] = ['id' => (int) $row['id'], 'code' => $row['code'], 'name' => $row['name']]; }
        if (!preg_match('/^[a-f0-9]{32}$/D', (string) ($values['image_key'] ?? ''))) $data['values']['image_key'] = bin2hex(random_bytes(16));
        return $this->render($request, $response, 'product_form', $data);
    }
}
