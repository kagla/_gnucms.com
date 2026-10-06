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
    public const COPY_FIELDS = [
        'active' => ['active'],
        'tax_free' => ['tax_free'], 'shipping' => ['shipping_type', 'shipping_method', 'shipping_fee', 'shipping_free_minimum', 'shipping_per_qty'],
        'buy' => ['buy_min', 'buy_max'], 'phone_inquiry' => ['phone_inquiry'],
    ];
    public const SEARCH_FIELDS = ['name', 'code'];
    public const SORTS = ['code', 'name', 'sort_order', 'active', 'sold_out', 'phone_inquiry', 'price', 'list_price', 'tax_free', 'shipping_type'];
    public const MAX_EXTRA_CATEGORIES = 20;

    public function __construct(private Store $store, private HtmlSanitizer $sanitizer, private ContentImageService $contentImages,
        private Images $images, private Options $options, private Categories $categories) {}

    public function save(array $input, array $files, ?int $id = null, string $actor = 'admin'): int
    {
        $existing = $id === null ? null : $this->store->get('yc_products', $id);
        $copySourceId = $existing === null ? Input::optionalId($input['copy_source_id'] ?? '') : null;
        $copySource = $copySourceId === null ? null : $this->get($copySourceId);
        $row = $this->validate($input, $existing);
        $categoryIds = $this->categoryIds($input);
        $row['category_id'] = $categoryIds[1];
        $groups = [];
        for ($i = 1; $i <= Options::MAX_GROUPS; $i++) $groups[] = is_string($input['option_group'][$i] ?? null) ? $input['option_group'][$i] : '';
        $options = $this->options->validate($row['price'], $groups, Options::rows($input['options'] ?? []), Options::rows($input['extras'] ?? []));
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
            $productId = $this->store->transaction(function () use ($existing, $copySource, $id, $row, $version, $categoryIds, $options, $uploads, $deleteIds, $orderIds, $actor, &$saved, &$removed): int {
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
                $copyImages = $copySource['images'] ?? [];
                if (count($ordered) + count($copyImages) + count($uploads) > Images::MAX) throw DomainError::validation(['images' => '상품 이미지는 ' . Images::MAX . '장까지 등록할 수 있습니다.']);
                foreach ($copyImages as $image) {
                    $name = $this->images->copy((int) $copySource['id'], $productId, (string) $image['filename']);
                    $saved[] = [$productId, $name];
                    $ordered[] = ['id' => $this->store->insert('yc_product_images', ['product_id' => $productId, 'filename' => $name, 'sort_order' => 0])];
                }
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
        $descriptionRelocated = false;
        if ($existing === null && $copySource !== null) {
            $sourceFolder = 'products/' . (int) $copySource['id'];
            $this->contentImages->copyFolder($sourceFolder, $folder);
            $row['description'] = ContentImageService::relocatedHtml($sourceFolder, $folder, $row['description']);
            $descriptionRelocated = true;
        }
        if ($existing === null && $tmpKey !== null) {
            $this->contentImages->move($tmpKey, $folder);
            $row['description'] = ContentImageService::relocatedHtml($tmpKey, $folder, $row['description']);
            $descriptionRelocated = true;
        }
        if ($descriptionRelocated) $this->store->update('yc_products', $productId, ['description' => $row['description']]);
        $this->contentImages->sync($folder, $row['description']);
        return $productId;
    }

    private function validate(array $input, ?array $existing): array
    {
        $row = ['name' => Input::text(strip_tags((string) ($input['name'] ?? '')), 'name', 250, false)];
        if ($existing === null) {
            $code = $input['code'] ?? '';
            if ($code === '') {
                do { $code = 'P' . bin2hex(random_bytes(8)); }
                while ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_products') . ' WHERE code = ?', [$code]) !== null);
            }
            $row['code'] = Input::code($code, 'code', self::CODE_PATTERN, '상품 코드는 영문·숫자·-·_ 1~20자입니다.');
            if ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_products') . ' WHERE code = ?', [$row['code']]) !== null) {
                throw DomainError::validation(['code' => '이미 사용 중인 상품 코드입니다.']);
            }
        }
        $row['slug'] = $this->uniqueSlug(Input::slug($row['name'], $row['code'] ?? $existing['code']), $existing === null ? null : (int) $existing['id']);
        $row['summary'] = Input::html($input['summary'] ?? '', 'summary', $this->sanitizer, 20000);
        $row['description'] = Input::html($input['description'] ?? '', 'description', $this->sanitizer, 500000);
        $row['description_text'] = Input::plain($row['description']);
        $row['list_price'] = Input::int($input['list_price'] ?? '', 'list_price', 0, 10000000000, 0);
        $row['price'] = Input::int($input['price'] ?? '', 'price', 0, 10000000000);
        foreach (['tax_free', 'active', 'sold_out', 'phone_inquiry'] as $field) $row[$field] = Input::bool($input[$field] ?? '0');
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

    /**
     * 대표 분류(slot 1) + 추가 분류(slot 2…). 빈값·대표와 겹침·중복은 빼고, 없는 분류와 상한 초과는 거절한다.
     *
     * @return array<int,int> slot => category id
     */
    private function categoryIds(array $input): array
    {
        $primary = Input::optionalId($input['category_id'] ?? '') ?? throw DomainError::validation(['category_id' => '대표 분류를 선택해 주세요.']);
        if ($this->store->find('yc_categories', $primary) === null) throw DomainError::validation(['category_id' => '분류를 찾을 수 없습니다.']);
        $ids = [1 => $primary];
        $extras = [];
        $chosen = 0;
        foreach (is_array($input['extra_category_ids'] ?? null) ? $input['extra_category_ids'] : [] as $raw) {
            if (!is_string($raw) && !is_int($raw)) continue;
            $extra = Input::optionalId((string) $raw);
            if ($extra === null) continue;
            $chosen++;
            if ($extra === $primary || in_array($extra, $extras, true)) continue;
            $extras[] = $extra;
        }
        // 고른 줄 수로 센다. 21줄을 똑같은 분류로 채워도 상한을 넘은 폼이다.
        if ($chosen > self::MAX_EXTRA_CATEGORIES) throw DomainError::validation(['extra_category_ids' => '추가 분류는 ' . self::MAX_EXTRA_CATEGORIES . '개까지입니다.']);
        foreach ($extras as $extra) {
            if ($this->store->find('yc_categories', $extra) === null) throw DomainError::validation(['extra_category_ids' => '분류를 찾을 수 없습니다.']);
            $ids[] = $extra; // 2, 3, …
        }
        return $ids;
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
        $row['info'] = ProductInfo::decode((string) $row['info_values']);
        $extra = json_decode((string) $row['extra'], true);
        $row['extra'] = is_array($extra) ? $extra : [];
        $row['sold_out_computed'] = Stock::soldOut($row, $row['options']['select']);
        return $row;
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
            $this->store->delete('yc_product_feedback', 'product_id = ?', [$id]);
            $this->store->delete('yc_products', 'id = ?', [$id]);
        });
        $this->images->deleteAll($id);
        $this->contentImages->deleteFolder('products/' . $id);
    }

    public function bulkDelete(array $ids): void
    {
        foreach ($ids as $id) $this->delete(Input::id($id));
    }

    /** 기준 상품의 운영 설정을 검색 결과·같은 대표 분류·전체 상품 중 고른 범위에 복사한다. */
    public function copySettingsByScope(int $sourceId, array $groups, string $scope, array $filters): int
    {
        $source = $this->store->get('yc_products', $sourceId);

        $columns = [];
        $chosenGroups = [];
        foreach ($groups as $group) {
            if (!is_string($group) || !isset(self::COPY_FIELDS[$group])) throw DomainError::validation(['copy_fields' => '복사할 설정을 확인해 주세요.']);
            if (in_array($group, $chosenGroups, true)) continue;
            $chosenGroups[] = $group;
            foreach (self::COPY_FIELDS[$group] as $column) $columns[$column] = $source[$column];
        }
        if ($columns === []) throw DomainError::validation(['copy_fields' => '복사할 설정을 선택하세요.']);
        if ($scope === 'search' && !$this->hasTargetFilter($filters)) throw DomainError::validation(['target' => '검색 결과에 적용하려면 검색어나 분류를 지정하세요.']);
        [$where, $params] = $this->copyTargetWhere($source, $scope, $filters);

        return $this->store->transaction(function () use ($columns, $where, $params): int {
            $table = $this->store->table('yc_products');
            $count = (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $table . ' p WHERE ' . $where, $params)['c'];
            if ($count === 0) throw DomainError::validation(['target' => '설정을 복사할 대상 상품이 없습니다.']);
            $set = []; $values = [];
            foreach ($columns as $column => $value) { $set[] = $this->store->db->q($column) . ' = ?'; $values[] = $value; }
            $set[] = 'version = version + 1'; $set[] = 'updated_at = ?'; $values[] = Clock::timestamp();
            $this->store->execute('UPDATE ' . $table . ' p SET ' . implode(', ', $set) . ' WHERE ' . $where, [...$values, ...$params]);
            return $count;
        });
    }

    public function copyTargetCount(int $sourceId, string $scope, array $filters): int
    {
        $source = $this->store->get('yc_products', $sourceId);
        if ($scope === 'search' && !$this->hasTargetFilter($filters)) return 0;
        [$where, $params] = $this->copyTargetWhere($source, $scope, $filters);
        return (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->store->table('yc_products') . ' p WHERE ' . $where, $params)['c'];
    }

    private function hasTargetFilter(array $filters): bool
    {
        return trim(is_string($filters['q'] ?? null) ? $filters['q'] : '') !== '' || Input::filterId($filters['ca'] ?? '') !== null;
    }

    /** @return array{string,list<mixed>} */
    private function copyTargetWhere(array $source, string $scope, array $filters): array
    {
        $where = ['p.id <> ?']; $params = [(int) $source['id']];
        if ($scope === 'category') {
            $where[] = 'p.category_id = ?'; $params[] = (int) $source['category_id'];
        } elseif ($scope === 'search') {
            $q = Input::text($filters['q'] ?? '', 'q', 100);
            if ($q !== '') {
                $field = in_array($filters['field'] ?? '', self::SEARCH_FIELDS, true) ? $filters['field'] : 'name';
                $where[] = 'p.' . $field . " LIKE ? ESCAPE '!'"; $params[] = '%' . self::like($q) . '%';
            }
            $category = ($caId = Input::filterId($filters['ca'] ?? '')) === null ? null : $this->categories->find($caId);
            if ($category !== null) {
                [$subtree, $subtreeParams] = Categories::subtreeWhere($category, 'c2');
                $where[] = 'EXISTS (SELECT 1 FROM ' . $this->store->table('yc_product_categories') . ' pc JOIN ' . $this->store->table('yc_categories')
                    . ' c2 ON c2.id = pc.category_id WHERE pc.product_id = p.id AND ' . $subtree . ')';
                array_push($params, ...$subtreeParams);
            }
        } elseif ($scope !== 'all') {
            throw DomainError::validation(['scope' => '적용 범위를 확인해 주세요.']);
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * 목록 일괄: 선택 상품을 분류에 넣는다. 이미 있으면 건너뛴다.
     *
     * @return array{changed:int, skipped:int}
     */
    public function addToCategory(array $ids, int $categoryId): array
    {
        if ($this->store->find('yc_categories', $categoryId) === null) throw DomainError::validation(['category' => '분류를 찾을 수 없습니다.']);
        $changed = 0; $skipped = 0;
        $this->store->transaction(function () use ($ids, $categoryId, &$changed, &$skipped): void {
            foreach ($ids as $raw) {
                $id = Input::id($raw);
                $this->store->get('yc_products', $id);
                if ($this->store->selectOne('SELECT 1 AS x FROM ' . $this->store->table('yc_product_categories') . ' WHERE product_id = ? AND category_id = ?', [$id, $categoryId]) !== null) { $skipped++; continue; }
                $slot = (int) $this->store->selectOne('SELECT COALESCE(MAX(slot), 0) AS s FROM ' . $this->store->table('yc_product_categories') . ' WHERE product_id = ?', [$id])['s'] + 1;
                // 두 관리자가 같은 상품을 동시에 넣으면 나중 삽입이 UNIQUE (product_id, category_id) 에 걸린다 — 이미 있는 것이니 건너뛴다.
                try { $this->store->insert('yc_product_categories', ['product_id' => $id, 'category_id' => $categoryId, 'slot' => max(2, $slot)]); }
                catch (DomainError) { $skipped++; continue; }
                $changed++;
            }
        });
        return ['changed' => $changed, 'skipped' => $skipped];
    }

    /**
     * 목록 일괄: 선택 상품을 분류에서 뺀다. 대표 분류(slot 1)는 건드리지 않는다.
     *
     * @return array{changed:int, skipped:int}
     */
    public function removeFromCategory(array $ids, int $categoryId): array
    {
        $changed = 0; $skipped = 0;
        $this->store->transaction(function () use ($ids, $categoryId, &$changed, &$skipped): void {
            foreach ($ids as $raw) {
                $id = Input::id($raw);
                $this->store->get('yc_products', $id);
                $deleted = $this->store->delete('yc_product_categories', 'product_id = ? AND category_id = ? AND slot <> 1', [$id, $categoryId]);
                $deleted > 0 ? $changed++ : $skipped++;
            }
        });
        return ['changed' => $changed, 'skipped' => $skipped];
    }

    public function list(array $filters, int $page, int $perPage = 20): array
    {
        $where = ['1 = 1']; $params = [];
        $excludeId = Input::filterId($filters['exclude_id'] ?? '');
        if ($excludeId !== null) { $where[] = 'p.id <> ?'; $params[] = $excludeId; }
        $q = Input::text($filters['q'] ?? '', 'q', 100);
        if ($q !== '') {
            $field = in_array($filters['field'] ?? '', self::SEARCH_FIELDS, true) ? $filters['field'] : 'name';
            $where[] = 'p.' . $field . ' LIKE ? ESCAPE \'!\''; $params[] = '%' . self::like($q) . '%';
        }
        $category = ($caId = Input::filterId($filters['ca'] ?? '')) === null ? null : $this->categories->find($caId);
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

    public function bulk(array $rows): void
    {
        $this->store->transaction(function () use ($rows): void {
            foreach ($rows as $id => $input) {
                $id = Input::id($id);
                $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1 WHERE id = ?', [$id]);
                $old = $this->store->get('yc_products', $id);
                try {
                    $categoryId = Input::id($input['category_id'] ?? '', 'category_id');
                    if ($this->store->find('yc_categories', $categoryId) === null) throw DomainError::validation(['category_id' => '분류를 찾을 수 없습니다.']);
                    $shippingType = Input::int($input['shipping_type'] ?? $old['shipping_type'], 'shipping_type', 0, 4, (int) $old['shipping_type']);
                    if ($shippingType >= 2 && (int) $old['shipping_fee'] < 1) throw DomainError::validation(['shipping_type' => '상품 수정에서 배송비를 먼저 입력해 주세요.']);
                    if ($shippingType === 2 && (int) $old['shipping_free_minimum'] < 1) throw DomainError::validation(['shipping_type' => '상품 수정에서 무료배송 기준 금액을 먼저 입력해 주세요.']);
                    if ($shippingType === 4 && (int) $old['shipping_per_qty'] < 1) throw DomainError::validation(['shipping_type' => '상품 수정에서 배송비 부과 수량을 먼저 입력해 주세요.']);
                    $data = ['category_id' => $categoryId, 'name' => Input::text(strip_tags((string) ($input['name'] ?? '')), 'name', 250, false),
                        'list_price' => Input::int(str_replace(',', '', (string) ($input['list_price'] ?? '')), 'list_price', 0, 10000000000, 0), 'price' => Input::int(str_replace(',', '', (string) ($input['price'] ?? '')), 'price', 0, 10000000000),
                        'tax_free' => Input::bool($input['tax_free'] ?? '0'), 'shipping_type' => $shippingType,
                        'phone_inquiry' => Input::bool($input['phone_inquiry'] ?? '0'),
                        'active' => Input::bool($input['active'] ?? '0'), 'sold_out' => Input::bool($input['sold_out'] ?? '0'),
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
            }
        });
    }

    public function stockList(string $q, int $page, int $perPage): array
    {
        // 선택옵션이 있는 상품의 재고는 조합 행에 있다(Stock) — 여기서는 상품 행에 재고가 있는 상품만 다룬다.
        $where = ' WHERE ' . Stock::withoutOptionsWhere($this->store->table('yc_options'), 'p'); $params = [];
        if ($q !== '') { $where .= ' AND (p.name LIKE ? ESCAPE \'!\' OR p.code LIKE ? ESCAPE \'!\')'; $params = ['%' . self::like($q) . '%', '%' . self::like($q) . '%']; }
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->store->table('yc_products') . ' p' . $where, $params)['c'];
        $items = $this->store->select('SELECT p.id, p.code, p.name, p.stock, p.stock_alert, p.active, p.sold_out FROM ' . $this->store->table('yc_products') . ' p' . $where
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
                        'active' => Input::bool($input['active'] ?? '0'), 'sold_out' => Input::bool($input['sold_out'] ?? '0'), 'updated_at' => Clock::timestamp()]);
                } catch (DomainError $e) {
                    throw DomainError::validation(['row_' . $id => implode(' ', $e->details())]);
                }
                if ($stock !== (int) $old['stock']) $this->store->logStock($id, null, $stock - (int) $old['stock'], 'admin', 'stock', $actor);
            }
        });
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
        return ['products' => $this->store->select('SELECT p.id, p.code, p.name, p.stock, p.stock_alert FROM ' . $this->store->table('yc_products') . ' p WHERE p.stock_alert > 0 AND p.stock <= p.stock_alert AND '
                . Stock::withoutOptionsWhere($this->store->table('yc_options'), 'p') . ' ORDER BY p.stock ASC, p.id DESC LIMIT ' . $limit),
            'options' => $this->store->select('SELECT o.id, o.product_id, o.value1, o.value2, o.value3, o.stock, o.stock_alert, p.name FROM ' . $this->store->table('yc_options') . ' o JOIN ' . $this->store->table('yc_products')
                . ' p ON p.id = o.product_id WHERE o.stock_alert > 0 AND o.stock <= o.stock_alert ORDER BY o.stock ASC, o.id DESC LIMIT ' . $limit)];
    }

    public static function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
