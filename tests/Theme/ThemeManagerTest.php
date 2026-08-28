<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Theme;

use ApiBoard\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

final class ThemeManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aboard-theme-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/templates/default', 0777, true);
        mkdir($this->root . '/templates/modern', 0777, true);
        mkdir($this->root . '/public/themes/default', 0777, true);
        mkdir($this->root . '/public/themes/modern', 0777, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->root);
    }

    public function testTwigUsesSelectedTemplateAndFallsBackToDefault(): void
    {
        file_put_contents($this->root . '/templates/default/page.html.twig', 'default page');
        file_put_contents($this->root . '/templates/default/layout.html.twig', 'default layout');
        file_put_contents($this->root . '/templates/modern/page.html.twig', 'modern page');

        $themes = $this->manager('modern');
        $twig = Twig::create($themes->templatePaths(), ['cache' => false]);

        self::assertSame('modern page', $twig->fetch('page.html.twig'));
        self::assertSame('default layout', $twig->fetch('layout.html.twig'));
        self::assertSame('default layout', $twig->fetch('@default/layout.html.twig'));
    }

    public function testAssetUsesSelectedFileAndFallsBackToDefault(): void
    {
        file_put_contents($this->root . '/public/themes/default/theme.css', 'default');
        file_put_contents($this->root . '/public/themes/default/logo.png', 'default logo');
        file_put_contents($this->root . '/public/themes/modern/theme.css', 'modern');

        $themes = $this->manager('modern');

        self::assertSame(
            '/community/themes/modern/theme.css?v='
                . filemtime($this->root . '/public/themes/modern/theme.css'),
            $themes->assetUrl('theme.css', '/community')
        );
        self::assertSame(
            '/community/themes/default/logo.png?v='
                . filemtime($this->root . '/public/themes/default/logo.png'),
            $themes->assetUrl('logo.png', '/community')
        );
    }

    public function testUnknownOrUnsafeThemeFallsBackToDefault(): void
    {
        self::assertSame('default', $this->manager('missing')->name());
        self::assertSame('default', $this->manager('../modern')->name());
    }

    public function testAvailableThemesAreDiscoveredFromTemplatesAndAssets(): void
    {
        mkdir($this->root . '/public/themes/minimal', 0777, true);

        self::assertSame(['default', 'minimal', 'modern'], $this->manager('default')->availableThemes());
    }

    private function manager(string $theme): ThemeManager
    {
        return new ThemeManager(
            $this->root . '/templates',
            $this->root . '/public/themes',
            $theme
        );
    }
}
