<?php

declare(strict_types=1);

namespace GnuCms\Tests\Oauth;

use GnuCms\Oauth\GoogleProvider;
use GnuCms\Oauth\KakaoProvider;
use GnuCms\Oauth\NaverProvider;
use GnuCms\Oauth\SocialProfile;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use UnexpectedValueException;

final class ProviderProfileTest extends TestCase
{
    public function testGoogleUsesSubAndVerifiedEmail(): void
    {
        $profile = $this->map(new GoogleProvider($this->config()), [
            'sub' => 'google-123', 'email' => 'USER@example.com',
            'email_verified' => true, 'name' => 'Google User',
            'picture' => 'https://lh3.googleusercontent.com/avatar.jpg',
        ]);

        self::assertSame('google', $profile->provider);
        self::assertSame('google-123', $profile->uid);
        self::assertSame('user@example.com', $profile->email);
        self::assertTrue($profile->emailVerified);
        self::assertSame('https://lh3.googleusercontent.com/avatar.jpg', $profile->imageUrl);
    }

    public function testNaverEmailIsTreatedAsVerifiedWhenPresent(): void
    {
        $profile = $this->map(new NaverProvider($this->config()), [
            'response' => ['id' => 'naver-123', 'email' => 'user@naver.com', 'nickname' => '네이버회원',
                'profile_image' => 'https://phinf.pstatic.net/avatar.jpg'],
        ]);

        self::assertSame('naver-123', $profile->uid);
        self::assertSame('user@naver.com', $profile->email);
        self::assertTrue($profile->emailVerified);
        self::assertSame('https://phinf.pstatic.net/avatar.jpg', $profile->imageUrl);
    }

    public function testNaverAuthorizationUrlMatchesProviderRequirements(): void
    {
        $url = (new NaverProvider($this->config()))->authorizationUrl('naver-state');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('https://nid.naver.com/oauth2.0/authorize', strtok($url, '?'));
        self::assertSame('naver-state', $query['state']);
        self::assertSame('code', $query['response_type']);
        self::assertArrayNotHasKey('scope', $query);
        self::assertArrayNotHasKey('approval_prompt', $query);
    }

    public function testNaverRejectsProviderErrorResponse(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->map(new NaverProvider($this->config()), [
            'resultcode' => '024', 'message' => 'Authentication failed',
        ]);
    }

    public function testKakaoTrustsOnlyValidAndVerifiedEmail(): void
    {
        $verified = $this->map(new KakaoProvider($this->config()), [
            'id' => 42,
            'kakao_account' => [
                'email' => 'user@kakao.com', 'is_email_valid' => true, 'is_email_verified' => true,
                'profile' => ['nickname' => '카카오회원',
                    'profile_image_url' => 'https://k.kakaocdn.net/avatar.jpg'],
            ],
        ]);
        $unverified = $this->map(new KakaoProvider($this->config()), [
            'id' => 43,
            'kakao_account' => [
                'email' => 'other@kakao.com', 'is_email_valid' => true, 'is_email_verified' => false,
            ],
        ]);

        self::assertSame('42', $verified->uid);
        self::assertTrue($verified->emailVerified);
        self::assertSame('https://k.kakaocdn.net/avatar.jpg', $verified->imageUrl);
        self::assertFalse($unverified->emailVerified);
    }

    public function testKakaoAuthorizationUrlDoesNotForceOptionalConsentItems(): void
    {
        $url = (new KakaoProvider($this->config()))->authorizationUrl('kakao-state');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('https://kauth.kakao.com/oauth/authorize', strtok($url, '?'));
        self::assertSame('kakao-state', $query['state']);
        self::assertArrayNotHasKey('scope', $query);
        self::assertArrayNotHasKey('approval_prompt', $query);
    }

    public function testKakaoRejectsProfileWithoutUserId(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->map(new KakaoProvider($this->config()), ['kakao_account' => []]);
    }

    private function map(object $provider, array $data): SocialProfile
    {
        $method = new ReflectionMethod($provider, 'mapProfile');
        /** @var SocialProfile $profile */
        $profile = $method->invoke($provider, $data, 'access-token-not-stored');
        return $profile;
    }

    private function config(): array
    {
        return [
            'client_id' => 'test-client', 'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.com/auth/callback',
        ];
    }
}
