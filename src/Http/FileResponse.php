<?php

declare(strict_types=1);

namespace ApiBoard\Http;

final class FileResponse implements ResponseInterface
{
    /** @var string */
    private $path;

    /** @var string */
    private $downloadName;

    /** @var string */
    private $mime;

    /** @var array */
    private $headers;

    public function __construct(string $path, string $downloadName, string $mime, array $headers = [])
    {
        $this->path = $path;
        $this->downloadName = $downloadName;
        $this->mime = $mime;
        $this->headers = $headers;
    }

    public function status(): int
    {
        return 200;
    }

    public function withHeaders(array $headers): ResponseInterface
    {
        return new self($this->path, $this->downloadName, $this->mime, array_merge($this->headers, $headers));
    }

    public function send(): void
    {
        http_response_code(200);
        header('Content-Type: ' . $this->mime);
        header('Content-Length: ' . (string) filesize($this->path));
        header('X-Content-Type-Options: nosniff');

        // 한글 파일명을 위해 RFC 5987 형식을 함께 준다.
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $this->downloadName);
        header(
            'Content-Disposition: attachment; filename="' . str_replace('"', '', (string) $ascii) . '";'
            . " filename*=UTF-8''" . rawurlencode($this->downloadName)
        );

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        readfile($this->path);
    }
}
