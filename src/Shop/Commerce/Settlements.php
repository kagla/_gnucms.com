<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use GnuCms\Error\DomainError;
use GnuCms\Payment\SettlementAdapter;
use GnuCms\Shop\Store;
use GnuCms\Support\Clock;

final class Settlements
{
    public function __construct(private Store $store, private SettlementAdapter $adapter) {}

    public function adapter(): SettlementAdapter { return $this->adapter; }

    public function import(string $contents): array
    {
        $rows = $this->adapter->parse($contents);
        return $this->store->transaction(function () use ($rows): array {
            $inserted = 0; $skipped = 0;
            foreach ($rows as $row) {
                $hash = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $existing = $this->store->selectOne('SELECT raw_hash FROM ' . $this->store->table('yc_settlements')
                    . ' WHERE provider = ? AND environment = ? AND merchant_id = ? AND transaction_key = ? FOR UPDATE',
                    [$row['provider'], $row['environment'], $row['merchant_id'], $row['transaction_key']]);
                if ($existing !== null) {
                    if (!hash_equals((string) $existing['raw_hash'], $hash)) throw DomainError::validation(['file' => $row['transaction_key'] . ' 거래는 이미 다른 내용으로 등록되어 있습니다.']);
                    $skipped++; continue;
                }
                $order = $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_orders')
                    . ' WHERE (payment_id = ? OR number = ?) AND payment_provider = ? AND payment_environment = ? '
                    . 'ORDER BY (payment_id = ?) DESC LIMIT 1', [$row['payment_id'], $row['payment_id'], $row['provider'], $row['environment'], $row['payment_id']]);
                $this->store->insert('yc_settlements', array_replace($row, ['order_id' => $order === null ? null : (int) $order['id'],
                    'source' => $this->adapter->id(), 'raw_hash' => $hash, 'imported_at' => Clock::timestamp(),
                    'payout_date' => $row['payout_date'] === '' ? null : $row['payout_date']]));
                $inserted++;
            }
            return ['inserted' => $inserted, 'skipped' => $skipped];
        });
    }

    public function reconcile(array $range, string $provider = '', string $status = ''): array
    {
        $orders = $this->store->table('yc_orders'); $settlements = $this->store->table('yc_settlements');
        $providerWhere = $provider === '' ? '' : ' AND o.payment_provider = ?';
        $params = [$range['start'], $range['end']];
        if ($provider !== '') $params[] = $provider;
        $rows = $this->store->select('SELECT o.id AS order_id, o.number, o.payment_id, o.payment_provider AS provider, '
            . 'o.payment_environment AS environment, o.paid_amount, o.refunded_amount, o.paid_at, '
            . 'COALESCE(SUM(s.amount),0) AS settlement_amount, COALESCE(SUM(s.fee_supply),0) AS fee_supply, '
            . 'COALESCE(SUM(s.fee_vat),0) AS fee_vat, COALESCE(SUM(s.payout_amount),0) AS payout_amount, '
            . 'MIN(s.sold_date) AS sold_date, MAX(s.payout_date) AS payout_date, COUNT(s.id) AS transaction_count '
            . 'FROM ' . $orders . ' o LEFT JOIN ' . $settlements . ' s ON s.order_id = o.id '
            . "WHERE o.paid_at >= ? AND o.paid_at < ? AND o.payment_provider <> ''" . $providerWhere
            . ' GROUP BY o.id ORDER BY o.paid_at DESC LIMIT 1000', $params);
        $orphanParams = [$range['from'], $range['to']];
        $orphanWhere = $provider === '' ? '' : ' AND s.provider = ?';
        if ($provider !== '') $orphanParams[] = $provider;
        $orphans = $this->store->select('SELECT NULL AS order_id, \'\' AS number, s.payment_id, s.provider, s.environment, '
            . '0 AS paid_amount, 0 AS refunded_amount, 0 AS paid_at, SUM(s.amount) AS settlement_amount, '
            . 'SUM(s.fee_supply) AS fee_supply, SUM(s.fee_vat) AS fee_vat, SUM(s.payout_amount) AS payout_amount, '
            . 'MIN(s.sold_date) AS sold_date, MAX(s.payout_date) AS payout_date, COUNT(*) AS transaction_count '
            . 'FROM ' . $settlements . ' s WHERE s.order_id IS NULL AND s.sold_date >= ? AND s.sold_date <= ?' . $orphanWhere
            . ' GROUP BY s.provider, s.environment, s.payment_id ORDER BY sold_date DESC LIMIT 1000', $orphanParams);
        $rows = array_merge($rows, $orphans);
        $counts = ['matched' => 0, 'missing' => 0, 'mismatch' => 0, 'orphan' => 0];
        foreach ($rows as &$row) {
            foreach (['paid_amount', 'refunded_amount', 'settlement_amount', 'fee_supply', 'fee_vat', 'payout_amount', 'transaction_count'] as $key) $row[$key] = (int) $row[$key];
            $row['expected_amount'] = $row['paid_amount'] - $row['refunded_amount'];
            $row['status'] = $row['order_id'] === null ? 'orphan' : ($row['transaction_count'] === 0 ? 'missing'
                : ($row['settlement_amount'] === $row['expected_amount'] ? 'matched' : 'mismatch'));
            $counts[$row['status']]++;
        }
        unset($row);
        if (isset($counts[$status])) $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === $status));
        return ['items' => $rows, 'counts' => $counts, 'total' => count($rows)];
    }
}
