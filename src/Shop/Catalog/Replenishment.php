<?php

declare(strict_types=1);

namespace GnuCms\Shop\Catalog;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Input;
use GnuCms\Shop\Store;
use GnuCms\Support\Clock;

final class Replenishment
{
    public function __construct(private Store $store) {}

    public function add(array $rows, bool $options, string $actor, string $token): int
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) throw DomainError::validation(['stock' => '재고 화면을 새로고침해 주세요.']);
        if (count($rows) > 60) throw DomainError::validation(['stock' => '한 번에 최대 60개 항목을 보충할 수 있습니다.']);
        $changes = [];
        foreach ($rows as $id => $input) {
            if (!is_array($input)) throw DomainError::validation(['stock' => '보충 수량을 확인해 주세요.']);
            $amount = Input::int($input['addition'] ?? '', 'addition', 0, 1000000, 0);
            if ($amount === 0) continue;
            $id = Input::id($id);
            $row = $this->store->get($options ? 'yc_options' : 'yc_products', $id);
            $changes[] = ['id' => $id, 'product_id' => $options ? (int) $row['product_id'] : $id, 'amount' => $amount];
        }
        if ($changes === []) throw DomainError::validation(['stock' => '보충할 수량을 하나 이상 입력해 주세요.']);
        usort($changes, static fn (array $a, array $b): int => [$a['product_id'], $a['id']] <=> [$b['product_id'], $b['id']]);
        return $this->store->transaction(function () use ($changes, $options, $actor, $token): int {
            $productIds = array_values(array_unique(array_column($changes, 'product_id')));
            foreach ($productIds as $id) {
                if ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_products') . ' WHERE id = ? FOR UPDATE', [$id]) === null)
                    throw DomainError::validation(['stock' => '상품이 변경되었습니다. 새로고침해 주세요.']);
            }
            $reference = 'restock:' . $token;
            foreach ($productIds as $id) {
                if ($this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_stock_log') . ' WHERE product_id = ? AND kind = ? AND reference = ? LIMIT 1', [$id, 'restock', $reference]) !== null) return 0;
            }
            foreach ($changes as $change) {
                $table = $options ? 'yc_options' : 'yc_products';
                $row = $this->store->selectOne('SELECT * FROM ' . $this->store->table($table) . ' WHERE id = ? FOR UPDATE', [$change['id']]);
                if ($row === null || ($options && (int) $row['product_id'] !== $change['product_id']))
                    throw DomainError::validation(['stock' => '재고 항목이 변경되었습니다. 새로고침해 주세요.']);
                if (!$options && $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_options') . " WHERE product_id = ? AND kind = 'select' LIMIT 1", [$change['product_id']]) !== null)
                    throw DomainError::validation(['stock' => '선택옵션이 있는 상품은 옵션 재고에서 보충해 주세요.']);
                if ((int) $row['stock'] + $change['amount'] > 1000000)
                    throw DomainError::validation(['row_' . $change['id'] => '재고는 1,000,000개까지입니다.']);
                $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1, updated_at = ? WHERE id = ?', [Clock::timestamp(), $change['product_id']]);
                $this->store->execute('UPDATE ' . $this->store->table($table) . ' SET stock = stock + ? WHERE id = ?', [$change['amount'], $change['id']]);
                $this->store->logStock($change['product_id'], $options ? $change['id'] : null, $change['amount'], 'restock', $reference, $actor);
            }
            return count($changes);
        });
    }

    /** Recent quantity changes only; do not collect supplier or customer details. */
    public function recent(string $search, bool $options): array
    {
        $where = [$options ? 'l.option_id IS NOT NULL' : 'l.option_id IS NULL']; $params = [];
        if ($search !== '') {
            $like = '%' . Products::like($search) . '%';
            $where[] = '(p.name LIKE ? ESCAPE \'!\' OR p.code LIKE ? ESCAPE \'!\')'; $params = [$like, $like];
        }
        return $this->store->select('SELECT l.delta, l.kind, l.created_at, p.name, o.value1, o.value2, o.value3 FROM '
            . $this->store->table('yc_stock_log') . ' l JOIN ' . $this->store->table('yc_products') . ' p ON p.id = l.product_id LEFT JOIN '
            . $this->store->table('yc_options') . ' o ON o.id = l.option_id WHERE ' . implode(' AND ', $where) . ' ORDER BY l.id DESC LIMIT 20', $params);
    }
}
