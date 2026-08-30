<?php

declare(strict_types=1);

namespace GnuCms\View;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Views\Twig;
use Slim\Views\TwigRuntimeLoader;

/** 지금까지의 Twig 렌더링을 그대로 감싼다. Twig 를 걷어낼 때 이 파일째 지운다. */
final class TwigView implements ViewInterface
{
    private Twig $twig;
    private RouteParserInterface $routes;
    private string $basePath;

    public function __construct(Twig $twig, RouteParserInterface $routes, string $basePath)
    {
        $this->twig = $twig;
        $this->routes = $routes;
        $this->basePath = $basePath;
    }

    public function twig(): Twig
    {
        return $this->twig;
    }

    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        return $this->twig->render($response, $template . '.html.twig', $data);
    }

    public function fetch(string $template, array $data = []): string
    {
        return $this->twig->fetch($template . '.html.twig', $data);
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->getEnvironment()->addGlobal($name, $value);
    }

    public function bindRequest(ServerRequestInterface $request): void
    {
        // TwigMiddleware 가 나중에 같은 값으로 다시 달아도 문제없다. 먼저 단 것이 쓰인다.
        $this->twig->addRuntimeLoader(new TwigRuntimeLoader($this->routes, $request->getUri(), $this->basePath));
    }
}
