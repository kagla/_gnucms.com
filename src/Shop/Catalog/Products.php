<?php

declare(strict_types=1);

namespace GnuCms\Shop\Catalog;

use GnuCms\Cms\ContentImageService;
use GnuCms\Cms\HtmlSanitizer;
use GnuCms\Error\DomainError;
use GnuCms\Shop\Images;
use GnuCms\Shop\Input;
use GnuCms\Shop\ProductInfo;
use GnuCms\Shop\Store;
use GnuCms\Support\Clock;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

final class Products
{
    public const CODE_PATTERN = '/^[A-Za-z0-9_-]{1,20}$/D';
    public const TYPES = ['is_hit', 'is_recommended', 'is_new', 'is_popular', 'is_discount'];
    public const APPLY_FIELDS = [
        'types' => self::TYPES, 'active' => ['active'], 'no_coupon' => ['no_coupon'], 'point' => ['point_type', 'point', 'supply_point'],
        'tax_free' => ['tax_free'], 'shipping' => ['shipping_type', 'shipping_method', 'shipping_fee', 'shipping_free_minimum', 'shipping_per_qty'],
        'buy' => ['buy_min', 'buy_max'], 'html' => ['head_html', 'tail_html'], 'seller_email' => ['seller_email'], 'phone_inquiry' => ['phone_inquiry'],
    ];
    public const SEARCH_FIELDS = ['name', 'code', 'maker', 'brand', 'model', 'origin', 'seller_email'];
    public const SORTS = ['code', 'name', 'sort_order', 'active', 'sold_out', 'hit', 'price', 'list_price', 'point', 'stock'];
    private const MAX_RELATIONS = 50;

    public function __construct(private Store $store, private HtmlSanitizer $sanitizer, private ContentImageService $contentImages,
        private Images $images, private Options $options, private Categories $categories) {}

