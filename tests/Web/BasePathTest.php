<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\Web\BasePath;
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
            'root, rewrite, /boards/free'    => ['/index.php', '/boards/free', ''],

            // 문서 루트, mod_rewrite 없음: /index.php 를 그대로 적어 넣는다.
            'root, no rewrite, bare, no trailing slash' => ['/index.php', '/index.php', '/index.php'],
            'root, no rewrite, bare, trailing slash'    => ['/index.php', '/index.php/', '/index.php'],
            'root, no rewrite, with path'                => ['/index.php', '/index.php/boards/free', '/index.php'],

            // 서브디렉터리, mod_rewrite 있음.
            'subdir, rewrite, /board/'        => ['/board/public/index.php', '/board/', '/board/public'],
            'subdir, rewrite, /board/boards/free'  => ['/board/public/index.php', '/board/boards/free', '/board/public'],

            // 서브디렉터리, mod_rewrite 없음.
            'subdir, no rewrite, bare, no trailing slash' => [
                '/board/public/index.php', '/board/public/index.php', '/board/public/index.php',
            ],
            'subdir, no rewrite, bare, trailing slash' => [
                '/board/public/index.php', '/board/public/index.php/', '/board/public/index.php',
            ],
        ];
    }

    /**
     * public/index.php 의 "/index.php" -> "/index.php/" 리다이렉트 결정을 뽑아 둔
     * 순수 함수. 이 리다이렉트가 없으면 rewrite 가 없는 호스팅에서 방문자가
     * 가장 먼저 입력할 만한 주소가 404 가 났다 — 그 버그를 고친 코드 경로다.
     *
     * @dataProvider redirectCases
     */
    public function testRedirectTarget(string $scriptName, string $requestUri, ?string $expected): void
    {
        self::assertSame($expected, BasePath::redirectTarget($scriptName, $requestUri));
    }

    public static function redirectCases(): array
    {
        return [
            // 문서 루트, mod_rewrite 있음: 이미 "/" 경로로 들어오므로 리다이렉트 불필요.
            'root, rewrite, /'          => ['/index.php', '/', null],
            'root, rewrite, /boards/free'    => ['/index.php', '/boards/free', null],

            // 문서 루트, mod_rewrite 없음, 슬래시 없이: 리다이렉트 대상은 슬래시가 붙은 자기 자신.
            'root, no rewrite, bare, no trailing slash' => [
                '/index.php', '/index.php', '/index.php/',
            ],
            // 이미 슬래시가 붙어 있으면(=위 리다이렉트의 도착지) 리다이렉트하지 않는다 — 무한 루프 방지.
            'root, no rewrite, bare, trailing slash' => ['/index.php', '/index.php/', null],
            // 슬래시 뒤에 경로가 더 있으면 이미 올바른 요청이라 리다이렉트하지 않는다.
            'root, no rewrite, with path' => ['/index.php', '/index.php/boards/free', null],
            // 쿼리스트링은 리다이렉트 대상에 그대로 살아 있어야 한다.
            'root, no rewrite, bare, with query string' => [
                '/index.php', '/index.php?page=2', '/index.php/?page=2',
            ],

            // 서브디렉터리, mod_rewrite 있음: 이미 올바른 경로라 리다이렉트 불필요.
            'subdir, rewrite, /board/'        => ['/board/public/index.php', '/board/', null],
            'subdir, rewrite, /board/boards/free'  => ['/board/public/index.php', '/board/boards/free', null],

            // 서브디렉터리, mod_rewrite 없음, 슬래시 없이.
            'subdir, no rewrite, bare, no trailing slash' => [
                '/board/public/index.php', '/board/public/index.php', '/board/public/index.php/',
            ],
            // 도착지에는 리다이렉트하지 않는다 — 무한 루프 방지.
            'subdir, no rewrite, bare, trailing slash' => [
                '/board/public/index.php', '/board/public/index.php/', null,
            ],
            // 서브디렉터리에서도 쿼리스트링이 살아 있어야 한다.
            'subdir, no rewrite, bare, with query string' => [
                '/board/public/index.php', '/board/public/index.php?page=2', '/board/public/index.php/?page=2',
            ],
        ];
    }

    /**
     * 무한 리다이렉트가 되지 않는다는 것을 구성만으로 주장하지 않고 실제로 확인한다:
     * redirectTarget() 이 내놓은 대상을 다시 같은 함수에 넣으면(=브라우저가 Location
     * 을 따라간 다음 요청) 더 이상 리다이렉트 대상이 아니어야 한다.
     *
     * @dataProvider redirectingCases
     */
    public function testRedirectTargetNeverRedirectsAgain(string $scriptName, string $requestUri): void
    {
        $target = BasePath::redirectTarget($scriptName, $requestUri);
        self::assertNotNull($target, '이 케이스는 애초에 리다이렉트가 나야 한다');

        self::assertNull(
            BasePath::redirectTarget($scriptName, $target),
            "리다이렉트 대상 {$target} 을 다시 넣었더니 또 리다이렉트가 났다 (무한 루프)"
        );
    }

    public static function redirectingCases(): array
    {
        return [
            'root, no rewrite, bare'               => ['/index.php', '/index.php'],
            'root, no rewrite, bare, with query'    => ['/index.php', '/index.php?page=2'],
            'subdir, no rewrite, bare'              => ['/board/public/index.php', '/board/public/index.php'],
            'subdir, no rewrite, bare, with query'  => [
                '/board/public/index.php', '/board/public/index.php?page=2',
            ],
        ];
    }

    public function testSiblingUrlReplacesScriptFileName(): void
    {
        self::assertSame('/install.php', BasePath::siblingUrl('/index.php', 'install.php'));
        self::assertSame('/board/install.php', BasePath::siblingUrl('/board/index.php', 'install.php'));
        self::assertSame('/install.php', BasePath::siblingUrl('', 'install.php'));
        self::assertSame('/install.php', BasePath::siblingUrl('index.php', 'install.php'));
    }
}
