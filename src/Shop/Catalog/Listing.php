<?php

declare(strict_types=1);

namespace GnuCms\Shop\Catalog;

use GnuCms\Shop\Settings;
use GnuCms\Shop\Store;

final class Listing
{
    public const SORTS = ['name' => 'p.name', 'sold' => 'p.sold_qty', 'price' => 'p.price', 'rating' => 'p.review_avg', 'reviews' => 'p.review_count', 'recent' => 'p.updated_at'];
    public const SORT_LABELS = ['sold_desc' => '판매많은순', 'price_asc' => '낮은가격순', 'price_desc' => '높은가격순', 'rating_desc' => '평점높은순', 'reviews_desc' => '후기많은순', 'recent_desc' => '최근등록순', 'name_asc' => '이름순'];
    private const DEFAULT_ORDER = 'p.sort_order ASC, p.id DESC';

    public function __construct(private Store $store, private Settings $settings, private Options $options) {}

    public function category(array $category, string $sort, string $dir, int $page): array
    {
        [$sub, $params] = Categories::subtreeWhere($category, 'c');
        $where = 'p.active = 1 AND EXISTS (SELECT 1 FROM ' . $this->store->table('yc_product_categories') . ' pc JOIN ' . $this->store->table('yc_categories')
            . ' c ON c.id = pc.category_id WHERE pc.product_id = p.id AND c.active = 1 AND ' . $sub . ')';
        return $this->paginate($where, $params, $this->order($sort, $dir), $page, (int) $category['list_columns'], (int) $category['list_rows']);
    }

    public function type(string $type, string $sort, string $dir, int $page): array
    {
        $column = Settings::TYPE_COLUMNS[$type] ?? throw \GnuCms\Error\DomainError::notFound('상품 유형을 찾을 수 없습니다.');
        $block = $this->settings->block('type');
        return $this->paginate($this->visible() . ' AND p.' . $column . ' = 1', [], $this->order($sort, $dir), $page, (int) $block['columns'], (int) $block['rows']);
    }

    public function search(string $q, ?array $category, int $min, int $max, string $sort, string $dir, int $page): array
    {
        $words = array_values(array_unique(array_filter(preg_split('/\s+/u', mb_substr(trim($q), 0, 50, 'UTF-8')) ?: [], static fn (string $w): bool => $w !== '')));
        $block = $this->settings->block('search');
        if ($words === []) return ['items' => [], 'page' => 1, 'total' => 0, 'total_pages' => 1, 'per_page' => $block['columns'] * $block['rows'], 'columns' => $block['columns'], 'facets' => [], 'words' => []];
        $where = [$this->visible()]; $params = [];
        foreach ($words as $word) {
            $like = '%' . Products::like($word) . '%';
            $where[] = '(p.name LIKE ? ESCAPE \'!\' OR p.code LIKE ? ESCAPE \'!\' OR p.summary LIKE ? ESCAPE \'!\' OR p.description_text LIKE ? ESCAPE \'!\')';
            array_push($params, $like, $like, $like, $like);
        }
        if ($min > 0) { $where[] = 'p.price >= ?'; $params[] = $min; }
        if ($max > 0) { $where[] = 'p.price <= ?'; $params[] = $max; }
        $facets = $this->store->select('SELECT c.slug, c.name, COUNT(*) AS count FROM ' . $this->store->table('yc_products') . ' p JOIN ' . $this->store->table('yc_categories')
            . ' c ON c.id = p.category_id WHERE ' . implode(' AND ', $where) . ' GROUP BY c.slug, c.name ORDER BY c.name', $params);
        if ($category !== null) {
            [$sub, $subParams] = Categories::subtreeWhere($category, 'cc');
            $where[] = 'EXISTS (SELECT 1 FROM ' . $this->store->table('yc_categories') . ' cc WHERE cc.id = p.category_id AND ' . $sub . ')';
            array_push($params, ...$subParams);
        }
        $result = $this->paginate(implode(' AND ', $where), $params, $this->order($sort, $dir), $page, (int) $block['columns'], (int) $block['rows']);
        $result['facets'] = array_map(static fn (array $f): array => ['slug' => $f['slug'], 'name' => $f['name'], 'count' => (int) $f['count']], $facets);
        $result['words'] = $words;
        return $result;
    }

