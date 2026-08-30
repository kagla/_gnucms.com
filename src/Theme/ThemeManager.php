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

    /** 테마 폴더의 theme.php 가 돌려주는 배열. 없으면 빈 배열(= Twig 테마). */
    public function manifest(): array
    {
        $file = $this->templateRoot . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR . 'theme.php';
        if (!is_file($file)) {
            return [];
        }
        try {
            $loaded = include $file;
        } catch (\Throwable $e) {
            // 매니페스트가 깨졌다고 사이트 전체가 죽으면 안 된다. Twig 테마로 본다.
            return [];
        }
        return is_array($loaded) ? $loaded : [];
    }

    /** 'php' 또는 'twig'. 매니페스트가 php 라고 하지 않으면 Twig 다. */
    public function engine(): string
    {
        return ($this->manifest()['engine'] ?? 'twig') === 'php' ? 'php' : 'twig';
    }

    /** PHP 엔진이 볼 템플릿 경로. 지금은 선택 테마 하나뿐이다. */
    public function phpTemplatePaths(): array
    {
        return [$this->templateRoot . DIRECTORY_SEPARATOR . $this->name];
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
