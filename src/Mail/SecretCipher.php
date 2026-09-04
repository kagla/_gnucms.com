<?php

declare(strict_types=1);

namespace GnuCms\Mail;

use GnuCms\Error\DomainError;

final class SecretCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const AAD = 'gnucms:secret:v2';

    private string $key;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw DomainError::internal('비밀값 암호화 키가 없습니다.');
        }
        $this->key = hash('sha256', self::AAD . "\0" . $secret, true);
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plain,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
            self::TAG_BYTES
        );
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw DomainError::internal('비밀값을 암호화하지 못했습니다.');
        }

        return 'v2:' . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, 'v2:')) {
            throw DomainError::internal('저장된 비밀값 형식을 확인할 수 없습니다.');
        }
        $payload = base64_decode(substr($encoded, 3), true);
        if ($payload === false || strlen($payload) < self::IV_BYTES + self::TAG_BYTES) {
            throw DomainError::internal('저장된 비밀값을 읽을 수 없습니다.');
        }
        $plain = openssl_decrypt(
            substr($payload, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            substr($payload, 0, self::IV_BYTES),
            substr($payload, self::IV_BYTES, self::TAG_BYTES),
            self::AAD
        );
        if ($plain === false) {
            throw DomainError::internal('저장된 비밀값을 복호화하지 못했습니다.');
        }
        return $plain;
    }
}
