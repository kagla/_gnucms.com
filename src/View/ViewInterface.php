<?php

declare(strict_types=1);

namespace GnuCms\View;

use Psr\Http\Message\ResponseInterface;

/**
 * 화면 그리기. 컨트롤러는 이것만 안다. 템플릿 이름은 확장자 없는 논리 이름
 * ('home/index')이고, 확장자는 엔진이 붙인다. 그래야 엔진을 바꿔도
 * 컨트롤러를 다시 안 만진다.
 */
interface ViewInterface
{
    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface;

    /** 문자열로. 오류 화면과 파리티 테스트가 쓴다. */
    public function fetch(string $template, array $data = []): string;

    public function addGlobal(string $name, mixed $value): void;
}
