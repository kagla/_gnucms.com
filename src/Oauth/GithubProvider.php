<?php

declare(strict_types=1);

namespace ApiBoard\Oauth;

final class GithubProvider extends AbstractProvider
{
    private const PROFILE = 'https://api.github.com/user';

    public function __construct(array $config)
    {
        parent::__construct($config, [
            'authorize' => 'https://github.com/login/oauth/authorize',
            'token' => 'https://github.com/login/oauth/access_token',
            'profile' => self::PROFILE,
        ], ['read:user', 'user:email']);
    }

    public function key(): string { return 'github'; }
    public function label(): string { return 'GitHub'; }
    protected function profileUrl(): string { return self::PROFILE; }

    protected function mapProfile(array $data, string $accessToken): SocialProfile
    {
        $email = null;
        foreach ($this->fetchJson('https://api.github.com/user/emails', $accessToken) as $candidate) {
            if (is_array($candidate) && ($candidate['primary'] ?? false) && ($candidate['verified'] ?? false)) {
                $email = isset($candidate['email']) ? (string) $candidate['email'] : null;
                break;
            }
        }
        return new SocialProfile($this->key(), (string) ($data['id'] ?? ''), $email, $email !== null,
            (string) ($data['name'] ?? $data['login'] ?? 'GitHub 회원'));
    }
}
