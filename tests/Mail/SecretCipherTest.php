<?php

declare(strict_types=1);

namespace GnuCms\Tests\Mail;

use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;
use PHPUnit\Framework\TestCase;

final class SecretCipherTest extends TestCase
{
    public function testRoundTripUsesOpenSslFormat(): void
    {
        $cipher = new SecretCipher('test-secret');

        $encrypted = $cipher->encrypt('private-value');

        self::assertStringStartsWith('v2:', $encrypted);
        self::assertStringNotContainsString('private-value', $encrypted);
        self::assertSame('private-value', $cipher->decrypt($encrypted));
    }

    public function testRejectsTamperedCiphertext(): void
    {
        $cipher = new SecretCipher('test-secret');
        $encrypted = $cipher->encrypt('private-value');
        $payload = base64_decode(substr($encrypted, 3), true);
        self::assertIsString($payload);
        $payload[strlen($payload) - 1] = chr(ord($payload[strlen($payload) - 1]) ^ 1);

        $this->expectException(DomainError::class);
        $cipher->decrypt('v2:' . base64_encode($payload));
    }

    public function testRejectsASecretFromAnotherInstallation(): void
    {
        $encrypted = (new SecretCipher('first-secret'))->encrypt('private-value');

        $this->expectException(DomainError::class);
        (new SecretCipher('second-secret'))->decrypt($encrypted);
    }
}
