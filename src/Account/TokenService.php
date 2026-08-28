<?php

declare(strict_types=1);

namespace ApiBoard\Account;

use ApiBoard\Error\DomainError;
use ApiBoard\Support\Base64Url;
use ApiBoard\Support\Clock;

final class TokenService
{
    public const VERIFY_EMAIL = 'verify_email';
    public const RESET_PASSWORD = 'reset_password';

    private TokenRepository $tokens;

    public function __construct(TokenRepository $tokens)
    {
        $this->tokens = $tokens;
    }

    public function issue(int $userId, string $purpose): string
    {
        $ttl = $purpose === self::VERIFY_EMAIL ? 86400 : 3600;
        $plain = Base64Url::encode(random_bytes(32));
        $expires = gmdate('Y-m-d H:i:s', Clock::timestamp() + $ttl);
        $this->tokens->replace($userId, $purpose, hash('sha256', $plain), $expires);

        return $plain;
    }

    public function consume(string $plain, string $purpose): int
    {
        $row = $plain === '' ? null : $this->tokens->consume(hash('sha256', $plain), $purpose);
        if ($row === null) {
            throw DomainError::validation(['token' => '유효하지 않거나 만료된 링크입니다.']);
        }

        return (int) $row['user_id'];
    }
}
