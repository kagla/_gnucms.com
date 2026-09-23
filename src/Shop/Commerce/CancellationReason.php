<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Input;

final class CancellationReason
{
    public const OPTIONS = [
        'change_mind' => '단순 변심',
        'order_change' => '상품·옵션·수량 변경',
        'delivery_change' => '배송지 변경',
        'payment_change' => '결제 방법 변경',
        'delay' => '배송 지연',
        'out_of_stock' => '상품 품절',
        'other' => '기타',
    ];

    public static function note(array $input): string
    {
        $key = Input::text($input['cancel_reason'] ?? '', 'cancel_reason', 30);
        if (!isset(self::OPTIONS[$key])) throw DomainError::validation(['cancel_reason' => '취소 사유를 선택해 주세요.']);
        $detail = Input::text($input['cancel_detail'] ?? '', 'cancel_detail', 400);
        if ($key === 'other' && $detail === '') throw DomainError::validation(['cancel_detail' => '기타 사유를 입력해 주세요.']);

        return '취소 사유: ' . self::OPTIONS[$key] . ($detail === '' ? '' : ' · ' . $detail);
    }
}
