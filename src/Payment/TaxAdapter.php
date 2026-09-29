<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** GNUCMS 표준 세금 명세를 결제대행사별 필드로 바꾸는 단일 어댑터. */
final class TaxAdapter
{
    public static function checkoutFields(string $provider, array $order): array
    {
        $tax = TaxAmounts::fromOrder($order);
        if ($tax['tax_free_amount'] === 0) return [];
        return match ($provider) {
            'inicis' => ['P_TAX' => (string) $tax['vat_amount'], 'P_TAXFREE' => (string) $tax['tax_free_amount']],
            'kcp', 'kcp_legacy' => self::kcp($tax, false),
            'toss' => ['taxFreeAmount' => $tax['tax_free_amount']],
            'nicepay' => ['taxFreeAmt' => $tax['tax_free_amount']],
            default => throw DomainError::validation(['tax' => '이 결제대행사의 복합과세 변환 규칙이 없습니다.']),
        };
    }

    public static function refundFields(string $provider, array $tax, array $order): array
    {
        if (TaxAmounts::fromOrder($order)['tax_free_amount'] === 0) return [];
        $tax = TaxAmounts::fromOrder(['total' => (int) ($tax['total_amount'] ?? 0), 'tax' => $tax]);
        return match ($provider) {
            'inicis' => ['tax' => (string) $tax['vat_amount'], 'taxFree' => (string) $tax['tax_free_amount']],
            'kcp' => self::kcp($tax, true),
            'toss' => ['taxFreeAmount' => $tax['tax_free_amount']],
            'nicepay' => ['taxFreeAmt' => $tax['tax_free_amount']],
            'kcp_legacy' => [],
            default => throw DomainError::validation(['tax' => '이 결제대행사의 취소 세금 변환 규칙이 없습니다.']),
        };
    }

    private static function kcp(array $tax, bool $refund): array
    {
        $prefix = $refund ? 'mod_' : 'comm_';
        return ['tax_flag' => 'TG03'] + [
            $prefix . 'tax_mny' => (string) $tax['supply_amount'],
            $prefix . 'free_mny' => (string) $tax['tax_free_amount'],
            $prefix . 'vat_mny' => (string) $tax['vat_amount'],
        ];
    }
}
