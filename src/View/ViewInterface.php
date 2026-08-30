<?php

declare(strict_types=1);

namespace GnuCms\View;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 화면 그리기. 컨트롤러는 이것만 안다. 템플릿 이름은 확장자 없는 논리 이름
 * ('home/index')이고, 확장자는 엔진이 붙인다. 그래야 Twig 를 걷어낼 때
 * 컨트롤러를 다시 안 만진다.
 */
interface ViewInterface
{
    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface;

    /** 문자열로. 오류 화면과 파리티 테스트가 쓴다. */
    public function fetch(string $template, array $data = []): string;

    public function addGlobal(string $name, mixed $value): void;

    /**
     * 요청에 묶인 준비. Twig 는 url_for 가 요청 URI 를 알아야 해서 여기서 런타임 로더를
     * 단다. 404 는 라우팅 미들웨어가 먼저 던져 TwigMiddleware 가 못 돌기 때문에 오류
     * 미들웨어도 이걸 부른다. 여러 번 불러도 안전해야 한다.
     */
    public function bindRequest(ServerRequestInterface $request): void;
}
