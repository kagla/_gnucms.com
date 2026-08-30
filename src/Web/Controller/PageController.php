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
        ]);
    }
}
