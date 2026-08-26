<?php

declare(strict_types=1);

namespace ApiBoard\Support;

use ApiBoard\Http\ApiError;

final class Json
{
    /**
     * @param mixed $value
     */
    public static function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw ApiError::internal('JSON 인코딩에 실패했습니다: ' . json_last_error_msg());
        }

        return $json;
    }

    public static function decode(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $value = json_decode($json, true);
        if (!is_array($value)) {
            throw ApiError::validation(['body' => '올바른 JSON 이 아닙니다.']);
        }

        return $value;
    }
}
