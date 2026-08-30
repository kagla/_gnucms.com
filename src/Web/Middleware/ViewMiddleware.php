<?php

declare(strict_types=1);

namespace GnuCms\Web\Middleware;

use GnuCms\View\View;
use GnuCms\View\ViewInterface;
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
        $this->view->bindRequest($request);
        return $handler->handle($request->withAttribute(View::ATTRIBUTE, $this->view));
    }
}
