<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** PG 설정에서 일반결제와 에스크로 결제를 구분한다. */
final class PaymentMode
{
    public static function validate(mixed $mode): string
    {
        if (!is_string($mode) || !in_array($mode, ['general', 'escrow'], true)) {
            throw DomainError::validation(['mode' => '일반결제 또는 에스크로 결제를 선택해 주세요.']);
        }
        return $mode;
    }
}
