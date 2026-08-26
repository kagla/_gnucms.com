<?php

declare(strict_types=1);

namespace StandardBoard;

use StandardBoard\Http\ApiError;
use StandardBoard\Support\Env;

/**
 * 설정 한 벌을 세 겹으로 만든다. 뒤에 오는 것이 앞을 덮는다.
 *
 *   1. 이 클래스의 기본값
 *   2. config/config.php  — 설치 마법사가 만드는 파일. 손대지 않아도 된다
 *   3. .env               — 환경마다 달라지는 값. PHP 를 편집하지 않고 바꾼다
 *   4. 진짜 환경변수      — 도커나 패널에서 주입하는 값. .env 보다 세다
 *
 * .env 를 쓰는 이유는 값을 바꾸려고 PHP 파일을 열지 않기 위해서다. 문법 오류
 * 하나로 사이트 전체가 죽는 파일과, 값만 적는 파일은 위험도가 다르다.
 *
 * config.php 를 없애지 않은 이유는 두 가지다. 설치 마법사가 만들어 낸 시크릿과
 * 비밀번호 해시를 담을 곳이 필요하고, 문서 루트를 옮길 수 없는 호스팅에서
 * .env 는 평문으로 노출될 수 있지만 PHP 파일은 실행되어 아무것도 내보내지
 * 않기 때문이다. 그래서 .env 는 선택이고, 있으면 이긴다.
 */
final class Config
{
    /**
     * .env 키 -> [설정 경로, 형 변환]. 여기 없는 키는 무시한다.
     * 설정 표면을 좁게 유지하는 것이 목적이다.
     */
    private const MAP = [
        'DB_DSN'                        => ['db.dsn', 'string'],
        'DB_USERNAME'                   => ['db.username', 'nullable'],
        'DB_PASSWORD'                   => ['db.password', 'nullable'],
        'AUTH_SECRET'                   => ['auth.secret', 'string'],
        'AUTH_TTL'                      => ['auth.ttl', 'int'],
        'AUTH_LEEWAY'                   => ['auth.leeway', 'int'],
        'BOOTSTRAP_ADMIN_ID'            => ['bootstrap_admin.id', 'string'],
        'BOOTSTRAP_ADMIN_PASSWORD_HASH' => ['bootstrap_admin.password_hash', 'string'],
        'UPLOADS_DIR'                   => ['uploads.dir', 'string'],
        'UPLOADS_MAX_BYTES'             => ['uploads.max_bytes', 'int'],
        'UPLOADS_ALLOWED_EXT'           => ['uploads.allowed_ext', 'list'],
        'CORS_ALLOWED_ORIGINS'          => ['cors.allowed_origins', 'list'],
        'LOG_FILE'                      => ['log.file', 'nullable'],
        'DEBUG'                         => ['debug', 'bool'],
    ];

    /** 부트스트랩 관리자 경로를 통째로 닫는 스위치. 나머지 키보다 나중에 적용한다. */
    private const BOOTSTRAP_SWITCH = 'BOOTSTRAP_ADMIN_ENABLED';

    private const TRUE_VALUES = ['1', 'true', 'yes', 'on'];
    private const FALSE_VALUES = ['0', 'false', 'no', 'off', ''];

    public static function load(string $configFile, string $envFile, string $basePath): array
    {
        $config = self::defaults($basePath);

        if (is_file($configFile)) {
            $fromFile = require $configFile;
            if (!is_array($fromFile)) {
                throw ApiError::internal('설정 파일이 배열을 반환하지 않았습니다: ' . $configFile);
            }
            $config = self::merge($config, $fromFile);
        }

        return self::applyEnv($config, Env::parseFile($envFile));
    }

    public static function defaults(string $basePath): array
    {
        $basePath = rtrim($basePath, '/');

        return [
            'db' => [
                'dsn'      => '',
                'username' => null,
                'password' => null,
            ],
            'auth' => [
                'secret' => '',
                'ttl'    => 3600,
                'leeway' => 60,
            ],
            // 기본은 닫힘이다. 설치 마법사나 .env 가 명시적으로 열어야 한다.
            'bootstrap_admin' => null,
            'uploads' => [
                'dir'         => $basePath . '/storage/uploads',
                'max_bytes'   => 5 * 1024 * 1024,
                'allowed_ext' => [
                    'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip', 'txt',
                    'hwp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                ],
            ],
            'cors' => [
                'allowed_origins' => [],
            ],
            'log' => [
                'file' => $basePath . '/storage/logs/error.log',
            ],
            'debug' => false,
        ];
    }

    /**
     * 두 단계까지만 병합한다. 설정이 그보다 깊지 않고, 재귀 병합은
     * allowed_ext 같은 목록을 인덱스별로 섞어 놓기 때문이다.
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !self::isList($value)) {
                $base[$key] = array_replace($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /** @param array<string, string> $fromFile */
    private static function applyEnv(array $config, array $fromFile): array
    {
        foreach (self::MAP as $key => $spec) {
            $raw = self::lookup($key, $fromFile);
            if ($raw === null) {
                continue;
            }
            $config = self::setPath($config, $spec[0], self::cast($key, $raw, $spec[1]));
        }

        $switch = self::lookup(self::BOOTSTRAP_SWITCH, $fromFile);
        if ($switch !== null && !self::cast(self::BOOTSTRAP_SWITCH, $switch, 'bool')) {
            $config['bootstrap_admin'] = null;
        }

        return $config;
    }

    /**
     * 진짜 환경변수를 .env 보다 먼저 본다.
     *
     * 요청 헤더는 언제나 HTTP_ 접두사가 붙어 $_SERVER 에 들어오므로,
     * 정확한 이름만 찾는 이 조회로는 밖에서 설정을 밀어 넣을 수 없다.
     *
     * @param array<string, string> $fromFile
     */
    private static function lookup(string $key, array $fromFile): ?string
    {
        foreach ([$_SERVER, $_ENV] as $source) {
            if (isset($source[$key]) && is_scalar($source[$key])) {
                return (string) $source[$key];
            }
        }

        $value = getenv($key);
        if (is_string($value)) {
            return $value;
        }

        return array_key_exists($key, $fromFile) ? $fromFile[$key] : null;
    }

    /** @return mixed */
    private static function cast(string $key, string $raw, string $type)
    {
        switch ($type) {
            case 'int':
                if (preg_match('/^-?\d+$/', trim($raw)) !== 1) {
                    throw ApiError::internal($key . ' 은 정수여야 합니다: ' . $raw);
                }

                return (int) trim($raw);

            case 'bool':
                $normalized = strtolower(trim($raw));
                if (in_array($normalized, self::TRUE_VALUES, true)) {
                    return true;
                }
                if (in_array($normalized, self::FALSE_VALUES, true)) {
                    return false;
                }

                throw ApiError::internal(
                    $key . ' 은 true 또는 false 여야 합니다: ' . $raw
                );

            case 'list':
                $items = [];
                foreach (explode(',', $raw) as $item) {
                    $item = trim($item);
                    if ($item !== '' && !in_array($item, $items, true)) {
                        $items[] = $item;
                    }
                }

                return $items;

            case 'nullable':
                return $raw === '' ? null : $raw;
        }

        return $raw;
    }

    /** @param mixed $value */
    private static function setPath(array $config, string $path, $value): array
    {
        $segments = explode('.', $path);
        if (count($segments) === 1) {
            $config[$segments[0]] = $value;

            return $config;
        }

        [$section, $field] = $segments;
        if (!isset($config[$section]) || !is_array($config[$section])) {
            $config[$section] = [];
        }
        $config[$section][$field] = $value;

        return $config;
    }
}
