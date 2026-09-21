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
            return $this->form($request, $response, $data, $input, $id);
        }
        throw DomainError::notFound('페이지를 찾을 수 없습니다.');
    }

    /** 새 분류의 첫 값. 목록의 "하위 추가" 가 ?parent=<id> 로 상위 분류를 미리 고른다. */
    private function defaults(array $input): array
    {
        $parent = Input::filterId($input['parent'] ?? '');
        $block = $this->service->settings->block('category');
        return ['name' => '', 'slug' => '', 'parent_id' => $parent === null ? '' : (string) $parent, 'sort_order' => '0', 'active' => '1', 'no_coupon' => '0',
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
        // 편집기 사진 폴더 키. 저장된 분류는 제 폴더(categories/<id>), 새 분류는 저장 때 옮길 임시 폴더다(입력 오류로 다시 그릴 때는 폼이 보낸 것을 지킨다).
        $values['image_key'] = $id !== null ? 'categories/' . $id
            : (is_string($values['image_key'] ?? null) && preg_match('/^tmp\/[a-f0-9]{32}$/D', $values['image_key']) ? $values['image_key'] : 'tmp/' . bin2hex(random_bytes(16)));
        $data['values'] = $values;
        $data['id'] = $id;
        // 상위 분류 선택. 10단계 분류는 그 아래에 만들 수 없어 빼고, 수정 화면에서는 자기와 자기 하위도 뺀다(자기 아래로는 옮길 수 없다).
        $data['parents'] = $this->service->categories->parentOptionDetails($id);
        // 저장된 분류의 공개 주소 — 도구 막대의 "쇼핑몰 보기"가 여기로 간다. 입력 오류로 다시 그릴 때도 저장된 슬러그로 간다.
        $data['public_view_url'] = $id === null ? '' : $data['public_url'] . '/c/' . rawurlencode($this->service->categories->get($id)['slug']);
        $data['extra'] = [];
        for ($i = 1; $i <= 10; $i++) {
            $data['extra'][$i] = ['label' => (string) ($values['extra_label'][$i] ?? $values['extra'][$i - 1]['label'] ?? ''), 'value' => (string) ($values['extra_value'][$i] ?? $values['extra'][$i - 1]['value'] ?? '')];
        }
        return $this->render($request, $response, 'category_form', $data);
    }
}
