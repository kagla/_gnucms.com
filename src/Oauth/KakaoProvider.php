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
            $this->imageUrl($profile, $properties));
    }

    private function imageUrl(array $profile, array $properties): ?string
    {
        foreach ([$profile['profile_image_url'] ?? null, $properties['profile_image'] ?? null,
            $profile['thumbnail_image_url'] ?? null, $properties['thumbnail_image'] ?? null] as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') continue;
            $url = trim($candidate);
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            // 카카오가 HTTP 주소를 반환해도 공식 CDN에서 HTTPS로만 내려받는다.
            // AvatarService의 HTTPS·제공자 호스트 검사와 리다이렉트 차단은 유지한다.
            if (strtolower((string) ($parts['scheme'] ?? '')) === 'http') {
                foreach (['kakaocdn.net', 'daumcdn.net'] as $allowed) {
                    if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                        $url = 'https://' . substr($url, 7);
                        break;
                    }
                }
            }
            return $url;
        }
        return null;
    }
}
