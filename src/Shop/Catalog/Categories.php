<?php

declare(strict_types=1);

namespace GnuCms\Shop\Catalog;

use GnuCms\Cms\ContentImageService;
use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Error\DomainError;
use GnuCms\Shop\Input;
use GnuCms\Shop\Settings;
use GnuCms\Shop\Store;
use GnuCms\Support\Clock;

final class Categories
{
    public const MAX_DEPTH = 5;

    public function __construct(private Store $store, private HtmlSanitizer $sanitizer, private Settings $settings, private ContentImageService $contentImages) {}

    /** 형제의 마지막 두 자리 최댓값(36진수)에 36을 더한다. 첫 형제는 '10'. 'zz'를 넘으면 null. */
    public function suggestCode(?string $parentCode): ?string
    {
        $prefix = $parentCode ?? '';
        if ($prefix !== '' && $this->byCode($prefix) === null) throw DomainError::notFound('상위 분류를 찾을 수 없습니다.');
        if (strlen($prefix) >= self::MAX_DEPTH * 2) throw DomainError::validation(['code' => self::MAX_DEPTH . '단계 아래에는 분류를 만들 수 없습니다.']);
        $rows = $this->store->select('SELECT code FROM ' . $this->store->table('yc_categories') . ' WHERE code LIKE ?', [$prefix . '__']);
        $max = -1;
        foreach ($rows as $row) $max = max($max, (int) base_convert(substr($row['code'], -2), 36, 10));
        $next = $max < 0 ? 36 : $max + 36;
        if ($next > 1295) return null;
        return $prefix . str_pad(base_convert((string) $next, 10, 36), 2, '0', STR_PAD_LEFT);
    }

    public function save(array $input, ?int $id = null): int
    {
        $defaults = $this->settings->block('category');
        $row = [
            'name' => Input::text($input['name'] ?? '', 'name', 100, false),
            'sort_order' => Input::int($input['sort_order'] ?? '', 'sort_order', -999999, 999999, 0),
            'active' => Input::bool($input['active'] ?? '0'),
            'no_coupon' => Input::bool($input['no_coupon'] ?? '0'),
            'head_html' => Input::html($input['head_html'] ?? '', 'head_html', $this->sanitizer),
            'tail_html' => Input::html($input['tail_html'] ?? '', 'tail_html', $this->sanitizer),
            'list_columns' => Input::int($input['list_columns'] ?? '', 'list_columns', 1, 12, $defaults['columns']),
            'list_rows' => Input::int($input['list_rows'] ?? '', 'list_rows', 1, 50, $defaults['rows']),
            'image_width' => Input::int($input['image_width'] ?? '', 'image_width', 0, 2000, $defaults['image_width']),
            'image_height' => Input::int($input['image_height'] ?? '', 'image_height', 0, 2000, $defaults['image_height']),
            'extra' => Input::extra($input),
            'updated_at' => Clock::timestamp(),
        ];
        // 목록 위·아래 HTML 의 편집기 사진 폴더 키. 폼이 올바른 키를 보내면 그것을, 아니면 저장된 키를 지킨다.
        $imageKey = is_string($input['image_key'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $input['image_key']) ? $input['image_key'] : null;
        $savedKey = '';
        $saved = $this->store->transaction(function () use ($input, $id, $row, $imageKey, &$savedKey): int {
            if ($id !== null) {
                $existing = $this->store->get('yc_categories', $id);
                $savedKey = $imageKey ?? (string) ($existing['image_key'] ?? '');
                $this->store->update('yc_categories', $id, $row + ['image_key' => $savedKey]);
                if (($input['apply_children'] ?? '') === '1') {
                    $this->store->db->update('yc_categories', array_intersect_key($row, array_flip(['active', 'no_coupon', 'list_columns', 'list_rows', 'image_width', 'image_height', 'updated_at'])),
                        'code LIKE :prefix AND id <> :id', ['prefix' => $existing['code'] . '%', 'id' => $id]);
                }
                return $id;
            }
            $code = strtolower(Input::code($input['code'] ?? '', 'code', '/^[0-9A-Za-z]{2,10}$/D', '분류 코드는 단계당 2자, 최대 10자의 영문 소문자·숫자입니다.'));
            if (strlen($code) % 2 !== 0) throw DomainError::validation(['code' => '분류 코드는 단계당 2자입니다.']);
            $parent = null;
            if (strlen($code) > 2) {
                $parent = $this->byCode(substr($code, 0, -2)) ?? throw DomainError::validation(['code' => '상위 분류 코드가 없습니다.']);
            }
            if ($this->byCode($code) !== null) throw DomainError::validation(['code' => '이미 사용 중인 분류 코드입니다.']);
            $savedKey = $imageKey ?? '';
            $row += ['code' => $code, 'parent_id' => $parent === null ? null : (int) $parent['id'], 'depth' => intdiv(strlen($code), 2), 'created_at' => Clock::timestamp(),
                'image_key' => $savedKey];
            return $this->store->insert('yc_categories', $row);
        });
        // 본문에서 빠진 사진은 지운다(폴더가 비면 폴더도).
        if ($savedKey !== '') $this->contentImages->sync($savedKey, $row['head_html'] . "\n" . $row['tail_html']);
        return $saved;
    }

    public function get(int $id): array
    {
        return $this->decode($this->store->get('yc_categories', $id));
    }

    public function byCode(string $code): ?array
    {
        $row = $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_categories') . ' WHERE code = ?', [$code]);
        return $row === null ? null : $this->decode($row);
    }

    /** 부모 우선 DFS. 형제는 sort_order, code 순. 각 행에 product_count. */
    public function tree(): array
    {
        $rows = $this->store->select('SELECT c.*, (SELECT COUNT(*) FROM ' . $this->store->table('yc_product_categories') . ' pc WHERE pc.category_id = c.id) AS product_count FROM '
            . $this->store->table('yc_categories') . ' c ORDER BY c.sort_order, c.code');
        $byParent = [];
        foreach ($rows as $row) $byParent[$row['parent_id'] === null ? 0 : (int) $row['parent_id']][] = $this->decode($row);
        $result = [];
        $walk = function (int $parent) use (&$walk, &$result, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $row) {
                $result[] = $row;
                $walk((int) $row['id']);
            }
        };
        $walk(0);
        return $result;
    }

