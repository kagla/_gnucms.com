<?php

declare(strict_types=1);

namespace GnuCms\Web\Controller;

use GnuCms\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use GnuCms\View\View;

final class PageController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $page = $this->app->cmsService()->publishedPage((string) $args['slug']);
        // 약관의 정식 주소는 /terms/{slug} 다. 옛 링크가 남아 있어도 그리로 보낸다.
        if ((int) ($page['is_consent'] ?? 0) === 1) {
            return $this->redirectTo($request, $response, 'terms.show', (string) $args['slug']);
        }
        return $this->render($request, $response, $page);
    }

    /** 약관 보기. 약관이 아닌 내용이 오면 정식 주소인 /content/{slug} 로 보낸다. */
    public function showTerms(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $page = $this->app->cmsService()->publishedPage((string) $args['slug']);
        if ((int) ($page['is_consent'] ?? 0) !== 1) {
            return $this->redirectTo($request, $response, 'content.show', (string) $args['slug']);
        }
        return $this->render($request, $response, $page);
    }

    private function render(ServerRequestInterface $request, ResponseInterface $response, array $page): ResponseInterface
    {
        return View::fromRequest($request)->render($response, 'pages/show', [
            'page' => $page,
            'preview' => false,
        ]);
    }

    private function redirectTo(ServerRequestInterface $request, ResponseInterface $response,
        string $route, string $slug): ResponseInterface
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor($route, ['slug' => $slug]);
        return $response->withHeader('Location', $url)->withStatus(301);
    }
}
