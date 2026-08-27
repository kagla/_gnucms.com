<?php

declare(strict_types=1);

namespace ApiBoard\Http;

use ApiBoard\Error\DomainError;
use ApiBoard\Support\Json;

final class Response implements ResponseInterface
{
    /** @var array */
    private $payload;

    /** @var int */
    private $status;

    /** @var array */
    private $headers;

    private function __construct(array $payload, int $status, array $headers)
    {
        $this->payload = $payload;
        $this->status = $status;
        $this->headers = $headers;
    }

    public static function json(array $payload, int $status = 200): self
    {
        return new self($payload, $status, []);
    }

    public static function fromError(DomainError $error, bool $debug): self
    {
        return new self([
            'error' => [
                'code'    => $error->code(),
                'message' => $debug || $error->code() !== 'INTERNAL'
                    ? $error->getMessage()
                    : '서버 오류가 발생했습니다.',
                'details' => (object) $error->details(),
            ],
        ], $error->status(), []);
    }

    public function withHeaders(array $headers): ResponseInterface
    {
        return new self($this->payload, $this->status, array_merge($this->headers, $headers));
    }

    public function status(): int
    {
        return $this->status;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo Json::encode($this->payload);
    }
}
