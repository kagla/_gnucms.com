<?php

declare(strict_types=1);

namespace ApiBoard\Account;

use ApiBoard\Error\DomainError;
use ApiBoard\Mail\MailerInterface;
use ApiBoard\Oauth\ProviderRegistry;
use ApiBoard\Oauth\SocialProfile;

final class SocialAuthService
{
    private ProviderRegistry $providers;
    private LinkingService $linking;
    private MailerInterface $mailer;
    private string $appUrl;

    public function __construct(ProviderRegistry $providers, LinkingService $linking, MailerInterface $mailer, string $appUrl)
    {
        $this->providers = $providers;
        $this->linking = $linking;
        $this->mailer = $mailer;
        $this->appUrl = rtrim($appUrl, '/');
    }

    public function profile(string $provider, string $code, string $state = ''): SocialProfile
    {
        if ($code === '') {
            throw DomainError::validation(['code' => '소셜 로그인이 취소되었거나 인증 코드가 없습니다.']);
        }
        return $this->providers->get($provider)->fetchProfile($code, $state);
    }

    public function resolve(SocialProfile $profile): ?array
    {
        return $this->linking->resolve($profile);
    }

    public function sendPendingEmail(SocialProfile $profile, string $email, string $token): string
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            throw DomainError::validation(['email' => '올바른 이메일 주소를 입력해 주세요.']);
        }
        $url = $this->appUrl . '/auth/complete?token=' . rawurlencode($token);
        $this->mailer->send($email, '[aboard] 소셜 로그인 이메일 확인',
            "소셜 로그인을 완료하려면 아래 링크를 열어 주세요.\n\n{$url}\n\n이 링크는 30분 동안 유효합니다.");

        return $email;
    }

    public function complete(SocialProfile $profile, string $email): array
    {
        return $this->linking->completeVerifiedEmail($profile, $email);
    }
}