    public function main(): array
    {
        $blocks = [];
        foreach ($this->settings->all()['main'] as $type => $block) {
            if (!$block['use']) continue;
            $blocks[$type] = $this->paginate($this->visible() . ' AND p.' . Settings::TYPE_COLUMNS[$type] . ' = 1', [], self::DEFAULT_ORDER, 1, (int) $block['columns'], (int) $block['rows'])['items'];
        }
        return $blocks;
    }

    public function related(int $productId): array
    {
        $rows = $this->store->select('SELECT p.*, r.sort_order AS relation_order FROM ' . $this->store->table('yc_product_relations') . ' r JOIN ' . $this->store->table('yc_products')
            . ' p ON (r.product_id = ? AND p.id = r.related_id) OR (r.related_id = ? AND p.id = r.product_id) WHERE ' . $this->visible() . ' ORDER BY r.sort_order, p.id', [$productId, $productId]);
        $unique = [];
        foreach ($rows as $row) $unique[(int) $row['id']] ??= $row;
        return $this->decorate(array_values($unique));
    }

    public function adjacent(array $product): array
    {
        $base = 'SELECT p.id, p.code, p.slug, p.name FROM ' . $this->store->table('yc_products') . ' p WHERE p.active = 1 AND p.category_id = ? AND ';
        $params = [(int) $product['category_id'], (int) $product['sort_order'], (int) $product['sort_order'], (int) $product['id']];
        return ['prev' => $this->store->selectOne($base . '(p.sort_order < ? OR (p.sort_order = ? AND p.id > ?)) ORDER BY p.sort_order DESC, p.id ASC LIMIT 1', $params),
            'next' => $this->store->selectOne($base . '(p.sort_order > ? OR (p.sort_order = ? AND p.id < ?)) ORDER BY p.sort_order ASC, p.id DESC LIMIT 1', $params)];
    }

    /** 활성 상품이고 대표 분류가 활성인 조건. */
    private function visible(): string
    {
        return 'p.active = 1 AND EXISTS (SELECT 1 FROM ' . $this->store->table('yc_categories') . ' c1 WHERE c1.id = p.category_id AND c1.active = 1)';
    }

    private function order(string $sort, string $dir): string
    {
        if (!isset(self::SORTS[$sort])) return self::DEFAULT_ORDER;
        return self::SORTS[$sort] . ' ' . ($dir === 'asc' ? 'ASC' : 'DESC') . ', ' . self::DEFAULT_ORDER;
    }

    private function paginate(string $where, array $params, string $order, int $page, int $columns, int $rows): array
    {
        $perPage = max(1, $columns) * max(1, $rows);
        $page = max(1, $page);
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->store->table('yc_products') . ' p WHERE ' . $where, $params)['c'];
        $totalPages = max(1, (int) ceil($total / $perPage));
        $items = $page > $totalPages ? [] : $this->store->select('SELECT p.* FROM ' . $this->store->table('yc_products') . ' p WHERE ' . $where . ' ORDER BY ' . $order
            . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        return ['items' => $this->decorate($items), 'page' => $page, 'total' => $total, 'total_pages' => $totalPages, 'per_page' => $perPage, 'columns' => max(1, $columns)];
    }

    /** 대표 이미지와 품절 여부를 한 번의 조회로 붙인다. */
    private function decorate(array $items): array
    {
        if ($items === []) return [];
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $items);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $images = [];
        foreach ($this->store->select('SELECT product_id, filename FROM ' . $this->store->table('yc_product_images') . ' WHERE product_id IN (' . $marks . ') ORDER BY sort_order, id', $ids) as $image) {
            $images[(int) $image['product_id']] ??= $image['filename'];
        }
        $options = [];
        foreach ($this->store->select('SELECT product_id, stock, active FROM ' . $this->store->table('yc_options') . " WHERE kind = 'select' AND product_id IN (" . $marks . ')', $ids) as $option) {
            $options[(int) $option['product_id']][] = $option;
        }
        foreach ($items as &$item) {
            $item['image'] = $images[(int) $item['id']] ?? null;
            $item['sold_out'] = Options::soldOut($item, $options[(int) $item['id']] ?? []);
        }
        return $items;
    }
}
