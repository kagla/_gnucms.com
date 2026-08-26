<?php

declare(strict_types=1);

namespace StandardBoard\Support;

final class Base64Url
{
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        $padded = str_pad(strtr($encoded, '-_', '+/'), (int) (ceil(strlen($encoded) / 4) * 4), '=');
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }
}
