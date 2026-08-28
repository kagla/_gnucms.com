<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Cms\ContentImageService;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

final class FileController
{
    /** 인라인으로 내려도 되는 이미지 형식. 목록만 허용해 SVG 같은 실행 가능한 형식을 막는다. */
    private const INLINE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

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

    /**
     * 목록 썸네일용 인라인 이미지. 권한과 비밀글 확인은 download() 와 같은
     * AttachmentService 를 거치므로 여기서 다시 하지 않는다. 이미지가 아닌
     * 첨부는 브라우저에서 실행될 여지를 없애려고 내려주지 않는다.
     */
    public function image(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $this->app->attachments()->download(
            $this->app->guestAcl(),
            (int) $args['id'],
            (int) $args['index'],
            null
        );

        if (!in_array($file['mime'], self::INLINE_MIMES, true)) {
            throw DomainError::notFound('이미지 첨부가 아닙니다.');
        }

        $variant = (string) ($args['variant'] ?? '');
        if (isset(ContentImageService::VARIANTS[$variant])) {
            $file['path'] = $this->app->attachments()->thumbnailPath(
                $file['path'],
                ContentImageService::VARIANTS[$variant]
            );
        }

        $handle = fopen($file['path'], 'rb');
        if ($handle === false) {
            throw DomainError::internal('첨부 파일을 열 수 없습니다: ' . $file['path']);
        }

        return $response
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Length', (string) filesize($file['path']))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'private, max-age=600')
            ->withBody(new Stream($handle));
    }
}
