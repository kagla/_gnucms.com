<?php

declare(strict_types=1);

namespace StandardBoard\Auth;

use StandardBoard\Http\ApiError;
use StandardBoard\Support\Base64Url;
use StandardBoard\Support\Clock;
use StandardBoard\Support\Json;

final class TokenVerifier
{
    /** @var string */
    private $secret;

    /** @var int */
    private $leeway;

    public function __construct(string $secret, int $leeway)
    {
        $this->secret = $secret;
        $this->leeway = $leeway;
    }

    public function verify(?string $jwt): Identity
    {
        if ($jwt === null || trim($jwt) === '') {
            return Identity::guest();
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw ApiError::unauthorized('토큰 형식이 올바르지 않습니다.');
        }
        [$header, $payload, $signature] = $parts;

        $decodedHeader = Json::decode(Base64Url::decode($header));
        if (($decodedHeader['alg'] ?? '') !== 'HS256') {
            throw ApiError::unauthorized('지원하지 않는 토큰 알고리즘입니다.');
        }

        $expected = Base64Url::encode(hash_hmac('sha256', $header . '.' . $payload, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            throw ApiError::unauthorized('토큰 서명이 올바르지 않습니다.');
        }

        $claims = Json::decode(Base64Url::decode($payload));

        if (!isset($claims['exp'])) {
            throw ApiError::unauthorized('토큰에 만료 시각이 없습니다.');
        }
        if (Clock::timestamp() > ((int) $claims['exp'] + $this->leeway)) {
            throw ApiError::unauthorized('토큰이 만료되었습니다.');
        }

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            throw ApiError::unauthorized('토큰에 사용자 식별자가 없습니다.');
        }

        return Identity::user($sub, (string) ($claims['name'] ?? $sub), (bool) ($claims['admin'] ?? false));
    }
}
