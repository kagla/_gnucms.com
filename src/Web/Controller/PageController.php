<?php

declare(strict_types=1);

namespace ApiBoard\Web\Controller;

use ApiBoard\App;
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
        ]);
    }

    public function legal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) $args['type'] === 'service' ? 'terms' : 'privacy';
        return Twig::fromRequest($request)->render($response, 'pages/show.html.twig', [
            'page' => $this->app->cmsService()->publishedPage($slug),
            'preview' => false,
            'preview_legal_type' => null,
        ]);
    }
}
