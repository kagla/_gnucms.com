<?php

declare(strict_types=1);

namespace StandardBoard\Http;

interface ResponseInterface
{
    public function status(): int;

    public function withHeaders(array $headers): ResponseInterface;

    public function send(): void;
}
