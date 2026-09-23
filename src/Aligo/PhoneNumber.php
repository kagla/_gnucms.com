<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

use GnuCms\Error\DomainError;

/** 국내 번호를 한 곳에서만 다룬다. 저장은 숫자만, 표시할 때만 하이픈을 넣는다. */
final class PhoneNumber
{
    private const MOBILE = '/^01[016789]\d{7,8}$/D';
    private const SENDER = '/^(01[016789]\d{7,8}|0[2-6]\d{7,9}|1[5-8]\d{6})$/D';

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

    /**
     * 수정 화면용. 저장된 값을 손대지 않고 그대로 다시 제출했을 때만 형식 검사를
     * 건너뛴다. 수정 화면은 저장된 번호를 미리 채워 보여주는데, 그 번호가 휴대폰
     * 형식이 아니면(예전 데이터·외부 이관으로 들어온 02-1234-5678 같은 값) 그
     * 화면은 번호뿐 아니라 이름·비밀번호까지 저장할 수 없게 된다 — 그 값을
     * 고치려는 사람까지 막는 셈이다. 값을 실제로 바꿀 때는 normalize() 와 똑같이
     * 휴대폰 형식을 요구한다.
     */
    public static function normalizeEdit(string $value, ?string $stored): string
    {
        $digits = self::digits($value);
        if ($digits !== '' && $stored !== null && $digits === self::digits($stored)) {
            return $digits;
        }

        return self::normalize($value);
    }

    public static function normalizeSender(string $value): string
    {
        $digits = self::digits($value);
        if (preg_match(self::SENDER, $digits) !== 1) {
            throw DomainError::validation(['sender' => '발신번호를 확인해 주세요. 휴대폰·지역번호·대표번호를 쓸 수 있습니다.']);
        }

        return $digits;
    }

    public static function format(string $value): string
    {
        $digits = self::digits($value);
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

        // 국제번호 등 국내 형식에 맞지 않는 값은 원문을 보존한다.
        return $value;
    }

    /**
     * 가운데만 가리고 앞뒤는 남겨 누구인지 구분할 수 있게 한다.
     *
     * 쓰는 곳은 관리자 회원 목록이다(`docs/superpowers/plans/2026-09-17-aligo-2-member-phone.md`
     * 의 회원 휴대폰번호 작업에서 호출한다). 이력 **목록**은 작업 단위라 수신번호를
     * 아예 싣지 않으므로 여기서 가릴 것이 없고, 이력 **상세**는 전체 번호를 그대로
     * 보여준다 — 둘 다 의도한 선택이다. 이 함수가 이력 화면에서 안 보인다고 해서
     * 죽은 코드가 아니다.
     */
    public static function mask(string $digits): string
    {
        $formatted = self::format($digits);
        $parts = explode('-', $formatted);
        if (count($parts) !== 3) {
            // 형식을 못 맞춘 값(잘린 번호·외국 번호 등)은 가릴 자리를 고를 수 없다.
            // 그렇다고 원본을 그대로 돌려주면 가리는 것이 유일한 일인 함수가 열리는
            // 쪽으로 실패한다 — 못 가리면 아예 보여주지 않고 전부 별로 덮는다.
            return str_repeat('*', mb_strlen($formatted));
        }

        return $parts[0] . '-' . str_repeat('*', strlen($parts[1])) . '-' . $parts[2];
    }
}
