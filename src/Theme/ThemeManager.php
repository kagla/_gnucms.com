<?php

declare(strict_types=1);

namespace ApiBoard\Theme;

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

    /** 선택 테마를 먼저 찾고, 파일이 없으면 default 에서 찾는다. */
    public function templatePaths(): array
    {
        $paths = [];
        if ($this->name !== self::DEFAULT_THEME) {
            $selected = $this->templateRoot . DIRECTORY_SEPARATOR . $this->name;
            if (is_dir($selected)) {
                $paths[] = $selected;
            }
        }

        $default = $this->templateRoot . DIRECTORY_SEPARATOR . self::DEFAULT_THEME;
        if (!is_dir($default)) {
            throw new InvalidArgumentException('기본 템플릿 디렉터리를 찾을 수 없습니다: ' . $default);
        }
        $paths[] = $default;
        $paths['default'] = $default;

        return $paths;
    }

    /** @return string[] */
    public function availableThemes(): array
    {
        $themes = [self::DEFAULT_THEME => true];
        foreach ([$this->templateRoot, $this->assetRoot] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $entries = scandir($root);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($this->isValidName($entry) && is_dir($root . DIRECTORY_SEPARATOR . $entry)) {
                    $themes[$entry] = true;
                }
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
        $modifiedAt = is_file($file) ? filemtime($file) : false;

        return $modifiedAt === false ? $url : $url . '?v=' . $modifiedAt;
    }

    private function isAvailable(string $theme): bool
    {
        if (!$this->isValidName($theme)) {
            return false;
        }

        return is_dir($this->templateRoot . DIRECTORY_SEPARATOR . $theme)
            || is_dir($this->assetRoot . DIRECTORY_SEPARATOR . $theme);
    }

    private function isValidName(string $theme): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $theme) === 1;
    }

    private function validatedAssetPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path, '/'));
        if ($path === '' || str_contains($path, "\0")) {
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
