<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Web\BasePath;
use PHPUnit\Framework\TestCase;

final class BasePathTest extends TestCase
{
    /** @dataProvider cases */
    public function testResolve(string $scriptName, string $requestUri, string $expected): void
    {
        self::assertSame($expected, BasePath::resolve($scriptName, $requestUri));
    }

    public static function cases(): array
    {
        return [
            // 문서 루트, mod_rewrite 있음: 요청 경로에 index.php 가 나타나지 않는다.
            'root, rewrite, /'          => ['/index.php', '/', ''],
            'root, rewrite, /b/free'    => ['/index.php', '/b/free', ''],

            // 문서 루트, mod_rewrite 없음: /index.php 를 그대로 적어 넣는다.
            'root, no rewrite, bare, no trailing slash' => ['/index.php', '/index.php', '/index.php'],
            'root, no rewrite, bare, trailing slash'    => ['/index.php', '/index.php/', '/index.php'],
            'root, no rewrite, with path'                => ['/index.php', '/index.php/b/free', '/index.php'],

            // 서브디렉터리, mod_rewrite 있음.
            'subdir, rewrite, /board/'        => ['/board/public/index.php', '/board/', '/board/public'],
            'subdir, rewrite, /board/b/free'  => ['/board/public/index.php', '/board/b/free', '/board/public'],

            // 서브디렉터리, mod_rewrite 없음.
            'subdir, no rewrite, bare, no trailing slash' => [
                '/board/public/index.php', '/board/public/index.php', '/board/public/index.php',
            ],
            'subdir, no rewrite, bare, trailing slash' => [
                '/board/public/index.php', '/board/public/index.php/', '/board/public/index.php',
            ],
        ];
    }
}
