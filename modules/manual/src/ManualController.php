<?php

declare(strict_types=1);

namespace GnuCmsManual;

use GnuCms\View\View;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ManualController
{
    public function __construct(private ManualLibrary $library, private string $prefix, private string $templates) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, null);
    }

    public function article(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        return $this->render($request, $response, $this->library->article($slug));
    }

    private function render(ServerRequestInterface $request, ResponseInterface $response, ?array $article): ResponseInterface
    {
        return View::forExtension($request, 'manual', $this->templates)->render($response, 'manual', [
            'manual_catalog' => $this->library->catalog(), 'manual_article' => $article,
            'manual_prefix' => $this->prefix,
        ]);
    }

    /** 작성된 설명서만 반환한다. 회원·설정·발송 기록을 조회하지 않는다. */
    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode($this->library->search(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=300');
    }
}
