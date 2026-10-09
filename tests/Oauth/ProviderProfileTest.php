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

    public function testKakaoUpgradesOfficialCdnHttpImageUrlsToHttps(): void
    {
        $cases = [
            ['http://k.kakaocdn.net/avatar.jpg', 'https://k.kakaocdn.net/avatar.jpg'],
            ['http://kakaocdn.net/avatar.jpg', 'https://kakaocdn.net/avatar.jpg'],
            ['http://profile.daumcdn.net/avatar.jpg', 'https://profile.daumcdn.net/avatar.jpg'],
            ['HTTP://K.KAKAOCdn.NET/avatar.jpg', 'https://K.KAKAOCdn.NET/avatar.jpg'],
        ];
        foreach ($cases as [$url, $expected]) {
            $profile = $this->map(new KakaoProvider($this->config()), [
                'id' => 42, 'kakao_account' => ['profile' => ['profile_image_url' => $url]],
            ]);

            self::assertSame($expected, $profile->imageUrl, $url);
        }
    }

    public function testKakaoDoesNotUpgradeImageUrlsOutsideOfficialCdnHosts(): void
    {
        foreach (['http://example.com/avatar.jpg', 'http://kakaocdn.net.example.com/avatar.jpg',
            'http://notkakaocdn.net/avatar.jpg', 'http://daumcdn.net.example.com/avatar.jpg',
            'http://k.kakaocdn.net@evil.example/avatar.jpg'] as $url) {
            $profile = $this->map(new KakaoProvider($this->config()), [
                'id' => 42, 'kakao_account' => ['profile' => ['profile_image_url' => $url]],
            ]);

            self::assertSame($url, $profile->imageUrl, $url);
        }
    }

    public function testKakaoFallsBackFromBlankImageUrlToLegacyImageOrThumbnail(): void
    {
        $cases = [
            [['profile_image_url' => ' '], ['profile_image' => 'http://k.kakaocdn.net/legacy.jpg'],
                'https://k.kakaocdn.net/legacy.jpg'],
            [['profile_image_url' => '', 'thumbnail_image_url' => 'http://k.kakaocdn.net/thumb.jpg'],
                ['profile_image' => ' '], 'https://k.kakaocdn.net/thumb.jpg'],
            [[], ['thumbnail_image' => 'http://profile.daumcdn.net/thumb.jpg'],
                'https://profile.daumcdn.net/thumb.jpg'],
        ];
        foreach ($cases as [$accountProfile, $properties, $expected]) {
            $profile = $this->map(new KakaoProvider($this->config()), [
                'id' => 42, 'kakao_account' => ['profile' => $accountProfile], 'properties' => $properties,
            ]);

            self::assertSame($expected, $profile->imageUrl);
        }
    }

    public function testKakaoPrefersOriginalImageToLegacyImageAndThumbnail(): void
    {
        $profile = $this->map(new KakaoProvider($this->config()), [
            'id' => 42,
            'kakao_account' => ['profile' => [
                'profile_image_url' => 'http://k.kakaocdn.net/original.jpg',
                'thumbnail_image_url' => 'http://k.kakaocdn.net/thumb.jpg',
            ]],
            'properties' => ['profile_image' => 'http://k.kakaocdn.net/legacy.jpg'],
        ]);

        self::assertSame('https://k.kakaocdn.net/original.jpg', $profile->imageUrl);
    }

    public function testKakaoAllowsProfileWithoutOptionalPhoto(): void
    {
        foreach ([[], ['profile_image_url' => '', 'thumbnail_image_url' => ' ']] as $accountProfile) {
            $profile = $this->map(new KakaoProvider($this->config()), [
                'id' => 42, 'kakao_account' => ['profile' => $accountProfile],
            ]);

            self::assertNull($profile->imageUrl);
        }
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
