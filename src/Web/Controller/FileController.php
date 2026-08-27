<?php

declare(strict_types=1);

namespace ApiBoard\Web\Controller;

use ApiBoard\App;
use ApiBoard\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

final class FileController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $this->app->attachments()->download(
            $this->app->guestAcl(),
            (int) $args['id'],
            (int) $args['index'],
            null
        );

        // 한글 파일명 때문에 RFC 5987 형식을 함께 준다. ASCII 폴백이 없으면
        // 오래된 클라이언트가 이름을 통째로 버린다.
        $ascii = (string) preg_replace('/[^\x20-\x7e]/', '_', $file['name']);
        $disposition = 'attachment; filename="' . str_replace('"', '', $ascii) . '";'
            . " filename*=UTF-8''" . rawurlencode($file['name']);

        $handle = fopen($file['path'], 'rb');
        if ($handle === false) {
            // 서비스가 이미 파일 존재를 확인했으므로 여기서 실패하면 조용히 넘어갈 일이
            // 아니라 명확한 내부 오류다.
            throw DomainError::internal('첨부 파일을 열 수 없습니다: ' . $file['path']);
        }

        return $response
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Length', (string) filesize($file['path']))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', $disposition)
            ->withBody(new Stream($handle));
    }
}
