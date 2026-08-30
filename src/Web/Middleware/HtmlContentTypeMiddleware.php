<?php

declare(strict_types=1);

namespace GnuCms\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PhpView::render() 는 Content-Type 을 설정하지 않는다. 매 라우트마다
 * 반복해서 지정하는 대신 여기서 한 번에 채운다. 이미 Content-Type 이 정해져 있으면
 * 손대지 않는다 — 파일 다운로드 같은 라우트가 자신의 Content-Type 을 지켜야 한다.
 *
 * ErrorPageMiddleware 가 만든 응답도 감싸야 하므로 스택에서 그것보다 바깥(=더 나중에
 * add)에 등록해야 한다.
 */
final class HtmlContentTypeMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->getHeaderLine('Content-Type') !== '') {
            return $response;
        }

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
