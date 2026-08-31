<?php

declare(strict_types=1);

namespace GnuCms\Tests\Theme;

use GnuCms\Theme\ThemeManager;
use PHPUnit\Framework\TestCase;

final class ThemeManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . GNUCMS_ID . '-theme-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/templates/default', 0777, true);
        mkdir($this->root . '/templates/modern', 0777, true);
        mkdir($this->root . '/www/themes/default', 0777, true);
        mkdir($this->root . '/www/themes/modern', 0777, true);
        // theme.php 가 있어야 테마다.
        file_put_contents($this->root . '/templates/default/theme.php', "<?php return ['label' => '기본'];");
        file_put_contents($this->root . '/templates/modern/theme.php', "<?php return ['label' => '모던'];");
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

    public function testTemplatePathsPointAtTheSelectedThemeOnly(): void
    {
        self::assertSame([$this->root . '/templates/modern'], $this->manager('modern')->templatePaths());
        self::assertSame([$this->root . '/templates/default'], $this->manager('default')->templatePaths());
    }

    public function testAssetUsesSelectedFileAndFallsBackToDefault(): void
    {
        file_put_contents($this->root . '/www/themes/default/theme.css', 'default');
        file_put_contents($this->root . '/www/themes/default/logo.png', 'default logo');
        file_put_contents($this->root . '/www/themes/modern/theme.css', 'modern');

        $themes = $this->manager('modern');

        self::assertSame(
            '/community/themes/modern/theme.css?v='
                . substr(hash_file('sha256', $this->root . '/www/themes/modern/theme.css'), 0, 12),
            $themes->assetUrl('theme.css', '/community')
        );
        self::assertSame(
            '/community/themes/default/logo.png?v='
                . substr(hash_file('sha256', $this->root . '/www/themes/default/logo.png'), 0, 12),
            $themes->assetUrl('logo.png', '/community')
        );
    }

    public function testUnknownOrUnsafeThemeFallsBackToDefault(): void
    {
        self::assertSame('default', $this->manager('missing')->name());
        self::assertSame('default', $this->manager('../modern')->name());
    }

    public function testAvailableThemesNeedAManifest(): void
    {
        // 화면 없이 폴더만 있는 것(옛 테마 보관본)은 목록에 오르지 않는다.
        mkdir($this->root . '/templates/archive', 0777, true);
        mkdir($this->root . '/www/themes/minimal', 0777, true);

        self::assertSame(['default', 'modern'], $this->manager('default')->availableThemes());
        self::assertSame('default', $this->manager('archive')->name(), 'theme.php 없는 폴더는 고를 수 없다');
    }

    public function testManifestGivesTheLabel(): void
    {
        self::assertSame('모던', $this->manager('modern')->manifest()['label']);
    }

    public function testThrowingManifestDoesNotKillKernelCreate(): void
    {
        $root = sys_get_temp_dir() . '/gnucms-theme-throw-' . getmypid();
        @mkdir($root . '/default', 0777, true);
        @mkdir($root . '/broken', 0777, true);
        file_put_contents($root . '/default/theme.php', "<?php return [];");
        file_put_contents($root . '/broken/theme.php', "<?php throw new \\RuntimeException('boom');");
        try {
            // 깨진 매니페스트는 빈 배열이고, 그 테마로는 화면을 그릴 수 없으니 이름도 default 로 떨어진다.
            self::assertSame([], (new ThemeManager($root, $root, 'broken'))->manifest());
        } finally {
            @unlink($root . '/broken/theme.php');
            @unlink($root . '/default/theme.php');
            @rmdir($root . '/broken');
            @rmdir($root . '/default');
            @rmdir($root);
        }
    }

    private function manager(string $theme): ThemeManager
    {
        return new ThemeManager(
            $this->root . '/templates',
            $this->root . '/www/themes',
            $theme
        );
    }
}
