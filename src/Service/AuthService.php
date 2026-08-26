<?php

declare(strict_types=1);

namespace ApiBoard\Service;

use ApiBoard\Auth\TokenIssuer;
use ApiBoard\Http\ApiError;

/**
 * 호스트 앱이 없을 때를 위한 진입점. 호스트를 붙인 뒤에는 설정에서
 * bootstrap_admin 을 null 로 두어 이 경로를 닫는다.
 *
 * 관리자 진입점이 두 개가 되는 것이 아니다. 진입점은 서명된 토큰 하나이고
 * 그 토큰을 만드는 방법이 두 가지일 뿐이다.
 */
final class AuthService
{
    /** @var array|null */
    private $bootstrapAdmin;

    /** @var TokenIssuer */
    private $issuer;

    public function __construct(?array $bootstrapAdmin, TokenIssuer $issuer)
    {
        $this->bootstrapAdmin = $bootstrapAdmin;
        $this->issuer = $issuer;
    }

    public function login(string $id, string $password): string
    {
        $configuredId = (string) ($this->bootstrapAdmin['id'] ?? '');
        $configuredHash = (string) ($this->bootstrapAdmin['password_hash'] ?? '');

        if ($configuredId === '' || $configuredHash === '') {
            throw ApiError::unauthorized('부트스트랩 관리자가 비활성화되어 있습니다.');
        }

        // 아이디가 틀려도 해시 검증을 수행해 응답 시간으로 아이디 존재 여부가
        // 드러나지 않게 한다.
        $idMatches = hash_equals($configuredId, $id);
        $passwordMatches = password_verify($password, $configuredHash);

        if (!$idMatches || !$passwordMatches) {
            throw ApiError::unauthorized('아이디 또는 비밀번호가 올바르지 않습니다.');
        }

        return $this->issuer->issue($configuredId, '관리자', true);
    }
}
