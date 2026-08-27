<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use PHPUnit\Framework\TestCase;

class EntryPointTest extends TestCase
{
    /**
     * 진입점 스크립트들이 fatal error 를 일으키지 않는지 확인한다.
     * public/install.php 가 삭제된 autoload.php 를 require 하는 버그를 감지한다.
     */
    public function testPublicIndexPhpDoesNotProduceFatalError(): void
    {
        $path = __DIR__ . '/../../public/index.php';
        $this->assertEntryPointValid($path);
    }

    /**
     * public/install.php 가 올바른 autoload.php 를 require 하는지 확인한다.
     */
    public function testPublicInstallPhpDoesNotProduceFatalError(): void
    {
        $path = __DIR__ . '/../../public/install.php';
        $this->assertEntryPointValid($path);
    }

    private function assertEntryPointValid(string $path): void
    {
        $output = [];
        $status = 0;
        exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);
        self::assertStringNotContainsString('Fatal error', $text, "Entry point {$path} produced a fatal error: {$text}");
    }
}
