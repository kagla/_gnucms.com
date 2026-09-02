<?php

declare(strict_types=1);

namespace GnuCms\Oauth;

final class GoogleProvider extends AbstractProvider
{
    private const PROFILE = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function __construct(array $config)
    {
        parent::__construct($config, [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'profile' => self::PROFILE,
        ], ['openid', 'email', 'profile']);
    }

    public function key(): string { return 'google'; }
    public function label(): string { return 'Google'; }
    protected function profileUrl(): string { return self::PROFILE; }

    public function authorizationUrl(string $state): string
    {
        $url = $this->client->getAuthorizationUrl([
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        unset($query['approval_prompt']);
        $query['prompt'] = 'select_account';
        $queryString = http_build_query($query);

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'accounts.google.com';
        $path = $parts['path'] ?? '/o/oauth2/v2/auth';

        return $scheme . '://' . $host . $path . '?' . $queryString;
    }

    protected function mapProfile(array $data, string $accessToken): SocialProfile
    {
        return new SocialProfile($this->key(), (string) ($data['sub'] ?? ''),
            isset($data['email']) ? (string) $data['email'] : null,
            (bool) ($data['email_verified'] ?? false), (string) ($data['name'] ?? 'Google 회원'),
            isset($data['picture']) ? (string) $data['picture'] : null);
    }
}
