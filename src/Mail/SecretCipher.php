<?php

declare(strict_types=1);

namespace ApiBoard\Mail;

use ApiBoard\Error\DomainError;

final class SecretCipher
{
    private string $key;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw DomainError::internal('메일 비밀번호 암호화 키가 없습니다.');
        }
        $this->key = sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    public function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, 'v1:')) {
            throw DomainError::internal('저장된 메일 비밀번호 형식을 확인할 수 없습니다.');
        }
        $payload = base64_decode(substr($encoded, 3), true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw DomainError::internal('저장된 메일 비밀번호를 읽을 수 없습니다.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );
        if ($plain === false) {
            throw DomainError::internal('저장된 메일 비밀번호를 복호화하지 못했습니다.');
        }
        return $plain;
    }
}
