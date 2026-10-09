<?php

declare(strict_types=1);

namespace GnuCms\Web\Middleware;

use GnuCms\View\View;
use GnuCms\View\ViewInterface;
use GnuCms\Web\LoginRedirect;
use Slim\Routing\RouteContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** 요청마다 View 를 실어 준다. 컨트롤러는 View::fromRequest() 로 꺼낸다. */
final class ViewMiddleware implements MiddlewareInterface
{
    private ViewInterface $view;

    public function __construct(ViewInterface $view)
    {
        $this->view = $view;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = RouteContext::fromRequest($request);
        $returnUrl = null;
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $routeName = $context->getRoute()?->getName() ?? '';
            if (str_starts_with($routeName, 'auth.') || str_starts_with($routeName, 'oauth.')) {
                // 인증 화면 자체로 되돌리지 않고 이미 검증한 복귀 주소를 유지한다.
                $returnUrl = LoginRedirect::fromRequest($request);
            } else {
                $uri = $request->getUri();
                $returnUrl = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());
            }
        }
        $this->view->addGlobal('login_url', LoginRedirect::loginUrl($context->getRouteParser(), $returnUrl));

        return $handler->handle($request->withAttribute(View::ATTRIBUTE, $this->view));
    }
}
