<?php

declare(strict_types=1);

namespace StandardBoard\Http;

final class Router
{
    /** @var array<int, array{method: string, regex: string, names: string[], handler: callable}> */
    private $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}/i',
            static function (array $m) use (&$names): string {
                $names[] = $m[1];

                return '([^\\/]+)';
            },
            str_replace('/', '\/', $pattern)
        );

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '/^' . $regex . '$/',
            'names'   => $names,
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function dispatch(Request $request): ResponseInterface
    {
        $path = $request->path();
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            array_shift($matches);
            $params = [];
            foreach ($route['names'] as $index => $name) {
                $params[$name] = rawurldecode($matches[$index]);
            }

            return $route['handler']($request, $params);
        }

        throw ApiError::notFound('요청한 경로를 찾을 수 없습니다: ' . $request->method() . ' ' . $path);
    }
}
