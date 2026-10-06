<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Input;

final class ReturnReason
{
    public const OPTIONS = ['change_mind' => '단순 변심', 'defective' => '상품 불량',
        'wrong_item' => '다른 상품 배송', 'damaged' => '배송 중 파손', 'other' => '기타'];

    public static function note(array $input): string
    {
        $key = Input::text($input['return_reason'] ?? '', 'return_reason', 30);
        if (!isset(self::OPTIONS[$key])) throw DomainError::validation(['return_reason' => '반품 사유를 선택해 주세요.']);
        $detail = Input::text($input['return_detail'] ?? '', 'return_detail', 400);
        if ($key === 'other' && $detail === '') throw DomainError::validation(['return_detail' => '기타 사유를 입력해 주세요.']);
        return '반품 사유: ' . self::OPTIONS[$key] . ($detail === '' ? '' : ' · ' . $detail);
    }
}