    /** 선택 상자용 `id => 경로 이름`. */
    public function options(): array
    {
        $names = [];
        $options = [];
        foreach ($this->tree() as $row) {
            $names[(int) $row['id']] = ($row['parent_id'] === null ? '' : $names[(int) $row['parent_id']] . ' > ') . $row['name'];
            $options[(int) $row['id']] = $names[(int) $row['id']];
        }
        return $options;
    }

    public function path(string $code): array
    {
        $codes = [];
        for ($length = 2; $length <= strlen($code); $length += 2) $codes[] = substr($code, 0, $length);
        $rows = [];
        foreach ($codes as $c) { $row = $this->byCode($c); if ($row !== null) $rows[] = $row; }
        return $rows;
    }

    public function children(string $code, bool $activeOnly): array
    {
        $rows = $this->store->select('SELECT * FROM ' . $this->store->table('yc_categories') . ' WHERE code LIKE ?'
            . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort_order, code', [$code . '__']);
        return array_map($this->decode(...), $rows);
    }

    public function delete(int $id): void
    {
        $category = $this->store->get('yc_categories', $id);
        if ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_categories') . ' WHERE parent_id = ?', [$id]) !== null) {
            throw DomainError::validation(['category' => '하위 분류가 있어 삭제할 수 없습니다. 하위 분류를 먼저 삭제해 주세요.']);
        }
        $count = (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->store->table('yc_product_categories') . ' WHERE category_id = ?', [$id])['c'];
        if ($count > 0) throw DomainError::validation(['category' => $count . '개 상품이 연결되어 있어 삭제할 수 없습니다. 상품의 분류를 먼저 옮겨 주세요.']);
        $this->store->delete('yc_categories', 'id = ?', [(int) $category['id']]);
        if (($category['image_key'] ?? '') !== '') $this->contentImages->deleteFolder((string) $category['image_key']);
    }

    /** @param array<int, array<string, mixed>> $rows 한 행이라도 틀리면 전체를 취소한다. */
    public function bulk(array $rows): void
    {
        $this->store->transaction(function () use ($rows): void {
            foreach ($rows as $id => $input) {
                $id = Input::id($id);
                $this->store->get('yc_categories', $id);
                try {
                    $this->store->update('yc_categories', $id, [
                        'name' => Input::text($input['name'] ?? '', 'name', 100, false),
                        'sort_order' => Input::int($input['sort_order'] ?? '', 'sort_order', -999999, 999999, 0),
                        'active' => Input::bool($input['active'] ?? '0'),
                        'list_columns' => Input::int($input['list_columns'] ?? '', 'list_columns', 1, 12),
                        'list_rows' => Input::int($input['list_rows'] ?? '', 'list_rows', 1, 50),
                        'image_width' => Input::int($input['image_width'] ?? '', 'image_width', 0, 2000),
                        'image_height' => Input::int($input['image_height'] ?? '', 'image_height', 0, 2000),
                        'updated_at' => Clock::timestamp(),
                    ]);
                } catch (DomainError $e) {
                    throw DomainError::validation(['row_' . $id => implode(' ', $e->details())]);
                }
            }
        });
    }

    public function count(): int
    {
        return (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->store->table('yc_categories'))['c'];
    }

    private function decode(array $row): array
    {
        $extra = json_decode((string) $row['extra'], true);
        $row['extra'] = is_array($extra) ? $extra : [];
        return $row;
    }
}
