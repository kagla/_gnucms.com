<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Cms;

use ApiBoard\Cms\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testItKeepsEditorMarkupAndRemovesExecutableHtml(): void
    {
        $html = '<h2>안내</h2><p><strong>중요</strong>'
            . '<img src="/media/editor/2026/08/example.png" onerror="alert(1)"></p>'
            . '<script>alert(2)</script><a href="javascript:alert(3)">위험</a>';

        $clean = (new HtmlSanitizer())->clean($html);

        self::assertStringContainsString('<h2>안내</h2>', $clean);
        self::assertStringContainsString('<strong>중요</strong>', $clean);
        self::assertStringContainsString('src="/media/editor/2026/08/example.png"', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertStringNotContainsString('<script', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
    }

    public function testLegacyPlainTextKeepsLineBreaks(): void
    {
        $clean = (new HtmlSanitizer())->clean("첫 줄\n둘째 줄");

        self::assertSame("<p>첫 줄<br />\n둘째 줄</p>", $clean);
    }
}
