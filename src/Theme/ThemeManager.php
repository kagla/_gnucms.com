<?php

declare(strict_types=1);

namespace GnuCms\Theme;

use InvalidArgumentException;

final class ThemeManager
{
    public const DEFAULT_THEME = 'default';

    private string $templateRoot;
    private string $assetRoot;
    private string $name;

    public function __construct(string $templateRoot, string $assetRoot, string $requestedTheme)
    {
        $this->templateRoot = rtrim($templateRoot, DIRECTORY_SEPARATOR);
        $this->assetRoot = rtrim($assetRoot, DIRECTORY_SEPARATOR);
        $this->name = $this->isAvailable($requestedTheme) ? $requestedTheme : self::DEFAULT_THEME;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * 템플릿 경로. 지금은 선택 테마 하나뿐이다. 나중에 테마끼리 폴백하고 싶으면
     * 여기에 default 를 뒤에 더하면 PhpView 가 차례로 찾는다.
     */
    public function templatePaths(): array
    {
        return [$this->templateRoot . DIRECTORY_SEPARATOR . $this->name];
    }

    /**
     * 테마 폴더의 theme.php 가 돌려주는 배열(label 등). 이 파일이 있어야 테마로 친다 —
     * .php 화면 없이 폴더만 있는 것(옛 테마 보관본 등)을 골라 500 이 나는 일을 막는다.
     */
    public function manifest(): array
    {
        return self::readManifest($this->templateRoot . DIRECTORY_SEPARATOR . $this->name);
    }

    private static function readManifest(string $dir): array
    {
        $file = $dir . DIRECTORY_SEPARATOR . 'theme.php';
        if (!is_file($file)) {
            return [];
        }
        try {
            $loaded = include $file;
        } catch (\Throwable $e) {
            // 매니페스트가 깨졌다고 사이트 전체가 죽으면 안 된다. 테마가 없는 것으로 본다.
            return [];
        }
        return is_array($loaded) ? $loaded : [];
    }

    /** @return string[] theme.php 를 가진 템플릿 폴더. default 가 맨 앞이다. */
    public function availableThemes(): array
    {
        $themes = [self::DEFAULT_THEME => true];
        $entries = is_dir($this->templateRoot) ? scandir($this->templateRoot) : false;
        foreach ($entries === false ? [] : $entries as $entry) {
            $dir = $this->templateRoot . DIRECTORY_SEPARATOR . $entry;
            if ($this->isValidName($entry) && is_dir($dir) && is_file($dir . DIRECTORY_SEPARATOR . 'theme.php')) {
                $themes[$entry] = true;
            }
        }

        $names = array_keys($themes);
        sort($names, SORT_STRING);
        if (($index = array_search(self::DEFAULT_THEME, $names, true)) !== false) {
            unset($names[$index]);
        }
        array_unshift($names, self::DEFAULT_THEME);

        return array_values($names);
    }

    /** 선택 테마에 파일이 없으면 default 정적 파일 주소를 돌려준다. */
    public function assetUrl(string $path, string $basePath = ''): string
    {
        $path = $this->validatedAssetPath($path);
        $theme = $this->name;
        if ($theme !== self::DEFAULT_THEME
            && !is_file($this->assetRoot . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . $path)) {
            $theme = self::DEFAULT_THEME;
        }

        $file = $this->assetRoot . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . $path;
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
        $url = rtrim($basePath, '/') . '/themes/' . rawurlencode($theme) . '/' . $encodedPath;
        $version = is_file($file) ? hash_file('sha256', $file) : false;

        // 초 단위 수정 시각은 짧은 시간에 같은 파일을 여러 번 고치면 값이 같을 수 있다.
        // 내용 해시를 쓰면 브라우저나 프록시가 이전 CSS/JS를 재사용하지 않는다.
        return $version === false ? $url : $url . '?v=' . substr($version, 0, 12);
    }

    private function isAvailable(string $theme): bool
    {
        if (!$this->isValidName($theme)) {
            return false;
        }

        // 화면(.php)을 가진 테마여야 한다. theme.php 가 그 표식이다.
        return is_file($this->templateRoot . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . 'theme.php');
    }

    private function isValidName(string $theme): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $theme) === 1;
    }

    private function validatedAssetPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path, '/'));
        if ($path === '' || strpos($path, "\0") !== false) {
            throw new InvalidArgumentException('테마 파일 경로가 올바르지 않습니다.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..'
                || preg_match('/^[A-Za-z0-9._-]+$/D', $segment) !== 1) {
                throw new InvalidArgumentException('테마 파일 경로가 올바르지 않습니다.');
            }
        }

        return $path;
    }
}
