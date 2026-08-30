<?php

declare(strict_types=1);

namespace GnuCms\View;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\Interfaces\RouteParserInterface;

/**
 * PHP 파일 템플릿 엔진. 경로 목록에서 '{이름}.php' 를 찾아 PhpTemplate 로 돌린다.
 * 지금은 경로가 하나(선택 테마)뿐이다. 나중에 PHP 테마끼리 폴백할 때 둘 이상이 된다.
 */
final class PhpView implements ViewInterface
{
    /** @var string[] */
    private array $paths;

    private RouteParserInterface $routes;

    private string $basePath;

    /** @var callable(string):string */
    private $assetUrl;

    /** @var callable(string):string */
    private $htmlRenderer;

    /** @var array<string,mixed> */
    private array $globals = [];

    /** @var array<string,string>|null _icons.php 를 한 번만 읽는다 */
    private ?array $icons = null;

    public function __construct(
        array $paths,
        RouteParserInterface $routes,
        string $basePath,
        callable $assetUrl,
        callable $htmlRenderer
    ) {
        $this->paths = array_values(array_map(static fn (string $p): string => rtrim($p, '/'), $paths));
        $this->routes = $routes;
        $this->basePath = $basePath;
        $this->assetUrl = $assetUrl;
        $this->htmlRenderer = $htmlRenderer;
    }

    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        $response->getBody()->write($this->fetch($template, $data));
        return $response;
    }

    public function fetch(string $template, array $data = []): string
    {
        // 이름이 겹치면 데이터가 전역을 이긴다.
        return (new PhpTemplate($this, $data + $this->globals, $this->basePath))->run($template);
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
    }

    /** @return array<string,mixed> */
    public function globals(): array
    {
        return $this->globals;
    }


    /** 조각이 있는가. 목록 형태처럼 없을 수 있는 조각을 고를 때 쓴다. */
    public function exists(string $template): bool
    {
        try {
            $this->resolve($template);
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /** '{이름}.php' 의 실제 경로. 없으면 예외 — 다른 테마로 조용히 폴백하지 않는다. */
    public function resolve(string $template): string
    {
        if ($template === '' || str_contains($template, '..') || str_contains($template, "\0")) {
            throw new RuntimeException('템플릿 이름이 올바르지 않습니다: ' . $template);
        }
        foreach ($this->paths as $path) {
            $file = $path . '/' . $template . '.php';
            if (is_file($file)) {
                return $file;
            }
        }
        throw new RuntimeException('템플릿을 찾을 수 없습니다: ' . $template . '.php');
    }

    public function url(string $route, array $params = [], array $query = []): string
    {
        // Twig 판은 slim/twig-view 가 url_for 를 is_safe 없이 등록해 결과를 자동
        // 이스케이프했다. 주소에 &·" 가 섞일 수 있으므로 여기서도 똑같이 거른다.
        return $this->escape($this->routes->urlFor($route, $params, $query));
    }

    public function asset(string $path): string
    {
        // theme_asset 도 Kernel 이 is_safe 없이 등록했다(=Twig 가 이스케이프했다). 같게 맞춘다.
        return $this->escape(($this->assetUrl)($path));
    }

    private function escape(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function html(string $content): string
    {
        return ($this->htmlRenderer)($content);
    }

    /** @return array<string,string> */
    public function icons(): array
    {
        if ($this->icons === null) {
            $this->icons = [];
            foreach ($this->paths as $path) {
                $file = $path . '/_icons.php';
                if (is_file($file)) {
                    $loaded = include $file;
                    $this->icons = is_array($loaded) ? $loaded : [];
                    break;
                }
            }
        }
        return $this->icons;
    }
}
