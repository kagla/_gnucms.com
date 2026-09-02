<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

final class AvatarController
{
    public function __construct(private App $app) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $image = $this->app->avatars()->image((string) ($args['file'] ?? ''));
        $handle = fopen($image['path'], 'rb');
        if ($handle === false) throw \GnuCms\Error\DomainError::internal('프로필 이미지를 열 수 없습니다.');
        return $response->withHeader('Content-Type', $image['mime'])
            ->withHeader('Content-Length', (string) filesize($image['path']))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('X-Content-Type-Options', 'nosniff')->withBody(new Stream($handle));
    }
}
