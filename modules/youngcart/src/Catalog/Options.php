<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart\Catalog;

use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Input;
use GnuCms\Modules\YoungCart\Store;

final class Options
{
    public const MAX_GROUPS = 3;
    public const MAX_VALUES = 20;
    public const MAX_COMBOS = 1000;
    public const MAX_EXTRA_GROUPS = 10;
    public const MAX_EXTRA_ITEMS = 20;
    public const DEFAULT_STOCK = 9999;
    public const DEFAULT_ALERT = 100;

    public function __construct(private Store $store) {}

    /** @param list<list<string>> $groupValues @return list<array{0:string,1:string,2:string}> */
    public static function combine(array $groupValues): array
    {
        $groupValues = array_values(array_filter($groupValues, static fn (array $values): bool => $values !== []));
        if ($groupValues === []) return [];
        $rows = [[]];
        foreach ($groupValues as $values) {
            $next = [];
            foreach ($rows as $row) foreach ($values as $value) $next[] = [...$row, $value];
            $rows = $next;
        }
        return array_map(static fn (array $row): array => array_pad($row, 3, ''), $rows);
    }

    public static function draft(array $input, array $existing): array
    {
        $groups = [];
        $values = [];
        for ($i = 1; $i <= self::MAX_GROUPS; $i++) {
            $name = Input::text($input['option_group'][$i] ?? '', 'option_group', 100);
            $list = Input::csv($input['option_values'][$i] ?? '', self::MAX_VALUES, 100, 'option_values');
            if ($name === '' && $list === []) continue;
            if ($name === '' || $list === []) throw DomainError::validation(['option_group' => '옵션 이름과 값을 함께 입력해 주세요.']);
            if (count($groups) !== $i - 1) throw DomainError::validation(['option_group' => '옵션 그룹은 순서대로 채워 주세요.']);
            $groups[] = $name;
            $values[] = $list;
        }
        $combos = self::combine($values);
        if (count($combos) > self::MAX_COMBOS) throw DomainError::validation(['option_values' => '옵션 조합은 ' . self::MAX_COMBOS . '개까지 만들 수 있습니다.']);
        $known = [];
        foreach ($existing as $row) $known[self::key($row)] = $row;
        $rows = [];
        foreach ($combos as [$v1, $v2, $v3]) {
            $row = ['value1' => $v1, 'value2' => $v2, 'value3' => $v3];
            $old = $known[self::key($row)] ?? null;
            $rows[] = $row + ['price' => (int) ($old['price'] ?? 0), 'stock' => (int) ($old['stock'] ?? self::DEFAULT_STOCK),
                'stock_alert' => (int) ($old['stock_alert'] ?? self::DEFAULT_ALERT), 'active' => (int) ($old['active'] ?? 1)];
        }
        return ['groups' => $groups, 'rows' => $rows];
    }

