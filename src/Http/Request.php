<?php

declare(strict_types=1);

namespace StandardBoard\Http;

use StandardBoard\Support\Json;

final class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $path;

    /** @var array */
    private $query;

    /** @var array */
    private $body;

    /** @var array 헤더 이름은 소문자로 정규화해 보관한다 */
    private $headers;

    /** @var array */
    private $files;

    public function __construct(string $method, string $path, array $query, array $body, array $headers, array $files)
    {
        $this->method = strtoupper($method);
        $this->path = $path;
        $this->query = $query;
        $this->body = $body;
        $this->files = $files;

        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // mod_rewrite 가 없는 호스팅을 위해 ?p= 폴백을 지원한다.
        $path = (string) ($_SERVER['PATH_INFO'] ?? '');
        if ($path === '') {
            $path = (string) ($_GET['p'] ?? '/');
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $raw = (string) file_get_contents('php://input');
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $body = [];
        if (stripos($contentType, 'application/json') !== false) {
            $body = Json::decode($raw);
        } elseif ($_POST !== []) {
            $body = $_POST;
        }

        return new self($method, $path, $_GET, $body, self::readHeaders(), $_FILES);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return mixed */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function body(): array
    {
        return $this->body;
    }

    /** 본문을 먼저 보고 없으면 쿼리스트링을 본다. */
    public function input(string $key, $default = null)
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }

        return $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $value = $this->header('Authorization');
        if ($value === null || stripos($value, 'bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($value, 7));

        return $token === '' ? null : $token;
    }

    public function files(): array
    {
        return $this->files;
    }

    private static function readHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strncmp((string) $key, 'HTTP_', 5) === 0) {
                $name = str_replace('_', '-', substr((string) $key, 5));
                $headers[$name] = $value;
            }
        }

        // 일부 공유 호스팅(CGI)은 Authorization 을 HTTP_ 로 넘기지 않는다.
        if (!isset($headers['AUTHORIZATION'])) {
            $fallback = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
            if ($fallback !== null) {
                $headers['AUTHORIZATION'] = $fallback;
            }
        }

        return $headers;
    }
}
