<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Shop\Service;
use GnuCms\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

abstract class AdminBase
{
    public function __construct(protected Service $service, protected string $routePrefix, protected ?string $adminRoutePrefix) {}

    protected function context(ServerRequestInterface $request, string $page): array
    {
        $base = RouteContext::fromRequest($request)->getBasePath();
        $identity = $this->service->app->guestAcl()->identity();
        return ['base' => $base, 'admin_url' => $base . ($this->adminRoutePrefix ?? '/admin/shop'), 'public_url' => $base . $this->routePrefix,
            'csrf_token' => $_SESSION['csrf_token'] ?? '', 'page' => $page, 'ready' => $this->service->ready(), 'errors' => [], 'notice' => '',
            'input' => $this->input($request), 'actor' => (string) ($identity->displayName() ?? $identity->sub() ?? 'admin')];
    }

    /** POST는 본문, GET은 쿼리. 배열 값은 그대로 두고 문자열·정수 외의 스칼라는 빈 문자열로 만든다. */
    protected function input(ServerRequestInterface $request): array
    {
        $input = $request->getMethod() === 'POST' ? $request->getParsedBody() : $request->getQueryParams();
        if (!is_array($input)) return [];
        foreach ($input as $key => $value) {
            if (!is_array($value) && !is_string($value) && !is_int($value)) $input[$key] = '';
        }
        return $input;
    }

    protected function render(ServerRequestInterface $request, ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $view = View::forShop($request);
        return $view->render($response->withHeader('Cache-Control', 'no-store'), 'admin/' . $template, $data);
    }

    protected function redirect(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $url);
    }

    protected function requireReady(ResponseInterface $response, array $context): ?ResponseInterface
    {
        return $context['ready'] ? null : $this->redirect($response, $context['admin_url'] . '?install=1');
    }

    protected function page(mixed $value): int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,5}$/D', $value) ? (int) $value : 1;
    }
}
