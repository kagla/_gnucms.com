<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Cms\ContentImageService;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
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

    /**
     * 글쓰기 폼이 파일을 고르는 즉시 부르는 업로드. 서명된 디스크립터를 돌려주고,
     * 글이 저장될 때 그 서명으로 되검증하므로 임시 표가 필요 없다.
     */
    public function upload(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertCsrf($request->getQueryParams());
        $uploaded = $request->getUploadedFiles()['file'] ?? null;
        if (!$uploaded instanceof UploadedFileInterface) {
            return $this->json($response->withStatus(422), ['error' => '파일이 없습니다.']);
        }

        try {
            $descriptor = $this->app->attachments()->upload(
                $this->app->guestAcl(),
                (string) $args['key'],
                $this->toFilesArray($uploaded)
            );
        } catch (DomainError $e) {
            // 용량·형식 오류는 폼이 문구로 보여 준다. 권한 같은 판단은 그대로 내보낸다.
            if (!in_array($e->status(), [413, 422], true)) {
                throw $e;
            }
            $message = $e->details() !== [] ? implode(' ', $e->details()) : $e->getMessage();

            return $this->json($response->withStatus($e->status()), ['error' => $message]);
        }

        $descriptor['size_label'] = $this->sizeLabel((int) $descriptor['size']);

        return $this->json($response, $descriptor);
    }

    /** PSR-7 업로드 파일을 AttachmentService 가 받는 $_FILES 모양으로 바꾼다. */
    private function toFilesArray(UploadedFileInterface $uploaded): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'gnucms-att-');
        if ($tmp === false) {
            throw DomainError::internal('임시 파일을 만들 수 없습니다.');
        }
        if ((int) $uploaded->getError() === UPLOAD_ERR_OK) {
            // moveTo() 는 실제 SAPI 에서는 move_uploaded_file 을 쓰고, 테스트에서는 rename 한다.
            $uploaded->moveTo($tmp);
        }

        return [
            'name'     => (string) $uploaded->getClientFilename(),
            'size'     => (int) $uploaded->getSize(),
            'tmp_name' => $tmp,
            'error'    => (int) $uploaded->getError(),
            'type'     => (string) $uploaded->getClientMediaType(),
        ];
    }

    private function sizeLabel(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function assertCsrf(array $input): void
    {
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        $given = isset($input['csrf_token']) && is_scalar($input['csrf_token']) ? (string) $input['csrf_token'] : '';
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }
}
