<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

/** 알리고가 반환한 접수 비용만 보관한다. 단가 계산이나 최종 정산액 추정은 하지 않는다. */
final class ProviderCost
{
    private const SCALE = 1000000;

    public static function fromInfo(array $info): ?array
    {
        $total = self::decimal($info['total'] ?? null);
        if ($total === null) {
            return null;
        }

        return ['total' => $total, 'unit' => self::decimal($info['unit'] ?? null)];
    }

    /** 부동소수점으로 합산하지 않도록 최대 소수 여섯 자리의 십진 문자열로 보관한다. */
    private static function decimal(mixed $value): ?string
    {
        if (is_float($value)) {
            if (!is_finite($value) || $value < 0 || $value > 999999999) return null;
            $value = sprintf('%.6F', $value);
        } elseif (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^\d{1,9}(?:\.\d{1,6})?$/D', $value) !== 1) return null;
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $whole . ($fraction !== '' ? '.' . $fraction : '');
    }

    private static function micros(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (int) $whole * self::SCALE + (int) str_pad($fraction, 6, '0');
    }

    private static function label(int $value): string
    {
        $fraction = rtrim(str_pad((string) ($value % self::SCALE), 6, '0', STR_PAD_LEFT), '0');
        return number_format(intdiv($value, self::SCALE), 0, '.', ',')
            . ($fraction !== '' ? '.' . $fraction : '') . ' 포인트';
    }

    /** 반환 금액이 하나도 없으면 UI에 항목을 만들지 않는다. 원 단가와 묶음별 응답도 유지한다. */
    public static function summary(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') return null;
        $records = json_decode($json, true);
        if (!is_array($records)) return null;
        $total = 0;
        $missing = 0;
        $receipts = [];
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            $cost = is_array($record['cost'] ?? null) ? self::fromInfo($record['cost']) : null;
            if ($cost === null) {
                $missing++;
                continue;
            }
            $amount = self::micros($cost['total']);
            if ($amount > PHP_INT_MAX - $total) return null;
            $total += $amount;
            $receipts[] = [
                'mid' => (string) ($record['mid'] ?? ''),
                'amount' => self::label($amount),
                'unit' => $cost['unit'] !== null ? self::label(self::micros($cost['unit'])) : null,
                'recorded_at' => (string) ($record['recorded_at'] ?? ''),
            ];
        }
        return $receipts === [] ? null : ['amount' => self::label($total),
            'receipts' => $receipts, 'missing' => $missing];
    }
}
