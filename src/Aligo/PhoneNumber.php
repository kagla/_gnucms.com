<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Error\DomainError;

/** 국내 번호를 한 곳에서만 다룬다. 저장은 숫자만, 표시할 때만 하이픈을 넣는다. */
final class PhoneNumber
{
    private const MOBILE = '/^01[016789]\d{7,8}$/D';
    private const SENDER = '/^(01[016789]\d{7,8}|0[2-6]\d{7,9}|1[0-9]{3}\d{4})$/D';

    public static function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }

    public static function isMobile(string $value): bool
    {
        return preg_match(self::MOBILE, self::digits($value)) === 1;
    }

    public static function normalize(string $value): string
    {
        $digits = self::digits($value);
        if (preg_match(self::MOBILE, $digits) !== 1) {
            throw DomainError::validation(['phone' => '국내 휴대폰 번호를 입력해 주세요.']);
        }

        return $digits;
    }

    public static function normalizeSender(string $value): string
    {
        $digits = self::digits($value);
        if (preg_match(self::SENDER, $digits) !== 1) {
            throw DomainError::validation(['sender' => '발신번호를 확인해 주세요. 휴대폰·지역번호·대표번호를 쓸 수 있습니다.']);
        }

        return $digits;
    }

    public static function format(string $digits): string
    {
        $digits = self::digits($digits);
        if (preg_match('/^(01[016789])(\d{3,4})(\d{4})$/D', $digits, $m) === 1) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(02)(\d{3,4})(\d{4})$/D', $digits, $m) === 1) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(0[3-6]\d)(\d{3,4})(\d{4})$/D', $digits, $m) === 1) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(1\d{3})(\d{4})$/D', $digits, $m) === 1) {
            return $m[1] . '-' . $m[2];
        }

        return $digits;
    }

    /** 이력 목록용. 가운데만 가리고 앞뒤는 남겨 누구인지 구분할 수 있게 한다. */
    public static function mask(string $digits): string
    {
        $formatted = self::format($digits);
        $parts = explode('-', $formatted);
        if (count($parts) !== 3) {
            return $formatted;
        }

        return $parts[0] . '-' . str_repeat('*', strlen($parts[1])) . '-' . $parts[2];
    }
}
