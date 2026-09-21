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
        if ($page === 'products/search') {
            return $this->searchJson($response, $data['input']);
        }
        $input = $data['input'];
        $products = $this->service->products;
        $post = $request->getMethod() === 'POST';
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
        try {
            if ($post) {
                switch ($page) {
                    case 'products':
                        $action = $input['action'] ?? '';
                        if ($action === 'bulk') $products->bulk($rows, $data['actor']);
                        elseif ($action === 'delete') $products->bulkDelete(is_array($input['ids'] ?? null) ? $input['ids'] : []);
                        else throw DomainError::validation(['action' => '작업을 확인해 주세요.']);
                        return $this->redirect($response, $data['admin_url'] . '/products?saved=1');
                    case 'products/copy':
                        $id = $products->copy(Input::id($input['id'] ?? ''), is_string($input['code'] ?? null) ? $input['code'] : '', $data['actor']);
                        return $this->redirect($response, $data['admin_url'] . '/products/edit?id=' . $id . '&saved=1');
                    case 'products/types':
                        $products->setTypes($rows);
                        return $this->redirect($response, $data['admin_url'] . '/products/types?saved=1');
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
            $page = $page === 'products/copy' ? 'products' : $page;
            $data['page'] = $page;
        }
        if (($input['saved'] ?? '') === '1') $data['notice'] = '저장했습니다.';
        $q = is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 100, 'UTF-8') : '';
        switch ($page) {
            case 'products':
            case 'products/types':
                $filters = ['q' => $q, 'field' => is_string($input['field'] ?? null) ? $input['field'] : 'name', 'ca' => is_string($input['ca'] ?? null) ? $input['ca'] : '',
                    'sort' => is_string($input['sort'] ?? null) ? $input['sort'] : '', 'dir' => is_string($input['dir'] ?? null) ? $input['dir'] : 'desc'];
                $data['filters'] = $filters;
                $data['list'] = $products->list($filters, $this->page($input['page'] ?? ''));
                $data['categories'] = $this->service->categories->options();
                $data['fields'] = Products::SEARCH_FIELDS;
                $data['sorts'] = Products::SORTS;
                $data['types'] = Products::TYPES;
                return $this->render($request, $response, $page === 'products' ? 'products' : 'product_types', $data);
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

    private function searchJson(ResponseInterface $response, array $input): ResponseInterface
    {
        $q = is_string($input['q'] ?? null) ? mb_substr(trim($input['q']), 0, 100, 'UTF-8') : '';
        $ca = is_string($input['ca'] ?? null) ? $input['ca'] : '';
        $items = $this->service->products->search($q, $ca, Input::optionalId($input['exclude'] ?? ''));
        $response->getBody()->write((string) json_encode(['items' => array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'price' => (int) $r['price'], 'category_name' => $r['category_name'] ?? ''], $items)], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }
}
