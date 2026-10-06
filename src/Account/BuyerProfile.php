<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Aligo\PhoneNumber;
use GnuCms\Error\DomainError;

/** 주문자명은 공개 닉네임과 분리하고, 연락처는 회원 휴대폰번호를 사용한다. */
final class BuyerProfile
{
    public static function name(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value)) {
            throw DomainError::validation(['buyer_name' => '주문자명을 입력해 주세요.']);
        }
        $name = trim($value);
        if ($name === '') return null;
        if (mb_strlen($name, 'UTF-8') > 100 || !preg_match('//u', $name)
            || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw DomainError::validation(['buyer_name' => '주문자명은 줄바꿈 없이 100자 이내로 입력해 주세요.']);
        }
        return $name;
    }

    public static function values(array $member): array
    {
        $phone = (string) ($member['phone'] ?? '');
        $email = (string) ($member['email'] ?? '');
        return [
            'buyer_name' => trim((string) ($member['buyer_name'] ?? '')),
            'phone' => PhoneNumber::isMobile($phone) ? PhoneNumber::digits($phone) : '',
            'email' => !UserRepository::isSocialPlaceholderEmail($email)
                && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
        ];
    }
}
