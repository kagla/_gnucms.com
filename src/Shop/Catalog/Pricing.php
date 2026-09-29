<?php

declare(strict_types=1);

namespace GnuCms\Shop\Catalog;

/** 표시 가격을 계산하고 금액을 표시한다. 3단계의 등급 할인은 display()에 끼운다. */
final class Pricing
{
    public static function display(array $product): ?int
    {
        return (int) ($product['phone_inquiry'] ?? 0) === 1 ? null : (int) $product['price'];
    }

    public static function format(int $amount): string
    {
        return number_format($amount) . '원';
    }
}
