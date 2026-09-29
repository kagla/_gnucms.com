<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Options;
use GnuCms\Shop\Input;
use GnuCms\Shop\ProductInfo;
use GnuCms\Support\Json;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class ProductFormController extends AdminBase
{
    private const COOKIES = ['category_id' => 'yc_last_category'];

    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, $page);
        $input = $data['input'];
        $id = $page === 'products/edit' ? Input::id($input['id'] ?? '') : null;
        $product = $id === null ? null : $this->service->products->get($id);
        $copyRaw = $id === null ? ($input[$request->getMethod() === 'POST' ? 'copy_source_id' : 'copy'] ?? '') : '';
        $copySourceId = $copyRaw === '' ? null : Input::id($copyRaw, 'copy_source_id');
        $copySource = $copySourceId === null ? null : $this->service->products->get($copySourceId);
        if ($request->getMethod() !== 'POST') {
            if (($input['saved'] ?? '') === '1') $data['notice'] = '상품을 저장했습니다.';
            if ($copySource !== null) {
                $values = $this->values($copySource);
                $values['code'] = is_string($input['code'] ?? null) ? mb_substr($input['code'], 0, 20, 'UTF-8') : (string) time();
                $values['copy_source_id'] = (string) $copySourceId;
                $values['version'] = '0';
                return $this->form($request, $response, $data, $values, null, $copySource);
            }
            return $this->form($request, $response, $data, $product === null ? $this->defaults($request) : $this->values($product), $product);
        }
        $action = $input['action'] ?? '';
        $ajaxCombine = $action === 'combine' && stripos($request->getHeaderLine('Accept'), 'application/json') !== false;
        try {
            if ($action === 'combine') {
                $draft = Options::draft($input, array_merge($product['options']['select'] ?? [], Options::rows($input['options'] ?? [])));
                $data['notice'] = count($draft['rows']) . '개 조합을 만들었습니다. 가격·재고를 확인한 뒤 상품 저장을 눌러 주세요.';
                if ($ajaxCombine) {
                    $response->getBody()->write(Json::encode([
                        'html' => View::forShop($request)->fetch('admin/_option_combinations', ['options_rows' => $draft['rows']]),
                        'message' => $data['notice'],
                    ]));
                    return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
                }
                $values = $input;
                for ($i = 1; $i <= Options::MAX_GROUPS; $i++) $values['option_group'][$i] = $draft['groups'][$i - 1] ?? '';
                $values['options'] = $draft['rows'];
                return $this->form($request, $response, $data, $values, $product, $copySource);
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
            if ($ajaxCombine || $e->status() === 404) throw $e;
            $data['errors'] = $e->details() ?: [$e->getMessage()];
            return $this->form($request, $response->withStatus($e->status()), $data, $input, $product, $copySource);
        }
    }

    private function defaults(ServerRequestInterface $request): array
    {
        $cookies = $request->getCookieParams();
        $values = ['code' => (string) time(), 'name' => '', 'category_id' => '', 'extra_category_ids' => [],
            'summary' => '', 'description' => '', 'list_price' => '0', 'price' => '', 'tax_free' => '0',
            'active' => '1', 'sold_out' => '0', 'stock' => '0', 'stock_alert' => '0', 'buy_min' => '0', 'buy_max' => '0', 'phone_inquiry' => '0',
            'shipping_type' => '0', 'shipping_method' => '0', 'shipping_fee' => '0', 'shipping_free_minimum' => '0', 'shipping_per_qty' => '0',
            'info_group' => '', 'info' => [], 'memo' => '', 'sort_order' => '0', 'option_group' => [1 => '', 2 => '', 3 => ''], 'option_values' => [1 => '', 2 => '', 3 => ''],
            'options' => [], 'extras' => [], 'extra_label' => [], 'extra_value' => [], 'version' => '0'];
        foreach (self::COOKIES as $field => $cookie) if (is_string($cookies[$cookie] ?? null)) $values[$field] = mb_substr($cookies[$cookie], 0, 100, 'UTF-8');
        // 분류 화면의 "이 분류에 상품 등록"이 ?category=<id> 로 온다. 있는 분류일 때만 대표 분류로 미리 고른다.
        $category = Input::filterId($request->getQueryParams()['category'] ?? '');
        if ($category !== null && $this->service->categories->find($category) !== null) $values['category_id'] = (string) $category;
        return $values;
    }

    /** 저장된 상품을 폼 입력 이름으로 편다. */
    private function values(array $product): array
    {
        $values = [];
        foreach ($product as $key => $value) if (is_scalar($value) || $value === null) $values[$key] = (string) $value;
        $values['category_id'] = (string) ($product['categories'][1]['id'] ?? '');
        $values['extra_category_ids'] = array_values(array_map(static fn (array $c): string => (string) $c['id'], array_filter($product['categories'], static fn (int $slot): bool => $slot >= 2, ARRAY_FILTER_USE_KEY)));
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
        foreach ($product['extra'] as $index => $field) { $values['extra_label'][$index + 1] = $field['label']; $values['extra_value'][$index + 1] = $field['value']; }
        return $values;
    }

    private function form(ServerRequestInterface $request, ResponseInterface $response, array $data, array $values, ?array $product, ?array $copySource = null): ResponseInterface
    {
        $data['id'] = $product === null ? null : (int) $product['id'];
        $data['product'] = $product;
        $data['copy_source'] = $copySource;
        $data['values'] = $values;
        $data['options_rows'] = Options::rows($values['options'] ?? []);
        $data['extras_rows'] = Options::rows($values['extras'] ?? []);
        $data['images'] = $product['images'] ?? $copySource['images'] ?? [];
        $data['image_owner_id'] = (int) ($product['id'] ?? $copySource['id'] ?? 0);
        $data['categories'] = $this->service->categories->optionDetails();
        $data['info_groups'] = ProductInfo::GROUPS;
        $data['shop_shipping'] = $this->service->settings->all()['shipping'];
        // 편집기 사진 폴더 키. 저장된 상품은 제 폴더(products/<id>), 새 상품은 저장 때 옮길 임시 폴더다(입력 오류로 다시 그릴 때는 폼이 보낸 것을 지킨다).
        // 저장된 상품의 공개 주소 — 도구 막대의 "쇼핑몰 보기"가 여기로 간다.
        $data['public_view_url'] = $product === null ? '' : $data['public_url'] . '/item?id=' . rawurlencode((string) $product['code']);
        $data['values']['image_key'] = $product !== null ? 'products/' . (int) $product['id']
            : (is_string($values['image_key'] ?? null) && preg_match('/^tmp\/[a-f0-9]{32}$/D', $values['image_key']) ? $values['image_key'] : 'tmp/' . bin2hex(random_bytes(16)));
        return $this->render($request, $response, 'product_form', $data);
    }
}
