<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Error\DomainError;
use GnuCms\Support\Base64Url;
use GnuCms\Support\Clock;

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

    /** 사용 여부와 관계없이 실제로 발급했던 토큰의 회원 번호를 찾는다. */
    public function ownerOf(string $plain, string $purpose): ?int
    {
        if ($plain === '') {
            return null;
        }
        $row = $this->tokens->find(hash('sha256', $plain), $purpose);

        return $row === null ? null : (int) $row['user_id'];
    }
}
