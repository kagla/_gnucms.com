<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Products;
use GnuCms\Shop\Fulfillment\CarrierAdapter;
use GnuCms\Shop\Settings;
use GnuCms\Shop\Store;

final class Fulfillment
{
    public function __construct(private Store $store, private Orders $orders, private CarrierAdapter $adapter) {}

    public function adapter(): CarrierAdapter { return $this->adapter; }

    public function ready(string $search, int $page): array
    {
        $where = ['o.status = ?']; $params = ['confirmed'];
        if ($search !== '') {
            $like = '%' . Products::like($search) . '%';
            $where[] = '(o.number LIKE ? ESCAPE \'!\' OR o.buyer_name LIKE ? ESCAPE \'!\' OR o.recipient LIKE ? ESCAPE \'!\''
                . ' OR EXISTS (SELECT 1 FROM ' . $this->store->table('yc_order_items') . ' si WHERE si.order_id = o.id AND si.product_name LIKE ? ESCAPE \'!\'))';
            array_push($params, $like, $like, $like, $like);
        }
        $from = ' FROM ' . $this->store->table('yc_orders') . ' o WHERE ' . implode(' AND ', $where);
        $total = (int) ($this->store->selectOne('SELECT COUNT(*) AS c' . $from, $params)['c'] ?? 0);
        $pageSize = 30; $page = max(1, min(100000, $page));
        $items = $this->store->select('SELECT o.*' . $from . ' ORDER BY o.id LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize), $params);
        $this->attachItemSummary($items);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'total_pages' => max(1, (int) ceil($total / $pageSize))];
    }

    /** @return list<int> */
    public function selectedIds(mixed $value): array
    {
        if (!is_array($value)) throw DomainError::validation(['orders' => '처리할 주문을 선택해 주세요.']);
        $ids = [];
        foreach ($value as $id) {
            if ((!is_string($id) && !is_int($id)) || preg_match('/^[1-9][0-9]{0,15}$/D', (string) $id) !== 1) {
                throw DomainError::validation(['orders' => '선택한 주문을 확인해 주세요.']);
            }
            $ids[(int) $id] = (int) $id;
        }
        if ($ids === [] || count($ids) > 500) throw DomainError::validation(['orders' => '주문은 한 번에 1~500건을 선택해 주세요.']);
        return array_values($ids);
    }

    public function selectedOrders(array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->store->select('SELECT * FROM ' . $this->store->table('yc_orders') . ' WHERE id IN (' . $marks . ')', $ids);
        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['id']] = $row;
        $orders = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id]) || $byId[$id]['status'] !== 'confirmed') {
                throw DomainError::validation(['orders' => '상품 준비 상태가 아닌 주문이 포함되어 있습니다. 목록을 새로고침해 주세요.']);
            }
            $orders[] = $byId[$id];
        }
        $this->attachItemSummary($orders, true);
        return $orders;
    }

    public function export(array $ids): string { return $this->adapter->export($this->selectedOrders($ids)); }

    public function importAndShip(string $contents, string $actor): int
    {
        $rows = $this->adapter->import($contents);
        $carriers = Settings::carriers();
        foreach ($rows as $row) {
            if (!isset($carriers[$row['carrier']])) {
                throw DomainError::validation(['file' => $row['carrier'] . '은(는) 설정된 택배사가 아닙니다. 택배사 목록 또는 CSV 값을 확인해 주세요.']);
            }
        }
        return $this->store->transaction(function () use ($rows, $actor): int {
            foreach ($rows as $row) {
                $order = $this->store->selectOne('SELECT id, status FROM ' . $this->store->table('yc_orders') . ' WHERE number = ? FOR UPDATE', [$row['number']]);
                if ($order === null) throw DomainError::validation(['file' => $row['number'] . ' 주문을 찾을 수 없습니다.']);
                if ($order['status'] !== 'confirmed') throw DomainError::validation(['file' => $row['number'] . ' 주문은 상품 준비 상태가 아닙니다.']);
                $this->orders->transition((int) $order['id'], 'confirmed', 'shipped', $actor, [
                    'carrier' => $row['carrier'], 'tracking_number' => $row['tracking_number'],
                    'note' => $this->adapter->label() . '로 배송 처리',
                ]);
            }
            return count($rows);
        });
    }

    private function attachItemSummary(array &$orders, bool $withItems = false): void
    {
        if ($orders === []) return;
        $ids = array_map(static fn (array $order): int => (int) $order['id'], $orders);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $lines = $this->store->select('SELECT * FROM ' . $this->store->table('yc_order_items') . ' WHERE order_id IN (' . $marks . ') ORDER BY id', $ids);
        $byOrder = [];
        foreach ($lines as $line) {
            $name = (string) $line['product_name'];
            if ((string) $line['option_label'] !== '') $name .= ' · ' . $line['option_label'];
            $line['summary'] = $name . ' × ' . (int) $line['quantity'];
            $byOrder[(int) $line['order_id']][] = $line;
        }
        foreach ($orders as &$order) {
            $orderLines = $byOrder[(int) $order['id']] ?? [];
            $order['item_summary'] = array_column($orderLines, 'summary');
            if ($withItems) $order['items'] = $orderLines;
        }
        unset($order);
    }
}
