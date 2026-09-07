<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart\Catalog;

/** 표시 가격과 포인트를 한 곳에서 계산한다. 3단계의 등급 할인은 display()에 끼운다. */
final class Pricing
{
    public static function display(array $product): ?int
    {
        return (int) ($product['phone_inquiry'] ?? 0) === 1 ? null : (int) $product['price'];
    }

    /** 선택옵션 차액은 point_type 2에서만 더한다. 10점 단위로 내린다. */
    public static function point(array $product, int $optionDelta = 0): int
    {
        $type = (int) ($product['point_type'] ?? 0);
        $point = (int) ($product['point'] ?? 0);
        if ($type === 0) return max(0, $point);
        $base = (int) $product['price'] + ($type === 2 ? $optionDelta : 0);
        return max(0, (int) floor($base * $point / 100 / 10) * 10);
    }

    public static function format(int $amount): string
    {
        return number_format($amount) . '원';
    }

    public static function pointLabel(array $product): string
    {
        if ((int) ($product['point_type'] ?? 0) === 2) return '구매금액(추가옵션 제외)의 ' . (int) $product['point'] . '%';
        return number_format(self::point($product)) . '점';
    }
}
