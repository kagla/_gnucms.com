<?php

declare(strict_types=1);

namespace ApiBoard\Auth;

use ApiBoard\Support\Base64Url;
use ApiBoard\Support\Clock;
use ApiBoard\Support\Json;

final class TokenIssuer
{
    /** @var string */
    private $secret;

    /** @var int */
    private $ttl;

    public function __construct(string $secret, int $ttl)
    {
        $this->secret = $secret;
        $this->ttl = $ttl;
    }

    public function issue(string $sub, string $name, bool $admin): string
    {
        $issuedAt = Clock::timestamp();

        $header = Base64Url::encode(Json::encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = Base64Url::encode(Json::encode([
            'sub'   => $sub,
            'name'  => $name,
            'admin' => $admin,
            'iat'   => $issuedAt,
            'exp'   => $issuedAt + $this->ttl,
        ]));

        $signature = Base64Url::encode(
            hash_hmac('sha256', $header . '.' . $payload, $this->secret, true)
        );

        return $header . '.' . $payload . '.' . $signature;
    }
}