    /** 폼의 `options[i][field]`·`extras[i][field]` 배열을 문자열 필드만 남겨 정규화한다. */
    public static function rows(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) continue;
            $clean = [];
            foreach (['value1', 'value2', 'value3', 'price', 'stock', 'stock_alert', 'active'] as $field) {
                $value = $row[$field] ?? '';
                $clean[$field] = is_string($value) || is_int($value) ? (string) $value : '';
            }
            $rows[] = $clean;
        }
        return $rows;
    }

    public function validate(int $productPrice, array $groups, array $rows, array $extraRows): array
    {
        $groups = array_values(array_filter(array_map(static fn ($g) => Input::text($g, 'option_group', 100), $groups), static fn (string $g): bool => $g !== ''));
        if (count($groups) > self::MAX_GROUPS) throw DomainError::validation(['option_group' => '선택옵션 그룹은 ' . self::MAX_GROUPS . '개까지입니다.']);
        $select = [];
        $seen = [];
        foreach ($rows as $row) {
            $values = [];
            for ($i = 1; $i <= 3; $i++) $values[] = Input::text($row['value' . $i] ?? '', 'options', 100);
            if ($values[0] === '') continue;
            foreach ($values as $value) if (preg_match('/[<>"\']/', $value)) throw DomainError::validation(['options' => '옵션 값에 <>"\' 문자를 쓸 수 없습니다.']);
            $filled = count(array_filter($values, static fn (string $v): bool => $v !== ''));
            if ($groups === [] || $filled !== count($groups)) throw DomainError::validation(['options' => '옵션 그룹 수와 조합 값의 수가 맞지 않습니다.']);
            $price = Input::int($row['price'] ?? '', 'options', -1000000000, 1000000000, 0);
            if ($productPrice + $price < 0) throw DomainError::validation(['options' => '판매가와 옵션 차액의 합은 0원 이상이어야 합니다.']);
            $normalized = ['value1' => $values[0], 'value2' => $values[1], 'value3' => $values[2], 'price' => $price,
                'stock' => Input::int($row['stock'] ?? '', 'options', 0, 1000000, self::DEFAULT_STOCK),
                'stock_alert' => Input::int($row['stock_alert'] ?? '', 'options', 0, 1000000, self::DEFAULT_ALERT),
                'active' => Input::bool(($row['active'] ?? '') === '' ? '1' : $row['active']), 'sort_order' => count($select)];
            $key = self::key($normalized);
            if (isset($seen[$key])) throw DomainError::validation(['options' => '중복된 옵션 조합이 있습니다: ' . implode('/', array_filter($values))]);
            $seen[$key] = true;
            $select[] = $normalized;
        }
        if ($groups !== [] && $select === []) throw DomainError::validation(['options' => '옵션 그룹을 지정했으면 조합을 하나 이상 만들어 주세요.']);
        for ($i = 1; $i <= 3; $i++) {
            $distinct = count(array_unique(array_filter(array_map(static fn (array $r): string => $r['value' . $i], $select))));
            if ($distinct > self::MAX_VALUES) throw DomainError::validation(['options' => '옵션 그룹당 값은 ' . self::MAX_VALUES . '개까지입니다.']);
        }
        if (count($select) > self::MAX_COMBOS) throw DomainError::validation(['options' => '옵션 조합은 ' . self::MAX_COMBOS . '개까지입니다.']);
        $extra = [];
        $extraGroups = [];
        $extraSeen = [];
        foreach ($extraRows as $row) {
            $group = Input::text($row['value1'] ?? '', 'extras', 100);
            $name = Input::text($row['value2'] ?? '', 'extras', 100);
            if ($group === '' && $name === '') continue;
            if ($group === '' || $name === '' || preg_match('/[<>"\']/', $group . $name)) throw DomainError::validation(['extras' => '추가옵션은 그룹명과 항목명을 함께 입력하고 <>"\' 문자를 쓸 수 없습니다.']);
            if (!in_array($group, $extraGroups, true)) $extraGroups[] = $group;
            $key = $group . "\x1e" . $name;
            if (isset($extraSeen[$key])) throw DomainError::validation(['extras' => '중복된 추가옵션이 있습니다: ' . $group . ' ' . $name]);
            $extraSeen[$key] = true;
            $extra[] = ['value1' => $group, 'value2' => $name, 'value3' => '', 'price' => Input::int($row['price'] ?? '', 'extras', 0, 1000000000, 0),
                'stock' => Input::int($row['stock'] ?? '', 'extras', 0, 1000000, self::DEFAULT_STOCK),
                'stock_alert' => Input::int($row['stock_alert'] ?? '', 'extras', 0, 1000000, self::DEFAULT_ALERT),
                'active' => Input::bool(($row['active'] ?? '') === '' ? '1' : $row['active']), 'sort_order' => count($extra)];
        }
        if (count($extraGroups) > self::MAX_EXTRA_GROUPS) throw DomainError::validation(['extras' => '추가옵션 그룹은 ' . self::MAX_EXTRA_GROUPS . '개까지입니다.']);
        foreach ($extraGroups as $group) {
            if (count(array_filter($extra, static fn (array $r): bool => $r['value1'] === $group)) > self::MAX_EXTRA_ITEMS) {
                throw DomainError::validation(['extras' => '추가옵션 그룹당 항목은 ' . self::MAX_EXTRA_ITEMS . '개까지입니다.']);
            }
        }
        return ['select_groups' => $groups, 'select' => $select, 'extra_groups' => $extraGroups, 'extra' => $extra];
    }

    /** 조합 키 기준 upsert. 제출되지 않은 기존 옵션은 삭제한다. 재고 차이는 원장에 기록한다. 트랜잭션 안에서 호출한다. */
    public function replace(int $productId, array $normalized, string $actor): void
    {
        foreach (['select', 'extra'] as $kind) {
            $this->store->delete('yc_option_groups', 'product_id = ? AND kind = ?', [$productId, $kind]);
            foreach ($normalized[$kind . '_groups'] as $position => $name) {
                $this->store->insert('yc_option_groups', ['product_id' => $productId, 'kind' => $kind, 'position' => $position + 1, 'name' => $name]);
            }
            $existing = [];
            foreach ($this->store->select('SELECT * FROM ' . $this->store->table('yc_options') . ' WHERE product_id = ? AND kind = ?', [$productId, $kind]) as $row) {
                $existing[self::key($row)] = $row;
            }
            foreach ($normalized[$kind] as $row) {
                $key = self::key($row);
                if (isset($existing[$key])) {
                    $old = $existing[$key];
                    unset($existing[$key]);
                    $this->store->update('yc_options', (int) $old['id'], ['price' => $row['price'], 'stock' => $row['stock'], 'stock_alert' => $row['stock_alert'], 'active' => $row['active'], 'sort_order' => $row['sort_order']]);
                    if ((int) $old['stock'] !== $row['stock']) $this->store->logStock($productId, (int) $old['id'], $row['stock'] - (int) $old['stock'], 'admin', 'option', $actor);
                } else {
                    $id = $this->store->insert('yc_options', ['product_id' => $productId, 'kind' => $kind] + $row);
                    if ($row['stock'] !== 0) $this->store->logStock($productId, $id, $row['stock'], 'admin', 'option', $actor);
                }
            }
            foreach ($existing as $old) {
                if ($this->store->selectOne('SELECT i.id FROM ' . $this->store->table('yc_order_items') . ' i JOIN ' . $this->store->table('yc_orders')
                    . " o ON o.id = i.order_id WHERE i.option_id = ? AND o.status IN ('pending', 'confirmed') LIMIT 1", [(int) $old['id']]) !== null) {
                    throw DomainError::validation(['options' => '처리 중인 주문에 포함된 옵션은 삭제할 수 없습니다. 사용 여부를 꺼 주세요.']);
                }
                if ((int) $old['stock'] !== 0) $this->store->logStock($productId, (int) $old['id'], -(int) $old['stock'], 'admin', 'option-removed', $actor);
                $this->store->delete('yc_options', 'id = ?', [(int) $old['id']]);
            }
        }
    }

    public function load(int $productId): array
    {
        $result = ['select_groups' => [], 'select' => [], 'extra_groups' => [], 'extra' => []];
        foreach ($this->store->select('SELECT * FROM ' . $this->store->table('yc_option_groups') . ' WHERE product_id = ? ORDER BY kind, position', [$productId]) as $row) {
            $result[$row['kind'] . '_groups'][] = $row['name'];
        }
        foreach ($this->store->select('SELECT * FROM ' . $this->store->table('yc_options') . ' WHERE product_id = ? ORDER BY kind, sort_order, id', [$productId]) as $row) {
            $result[$row['kind']][] = $row;
        }
        return $result;
    }

    public static function soldOut(array $product, array $selectRows): bool
    {
        if ((int) ($product['sold_out'] ?? 0) === 1) return true;
        if ($selectRows === []) return (int) ($product['stock'] ?? 0) <= 0;
        foreach ($selectRows as $row) {
            if ((int) ($row['active'] ?? 1) === 1 && (int) $row['stock'] > 0) return false;
        }
        return true;
    }

    public static function pageJson(array $product, array $loaded): array
    {
        $items = [];
        foreach ($loaded['select'] as $row) {
            $items[] = ['v' => array_values(array_filter([$row['value1'], $row['value2'], $row['value3']], static fn ($v) => $v !== '')),
                'price' => (int) $row['price'], 'stock' => (int) $row['active'] === 1 ? (int) $row['stock'] : 0];
        }
        $extraGroups = [];
        foreach ($loaded['extra_groups'] as $group) {
            $groupItems = [];
            foreach ($loaded['extra'] as $row) {
                if ($row['value1'] === $group) $groupItems[] = ['name' => $row['value2'], 'price' => (int) $row['price'], 'stock' => (int) $row['active'] === 1 ? (int) $row['stock'] : 0];
            }
            $extraGroups[] = ['name' => $group, 'items' => $groupItems];
        }
        return ['price' => (int) $product['price'], 'select' => ['groups' => $loaded['select_groups'], 'items' => $items], 'extra' => ['groups' => $extraGroups]];
    }

    public function stockList(string $q, int $page, int $perPage): array
    {
        $where = ''; $params = [];
        if ($q !== '') { $where = ' WHERE (p.name LIKE ? ESCAPE \'!\' OR p.code LIKE ? ESCAPE \'!\')'; $params = ['%' . Products::like($q) . '%', '%' . Products::like($q) . '%']; }
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->store->table('yc_options') . ' o JOIN ' . $this->store->table('yc_products') . ' p ON p.id = o.product_id' . $where, $params)['c'];
        $rows = $this->store->select('SELECT o.*, p.name AS product_name, p.code AS product_code FROM ' . $this->store->table('yc_options') . ' o JOIN ' . $this->store->table('yc_products')
            . ' p ON p.id = o.product_id' . $where . ' ORDER BY o.stock ASC, o.id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'total_pages' => max(1, (int) ceil($total / $perPage))];
    }

    public function updateStock(array $rows, string $actor): void
    {
        $this->store->transaction(function () use ($rows, $actor): void {
            foreach ($rows as $id => $input) {
                $id = Input::id($id);
                $initial = $this->store->get('yc_options', $id);
                $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1 WHERE id = ?', [(int) $initial['product_id']]);
                $old = $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_options') . ' WHERE id = ?'
                    . ($this->store->db->dialect()->name() === 'mysql' ? ' FOR UPDATE' : ''), [$id])
                    ?? throw DomainError::notFound('옵션을 찾을 수 없습니다.');
                try {
                    $this->store->assertStockUnchanged($input, $old);
                    $stock = Input::int($input['stock'] ?? '', 'stock', 0, 1000000);
                    $this->store->update('yc_options', $id, ['stock' => $stock, 'stock_alert' => Input::int($input['stock_alert'] ?? '', 'stock_alert', 0, 1000000, 0), 'active' => Input::bool($input['active'] ?? '0')]);
                } catch (DomainError $e) {
                    throw DomainError::validation(['row_' . $id => implode(' ', $e->details())]);
                }
                if ($stock !== (int) $old['stock']) $this->store->logStock((int) $old['product_id'], $id, $stock - (int) $old['stock'], 'admin', 'option-stock', $actor);
            }
        });
    }

    private static function key(array $row): string
    {
        return ($row['value1'] ?? '') . "\x1e" . ($row['value2'] ?? '') . "\x1e" . ($row['value3'] ?? '');
    }
}
