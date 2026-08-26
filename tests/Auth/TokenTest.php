<?php

declare(strict_types=1);

namespace StandardBoard\Tests\Auth;

use PHPUnit\Framework\TestCase;
use StandardBoard\Auth\TokenIssuer;
use StandardBoard\Auth\TokenVerifier;
use StandardBoard\Http\ApiError;
use StandardBoard\Support\Base64Url;
use StandardBoard\Support\Clock;
use StandardBoard\Support\Json;

final class TokenTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-long-enough-32b';

    protected function setUp(): void
    {
        Clock::freeze('2026-08-26 01:02:03');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
    }

    public function testRoundTripPreservesClaims(): void
    {
        $token = (new TokenIssuer(self::SECRET, 3600))->issue('user-123', '홍길동', true);

        $identity = (new TokenVerifier(self::SECRET, 60))->verify($token);

        $this->assertSame('user-123', $identity->sub());
        $this->assertSame('홍길동', $identity->name());
        $this->assertTrue($identity->isAdmin());
        $this->assertFalse($identity->isGuest());
    }

    public function testTokenHasThreeSegments(): void
    {
        $token = (new TokenIssuer(self::SECRET, 3600))->issue('u', 'n', false);

        $this->assertCount(3, explode('.', $token));
    }

    public function testMissingTokenGivesGuestIdentity(): void
    {
        $identity = (new TokenVerifier(self::SECRET, 60))->verify(null);

        $this->assertTrue($identity->isGuest());
        $this->assertNull($identity->sub());
        $this->assertFalse($identity->isAdmin());
    }

    public function testEmptyStringGivesGuestIdentity(): void
    {
        $this->assertTrue((new TokenVerifier(self::SECRET, 60))->verify('')->isGuest());
    }

    public function testWrongSecretIsRejected(): void
    {
        $token = (new TokenIssuer(self::SECRET, 3600))->issue('u', 'n', true);

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('토큰 서명이 올바르지 않습니다.');
        (new TokenVerifier('another-secret-entirely-different!!', 60))->verify($token);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $token = (new TokenIssuer(self::SECRET, 3600))->issue('u', 'n', false);
        [$header, $payload, $signature] = explode('.', $token);

        $claims = Json::decode(Base64Url::decode($payload));
        $claims['admin'] = true;
        $forged = $header . '.' . Base64Url::encode(Json::encode($claims)) . '.' . $signature;

        $this->expectException(ApiError::class);
        (new TokenVerifier(self::SECRET, 60))->verify($forged);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = (new TokenIssuer(self::SECRET, 3600))->issue('u', 'n', false);

        Clock::freeze('2026-08-26 02:03:04');

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('토큰이 만료되었습니다.');
        (new TokenVerifier(self::SECRET, 60))->verify($token);
    }

    public function testExpiryWithinLeewayIsAccepted(): void
    {
        $token = (new TokenIssuer(self::SECRET, 3600))->issue('u', 'n', false);

        // 만료 30초 뒤. 허용 오차 60초 안이므로 통과해야 한다.
        Clock::freeze('2026-08-26 02:02:33');

        $this->assertSame('u', (new TokenVerifier(self::SECRET, 60))->verify($token)->sub());
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->expectException(ApiError::class);
        (new TokenVerifier(self::SECRET, 60))->verify('not-a-token');
    }

    public function testUnsupportedAlgorithmIsRejected(): void
    {
        $header = Base64Url::encode(Json::encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = Base64Url::encode(Json::encode(['sub' => 'u', 'name' => 'n', 'admin' => true, 'exp' => 99999999999]));

        $this->expectException(ApiError::class);
        (new TokenVerifier(self::SECRET, 60))->verify($header . '.' . $payload . '.');
    }

    public function testTokenWithoutExpiryIsRejected(): void
    {
        $header = Base64Url::encode(Json::encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = Base64Url::encode(Json::encode(['sub' => 'u', 'name' => 'n', 'admin' => false]));
        $signature = Base64Url::encode(hash_hmac('sha256', $header . '.' . $payload, self::SECRET, true));

        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('토큰에 만료 시각이 없습니다.');
        (new TokenVerifier(self::SECRET, 60))->verify($header . '.' . $payload . '.' . $signature);
    }

    public function testAdminClaimDefaultsToFalseWhenAbsent(): void
    {
        $header = Base64Url::encode(Json::encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = Base64Url::encode(Json::encode(['sub' => 'u', 'name' => 'n', 'exp' => Clock::timestamp() + 60]));
        $signature = Base64Url::encode(hash_hmac('sha256', $header . '.' . $payload, self::SECRET, true));

        $identity = (new TokenVerifier(self::SECRET, 60))->verify($header . '.' . $payload . '.' . $signature);

        $this->assertFalse($identity->isAdmin());
    }

    public function testBase64UrlHasNoPaddingOrUnsafeCharacters(): void
    {
        $encoded = Base64Url::encode("\xfb\xff\xfe binary");

        $this->assertSame(0, preg_match('/[+\/=]/', $encoded));
        $this->assertSame("\xfb\xff\xfe binary", Base64Url::decode($encoded));
    }
}
