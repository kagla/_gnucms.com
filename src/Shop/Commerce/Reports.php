<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use GnuCms\Error\DomainError;
use GnuCms\Shop\Store;

final class Reports
{
    private const ZONE = 'Asia/Seoul';

    public function __construct(private Store $store) {}

    public function range(mixed $from, mixed $to, mixed $group = 'day'): array
    {
        $zone = new DateTimeZone(self::ZONE);
        $today = (new DateTimeImmutable('now', $zone))->setTime(0, 0);
        $toDate = $this->date($to, $today);
        $fromDate = $this->date($from, $toDate->modify('-29 days'));
        if ($fromDate > $toDate) throw DomainError::validation(['date' => '조회 시작일은 종료일보다 늦을 수 없습니다.']);
        if ($fromDate < $toDate->modify('-3 years')) throw DomainError::validation(['date' => '한 번에 최대 3년까지 조회할 수 있습니다.']);
        $unit = $group === 'month' ? 'month' : 'day';
        return ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d'), 'group' => $unit,
            'start' => $fromDate->getTimestamp(), 'end' => $toDate->modify('+1 day')->getTimestamp()];
    }

    public function sales(array $range): array
    {
        $orders = $this->store->table('yc_orders'); $items = $this->store->table('yc_order_items');
        $refunds = $this->store->table('yc_order_refunds'); $products = $this->store->table('yc_products');
        $categories = $this->store->table('yc_categories');
        $paid = $this->store->selectOne('SELECT COUNT(*) AS order_count, COALESCE(SUM(paid_amount),0) AS paid_amount, '
            . 'COALESCE(SUM(taxable_amount),0) AS taxable_amount, COALESCE(SUM(supply_amount),0) AS supply_amount, '
            . 'COALESCE(SUM(vat_amount),0) AS vat_amount, COALESCE(SUM(tax_free_amount),0) AS tax_free_amount, '
            . 'COALESCE(SUM(shipping_fee),0) AS shipping_fee FROM ' . $orders . ' WHERE paid_at >= ? AND paid_at < ?',
            [$range['start'], $range['end']]) ?? [];
        $returned = $this->store->selectOne('SELECT COUNT(*) AS refund_count, COALESCE(SUM(amount),0) AS refunded_amount, '
            . 'COALESCE(SUM(taxable_amount),0) AS taxable_amount, COALESCE(SUM(supply_amount),0) AS supply_amount, '
            . 'COALESCE(SUM(vat_amount),0) AS vat_amount, COALESCE(SUM(tax_free_amount),0) AS tax_free_amount '
            . 'FROM ' . $refunds . ' WHERE created_at >= ? AND created_at < ?', [$range['start'], $range['end']]) ?? [];
        $summary = [];
        foreach (['order_count', 'paid_amount', 'taxable_amount', 'supply_amount', 'vat_amount', 'tax_free_amount', 'shipping_fee'] as $key) {
            $summary[$key] = (int) ($paid[$key] ?? 0);
        }
        foreach (['refund_count', 'refunded_amount'] as $key) $summary[$key] = (int) ($returned[$key] ?? 0);
        foreach (['taxable_amount', 'supply_amount', 'vat_amount', 'tax_free_amount'] as $key) {
            $summary['refunded_' . $key] = (int) ($returned[$key] ?? 0);
            $summary['net_' . $key] = $summary[$key] - (int) ($returned[$key] ?? 0);
        }
        $summary['net_amount'] = $summary['paid_amount'] - $summary['refunded_amount'];

        $format = $range['group'] === 'month' ? '%Y-%m' : '%Y-%m-%d';
        $paidRows = $this->store->select("SELECT DATE_FORMAT(FROM_UNIXTIME(paid_at + 32400), '{$format}') AS period, "
            . 'COUNT(*) AS order_count, SUM(paid_amount) AS paid_amount, SUM(taxable_amount) AS taxable_amount, '
            . 'SUM(vat_amount) AS vat_amount, SUM(tax_free_amount) AS tax_free_amount, SUM(shipping_fee) AS shipping_fee '
            . 'FROM ' . $orders . ' WHERE paid_at >= ? AND paid_at < ? GROUP BY period ORDER BY period', [$range['start'], $range['end']]);
        $refundRows = $this->store->select("SELECT DATE_FORMAT(FROM_UNIXTIME(created_at + 32400), '{$format}') AS period, "
            . 'COUNT(*) AS refund_count, SUM(amount) AS refunded_amount, SUM(taxable_amount) AS refunded_taxable_amount, '
            . 'SUM(vat_amount) AS refunded_vat_amount, SUM(tax_free_amount) AS refunded_tax_free_amount '
            . 'FROM ' . $refunds . ' WHERE created_at >= ? AND created_at < ? GROUP BY period ORDER BY period', [$range['start'], $range['end']]);
        $periods = [];
        foreach ($paidRows as $row) $periods[$row['period']] = $this->integerRow($row);
        foreach ($refundRows as $row) $periods[$row['period']] = array_replace($periods[$row['period']] ?? ['period' => $row['period']], $this->integerRow($row));
        foreach ($periods as &$row) {
            foreach (['order_count', 'paid_amount', 'taxable_amount', 'vat_amount', 'tax_free_amount', 'shipping_fee', 'refund_count', 'refunded_amount',
                'refunded_taxable_amount', 'refunded_vat_amount', 'refunded_tax_free_amount'] as $key) $row[$key] = (int) ($row[$key] ?? 0);
            $row['net_amount'] = $row['paid_amount'] - $row['refunded_amount'];
        }
        unset($row); ksort($periods);

        $productRows = $this->store->select('SELECT i.product_id, i.product_code, i.product_name, SUM(i.quantity) AS quantity, SUM(i.total) AS amount '
            . 'FROM ' . $items . ' i INNER JOIN ' . $orders . ' o ON o.id = i.order_id '
            . 'WHERE o.paid_at >= ? AND o.paid_at < ? GROUP BY i.product_id, i.product_code, i.product_name ORDER BY amount DESC LIMIT 200',
            [$range['start'], $range['end']]);
        $categoryRows = $this->store->select("SELECT COALESCE(c.name, '분류 없음') AS category_name, SUM(i.quantity) AS quantity, SUM(i.total) AS amount "
            . 'FROM ' . $items . ' i INNER JOIN ' . $orders . ' o ON o.id = i.order_id LEFT JOIN ' . $products . ' p ON p.id = i.product_id '
            . 'LEFT JOIN ' . $categories . ' c ON c.id = p.category_id WHERE o.paid_at >= ? AND o.paid_at < ? '
            . 'GROUP BY c.id, c.name ORDER BY amount DESC LIMIT 200', [$range['start'], $range['end']]);
        $paymentRows = $this->store->select("SELECT CASE WHEN payment_provider = '' THEN 'manual' ELSE payment_provider END AS provider, payment_method, "
            . 'COUNT(*) AS order_count, SUM(paid_amount) AS amount FROM ' . $orders . ' WHERE paid_at >= ? AND paid_at < ? '
            . 'GROUP BY provider, payment_method ORDER BY amount DESC', [$range['start'], $range['end']]);
        return ['range' => $range, 'summary' => $summary, 'periods' => array_values($periods),
            'products' => array_map($this->integerRow(...), $productRows), 'categories' => array_map($this->integerRow(...), $categoryRows),
            'payments' => array_map($this->integerRow(...), $paymentRows)];
    }

    public function csv(array $report): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw DomainError::internal('CSV를 만들 수 없습니다.');
        fwrite($stream, "\xEF\xBB\xBF");
        $write = static fn (array $row): int|false => fputcsv($stream, array_map(self::safeCsvCell(...), $row), ',', '"', '');
        $write(['매출 집계', $report['range']['from'] . ' ~ ' . $report['range']['to']]);
        $write(['결제액', '환불액', '순매출', '과세 공급가', '부가세', '면세액', '배송비', '결제 주문', '환불 건']);
        $s = $report['summary'];
        $write([$s['paid_amount'], $s['refunded_amount'], $s['net_amount'], $s['net_supply_amount'], $s['net_vat_amount'],
            $s['net_tax_free_amount'], $s['shipping_fee'], $s['order_count'], $s['refund_count']]);
        $write([]); $write(['기간', '주문', '결제액', '환불', '순매출', '과세액', '부가세', '면세액', '배송비']);
        foreach ($report['periods'] as $row) $write([$row['period'], $row['order_count'], $row['paid_amount'], $row['refunded_amount'], $row['net_amount'],
            $row['taxable_amount'] - $row['refunded_taxable_amount'], $row['vat_amount'] - $row['refunded_vat_amount'],
            $row['tax_free_amount'] - $row['refunded_tax_free_amount'], $row['shipping_fee']]);
        $write([]); $write(['상품코드', '상품명', '수량', '매출']);
        foreach ($report['products'] as $row) $write([$row['product_code'], $row['product_name'], $row['quantity'], $row['amount']]);
        $write([]); $write(['현재 대표 분류', '수량', '매출']);
        foreach ($report['categories'] as $row) $write([$row['category_name'], $row['quantity'], $row['amount']]);
        rewind($stream); $contents = stream_get_contents($stream); fclose($stream);
        if ($contents === false) throw DomainError::internal('CSV를 만들 수 없습니다.');
        return $contents;
    }

    private function date(mixed $value, DateTimeImmutable $default): DateTimeImmutable
    {
        if ($value === null || $value === '') return $default;
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) throw DomainError::validation(['date' => '날짜 형식을 확인해 주세요.']);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::ZONE));
        if ($date === false || $date->format('Y-m-d') !== $value) throw DomainError::validation(['date' => '날짜를 확인해 주세요.']);
        return $date;
    }

    private function integerRow(array $row): array
    {
        $numeric = ['product_id', 'quantity', 'amount', 'order_count', 'paid_amount', 'taxable_amount', 'vat_amount',
            'tax_free_amount', 'shipping_fee', 'refund_count', 'refunded_amount', 'refunded_taxable_amount',
            'refunded_vat_amount', 'refunded_tax_free_amount'];
        foreach ($numeric as $key) if (array_key_exists($key, $row)) $row[$key] = (int) $row[$key];
        return $row;
    }

    private static function safeCsvCell(mixed $value): string|int
    {
        if (is_int($value)) return $value;
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'" . $value : $value;
    }
}
