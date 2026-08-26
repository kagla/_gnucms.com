<?php

declare(strict_types=1);

namespace StandardBoard\Support;

use StandardBoard\Http\ApiError;

/**
 * 최소한의 .env 파서. 런타임 의존성 0 을 지켜야 하므로 직접 만든다.
 *
 * 지원하지 않는 것을 먼저 적는다. 셸 문법을 흉내 내면 설정 파일이
 * 프로그램이 되고, 그러면 config.php 를 피한 이유가 사라진다.
 *
 *  - 인라인 주석을 지원하지 않는다. 주석은 줄 전체(`#` 로 시작)만이다.
 *    `DB_PASSWORD=p@ss#word` 의 `#` 뒤를 잘라 버리면 조용히 틀린 비밀번호가 된다.
 *  - 변수 치환(`${OTHER}`), 명령 치환, 여러 줄 값을 지원하지 않는다.
 *  - 형식이 어긋난 줄은 건너뛰지 않고 오류로 만든다. 오타 하나가
 *    설정 누락으로 둔갑하면 원인을 찾는 데 훨씬 오래 걸린다.
 */
final class Env
{
    /** @return array<string, string> */
    public static function parseFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw ApiError::internal('.env 파일을 읽지 못했습니다: ' . $path);
        }

        return self::parse($contents);
    }

    /** @return array<string, string> */
    public static function parse(string $contents): array
    {
        $values = [];

        $lines = explode("\n", str_replace("\r", '', $contents));
        foreach ($lines as $index => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (strncmp($line, 'export ', 7) === 0) {
                $line = ltrim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                throw self::malformed($index, 'KEY=값 형태가 아닙니다');
            }

            $key = rtrim(substr($line, 0, $separator));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw self::malformed($index, '이름으로 쓸 수 없습니다: ' . $key);
            }

            $values[$key] = self::value(ltrim(substr($line, $separator + 1)));
        }

        return $values;
    }

    private static function value(string $raw): string
    {
        $raw = rtrim($raw);
        $length = strlen($raw);

        if ($length >= 2 && $raw[0] === "'" && $raw[$length - 1] === "'") {
            return substr($raw, 1, -1);
        }

        if ($length >= 2 && $raw[0] === '"' && $raw[$length - 1] === '"') {
            return strtr(substr($raw, 1, -1), [
                '\\n'  => "\n",
                '\\r'  => "\r",
                '\\t'  => "\t",
                '\\"'  => '"',
                '\\\\' => '\\',
            ]);
        }

        return $raw;
    }

    private static function malformed(int $index, string $reason): ApiError
    {
        return ApiError::internal('.env ' . ($index + 1) . '번째 줄을 읽을 수 없습니다: ' . $reason);
    }
}
