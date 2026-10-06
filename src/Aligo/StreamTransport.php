<?php

declare(strict_types=1);

namespace GnuCms\Aligo;

final class StreamTransport implements Transport
{
    /** 값이 null 인 항목은 보내지 않는다. 알리고는 빈 문자열과 미전송을 다르게 본다. */
    public function encode(array $fields): string
    {
        $given = array_filter($fields, static fn ($value): bool => $value !== null);

        return http_build_query($given, '', '&', PHP_QUERY_RFC1738);
    }

    public function post(string $url, array $fields, int $timeout = 10): array
    {
        $body = $this->encode($fields);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded; charset=UTF-8\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n",
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new TransportFailure('알리고 서버에 연결하지 못했습니다.');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return ['status' => $status, 'body' => $response];
    }
}
