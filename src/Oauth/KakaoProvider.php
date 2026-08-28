<?php

declare(strict_types=1);

namespace ApiBoard\Oauth;

final class KakaoProvider extends AbstractProvider
{
    private const PROFILE = 'https://kapi.kakao.com/v2/user/me';

    public function __construct(array $config)
    {
        parent::__construct($config, [
            'authorize' => 'https://kauth.kakao.com/oauth/authorize',
            'token' => 'https://kauth.kakao.com/oauth/token',
            'profile' => self::PROFILE,
        ], ['account_email', 'profile_nickname'], ',');
    }

    public function key(): string { return 'kakao'; }
    public function label(): string { return '카카오'; }
    protected function profileUrl(): string { return self::PROFILE; }

    protected function mapProfile(array $data, string $accessToken): SocialProfile
    {
        $account = isset($data['kakao_account']) && is_array($data['kakao_account']) ? $data['kakao_account'] : [];
        $profile = isset($account['profile']) && is_array($account['profile']) ? $account['profile'] : [];
        $verified = (bool) ($account['is_email_valid'] ?? false) && (bool) ($account['is_email_verified'] ?? false);
        return new SocialProfile($this->key(), (string) ($data['id'] ?? ''),
            isset($account['email']) ? (string) $account['email'] : null,
            $verified, (string) ($profile['nickname'] ?? '카카오 회원'));
    }
}
