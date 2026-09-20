<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CategoryController extends AdminBase
{
    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, $page);
        $input = $data['input'];
        $categories = $this->service->categories;
        try {
            if ($page === 'categories') {
                if ($request->getMethod() === 'POST') {
                    $action = $input['action'] ?? '';
                    if ($action === 'bulk') $categories->bulk(is_array($input['rows'] ?? null) ? $input['rows'] : []);
                    elseif ($action === 'delete') $categories->delete(Input::id($input['id'] ?? ''));
                    else throw DomainError::validation(['action' => '작업을 확인해 주세요.']);
                    return $this->redirect($response, $data['admin_url'] . '/categories?saved=1');
                }
                if (($input['saved'] ?? '') === '1') $data['notice'] = '분류를 저장했습니다.';
                return $this->list($request, $response, $data);
            }
            if ($page === 'categories/new' || $page === 'categories/edit') {
                $id = $page === 'categories/edit' ? Input::id($input['id'] ?? '') : null;
                if ($request->getMethod() === 'POST') {
                    $saved = $categories->save($input, $id);
                    return $this->redirect($response, $data['admin_url'] . '/categories/edit?id=' . $saved . '&saved=1');
                }
                $values = $id === null ? $this->defaults($input) : $categories->get($id);
                if (($input['saved'] ?? '') === '1') $data['notice'] = '분류를 저장했습니다.';
                return $this->form($request, $response, $data, $values, $id);
            }
        } catch (DomainError $e) {
            if ($e->status() === 404) throw $e;
            $response = $response->withStatus($e->status());
            $data['errors'] = $e->details() ?: [$e->getMessage()];
            if ($page === 'categories') return $this->list($request, $response, $data);
            $id = $page === 'categories/edit' ? Input::id($input['id'] ?? '') : null;
            return $this->form($request, $response, $data, $input + ($id === null ? [] : ['code' => $categories->get($id)['code']]), $id);
        }
        throw DomainError::notFound('페이지를 찾을 수 없습니다.');
    }

    private function defaults(array $input): array
    {
        $parent = is_string($input['parent'] ?? null) && preg_match('/^[0-9a-z]{2,10}$/D', $input['parent']) ? $input['parent'] : null;
        $block = $this->service->settings->block('category');
        return ['code' => (string) $this->service->categories->suggestCode($parent), 'name' => '', 'sort_order' => '0', 'active' => '1', 'no_coupon' => '0',
            'head_html' => '', 'tail_html' => '', 'list_columns' => (string) $block['columns'], 'list_rows' => (string) $block['rows'],
            'image_width' => (string) $block['image_width'], 'image_height' => (string) $block['image_height'], 'extra' => []];
    }

    private function list(ServerRequestInterface $request, ResponseInterface $response, array $data): ResponseInterface
    {
        $data['tree'] = $this->service->categories->tree();
        return $this->render($request, $response, 'categories', $data);
    }

    private function form(ServerRequestInterface $request, ResponseInterface $response, array $data, array $values, ?int $id): ResponseInterface
    {
        $data['values'] = $values;
        $data['id'] = $id;
        $data['extra'] = [];
        for ($i = 1; $i <= 10; $i++) {
            $data['extra'][$i] = ['label' => (string) ($values['extra_label'][$i] ?? $values['extra'][$i - 1]['label'] ?? ''), 'value' => (string) ($values['extra_value'][$i] ?? $values['extra'][$i - 1]['value'] ?? '')];
        }
        return $this->render($request, $response, 'category_form', $data);
    }
}
