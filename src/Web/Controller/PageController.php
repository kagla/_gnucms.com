<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class PageController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Twig::fromRequest($request)->render($response, 'pages/show.html.twig', [
            'page' => $this->app->cmsService()->publishedPage((string) $args['slug']),
            'preview' => false,
            'preview_legal_type' => null,
            // 관리자에게 보여 줄 '이 내용 수정' 링크가 약관인지 일반 내용인지 가른다.
            'legal_type' => null,
        ]);
    }
}
