<?php

declare(strict_types=1);

namespace GnuCms\View;

use RuntimeException;
use Throwable;

/**
 * 한 번의 렌더. 템플릿 파일은 이 클래스의 메서드 안에서 include 되므로 템플릿 안의
 * $this 가 곧 이 객체다. 전역 함수도 숨은 상태도 없다.
 *
 * 레이아웃: 화면 파일이 먼저 돌며 start/stop 으로 블록을 잡는다. 화면이 layout() 을
 * 적어 두었으면 그 파일을 같은 블록 저장소로 한 번 더 돈다. 레이아웃이 또 layout() 을
 * 적으면 다시 감싼다. 먼저 잡은(=자식) 블록이 이긴다.
 */
final class PhpTemplate
{
    public string $base;

    private PhpView $view;

    /** @var array<string,mixed> 전역 + 넘겨받은 데이터. insert() 가 그대로 물려준다. */
    private array $vars;

    /** @var array<string,string> */
    private array $blocks = [];

    /** @var string[] 열려 있는 블록 이름. stop() 이 꺼낸다. */
    private array $open = [];

    private ?string $layout = null;

    /** 지금 도는 파일이 layout() 을 적었는가. 적었으면 블록을 조용히 잡고, 아니면 낸다. */
    private bool $isChild = false;

    public function __construct(PhpView $view, array $vars, string $base)
    {
        $this->view = $view;
        $this->vars = $vars;
        $this->base = $base;
    }

    public function run(string $template): string
    {
        $file = $this->view->resolve($template);
        $out = $this->capture($file);
        while ($this->layout !== null) {
            $next = $this->layout;
            $this->layout = null;
            $this->isChild = false;
            // 자식의 블록 밖 출력은 버린다. Twig 의 extends 화면과 같다.
            $out = $this->capture($this->view->resolve($next));
        }
        return $out;
    }

    private function capture(string $__file): string
    {
        $__level = ob_get_level();
        ob_start();
        try {
            extract($this->vars, EXTR_SKIP);
            include $__file;
        } catch (Throwable $e) {
            // 템플릿이 터지면 버퍼를 전부 걷어야 다음 화면(오류 화면)이 깨끗하다.
            while (ob_get_level() > $__level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }

    // ---- 헬퍼. 템플릿이 $this-> 로 부른다 ----

    public function e(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function layout(string $name): void
    {
        $this->layout = $name;
        $this->isChild = true;
    }

    public function start(string $block): void
    {
        $this->open[] = $block;
        ob_start();
    }

    public function stop(): void
    {
        $name = array_pop($this->open);
        if ($name === null) {
            throw new RuntimeException('start() 없이 stop() 을 불렀습니다.');
        }
        $content = (string) ob_get_clean();
        // 먼저 잡은 쪽(자식)이 이긴다. 부모가 같은 이름을 잡으면 그건 기본값이다.
        if (!array_key_exists($name, $this->blocks)) {
            $this->blocks[$name] = $content;
        }
        // 루트 레이아웃의 start/stop 은 Twig 의 {% block %} 처럼 그 자리에 낸다.
        if (!$this->isChild) {
            echo $this->blocks[$name];
        }
    }

    public function block(string $name, string $default = ''): string
    {
        return $this->blocks[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return trim($this->blocks[$name] ?? '') !== '';
    }

    public function insert(string $template, array $data = []): void
    {
        echo $this->fetch($template, $data);
    }

    public function fetch(string $template, array $data = []): string
    {
        // 조각은 자기 블록 저장소를 갖는 새 렌더다. 변수는 지금 것에 덧붙여 물려준다.
        return (new self($this->view, $data + $this->vars, $this->base))->run($template);
    }

    public function url(string $route, array $params = [], array $query = []): string
    {
        return $this->view->url($route, $params, $query);
    }

    public function asset(string $path): string
    {
        return $this->view->asset($path);
    }

    public function html(string $content): string
    {
        return $this->view->html($content);
    }

    public function icon(string $name, int $size = 20, string $cls = ''): string
    {
        // 여는 태그는 templates/default/_icons.html.twig 의 매크로와 글자 단위로 같다.
        // 파리티 테스트가 두 엔진의 HTML 을 그대로 비교하기 때문이다.
        $paths = $this->view->icons();
        return '<svg class="icon' . ($cls !== '' ? ' ' . $this->e($cls) : '') . '"'
            . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">'
            . ($paths[$name] ?? '')
            . '</svg>';
    }

    public function date(mixed $v, string $format): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $ts = is_int($v) ? $v : strtotime((string) $v);
        return $ts === false ? '' : date($format, $ts);
    }

    public function json(mixed $v): string
    {
        // Twig 의 json_encode 필터와 같은 기본 옵션. 파리티가 이 값을 비교한다.
        return (string) json_encode($v);
    }
}
