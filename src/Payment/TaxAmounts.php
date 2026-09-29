<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** 결제사와 무관한 부가세 포함 금액 명세. 모든 금액은 정수 원이다. */
final class TaxAmounts
{
    /** 상품별 면세 표시를 합산한다. 선불 배송비는 과세 거래로 본다. */
    public static function calculate(array $items, int $shippingFee): array
    {
        if ($shippingFee < 0) throw DomainError::validation(['tax' => '배송비의 세금 계산 금액을 확인해 주세요.']);
        $taxable = $shippingFee;
        $taxFree = 0;
        foreach ($items as $item) {
            $amount = (int) ($item['total'] ?? 0);
            if ($amount < 0) throw DomainError::validation(['tax' => '상품의 세금 계산 금액을 확인해 주세요.']);
            if ((int) ($item['tax_free'] ?? 0) === 1) $taxFree += $amount;
            else $taxable += $amount;
        }
        return self::split($taxable, $taxFree);
    }

    /** 저장 주문 또는 결제용 표준 주문 배열에서 세금 명세를 읽고 검증한다. */
    public static function fromOrder(array $order): array
    {
        $total = (int) ($order['total'] ?? 0);
        if (is_array($order['tax'] ?? null)) $tax = $order['tax'];
        else {
            $stored = array_sum(array_map(static fn (string $key): int => (int) ($order[$key] ?? 0),
                ['taxable_amount', 'supply_amount', 'vat_amount', 'tax_free_amount']));
            $tax = $stored === 0 && $total > 0 ? self::split($total, 0) : [
                'total_amount' => $total,
                'taxable_amount' => (int) ($order['taxable_amount'] ?? 0),
                'supply_amount' => (int) ($order['supply_amount'] ?? 0),
                'vat_amount' => (int) ($order['vat_amount'] ?? 0),
                'tax_free_amount' => (int) ($order['tax_free_amount'] ?? 0),
            ];
        }
        return self::validate($tax, $total);
    }

    /** 임의 금액 부분 취소에서 남은 과세·면세 비율을 보존한다. */
    public static function refund(array $order, array $state, int $amount, int $remaining): array
    {
        $original = self::fromOrder($order);
        if ($amount < 1 || $remaining < $amount || $remaining > $original['total_amount']) {
            throw DomainError::validation(['tax' => '취소할 세금 금액을 확인해 주세요.']);
        }
        $knownAmount = 0;
        $knownSupply = 0;
        $knownVat = 0;
        $knownTaxFree = 0;
        $knownTaxComplete = true;
        foreach ($state['refunds'] ?? [] as $refund) {
            if (($refund['status'] ?? '') !== 'succeeded') continue;
            $knownAmount += (int) ($refund['result']['amount'] ?? 0);
            if (!is_array($refund['result']['tax'] ?? null)) $knownTaxComplete = false;
            $knownSupply += (int) ($refund['result']['tax']['supply_amount'] ?? 0);
            $knownVat += (int) ($refund['result']['tax']['vat_amount'] ?? 0);
            $knownTaxFree += (int) ($refund['result']['tax']['tax_free_amount'] ?? 0);
        }
        $alreadyRefunded = $original['total_amount'] - $remaining;
        if ($knownTaxComplete && $knownAmount === $alreadyRefunded) {
            $remainingSupply = $original['supply_amount'] - $knownSupply;
            $remainingVat = $original['vat_amount'] - $knownVat;
            $remainingTaxFree = $original['tax_free_amount'] - $knownTaxFree;
        } else {
            $remainingTaxFree = intdiv($original['tax_free_amount'] * $remaining, max(1, $original['total_amount']));
            $remainingTaxable = $remaining - $remainingTaxFree;
            $remainingSupply = $original['taxable_amount'] > 0
                ? intdiv($original['supply_amount'] * $remainingTaxable, $original['taxable_amount']) : 0;
            $remainingVat = $remainingTaxable - $remainingSupply;
        }
        if ($amount === $remaining) return self::validate([
            'total_amount' => $amount, 'taxable_amount' => $remainingSupply + $remainingVat,
            'supply_amount' => $remainingSupply, 'vat_amount' => $remainingVat, 'tax_free_amount' => $remainingTaxFree,
        ], $amount);
        $after = $remaining - $amount;
        $afterTaxFree = intdiv($remainingTaxFree * $after, $remaining);
        $afterTaxable = $after - $afterTaxFree;
        $remainingTaxable = $remainingSupply + $remainingVat;
        $afterSupply = $remainingTaxable > 0 ? intdiv($remainingSupply * $afterTaxable, $remainingTaxable) : 0;
        $afterVat = $afterTaxable - $afterSupply;
        return self::validate([
            'total_amount' => $amount,
            'taxable_amount' => ($remainingSupply - $afterSupply) + ($remainingVat - $afterVat),
            'supply_amount' => $remainingSupply - $afterSupply,
            'vat_amount' => $remainingVat - $afterVat,
            'tax_free_amount' => $remainingTaxFree - $afterTaxFree,
        ], $amount);
    }

    public static function split(int $taxable, int $taxFree): array
    {
        if ($taxable < 0 || $taxFree < 0) throw DomainError::validation(['tax' => '과세·면세 금액을 확인해 주세요.']);
        $supply = intdiv($taxable * 10, 11);
        return ['total_amount' => $taxable + $taxFree, 'taxable_amount' => $taxable,
            'supply_amount' => $supply, 'vat_amount' => $taxable - $supply, 'tax_free_amount' => $taxFree];
    }

    private static function validate(array $tax, int $total): array
    {
        $values = [];
        foreach (['total_amount', 'taxable_amount', 'supply_amount', 'vat_amount', 'tax_free_amount'] as $key) {
            $value = $tax[$key] ?? null;
            if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
                throw DomainError::validation(['tax' => '결제 세금 명세를 확인해 주세요.']);
            }
            $values[$key] = (int) $value;
            if ($values[$key] < 0) throw DomainError::validation(['tax' => '결제 세금 명세를 확인해 주세요.']);
        }
        if ($values['total_amount'] !== $total
            || $values['taxable_amount'] !== $values['supply_amount'] + $values['vat_amount']
            || $total !== $values['taxable_amount'] + $values['tax_free_amount']) {
            throw DomainError::validation(['tax' => '결제 총액과 과세·면세 금액이 일치하지 않습니다.']);
        }
        return $values;
    }
}
