<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use PHPUnit\Framework\TestCase;

final class EntryPointTest extends TestCase
{
    /**
     * 진입점 스크립트들이 fatal error 를 일으키지 않는지 확인한다.
     * www/install.php 가 삭제된 autoload.php 를 require 하는 버그를 감지한다.
     */
    public function testWebIndexPhpDoesNotProduceFatalError(): void
    {
        $path = __DIR__ . '/../../www/index.php';
        $this->assertEntryPointValid($path);
    }

    /**
     * www/install.php 가 올바른 autoload.php 를 require 하는지 확인한다.
     */
    public function testWebInstallPhpDoesNotProduceFatalError(): void
    {
        $path = __DIR__ . '/../../www/install.php';
        $this->assertEntryPointValid($path);
    }

    private function assertEntryPointValid(string $path): void
    {
        $output = [];
        $status = 0;
        exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);
        self::assertStringNotContainsString('Fatal error', $text, "Entry point {$path} produced a fatal error: {$text}");
        // index.php 는 require 전에 display_errors=0 을 설정하므로, fatal error 가 나도
        // 화면에는 아무것도 찍히지 않는다. 그래서 위 문자열 검사만으로는 출력이 비어 있는
        // 성공과 죽은 채로 조용히 끝난 실패를 구분하지 못한다. 종료 코드까지 확인한다.
        self::assertSame(0, $status, "Entry point {$path} exited with status {$status}: {$text}");
    }
}
