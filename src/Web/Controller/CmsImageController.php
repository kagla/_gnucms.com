<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Support\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;
use Slim\Routing\RouteContext;

final class CmsImageController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->app->guestAcl()->assertGlobalAdmin();
            $this->assertCsrf((string) ($request->getQueryParams()['csrf_token'] ?? ''));
            $upload = $request->getUploadedFiles()['upload'] ?? null;
            if (!$upload instanceof UploadedFileInterface) {
                throw DomainError::validation(['upload' => '업로드할 이미지를 선택해 주세요.']);
            }
            $key = (string) ($request->getQueryParams()['image_key'] ?? '');
            $image = $this->app->contentImages()->upload($this->app->guestAcl(), $upload, $key);
            $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('editor.owned_image', $image);
            return $this->json($response, [
                'uploaded' => 1,
                'fileName' => $image['file'],
                'url' => $url,
            ]);
        } catch (DomainError $e) {
            $message = (string) (($e->details()['upload'] ?? null) ?: $e->getMessage());
            return $this->json($response->withStatus($e->status()), [
                'uploaded' => 0,
                'error' => ['message' => $message],
            ]);
        }
    }

    public function discard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->app->guestAcl()->assertGlobalAdmin();
        $query = $request->getQueryParams();
        $this->assertCsrf((string) ($query['csrf_token'] ?? ''));
        $input = $request->getParsedBody();
        $files = is_array($input) && isset($input['files']) && is_array($input['files'])
            ? $input['files'] : [];
        $this->app->contentImages()->discard(
            $this->app->guestAcl(),
            (string) ($query['image_key'] ?? ''),
            $files
        );
        return $response->withStatus(204);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->imageResponse($response, $this->app->contentImages()->image(
            (string) $args['year'],
            (string) $args['month'],
            (string) $args['file']
        ));
    }

    /** @param array{path:string, mime:string} $image */
    private function imageResponse(ResponseInterface $response, array $image): ResponseInterface
    {
        $handle = fopen($image['path'], 'rb');
        if ($handle === false) {
            throw DomainError::internal('이미지를 열 수 없습니다.');
        }
        return $response
            ->withHeader('Content-Type', $image['mime'])
            ->withHeader('Content-Length', (string) filesize($image['path']))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody(new Stream($handle));
    }

    public function showOwned(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->imageResponse($response, $this->app->contentImages()->ownedImage(
            (string) $args['key'], (string) $args['file']
        ));
    }

    private function assertCsrf(string $given): void
    {
        $expected = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            throw DomainError::forbidden('요청을 확인할 수 없습니다. 다시 시도해 주세요.');
        }
    }

    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write(Json::encode($payload));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
