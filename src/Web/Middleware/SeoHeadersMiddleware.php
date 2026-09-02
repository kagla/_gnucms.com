<?php

declare(strict_types=1);

namespace GnuCms\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** 공개 문서가 아닌 인증·관리·작성 화면이 검색 결과에 잡히지 않게 한다. */
final class SeoHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $path = $request->getUri()->getPath();
        $query = $request->getQueryParams();

        if ($response->getStatusCode() >= 400) {
            return $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        if (in_array($path, ['/sitemap.xml', '/rss.xml', '/content/rss.xml', '/robots.txt'], true)
            || preg_match('#^/boards/[a-z0-9_-]+/rss\.xml$#', $path)) {
            return $response;
        }

        $public = $path === '/' || $path === '/posts'
            || preg_match('#^/posts/[0-9]+$#', $path)
            || preg_match('#^/boards/[a-z0-9_-]+$#', $path)
            || preg_match('#^/(?:content|terms)/[a-z0-9][a-z0-9_-]*$#', $path);
        if (!$public) {
            return $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $filteredList = ($path === '/posts' && (isset($query['q']) || isset($query['author'])))
            || (str_starts_with($path, '/boards/')
                && (isset($query['q']) || isset($query['category']) || isset($query['view'])));

        return $filteredList ? $response->withHeader('X-Robots-Tag', 'noindex, follow') : $response;
    }
}
