<?php

declare(strict_types=1);

namespace GnuCms\Notify;

/**
 * 알림 하나가 향하는 대상. 회원이면 계정 행에서, 손님이면 이메일 하나로 만든다.
 *
 * phone 은 users.phone 값을 그대로 옮긴다 — 저장 시점에 GnuCms\Aligo\PhoneNumber::normalize()
 * 를 거치므로 여기서는 숫자만 남은 값이거나 NULL 뿐이고, 다시 검사하거나 형식을 바꾸지
 * 않는다. 빈 문자열('')도 "번호 없음"으로 보고 NULL 로 접어 둔다 — 나중에 전화 채널을
 * 쓸 수 있는지 묻는 쪽(예: Recipient::phone !== null)이 빈 문자열과 NULL을 각각
 * 따로 처리할 필요가 없게 하기 위해서다.
 */
final class Recipient
{
    public function __construct(
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $userId,
        public readonly string $name
    ) {
    }

    public static function forUser(array $user): self
    {
        return new self(
            self::scalarOrNull($user['email'] ?? null),
            self::scalarOrNull($user['phone'] ?? null),
            self::scalarOrNull($user['id'] ?? null),
            self::scalarString($user['display_name'] ?? '')
        );
    }

    public static function forEmail(string $email, string $name = ''): self
    {
        return new self(self::orNull($email), null, null, $name);
    }

    /**
     * $user 는 호출자가 만든 배열이라 칸 값이 배열일 수 있다(phone[]=x 가 넘어오면
     * array_merge() 가 그 배열을 그대로 얹는 것과 같은 모양). 이미
     * AccountController·AdminController·AccountService 세 곳에서 겪은 결함이라, 이
     * 값 객체도 (string) 캐스팅 전에 반드시 스칼라인지 본다 — 스칼라가 아니면 값이
     * 없는 것으로 접어 둔다(캐스팅해서 "Array" 라는 문자열을 만들지 않는다).
     */
    private static function scalarOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? self::orNull((string) $value) : null;
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function orNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
