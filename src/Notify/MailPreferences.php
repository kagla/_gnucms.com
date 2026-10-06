<?php
declare(strict_types=1);

namespace GnuCms\Notify;

use GnuCms\Account\UserRepository;
use GnuCms\Error\DomainError;

/** 회원 이메일 알림 선호와 메일의 수신거부 링크. 가입 시에는 기본 수신한다. */
final class MailPreferences
{
    public function __construct(private UserRepository $users, private string $secret, private string $appUrl) {}

    public function accepts(string $event, Recipient $to): bool
    {
        // 직접 요청한 계정 인증·복구 메일은 알림 이메일 선호와 분리한다.
        if (!Events::subscriptionMail($event) || $to->userId === null) return true;
        $user = $this->users->findById((int) $to->userId);
        return $user !== null && $user['status'] === 'active'
            && (int) ($user['email_notifications'] ?? 1) === 1;
    }

    public function footer(string $event, Recipient $to): string
    {
        if (!Events::subscriptionMail($event) || $to->userId === null) return '';
        $user = $this->users->findById((int) $to->userId);
        if ($user === null || $user['status'] !== 'active') return '';
        if (strlen($this->secret) < 32) throw DomainError::internal('수신거부 링크 설정을 확인해 주세요.');
        $payload = (int) $user['id'] . '.' . (time() + 365 * 86400);
        $token = $payload . '.' . $this->signature($payload, $user);
        return self::footerText(rtrim($this->appUrl, '/')
            . '/notifications/email/unsubscribe?token=' . rawurlencode($token));
    }

    /** 실제 발송과 미리보기에 같은 안내를 쓴다. 미리보기는 실제 토큰을 만들지 않는다. */
    public static function footerText(string $url): string
    {
        return "\n\n이메일 알림 수신거부: " . $url
            . "\n회원정보에서 이메일 알림 수신을 다시 켤 수 있습니다.";
    }

    public function userForToken(string $token): array
    {
        if (strlen($this->secret) < 32 || !preg_match('/^([1-9][0-9]{0,18})\.([0-9]{10})\.([a-f0-9]{64})$/D', $token, $m)
            || (int) $m[2] < time()) {
            throw DomainError::notFound('유효한 수신거부 링크가 아닙니다. 회원정보에서 변경해 주세요.');
        }
        $user = $this->users->findById((int) $m[1]);
        if ($user === null || $user['status'] !== 'active'
            || !hash_equals($this->signature($m[1] . '.' . $m[2], $user), $m[3])) {
            throw DomainError::notFound('유효한 수신거부 링크가 아닙니다. 회원정보에서 변경해 주세요.');
        }
        return $user;
    }

    private function signature(string $payload, array $user): string
    {
        return hash_hmac('sha256', 'notification-email-unsubscribe:' . $payload . ':' . $user['email'], $this->secret);
    }
}
