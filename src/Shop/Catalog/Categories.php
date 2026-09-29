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

/**
 * 분류 트리. 부모 id 로 잇고 path(/1/5/12/)로 하위 전체를 한 번에 건다.
 * 공개 주소는 slug 이고, 29판 이전의 2자 코드는 legacy_code 로만 남아 옛 주소를 넘긴다.
 */
final class Categories
{
    public const MAX_DEPTH = 10;

    public function __construct(private Store $store, private HtmlSanitizer $sanitizer, private Settings $settings, private ContentImageService $contentImages) {}

    /** 만들기와 고치기. 상위 분류를 바꾸면 자기와 하위 전체가 함께 옮겨진다. */
    public function save(array $input, ?int $id = null): int
    {
        $defaults = $this->settings->block('category');
        $existing = $id === null ? null : $this->get($id);
        $parentId = Input::optionalId($input['parent_id'] ?? '');
        $parent = null;
        if ($parentId !== null) {
            $parent = $this->find($parentId) ?? throw DomainError::validation(['parent_id' => '상위 분류를 찾을 수 없습니다.']);
            if ($existing !== null && str_starts_with((string) $parent['path'], (string) $existing['path'])) {
                throw DomainError::validation(['parent_id' => '자기 자신이나 자기 하위 분류 아래로 옮길 수 없습니다.']);
            }
        }
        $depth = $parent === null ? 1 : (int) $parent['depth'] + 1;
        // 옮기면 하위도 같이 내려간다 — 가장 깊은 하위가 상한을 넘지 않아야 한다.
        $below = $existing === null ? 0
            : (int) $this->store->selectOne('SELECT MAX(depth) AS d FROM ' . $this->store->table('yc_categories') . ' WHERE path LIKE ?', [$existing['path'] . '%'])['d'] - (int) $existing['depth'];
        if ($depth + $below > self::MAX_DEPTH) throw DomainError::validation(['parent_id' => '분류는 ' . self::MAX_DEPTH . '단계까지입니다.']);
        $name = Input::text($input['name'] ?? '', 'name', 100, false);
        $row = [
            'name' => $name,
            'slug' => $this->uniqueSlug($input['slug'] ?? '', $name, $id),
            'parent_id' => $parentId,
            'depth' => $depth,
            'sort_order' => Input::int($input['sort_order'] ?? '', 'sort_order', -999999, 999999, 0),
            'active' => Input::bool($input['active'] ?? '0'),
            'menu_hidden' => Input::bool($input['menu_hidden'] ?? '0'),
            'head_html' => Input::html($input['head_html'] ?? '', 'head_html', $this->sanitizer),
            'tail_html' => Input::html($input['tail_html'] ?? '', 'tail_html', $this->sanitizer),
            'list_columns' => Input::int($input['list_columns'] ?? '', 'list_columns', 1, 12, $defaults['columns']),
            'list_rows' => Input::int($input['list_rows'] ?? '', 'list_rows', 1, 50, $defaults['rows']),
            'image_width' => Input::int($input['image_width'] ?? '', 'image_width', 0, 2000, $defaults['image_width']),
            'image_height' => Input::int($input['image_height'] ?? '', 'image_height', 0, 2000, $defaults['image_height']),
            'extra' => Input::extra($input),
            'updated_at' => Clock::timestamp(),
        ];
        // 목록 위·아래 HTML 의 편집기 사진은 categories/<id> 폴더에 둔다. 첫 저장 전에는 폼이 준 tmp/<키> 에 모였다가 저장하면서 옮긴다.
        $tmpKey = is_string($input['image_key'] ?? null) && preg_match('/^tmp\/[a-f0-9]{32}$/D', $input['image_key']) ? $input['image_key'] : null;
        $saved = $this->store->transaction(function () use ($input, $id, $existing, $parent, $row): int {
            $above = $parent === null ? '/' : (string) $parent['path'];
            if ($existing !== null) {
                $newPath = $above . $id . '/';
                $this->store->update('yc_categories', $id, $row + ['path' => $newPath]);
                if ($newPath !== $existing['path']) {
                    // 하위 전체의 path 앞부분을 바꾸고 depth 를 차이만큼 옮긴다. 분류는 많지 않아 행마다 고친다(방언 차이 없음).
                    $delta = (int) $row['depth'] - (int) $existing['depth'];
                    $rows = $this->store->select('SELECT id, path, depth FROM ' . $this->store->table('yc_categories') . ' WHERE path LIKE ? AND id <> ?', [$existing['path'] . '%', $id]);
                    foreach ($rows as $node) {
                        $this->store->update('yc_categories', (int) $node['id'],
                            ['path' => $newPath . substr((string) $node['path'], strlen((string) $existing['path'])), 'depth' => (int) $node['depth'] + $delta]);
                    }
                }
                if (($input['apply_children'] ?? '') === '1') {
                    $this->store->db->update('yc_categories', array_intersect_key($row, array_flip(['active', 'list_columns', 'list_rows', 'image_width', 'image_height', 'updated_at'])),
                        'path LIKE :prefix AND id <> :id', ['prefix' => $newPath . '%', 'id' => $id]);
                }
                return $id;
            }
            $newId = $this->store->insert('yc_categories', $row + ['path' => '', 'legacy_code' => null, 'created_at' => Clock::timestamp()]);
            $this->store->update('yc_categories', $newId, ['path' => $above . $newId . '/']);
            return $newId;
        });
        $folder = 'categories/' . $saved;
        if ($id === null && $tmpKey !== null) {
            $this->contentImages->move($tmpKey, $folder);
            $row['head_html'] = ContentImageService::relocatedHtml($tmpKey, $folder, $row['head_html']);
            $row['tail_html'] = ContentImageService::relocatedHtml($tmpKey, $folder, $row['tail_html']);
            $this->store->update('yc_categories', $saved, ['head_html' => $row['head_html'], 'tail_html' => $row['tail_html']]);
        }
        // 본문에서 빠진 사진은 지운다(폴더가 비면 폴더도).
        $this->contentImages->sync($folder, $row['head_html'] . "\n" . $row['tail_html']);
        return $saved;
    }

