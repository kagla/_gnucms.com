<?php

declare(strict_types=1);

namespace GnuCms\Notify;

/**
 * 코어가 보내는 알림의 고정 목록. phone 이 false 인 것은 수신자가 이메일로만 식별되거나
 * 이메일 확인 자체가 목적이라 알림톡·문자로 보낼 수 없다.
 */
final class Events
{
    public const ALL = [
        'password_reset' => ['label' => '비밀번호 재설정',
            'vars' => ['사이트명', '이름', '링크', '유효시간'], 'phone' => true],
        'password_changed' => ['label' => '비밀번호 변경 안내',
            'vars' => ['사이트명', '이름', '일시', '링크'], 'phone' => true],
        'welcome' => ['label' => '가입 완료 안내',
            'vars' => ['사이트명', '이름'], 'phone' => true],
        'comment_new' => ['label' => '새 댓글·답글',
            'vars' => ['사이트명', '이름', '글제목', '작성자', '링크'], 'phone' => true],
        'email_verify' => ['label' => '이메일 인증',
            'vars' => ['사이트명', '이름', '링크', '유효시간'], 'phone' => false],
        'signup_attempt' => ['label' => '가입 시도 안내',
            'vars' => ['사이트명', '링크'], 'phone' => false],
        'social_email_verify' => ['label' => '소셜 로그인 이메일 확인',
            'vars' => ['사이트명', '링크', '유효시간'], 'phone' => false],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    /** @return array<string,string> */
    public static function labels(): array
    {
        return array_map(static fn (array $event): string => $event['label'], self::ALL);
    }

    /** @return list<string> */
    public static function variables(string $key): array
    {
        return self::ALL[$key]['vars'] ?? [];
    }

    public static function phoneCapable(string $key): bool
    {
        return (bool) (self::ALL[$key]['phone'] ?? false);
    }
}
