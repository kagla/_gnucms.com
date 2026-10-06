<?php

declare(strict_types=1);

namespace GnuCms\Notify;

/** 문자에는 인증된 사용자 자신의 조회 화면으로 이어지는 짧은 사이트 주소를 사용한다. */
final class SmsLinks
{
    public const PATHS = [
        'orders' => '/shop/orders', 'notice' => '/notifications', 'password' => '/forgot-password',
        // 이미 발송한 문자의 주소도 계속 열 수 있도록 기존 경로를 유지한다.
        'notifications' => '/notifications',
        'o' => '/shop/orders', 'n' => '/notifications', 'p' => '/forgot-password',
    ];

    public static function forBody(string $event, array $vars, string $siteUrl = ''): array
    {
        $values = MessageVars::forBody($event, $vars);
        $key = match (true) {
            str_starts_with($event, 'order_') => 'orders',
            in_array($event, ['comment_new', 'inquiry_replied'], true) => 'notice',
            $event === 'password_changed' => 'password',
            default => null,
        };
        $base = $siteUrl !== '' ? $siteUrl : ($values['사이트주소'] ?? '');
        if ($key !== null && preg_match('~^https?://~i', $base) === 1 && isset($values['링크'])) {
            $values['링크'] = rtrim($base, '/') . '/s/' . $key;
        }

        // 비밀번호 재설정 같은 일회용 인증 링크는 변경하거나 생략하지 않는다.
        return $values;
    }
}
