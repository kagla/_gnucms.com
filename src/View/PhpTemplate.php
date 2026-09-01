<?php

declare(strict_types=1);

namespace GnuCms\View;

use GnuCms\Support\Clock;
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
        // 이미 지나온 레이아웃 이름. 같은 것이 다시 나오면 서로를 감싸는 짝이다.
        $seen = [];
        while ($this->layout !== null) {
            $next = $this->layout;
            if (isset($seen[$next])) {
                throw new RuntimeException('레이아웃이 서로를 감쌉니다: ' . $next);
            }
            $seen[$next] = true;
            $this->layout = null;
            $this->isChild = false;
            // 자식의 블록 밖 출력은 버린다. 레이아웃이 낼 자리가 없는 출력이다.
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
        if (ob_get_level() > $__level + 1) {
            // stop() 없이 끝난 start() 가 있다. 조용히 삼키면 이후 화면이 전부 빈 채로 나간다.
            while (ob_get_level() > $__level) { ob_end_clean(); }
            throw new RuntimeException('stop() 없이 끝난 start() 가 있습니다: ' . $__file);
        }
        return (string) ob_get_clean();
    }

    // ---- 헬퍼. 템플릿이 $this-> 로 부른다 ----

    /** @param mixed $v */
    public function e($v): string
    {
        if ($v === null) {
            return '';
        }
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 비었으면 기본값. null·''·false·[] 를 '비었다' 로 본다. ?? 는 null 만 보므로 다르다. */
    /** @param mixed $v @param mixed $default @return mixed */
    public function def($v, $default)
    {
        if ($v instanceof \Countable) { return count($v) === 0 ? $default : $v; }
        if (is_object($v) && method_exists($v, '__toString')) { return (string) $v === '' ? $default : $v; }
        return ($v === '' || $v === false || $v === null || $v === []) ? $default : $v;
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
        // 루트 레이아웃의 start/stop 은 기본값을 정의하면서 그 자리에 낸다.
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

    /** 조각이 있는가. 배포에서 조각 하나가 빠져도 화면이 통째로 죽지 않게 고를 때 쓴다. */
    public function exists(string $template): bool
    {
        return $this->view->exists($template);
    }

    public function insert(string $template, array $data = [], bool $only = false): void
    {
        echo $this->fetch($template, $data, $only);
    }

    public function fetch(string $template, array $data = [], bool $only = false): string
    {
        // 조각은 자기 블록 저장소를 갖는 새 렌더다. 변수는 지금 것에 덧붙여 물려준다.
        // $only 가 참이면 지금 화면의 지역 변수는 끊는다. 전역(site, current_user 등)은
        // 어느 조각이든 필요하므로 계속 물려준다.
        $vars = $only ? $data + $this->view->globals() : $data + $this->vars;
        return (new self($this->view, $vars, $this->base))->run($template);
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
        // 여는 태그의 속성 순서는 theme.css 의 .icon 규칙과 짝이다. 함부로 바꾸지 않는다.
        // 파리티 테스트가 두 엔진의 HTML 을 그대로 비교하기 때문이다.
        $paths = $this->view->icons();
        return '<svg class="icon' . ($cls !== '' ? ' ' . $this->e($cls) : '') . '"'
            . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">'
            // 모르는 이름이면 빈 자리 대신 원을 낸다. 빠진 아이콘이 눈에 띄게.
            . ($paths[$name] ?? '<circle cx="12" cy="12" r="8.6"/>')
            . '</svg>';
    }

    /** @param mixed $v */
    public function date($v, string $format): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $ts = is_int($v) ? $v : strtotime((string) $v);
        return $ts === false ? '' : date($format, $ts);
    }

    /** @param mixed $v */
    public function compactDate($v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $ts = is_int($v) ? $v : strtotime((string) $v);
        if ($ts === false) {
            return '';
        }

        return date('Y-m-d', $ts) === date('Y-m-d', Clock::timestamp())
            ? date('H:i', $ts)
            : date('m-d', $ts);
    }

    /** @param mixed $v */
    public function truncate($v, int $length): string
    {
        $text = (string) $v;
        if ($length < 1 || mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '…';
    }

    /** @param mixed $v */
    public function json($v): string
    {
        // 기본 옵션 그대로. 값은 서버가 만든 주소·토큰이라 < 가 들어올 일이 없다.
        return (string) json_encode($v);
    }
}