    /** 직접 준 슬러그는 규칙대로 다듬고 겹치면 거절한다. 이름에서 만든 슬러그는 -2, -3 … 을 붙인다. */
    private function uniqueSlug(mixed $given, string $name, ?int $excludeId): string
    {
        $typed = Input::text($given, 'slug', 200);
        $base = Input::slug($typed !== '' ? $typed : $name, '');
        if ($base === '') throw DomainError::validation(['slug' => '슬러그를 만들 수 없습니다. 영문·숫자·한글이 든 이름이나 슬러그를 입력해 주세요.']);
        // 슬러그는 /shop/c/<슬러그> 의 한 칸이다. . 과 .. 은 브라우저가 주소에서 먼저 지워 버려 링크가 엉뚱한 곳으로 간다.
        if ($base === '.' || $base === '..') throw DomainError::validation(['slug' => '슬러그로 쓸 수 없는 값입니다.']);
        $slug = $base;
        for ($n = 2; ($other = $this->bySlug($slug)) !== null && (int) $other['id'] !== $excludeId; $n++) {
            if ($typed !== '') throw DomainError::validation(['slug' => '이미 쓰는 슬러그입니다.']);
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    public function get(int $id): array
    {
        return $this->decode($this->store->get('yc_categories', $id));
    }

    public function find(int $id): ?array
    {
        $row = $this->store->find('yc_categories', $id);
        return $row === null ? null : $this->decode($row);
    }

    public function bySlug(string $slug): ?array
    {
        if ($slug === '') return null;
        $row = $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_categories') . ' WHERE slug = ?', [$slug]);
        return $row === null ? null : $this->decode($row);
    }

    /** 29판 이전의 2자 코드로 찾는다 — 옛 주소를 새 주소로 넘길 때만 쓴다. */
    public function byLegacyCode(string $code): ?array
    {
        if ($code === '') return null;
        $row = $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_categories') . ' WHERE legacy_code = ?', [$code]);
        return $row === null ? null : $this->decode($row);
    }

    /** 부모 우선 DFS. 형제는 sort_order, name 순. 각 행에 product_count. */
    public function tree(): array
    {
        $rows = $this->store->select('SELECT c.*, (SELECT COUNT(*) FROM ' . $this->store->table('yc_product_categories') . ' pc WHERE pc.category_id = c.id) AS product_count FROM '
            . $this->store->table('yc_categories') . ' c ORDER BY c.sort_order, c.name, c.id');
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
        return array_map(static fn (array $row): string => $row['label'], $this->labelled());
    }

    /**
     * 선택 상자용 `id => ['label', 'text', 'title', 'slug']`. text 는 경로 이름에 슬러그가 이름과 다를 때만 `[슬러그]` 를 덧붙인 것,
     * title 은 마우스를 올리면 보이는 슬러그·옛 코드·번호다. 코드가 없어진 뒤에도 같은 이름의 분류를 가려낼 수 있게 한다.
     */
    public function optionDetails(): array
    {
        return array_map(self::detail(...), $this->labelled());
    }

    /** parentOptions() 와 같은 목록을 optionDetails() 모양으로. */
    public function parentOptionDetails(?int $excludeId): array
    {
        return array_map(self::detail(...), $this->parentRows($excludeId));
    }

    private static function detail(array $row): array
    {
        $title = '슬러그 ' . $row['slug'] . (($row['legacy_code'] ?? null) !== null && $row['legacy_code'] !== '' ? ' · 옛 코드 ' . $row['legacy_code'] : '') . ' · 번호 ' . (int) $row['id'];
        return ['label' => $row['label'], 'text' => $row['label'] . ($row['slug'] === $row['name'] ? '' : ' [' . $row['slug'] . ']'),
            'title' => $title, 'slug' => $row['slug']];
    }

    /**
     * 상위 분류 선택용 options(). 아래에 더 만들 수 없는 MAX_DEPTH 단계 분류는 뺀다.
     * 수정 화면($excludeId)에서는 자기와 자기 하위도 뺀다(자기 아래로는 옮길 수 없다).
     * 하위가 있는 분류를 옮길 때는 하위까지 함께 내려가므로 9단계 상위도 저장에서 거절될 수 있다 — 그 판단은 save() 가 한다.
     */
    public function parentOptions(?int $excludeId): array
    {
        return array_map(static fn (array $row): string => $row['label'], $this->parentRows($excludeId));
    }

    private function parentRows(?int $excludeId): array
    {
        $self = $excludeId === null ? null : $this->find($excludeId);
        $rows = [];
        foreach ($this->labelled() as $optionId => $row) {
            if ((int) $row['depth'] >= self::MAX_DEPTH) continue;
            if ($self !== null && str_starts_with((string) $row['path'], (string) $self['path'])) continue;
            $rows[$optionId] = $row;
        }
        return $rows;
    }

    /** 빵부스러기: path 의 id 순서대로. */
    public function ancestors(array $category): array
    {
        $ids = array_values(array_filter(explode('/', (string) $category['path']), static fn (string $s): bool => $s !== ''));
        $rows = [];
        foreach ($ids as $id) { $row = $this->find((int) $id); if ($row !== null) $rows[] = $row; }
        return $rows;
    }

    /** $menuOnly 는 상단 메뉴·바로가기·하위 분류 칩용이다 — 메뉴에서 숨긴 분류를 뺀다(주소·빵부스러기·검색은 그대로다). */
    public function children(?int $parentId, bool $activeOnly, bool $menuOnly = false): array
    {
        $rows = $this->store->select('SELECT * FROM ' . $this->store->table('yc_categories') . ' WHERE ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?')
            . ($activeOnly ? ' AND active = 1' : '') . ($menuOnly ? ' AND menu_hidden = 0' : '') . ' ORDER BY sort_order, name, id', $parentId === null ? [] : [$parentId]);
        return array_map($this->decode(...), $rows);
    }

    /** 하위 전체(자기 포함)를 거는 조건. [sql, params]. */
    public static function subtreeWhere(array $category, string $alias = 'c'): array
    {
        return [$alias . '.path LIKE ?', [$category['path'] . '%']];
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
        $this->contentImages->deleteFolder('categories/' . (int) $category['id']);
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

    /** tree() 의 각 행에 '의류 > 셔츠' 이름표를 붙여 id 로 색인한다. */
    private function labelled(): array
    {
        $rows = [];
        foreach ($this->tree() as $row) {
            $parent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $row['label'] = ($parent === null || !isset($rows[$parent]) ? '' : $rows[$parent]['label'] . ' > ') . $row['name'];
            $rows[(int) $row['id']] = $row;
        }
        return $rows;
    }

    private function decode(array $row): array
    {
        $extra = json_decode((string) $row['extra'], true);
        $row['extra'] = is_array($extra) ? $extra : [];
        return $row;
    }
}
