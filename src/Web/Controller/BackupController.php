<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use GnuCms\Service\AttachmentService;
use Slim\Psr7\Stream;
use Slim\Routing\RouteContext;
use GnuCms\View\View;
use Throwable;

final class BackupController
{
    public function __construct(private App $app)
    {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->assertAdminRequest($request);
        try {
            if (array_key_exists('format', $input) && !is_scalar($input['format'])) {
                throw new \RuntimeException('백업 형식은 zip 또는 tar만 선택할 수 있습니다.');
            }
            $format = array_key_exists('format', $input) ? (string) $input['format'] : null;
            $result = $this->app->backups()->create('manual', $format);
        } catch (Throwable $e) {
            return $this->renderError($request, $response, $e->getMessage());
        }

        return $this->redirect($request, $response, [
            'backup_created' => (string) $result['name'],
        ]);
    }

    public function verify(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertAdminRequest($request);
        try {
            $this->app->backups()->verify((string) $args['name']);
        } catch (Throwable $e) {
            return $this->renderError($request, $response, $e->getMessage());
        }

        return $this->redirect($request, $response, ['backup_verified' => (string) $args['name']]);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->assertAdminRequest($request, true);
        try {
            $upload = $request->getUploadedFiles()['backup_file'] ?? null;
            if (!$upload instanceof UploadedFileInterface) {
                throw new \RuntimeException(
                    '백업 파일이 전송되지 않았습니다. 파일을 선택했다면 PHP 업로드 용량 제한을 확인해 주세요.'
                );
            }
            $result = $this->app->backups()->storeUpload($upload);
        } catch (Throwable $e) {
            return $this->renderError($request, $response, $e->getMessage());
        }

        return $this->redirect($request, $response, [
            'backup_uploaded' => (string) $result['name'],
        ], 'backup-uploaded');
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->assertAdminRequest($request);
        if (($input['confirmation'] ?? null) !== '복원') {
            return $this->renderError($request, $response, '확인 칸에 “복원”을 정확히 입력해 주세요.');
        }
        try {
            $result = $this->app->backups()->restore((string) $args['name']);
        } catch (Throwable $e) {
            return $this->renderError($request, $response, $e->getMessage());
        }

        return $this->redirect($request, $response, [
            'backup_restored' => (string) $result['restored'],
            'safety_backup' => (string) $result['safety_backup'],
        ]);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertAdminRequest($request);
        try {
            $result = $this->app->backups()->delete((string) $args['name']);
        } catch (Throwable $e) {
            return $this->renderError($request, $response, $e->getMessage());
        }

        return $this->redirect($request, $response, ['backup_deleted' => (string) $result['deleted']]);
    }

    public function deleteAutomatic(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertAdminRequest($request);
        try {
            $result = $this->app->schemaUpgrader()->deleteBackup((string) $args['name']);
        } catch (Throwable $e) {
            return $this->renderError($request, $response, $e->getMessage());
        }

        return $this->redirect($request, $response, [
            'schema_backup_deleted' => (string) $result['deleted'],
        ]);
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $path = $this->app->backups()->downloadPath((string) $args['name']);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw DomainError::internal('백업 파일을 열 수 없습니다.');
        }
        $name = basename($path);
        $contentType = str_ends_with(strtolower($name), '.zip') ? 'application/zip' : 'application/x-tar';

        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody(new Stream($handle));
    }

    private function assertAdminRequest(ServerRequestInterface $request, bool $allowQueryToken = false): array
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $input = $request->getParsedBody();
        $input = is_array($input) ? $input : [];
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])
            ? $_SESSION['csrf_token'] : '';
        $given = isset($input['csrf_token']) && is_scalar($input['csrf_token'])
            ? (string) $input['csrf_token'] : '';
        if ($allowQueryToken && $given === '') {
            $query = $request->getQueryParams();
            $given = isset($query['csrf_token']) && is_scalar($query['csrf_token'])
                ? (string) $query['csrf_token'] : '';
        }
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }

        return $input;
    }

    private function renderError(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $message
    ): ResponseInterface {
        return View::fromRequest($request)->render($response->withStatus(422), 'admin/maintenance', [
            'query' => [],
            'schema' => $this->app->schemaUpgrader()->status(),
            'backup' => $this->app->backups()->status(),
            'garbage' => $this->app->attachments()->garbageCandidates($this->app->guestAcl()),
            'backup_upload_max_mb' => AttachmentService::serverMaxMb(),
            'backup_error' => $message,
        ]);
    }

    private function redirect(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $query,
        ?string $fragment = null
    ): ResponseInterface {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin.settings.maintenance');
        $location = $url . '?' . http_build_query($query);
        if ($fragment !== null && $fragment !== '') {
            $location .= '#' . rawurlencode($fragment);
        }

        return $response->withHeader('Location', $location)->withStatus(303);
    }
}
