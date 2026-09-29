<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Products;
use GnuCms\Shop\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProductController extends AdminBase
{
    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, $page);
        $input = $data['input'];
        $products = $this->service->products;
        $post = $request->getMethod() === 'POST';
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
        try {
            if ($post) {
                switch ($page) {
                    case 'products':
                        $action = $input['action'] ?? '';
                        if ($action === 'bulk') $products->bulk($rows);
                        elseif ($action === 'delete') $products->bulkDelete(is_array($input['ids'] ?? null) ? $input['ids'] : []);
                        elseif ($action === 'categorize' || $action === 'uncategorize') {
                            $categoryId = Input::optionalId($input['category'] ?? '') ?? throw DomainError::validation(['category' => '분류를 고르세요.']);
                            $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
                            if ($ids === []) throw DomainError::validation(['ids' => '상품을 선택하세요.']);
                            $result = $action === 'categorize' ? $products->addToCategory($ids, $categoryId) : $products->removeFromCategory($ids, $categoryId);
                            return $this->redirect($response, $data['admin_url'] . '/products?' . http_build_query(['op' => $action === 'categorize' ? 'add' : 'remove'] + $result));
                        }
                        else throw DomainError::validation(['action' => '작업을 확인해 주세요.']);
                        return $this->redirect($response, $data['admin_url'] . '/products?saved=1');
                    case 'products/settings-copy':
                        if (($input['action'] ?? '') !== 'copy-settings') throw DomainError::validation(['action' => '작업을 확인해 주세요.']);
                        $sourceId = Input::optionalId($input['source_id'] ?? '') ?? throw DomainError::validation(['source' => '설정을 가져올 상품을 확인할 수 없습니다.']);
                        $groups = is_array($input['copy_fields'] ?? null) ? $input['copy_fields'] : [];
                        $scope = is_string($input['scope'] ?? null) ? $input['scope'] : '';
                        $filters = ['q' => is_string($input['target_q'] ?? null) ? $input['target_q'] : '',
                            'field' => is_string($input['target_field'] ?? null) ? $input['target_field'] : 'name',
                            'ca' => is_string($input['target_ca'] ?? null) ? $input['target_ca'] : ''];
                        $changed = $products->copySettingsByScope($sourceId, $groups, $scope, $filters);
                        return $this->redirect($response, $data['admin_url'] . '/products/settings-copy?' . http_build_query([
                            'source' => $sourceId, 'target_q' => $filters['q'], 'target_field' => $filters['field'],
                            'target_ca' => $filters['ca'], 'scope' => $scope, 'copied' => '1', 'changed' => $changed,
                        ]));
                    case 'products/stock':
                        $products->updateStock($rows, $data['actor']);
                        return $this->redirect($response, $data['admin_url'] . '/products/stock?saved=1');
                    case 'products/option-stock':
                        $this->service->options->updateStock($rows, $data['actor']);
                        return $this->redirect($response, $data['admin_url'] . '/products/option-stock?saved=1');
                }
                throw DomainError::notFound('페이지를 찾을 수 없습니다.');
            }
        } catch (DomainError $e) {
            if ($e->status() === 404) throw $e;
            $response = $response->withStatus($e->status());
            $data['errors'] = $e->details() ?: [$e->getMessage()];
            $data['page'] = $page;
        }
        if (($input['saved'] ?? '') === '1') $data['notice'] = '저장했습니다.';
        if (($input['choose_copy_source'] ?? '') === '1') $data['notice'] = '상품 수정 화면에서 설정 복사를 눌러 주세요.';
        if (in_array($input['op'] ?? '', ['add', 'remove'], true)) {
            $changed = (int) ($input['changed'] ?? 0); $skipped = (int) ($input['skipped'] ?? 0);
            $data['notice'] = $changed . '개 상품을 분류에' . ($input['op'] === 'add' ? ' 넣었습니다.' : '서 뺐습니다.')
                . ($skipped > 0 ? ' ' . $skipped . '개는 ' . ($input['op'] === 'add' ? '이미 있어' : '대표 분류이거나 없어') . ' 건너뛰었습니다.' : '');
        }
        $q = is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 100, 'UTF-8') : '';
        switch ($page) {
            case 'products':
                $filters = ['q' => $q, 'field' => is_string($input['field'] ?? null) ? $input['field'] : 'name', 'ca' => is_string($input['ca'] ?? null) ? $input['ca'] : '',
                    'sort' => is_string($input['sort'] ?? null) ? $input['sort'] : '', 'dir' => is_string($input['dir'] ?? null) ? $input['dir'] : 'desc'];
                $data['filters'] = $filters;
                $data['list'] = $products->list($filters, $this->page($input['page'] ?? ''));
                $data['categories'] = $this->service->categories->options();
                $data['fields'] = Products::SEARCH_FIELDS;
                $data['sorts'] = Products::SORTS;
                return $this->render($request, $response, 'products', $data);
            case 'products/settings-copy':
                $sourceId = Input::filterId($input['source'] ?? $input['source_id'] ?? '');
                if ($sourceId === null) return $this->redirect($response, $data['admin_url'] . '/products?choose_copy_source=1');
                $source = $products->get($sourceId);
                $targetFilters = ['q' => is_string($input['target_q'] ?? null) ? mb_substr(trim($input['target_q']), 0, 100, 'UTF-8') : '',
                    'field' => is_string($input['target_field'] ?? null) ? $input['target_field'] : 'name',
                    'ca' => is_string($input['target_ca'] ?? null) ? $input['target_ca'] : '', 'sort' => '', 'dir' => 'desc',
                    'exclude_id' => (string) $sourceId];
                $hasTargetFilter = $targetFilters['q'] !== '' || Input::filterId($targetFilters['ca']) !== null;
                $data['source'] = $source;
                $data['target_filters'] = $targetFilters;
                $data['target_list'] = $hasTargetFilter ? $products->list($targetFilters, $this->page($input['target_page'] ?? ''))
                    : ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
                $data['categories'] = $this->service->categories->options();
                $data['fields'] = Products::SEARCH_FIELDS;
                $data['copy_field_labels'] = ['active' => '판매가능', 'phone_inquiry' => '전화문의',
                    'tax_free' => '면세', 'buy' => '구매수량 제한', 'shipping' => '배송비 설정'];
                $data['target_counts'] = [
                    'search' => $products->copyTargetCount($sourceId, 'search', $targetFilters),
                    'category' => $products->copyTargetCount($sourceId, 'category', $targetFilters),
                    'all' => $products->copyTargetCount($sourceId, 'all', $targetFilters),
                ];
                $scope = is_string($input['scope'] ?? null) && in_array($input['scope'], ['search', 'category', 'all'], true)
                    ? $input['scope'] : ($hasTargetFilter ? 'search' : 'category');
                if ($data['target_counts'][$scope] < 1) {
                    foreach (['search', 'category', 'all'] as $candidate) if ($data['target_counts'][$candidate] > 0) { $scope = $candidate; break; }
                }
                $data['copy_values'] = ['fields' => is_array($input['copy_fields'] ?? null) ? $input['copy_fields'] : [], 'scope' => $scope];
                if (($input['copied'] ?? '') === '1') $data['notice'] = (int) ($input['changed'] ?? 0) . '개 상품에 설정을 복사했습니다.';
                return $this->render($request, $response, 'product_settings_copy', $data);
            case 'products/stock':
                $data['q'] = $q;
                $data['list'] = $products->stockList($q, $this->page($input['page'] ?? ''), 30);
                return $this->render($request, $response, 'product_stock', $data);
            case 'products/option-stock':
                $data['q'] = $q;
                $data['list'] = $this->service->options->stockList($q, $this->page($input['page'] ?? ''), 30);
                return $this->render($request, $response, 'option_stock', $data);
        }
        throw DomainError::notFound('페이지를 찾을 수 없습니다.');
    }
}
