<?php

declare(strict_types=1);

namespace ApiBoard\Http;

final class Cors
{
    /**
     * 화이트리스트에 정확히 일치하는 출처에만 헤더를 준다.
     * 와일드카드는 지원하지 않는다. 토큰을 헤더로 받는 API 에서
     * 모든 출처를 허용할 이유가 없다.
     */
    public static function headersFor(?string $origin, array $allowedOrigins): array
    {
        if ($origin === null || $origin === '') {
            return [];
        }
        if (!in_array($origin, $allowedOrigins, true)) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin'  => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Max-Age'       => '600',
            'Vary'                         => 'Origin',
        ];
    }
}
