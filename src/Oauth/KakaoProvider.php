<?php

declare(strict_types=1);

namespace GnuCms\Oauth;

final class KakaoProvider extends AbstractProvider
{
    private const PROFILE = 'https://kapi.kakao.com/v2/user/me';

    public function __construct(array $config)
    {
        parent::__construct($config, [
            'authorize' => 'https://kauth.kakao.com/oauth/authorize',
            'token' => 'https://kauth.kakao.com/oauth/token',
            'profile' => self::PROFILE,
        ], []);
    }

    public function key(): string { return 'kakao'; }
    public function label(): string { return '카카오'; }
    protected function profileUrl(): string { return self::PROFILE; }

    protected function mapProfile(array $data, string $accessToken): SocialProfile
    {
        if (trim((string) ($data['id'] ?? '')) === '') {
            throw new \UnexpectedValueException('Invalid Kakao profile response');
        }
        $account = isset($data['kakao_account']) && is_array($data['kakao_account']) ? $data['kakao_account'] : [];
        $profile = isset($account['profile']) && is_array($account['profile']) ? $account['profile'] : [];
        $properties = isset($data['properties']) && is_array($data['properties']) ? $data['properties'] : [];
        $email = isset($account['email']) ? trim((string) $account['email']) : '';
        $email = $email === '' ? null : $email;
        $verified = $email !== null
            && (bool) ($account['is_email_valid'] ?? false)
            && (bool) ($account['is_email_verified'] ?? false);
        return new SocialProfile($this->key(), (string) ($data['id'] ?? ''),
            $email, $verified, (string) ($profile['nickname'] ?? $properties['nickname'] ?? '카카오 회원'),
            isset($profile['profile_image_url']) ? (string) $profile['profile_image_url']
                : (isset($properties['profile_image']) ? (string) $properties['profile_image'] : null));
    }
}