    public function save(array $input, array $files, ?int $id = null, string $actor = 'admin'): int
    {
        $existing = $id === null ? null : $this->store->get('yc_products', $id);
        $row = $this->validate($input, $existing);
        $categoryIds = $this->categoryIds($input);
        $row['category_id'] = $categoryIds[1];
        $groups = [];
        for ($i = 1; $i <= Options::MAX_GROUPS; $i++) $groups[] = is_string($input['option_group'][$i] ?? null) ? $input['option_group'][$i] : '';
        $options = $this->options->validate($row['price'], $groups, Options::rows($input['options'] ?? []), Options::rows($input['extras'] ?? []));
        $relations = $this->relationIds($input['relations'] ?? '', $id);
        $uploads = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE) $uploads[] = $file;
        }
        $deleteIds = [];
        foreach (is_array($input['image_delete'] ?? null) ? $input['image_delete'] : [] as $value) if ($value !== '') $deleteIds[] = Input::id($value, 'image_delete');
        $orderIds = [];
        foreach (array_filter(explode(',', is_string($input['image_order'] ?? null) ? $input['image_order'] : '')) as $value) $orderIds[] = Input::id(trim($value), 'image_order');
        // 상세 설명의 편집기 사진은 products/<id> 폴더에 둔다. 첫 저장 전에는 폼이 준 tmp/<키> 에 모였다가 저장하면서 옮긴다.
        $tmpKey = is_string($input['image_key'] ?? null) && preg_match('/^tmp\/[a-f0-9]{32}$/D', $input['image_key']) ? $input['image_key'] : null;
        $version = $existing === null ? 0 : Input::int($input['version'] ?? '', 'version', 0, PHP_INT_MAX, -1);
        $saved = [];
        $removed = [];
        try {
            $productId = $this->store->transaction(function () use ($existing, $id, $row, $version, $categoryIds, $options, $relations, $uploads, $deleteIds, $orderIds, $actor, &$saved, &$removed): int {
                $now = Clock::timestamp();
                if ($existing !== null) {
                    if ($this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1 WHERE id = ? AND version = ?', [$id, $version]) !== 1) {
                        throw DomainError::validation(['version' => '다른 관리자가 먼저 저장했습니다. 새로고침 후 다시 입력해 주세요.']);
                    }
                    $this->store->update('yc_products', $id, $row + ['updated_at' => $now]);
                    $productId = $id;
                    if ((int) $existing['stock'] !== $row['stock']) $this->store->logStock($productId, null, $row['stock'] - (int) $existing['stock'], 'admin', 'product', $actor);
                } else {
                    $productId = $this->store->insert('yc_products', $row + ['created_at' => $now, 'updated_at' => $now]);
                    if ($row['stock'] !== 0) $this->store->logStock($productId, null, $row['stock'], 'admin', 'product', $actor);
                }
                $this->store->delete('yc_product_categories', 'product_id = ?', [$productId]);
                foreach ($categoryIds as $slot => $categoryId) $this->store->insert('yc_product_categories', ['product_id' => $productId, 'category_id' => $categoryId, 'slot' => $slot]);
                $this->options->replace($productId, $options, $actor);
                $this->store->delete('yc_product_relations', 'product_id = ?', [$productId]);
                foreach ($relations as $index => $relatedId) $this->store->insert('yc_product_relations', ['product_id' => $productId, 'related_id' => $relatedId, 'sort_order' => $index]);
                $images = $this->store->select('SELECT * FROM ' . $this->store->table('yc_product_images') . ' WHERE product_id = ? ORDER BY sort_order, id', [$productId]);
                $kept = [];
                foreach ($images as $image) {
                    if (in_array((int) $image['id'], $deleteIds, true)) {
                        $this->store->delete('yc_product_images', 'id = ?', [(int) $image['id']]);
                        $removed[] = [$productId, $image['filename']];
                    } else $kept[(int) $image['id']] = $image;
                }
                $ordered = [];
                foreach ($orderIds as $imageId) if (isset($kept[$imageId])) { $ordered[] = $kept[$imageId]; unset($kept[$imageId]); }
                $ordered = [...$ordered, ...array_values($kept)];
                if (count($ordered) + count($uploads) > Images::MAX) throw DomainError::validation(['images' => '상품 이미지는 ' . Images::MAX . '장까지 등록할 수 있습니다.']);
                foreach ($uploads as $upload) {
                    $name = $this->images->save($productId, $upload);
                    $saved[] = [$productId, $name];
                    $ordered[] = ['id' => $this->store->insert('yc_product_images', ['product_id' => $productId, 'filename' => $name, 'sort_order' => 0])];
                }
                foreach ($ordered as $position => $image) $this->store->update('yc_product_images', (int) $image['id'], ['sort_order' => $position]);
                return $productId;
            });
        } catch (Throwable $e) {
            foreach ($saved as [$pid, $name]) $this->images->delete($pid, $name);
            throw $e;
        }
        foreach ($removed as [$pid, $name]) $this->images->delete($pid, $name);
        $folder = 'products/' . $productId;
        if ($existing === null && $tmpKey !== null) {
            $this->contentImages->move($tmpKey, $folder);
            $row['description'] = ContentImageService::relocatedHtml($tmpKey, $folder, $row['description']);
            $this->store->update('yc_products', $productId, ['description' => $row['description']]);
        }
        $this->contentImages->sync($folder, $row['description']);
        $this->applyScope($input, $row, $productId);
        return $productId;
    }

    private function validate(array $input, ?array $existing): array
    {
        $row = ['name' => Input::text(strip_tags((string) ($input['name'] ?? '')), 'name', 250, false)];
        if ($existing === null) {
            $row['code'] = Input::code($input['code'] ?? '', 'code', self::CODE_PATTERN, '상품 코드는 영문·숫자·-·_ 1~20자입니다.');
            if ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_products') . ' WHERE code = ?', [$row['code']]) !== null) {
                throw DomainError::validation(['code' => '이미 사용 중인 상품 코드입니다.']);
            }
        }
        $row['slug'] = $this->uniqueSlug(Input::slug($row['name'], $row['code'] ?? $existing['code']), $existing === null ? null : (int) $existing['id']);
        foreach (['maker', 'origin', 'brand', 'model'] as $field) $row[$field] = Input::text($input[$field] ?? '', $field, 100);
        $row['summary'] = Input::html($input['summary'] ?? '', 'summary', $this->sanitizer, 20000);
        $row['description'] = Input::html($input['description'] ?? '', 'description', $this->sanitizer, 500000);
        $row['description_text'] = Input::plain($row['description']);
        $row['list_price'] = Input::int($input['list_price'] ?? '', 'list_price', 0, 10000000000, 0);
        $row['price'] = Input::int($input['price'] ?? '', 'price', 0, 10000000000);
        $row['point_type'] = Input::int($input['point_type'] ?? '', 'point_type', 0, 2, 0);
        $row['point'] = Input::int($input['point'] ?? '', 'point', 0, $row['point_type'] === 0 ? 10000000 : 99, 0);
        $row['supply_point'] = Input::int($input['supply_point'] ?? '', 'supply_point', 0, 10000000, 0);
        foreach (['tax_free', 'active', 'no_coupon', 'sold_out', 'restock_notify', 'phone_inquiry', ...self::TYPES] as $field) $row[$field] = Input::bool($input[$field] ?? '0');
        $row['seller_email'] = Input::text($input['seller_email'] ?? '', 'seller_email', 191);
        if ($row['seller_email'] !== '' && filter_var($row['seller_email'], FILTER_VALIDATE_EMAIL) === false) throw DomainError::validation(['seller_email' => '판매자 이메일을 확인해 주세요.']);
        $row['stock'] = Input::int($input['stock'] ?? '', 'stock', 0, 1000000, 0);
        $row['stock_alert'] = Input::int($input['stock_alert'] ?? '', 'stock_alert', 0, 1000000, 0);
        $row['buy_min'] = Input::int($input['buy_min'] ?? '', 'buy_min', 0, 9999, 0);
        $row['buy_max'] = Input::int($input['buy_max'] ?? '', 'buy_max', 0, 9999, 0);
        if ($row['buy_max'] !== 0 && $row['buy_max'] < $row['buy_min']) throw DomainError::validation(['buy_max' => '최대 구매수량은 최소 구매수량 이상이어야 합니다.']);
        $row['shipping_type'] = Input::int($input['shipping_type'] ?? '', 'shipping_type', 0, 4, 0);
        $row['shipping_method'] = Input::int($input['shipping_method'] ?? '', 'shipping_method', 0, 2, 0);
        $row['shipping_fee'] = Input::int($input['shipping_fee'] ?? '', 'shipping_fee', 0, 100000000, 0);
        $row['shipping_free_minimum'] = Input::int($input['shipping_free_minimum'] ?? '', 'shipping_free_minimum', 0, 10000000000, 0);
        $row['shipping_per_qty'] = Input::int($input['shipping_per_qty'] ?? '', 'shipping_per_qty', 0, 9999, 0);
        if ($row['shipping_type'] >= 2 && $row['shipping_fee'] < 1) throw DomainError::validation(['shipping_fee' => '배송비를 입력해 주세요.']);
        if ($row['shipping_type'] === 2 && $row['shipping_free_minimum'] < 1) throw DomainError::validation(['shipping_free_minimum' => '무료배송 기준 금액을 입력해 주세요.']);
        if ($row['shipping_type'] === 4 && $row['shipping_per_qty'] < 1) throw DomainError::validation(['shipping_per_qty' => '배송비를 부과할 수량 단위를 입력해 주세요.']);
        $row['head_html'] = Input::html($input['head_html'] ?? '', 'head_html', $this->sanitizer);
        $row['tail_html'] = Input::html($input['tail_html'] ?? '', 'tail_html', $this->sanitizer);
        $row['info_group'] = Input::text($input['info_group'] ?? '', 'info_group', 50);
        $row['info_values'] = ProductInfo::normalize($row['info_group'], is_array($input['info'] ?? null) ? $input['info'] : []);
        $row['memo'] = Input::text($input['memo'] ?? '', 'memo', 5000);
        $row['sort_order'] = Input::int($input['sort_order'] ?? '', 'sort_order', -999999, 999999, 0);
        $row['extra'] = Input::extra($input);
        return $row;
    }

    private function uniqueSlug(string $base, ?int $excludeId): string
    {
        $taken = array_column($this->store->select('SELECT slug FROM ' . $this->store->table('yc_products') . ' WHERE slug LIKE ? ESCAPE \'!\'' . ($excludeId === null ? '' : ' AND id <> ' . $excludeId),
            [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $base) . '%']), 'slug');
        if (!in_array($base, $taken, true)) return $base;
        for ($n = 2; ; $n++) if (!in_array($base . '-' . $n, $taken, true)) return $base . '-' . $n;
    }

    /** @return array<int,int> slot => category id */
    private function categoryIds(array $input): array
    {
        $ids = [1 => Input::optionalId($input['category_id'] ?? '') ?? throw DomainError::validation(['category_id' => '대표 분류를 선택해 주세요.'])];
        foreach ([2 => 'category2_id', 3 => 'category3_id'] as $slot => $field) {
            $id = Input::optionalId($input[$field] ?? '');
            if ($id !== null) $ids[$slot] = $id;
        }
        foreach ($ids as $slot => $id) {
            $field = $slot === 1 ? 'category_id' : 'category' . $slot . '_id';
            if ($this->store->find('yc_categories', $id) === null) throw DomainError::validation([$field => '분류를 찾을 수 없습니다.']);
        }
        foreach ([2, 3] as $slot) {
            $earlier = array_filter($ids, static fn (int $s): bool => $s < $slot, ARRAY_FILTER_USE_KEY);
            if (isset($ids[$slot]) && in_array($ids[$slot], $earlier, true)) {
                throw DomainError::validation(['category' . $slot . '_id' => '같은 분류를 두 번 지정할 수 없습니다.']);
            }
        }
        return $ids;
    }

    /** @return list<int> */
    private function relationIds(mixed $raw, ?int $selfId): array
    {
        $ids = [];
        foreach (Input::csv($raw, self::MAX_RELATIONS, 20, 'relations') as $value) {
            $id = Input::optionalId($value) ?? throw DomainError::validation(['relations' => '관련상품 지정을 확인해 주세요.']);
            if ($id === $selfId) throw DomainError::validation(['relations' => '자기 자신을 관련상품으로 지정할 수 없습니다.']);
            if ($this->store->find('yc_products', $id) === null) throw DomainError::validation(['relations' => '관련상품을 찾을 수 없습니다.']);
            if (!in_array($id, $ids, true)) $ids[] = $id;
        }
        return $ids;
    }

    private function applyScope(array $input, array $row, int $productId): void
    {
        $scope = $input['apply_scope'] ?? '';
        if (!in_array($scope, ['category', 'all'], true)) return;
        $columns = [];
        foreach (is_array($input['apply_fields'] ?? null) ? $input['apply_fields'] : [] as $group) {
            foreach (self::APPLY_FIELDS[$group] ?? [] as $column) $columns[$column] = $row[$column];
        }
        if ($columns === []) return;
        $columns['updated_at'] = Clock::timestamp();
        if ($scope === 'category') $this->store->db->update('yc_products', $columns, 'category_id = :category', ['category' => $row['category_id']]);
        else $this->store->db->update('yc_products', $columns, 'id <> :none', ['none' => 0]);
    }

    public function find(int $id): ?array { return $this->store->find('yc_products', $id); }

    public function get(int $id): array { return $this->hydrate($this->store->get('yc_products', $id)); }

    public function byCode(string $code): ?array
    {
        $row = $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_products') . ' WHERE code = ?', [$code]);
        return $row === null ? null : $this->hydrate($row);
    }

    public function bySlug(string $slug): ?array
    {
        $row = $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_products') . ' WHERE slug = ?', [$slug]);
        return $row === null ? null : $this->hydrate($row);
    }

    private function hydrate(array $row): array
    {
        $id = (int) $row['id'];
        $row['categories'] = [];
        foreach ($this->store->select('SELECT pc.slot, c.* FROM ' . $this->store->table('yc_product_categories') . ' pc JOIN ' . $this->store->table('yc_categories') . ' c ON c.id = pc.category_id WHERE pc.product_id = ? ORDER BY pc.slot', [$id]) as $category) {
            $row['categories'][(int) $category['slot']] = $category;
        }
        $row['images'] = $this->store->select('SELECT * FROM ' . $this->store->table('yc_product_images') . ' WHERE product_id = ? ORDER BY sort_order, id', [$id]);
        $row['options'] = $this->options->load($id);
        $row['relations'] = $this->store->select('SELECT p.id, p.code, p.name FROM ' . $this->store->table('yc_product_relations') . ' r JOIN ' . $this->store->table('yc_products') . ' p ON p.id = r.related_id WHERE r.product_id = ? ORDER BY r.sort_order, p.id', [$id]);
        $row['info'] = ProductInfo::decode((string) $row['info_values']);
        $extra = json_decode((string) $row['extra'], true);
        $row['extra'] = is_array($extra) ? $extra : [];
        $row['sold_out_computed'] = Options::soldOut($row, $row['options']['select']);
        return $row;
    }

    public function copy(int $id, string $newCode, string $actor): int
    {
        $source = $this->get($id);
        $code = Input::code($newCode, 'code', self::CODE_PATTERN, '상품 코드는 영문·숫자·-·_ 1~20자입니다.');
        if ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_products') . ' WHERE code = ?', [$code]) !== null) throw DomainError::validation(['code' => '이미 사용 중인 상품 코드입니다.']);
        $copied = [];
        try {
            return $this->store->transaction(function () use ($source, $code, $actor, &$copied): int {
                $row = $source;
                unset($row['id'], $row['categories'], $row['images'], $row['options'], $row['relations'], $row['info'], $row['sold_out_computed']);
                $row['extra'] = $source['extra'] === [] ? '[]' : json_encode($source['extra'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $row['code'] = $code;
                $row['slug'] = $this->uniqueSlug(Input::slug($source['name'], $code), null);
                $row['hit'] = 0; $row['sold_qty'] = 0; $row['review_count'] = 0; $row['review_avg'] = 0; $row['version'] = 0;
                $row['created_at'] = $row['updated_at'] = Clock::timestamp();
                $newId = $this->store->insert('yc_products', $row);
                if ((int) $row['stock'] !== 0) $this->store->logStock($newId, null, (int) $row['stock'], 'admin', 'copy', $actor);
                foreach ($source['categories'] as $slot => $category) $this->store->insert('yc_product_categories', ['product_id' => $newId, 'category_id' => (int) $category['id'], 'slot' => $slot]);
                $options = $source['options'];
                foreach (['select', 'extra'] as $kind) {
                    foreach ($options[$kind] as &$option) {
                        unset($option['id'], $option['product_id'], $option['kind']);
                        foreach (['price', 'stock', 'stock_alert', 'active', 'sort_order'] as $column) $option[$column] = (int) $option[$column];
                    }
                    unset($option);
                }
                $this->options->replace($newId, $options, $actor);
                foreach ($source['relations'] as $index => $related) $this->store->insert('yc_product_relations', ['product_id' => $newId, 'related_id' => (int) $related['id'], 'sort_order' => $index]);
                foreach ($source['images'] as $position => $image) {
                    $name = $this->images->copy((int) $source['id'], $newId, $image['filename']);
                    $copied[] = [$newId, $name];
                    $this->store->insert('yc_product_images', ['product_id' => $newId, 'filename' => $name, 'sort_order' => $position]);
                }
                return $newId;
            });
        } catch (Throwable $e) {
            foreach ($copied as [$pid, $name]) $this->images->delete($pid, $name);
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->store->get('yc_products', $id);
        $this->store->transaction(function () use ($id): void {
            $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1 WHERE id = ?', [$id]);
            if ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_order_items') . ' WHERE product_id = ? LIMIT 1', [$id]) !== null) {
                throw DomainError::validation(['product' => '주문 내역이 있는 상품은 삭제할 수 없습니다. 판매 여부를 꺼 주세요.']);
            }
            foreach (['yc_option_groups', 'yc_options', 'yc_product_categories', 'yc_product_images', 'yc_stock_log'] as $table) $this->store->delete($table, 'product_id = ?', [$id]);
            $this->store->delete('yc_product_relations', 'product_id = ? OR related_id = ?', [$id, $id]);
            $this->store->delete('yc_products', 'id = ?', [$id]);
        });
        $this->images->deleteAll($id);
        $this->contentImages->deleteFolder('products/' . $id);
    }

    public function bulkDelete(array $ids): void
    {
        foreach ($ids as $id) $this->delete(Input::id($id));
    }

    public function list(array $filters, int $page, int $perPage = 20): array
    {
        $where = ['1 = 1']; $params = [];
        $q = Input::text($filters['q'] ?? '', 'q', 100);
        if ($q !== '') {
            $field = in_array($filters['field'] ?? '', self::SEARCH_FIELDS, true) ? $filters['field'] : 'name';
            $where[] = 'p.' . $field . ' LIKE ? ESCAPE \'!\''; $params[] = '%' . self::like($q) . '%';
        }
        $category = ($caId = Input::optionalId($filters['ca'] ?? '')) === null ? null : $this->categories->find($caId);
        if ($category !== null) {
            [$sub, $subParams] = Categories::subtreeWhere($category, 'c2');
            $where[] = 'EXISTS (SELECT 1 FROM ' . $this->store->table('yc_product_categories') . ' pc JOIN ' . $this->store->table('yc_categories') . ' c2 ON c2.id = pc.category_id WHERE pc.product_id = p.id AND ' . $sub . ')';
            array_push($params, ...$subParams);
        }
        $sort = in_array($filters['sort'] ?? '', self::SORTS, true) ? 'p.' . $filters['sort'] : 'p.id';
        $dir = ($filters['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $from = ' FROM ' . $this->store->table('yc_products') . ' p LEFT JOIN ' . $this->store->table('yc_categories') . ' c ON c.id = p.category_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS c' . $from, $params)['c'];
        $items = $this->store->select('SELECT p.*, c.name AS category_name' . $from . ' ORDER BY ' . $sort . ' ' . $dir . ', p.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'total_pages' => max(1, (int) ceil($total / $perPage))];
    }

    public function bulk(array $rows, string $actor): void
    {
        $this->store->transaction(function () use ($rows, $actor): void {
            foreach ($rows as $id => $input) {
                $id = Input::id($id);
                $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1 WHERE id = ?', [$id]);
                $old = $this->store->get('yc_products', $id);
                try {
                    $this->store->assertStockUnchanged($input, $old);
                    $categoryId = Input::id($input['category_id'] ?? '', 'category_id');
                    if ($this->store->find('yc_categories', $categoryId) === null) throw DomainError::validation(['category_id' => '분류를 찾을 수 없습니다.']);
                    $data = ['category_id' => $categoryId, 'name' => Input::text(strip_tags((string) ($input['name'] ?? '')), 'name', 250, false),
                        'list_price' => Input::int($input['list_price'] ?? '', 'list_price', 0, 10000000000, 0), 'price' => Input::int($input['price'] ?? '', 'price', 0, 10000000000),
                        'stock' => Input::int($input['stock'] ?? '', 'stock', 0, 1000000, 0), 'active' => Input::bool($input['active'] ?? '0'), 'sold_out' => Input::bool($input['sold_out'] ?? '0'),
                        'sort_order' => Input::int($input['sort_order'] ?? '', 'sort_order', -999999, 999999, 0), 'updated_at' => Clock::timestamp()];
                } catch (DomainError $e) {
                    throw DomainError::validation(['row_' . $id => implode(' ', $e->details())]);
                }
                if ($data['name'] !== $old['name']) $data['slug'] = $this->uniqueSlug(Input::slug($data['name'], $old['code']), $id);
                $this->store->update('yc_products', $id, $data);
                if ((int) $old['category_id'] !== $categoryId) {
                    $this->store->delete('yc_product_categories', 'product_id = ? AND (slot = 1 OR category_id = ?)', [$id, $categoryId]);
                    $this->store->insert('yc_product_categories', ['product_id' => $id, 'category_id' => $categoryId, 'slot' => 1]);
                }
                if ((int) $old['stock'] !== $data['stock']) $this->store->logStock($id, null, $data['stock'] - (int) $old['stock'], 'admin', 'bulk', $actor);
            }
        });
    }

    public function setTypes(array $rows): void
    {
        $this->store->transaction(function () use ($rows): void {
            foreach ($rows as $id => $input) {
                $id = Input::id($id);
                $this->store->get('yc_products', $id);
                try {
                    $data = ['updated_at' => Clock::timestamp()];
                    foreach (self::TYPES as $type) $data[$type] = Input::bool($input[$type] ?? '0');
                    $this->store->update('yc_products', $id, $data);
                } catch (DomainError $e) {
                    throw DomainError::validation(['row_' . $id => implode(' ', $e->details())]);
                }
            }
        });
    }

    public function stockList(string $q, int $page, int $perPage): array
    {
        $where = ''; $params = [];
        if ($q !== '') { $where = ' WHERE (p.name LIKE ? ESCAPE \'!\' OR p.code LIKE ? ESCAPE \'!\')'; $params = ['%' . self::like($q) . '%', '%' . self::like($q) . '%']; }
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->store->table('yc_products') . ' p' . $where, $params)['c'];
        $items = $this->store->select('SELECT p.id, p.code, p.name, p.stock, p.stock_alert, p.active, p.sold_out, p.restock_notify FROM ' . $this->store->table('yc_products') . ' p' . $where
            . ' ORDER BY p.stock ASC, p.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'total_pages' => max(1, (int) ceil($total / $perPage))];
    }

    public function updateStock(array $rows, string $actor): void
    {
        $this->store->transaction(function () use ($rows, $actor): void {
            foreach ($rows as $id => $input) {
                $id = Input::id($id);
                $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1 WHERE id = ?', [$id]);
                $old = $this->store->get('yc_products', $id);
                try {
                    $this->store->assertStockUnchanged($input, $old);
                    $stock = Input::int($input['stock'] ?? '', 'stock', 0, 1000000);
                    $this->store->update('yc_products', $id, ['stock' => $stock, 'stock_alert' => Input::int($input['stock_alert'] ?? '', 'stock_alert', 0, 1000000, 0),
                        'active' => Input::bool($input['active'] ?? '0'), 'sold_out' => Input::bool($input['sold_out'] ?? '0'), 'restock_notify' => Input::bool($input['restock_notify'] ?? '0'), 'updated_at' => Clock::timestamp()]);
                } catch (DomainError $e) {
                    throw DomainError::validation(['row_' . $id => implode(' ', $e->details())]);
                }
                if ($stock !== (int) $old['stock']) $this->store->logStock($id, null, $stock - (int) $old['stock'], 'admin', 'stock', $actor);
            }
        });
    }

    public function search(string $q, string $ca, ?int $exclude, int $limit = 30): array
    {
        $where = ['p.id <> ?']; $params = [$exclude ?? 0];
        if ($q !== '') { $where[] = '(p.name LIKE ? ESCAPE \'!\' OR p.code LIKE ? ESCAPE \'!\')'; $params[] = '%' . self::like($q) . '%'; $params[] = '%' . self::like($q) . '%'; }
        $category = ($caId = Input::optionalId($ca)) === null ? null : $this->categories->find($caId);
        if ($category !== null) { [$sub, $subParams] = Categories::subtreeWhere($category, 'c'); $where[] = $sub; array_push($params, ...$subParams); }
        return $this->store->select('SELECT p.id, p.code, p.name, p.price, c.name AS category_name FROM ' . $this->store->table('yc_products') . ' p LEFT JOIN ' . $this->store->table('yc_categories')
            . ' c ON c.id = p.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name, p.id LIMIT ' . $limit, $params);
    }

    public function stats(): array
    {
        $p = $this->store->table('yc_products');
        return ['products' => (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $p)['c'],
            'active' => (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $p . ' WHERE active = 1')['c'],
            'sold_out' => (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $p . ' WHERE sold_out = 1')['c'],
            'categories' => $this->categories->count()];
    }

    public function lowStock(int $limit = 20): array
    {
        return ['products' => $this->store->select('SELECT id, code, name, stock, stock_alert FROM ' . $this->store->table('yc_products') . ' WHERE stock_alert > 0 AND stock <= stock_alert ORDER BY stock ASC, id DESC LIMIT ' . $limit),
            'options' => $this->store->select('SELECT o.id, o.product_id, o.value1, o.value2, o.value3, o.stock, o.stock_alert, p.name FROM ' . $this->store->table('yc_options') . ' o JOIN ' . $this->store->table('yc_products')
                . ' p ON p.id = o.product_id WHERE o.stock_alert > 0 AND o.stock <= o.stock_alert ORDER BY o.stock ASC, o.id DESC LIMIT ' . $limit)];
    }

    public static function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
