<?php

declare(strict_types=1);

namespace ApiBoard\Oauth;

final class NaverProvider extends AbstractProvider
{
    private const PROFILE = 'https://openapi.naver.com/v1/nid/me';

    public function __construct(array $config)
    {
        parent::__construct($config, [
            'authorize' => 'https://nid.naver.com/oauth2.0/authorize',
            'token' => 'https://nid.naver.com/oauth2.0/token',
            'profile' => self::PROFILE,
        ], []);
    }

    public function key(): string { return 'naver'; }
    public function label(): string { return '네이버'; }
    protected function profileUrl(): string { return self::PROFILE; }

    protected function accessTokenOptions(string $code, string $state): array
    {
        return ['code' => $code, 'state' => $state];
    }

    protected function mapProfile(array $data, string $accessToken): SocialProfile
    {
        $profile = isset($data['response']) && is_array($data['response']) ? $data['response'] : [];
        return new SocialProfile($this->key(), (string) ($profile['id'] ?? ''),
            isset($profile['email']) ? (string) $profile['email'] : null,
            false, (string) ($profile['nickname'] ?? $profile['name'] ?? '네이버 회원'));
    }
}
