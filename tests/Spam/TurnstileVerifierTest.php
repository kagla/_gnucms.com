<?php

declare(strict_types=1);

namespace GnuCms\Tests\Spam;

use GnuCms\Error\DomainError;
use GnuCms\Spam\TurnstileVerifier;
use PHPUnit\Framework\TestCase;

final class TurnstileVerifierTest extends TestCase
{
    public function testDisabledVerifierDoesNotCallRemoteService(): void
    {
        $called = false;
        $verifier = new TurnstileVerifier(['enabled' => false], function () use (&$called): array {
            $called = true;
            return [];
        });
        $verifier->verify('', null, 'register');
        self::assertFalse($called);
    }

    public function testSuccessfulResponseMustMatchHostnameAndAction(): void
    {
        $sent = [];
        $verifier = new TurnstileVerifier([
            'enabled' => true, 'site_key' => 'site-key', 'secret_key' => 'secret-key',
            'hostname' => 'community.example.com',
        ], function (string $url, array $fields) use (&$sent): array {
            $sent = [$url, $fields];
            return ['success' => true, 'hostname' => 'community.example.com', 'action' => 'comment_create'];
        });
        $verifier->verify('browser-token', '203.0.113.8', 'comment_create');
        self::assertSame('secret-key', $sent[1]['secret']);
        self::assertSame('browser-token', $sent[1]['response']);
        self::assertSame('203.0.113.8', $sent[1]['remoteip']);
    }

    public function testMissingTokenIsRejectedWithoutRemoteCall(): void
    {
        $verifier = new TurnstileVerifier([
            'enabled' => true, 'site_key' => 'site-key', 'secret_key' => 'secret-key',
        ], static fn (): array => ['success' => true]);
        try {
            $verifier->verify('', null, 'register');
            self::fail('빈 토큰은 거부되어야 한다');
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertArrayHasKey('turnstile', $e->details());
        }
    }

    public function testMismatchedActionIsRejected(): void
    {
        $verifier = new TurnstileVerifier([
            'enabled' => true, 'site_key' => 'site-key', 'secret_key' => 'secret-key',
        ], static fn (): array => ['success' => true, 'action' => 'post_create']);
        $this->expectException(DomainError::class);
        $verifier->verify('token', null, 'comment_create');
    }

    public function testEnabledButIncompleteConfigurationIsUnavailable(): void
    {
        $verifier = new TurnstileVerifier(['enabled' => true, 'site_key' => 'site-key']);
        try {
            $verifier->verify('token', null, 'register');
            self::fail('불완전한 설정은 조용히 우회되면 안 된다');
        } catch (DomainError $e) {
            self::assertSame(503, $e->status());
            self::assertSame('SERVICE_UNAVAILABLE', $e->code());
        }
    }
}
