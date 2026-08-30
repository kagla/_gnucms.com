# PHP 네이티브 테마 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Twig 없이 PHP 파일 템플릿만으로 도는 `native` 테마를 만들고, 렌더 결과가 Twig `default` 와 같음을 41개 경로 파리티로 증명한다.

**Architecture:** 컨트롤러와 미들웨어는 `ViewInterface` 만 안다. `TwigView` 가 지금 코드를 감싸고 `PhpView` 가 새로 선다. 테마 폴더의 `theme.php` 매니페스트가 엔진을 고른다. PHP 템플릿은 `PhpTemplate` 메서드 안에서 include 되어 `$this` 헬퍼(`e`, `layout`, `start/stop`, `block`, `insert`, `url`, `asset`, `html`, `icon`)를 쓴다.

**Tech Stack:** PHP 8.4, Slim 4 (`RouteParserInterface`), slim/twig-view 3.4 (기존), PHPUnit 10.5

**설계 문서:** `docs/superpowers/specs/2026-08-30-native-php-theme-design.md` — 헬퍼 표(3.3)와 레이아웃 동작(3.4)은 그 문서가 정본이다.

## Global Constraints

- **런타임 의존성 0 추가.** 새 Composer 패키지를 넣지 않는다. 서버에서 `composer`·`npm` 을 쓸 수 없다.
- **디자인 불변.** `public/themes/native/theme.css` 는 `public/themes/default/theme.css` 의 복사본이며 한 글자도 바꾸지 않는다. 인라인 `<script>`·`<style>` 도 Twig 판과 같은 내용이어야 한다.
- **Twig 테마는 손대지 않는다.** `templates/default/**` 와 다른 23벌은 읽기만 한다.
- **엔진 간 폴백 없음.** `native` 는 58개 화면을 전부 갖는다. `PhpView` 는 `templates/native` 한 경로만 본다.
- **출력은 전부 `$this->e()`.** 예외는 `html()`·`icon()`·`json()`·`insert()`·`block()`·`url()`·`asset()` 의 결과처럼 이미 안전한 것뿐이다.
- **파리티 정규화는 세 가지만.** 줄 끝 공백과 빈 줄, 태그 사이 공백(`>\s+<`→`><`), `theme.css?v=` 해시와 `/themes/{이름}/` 의 테마 이름. 그 밖의 차이는 결함이다.
- **컨트롤러는 논리 이름.** `'home/index'` 처럼 확장자 없이 넘긴다. 확장자는 엔진이 붙인다.
- **주석은 한국어**, "왜"를 적는다. **커밋 메시지는 한국어**, 끝에 `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- **Twig 를 아는 파일은 셋뿐.** `src/View/TwigView.php`, `src/Web/Kernel.php`(조립 한 줄), `src/View/ViewInterface.php` 의 `bindRequest()` 주석. 다른 곳에 `use Twig\…`·`use Slim\Views\…` 가 남으면 결함이다.

## 검사 도구

```bash
cd /home/kagla/gnucms.com
./vendor/bin/phpunit                                          # 기준선 292 tests
GNUCMS_TEST_THEME=native ./vendor/bin/phpunit                 # Task 3 부터
./vendor/bin/phpunit --filter ThemeParityTest                 # Task 4 부터, 묶음별 --filter
php /tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad/lint.php     # Twig 판 무결성
php /tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad/smoke.php native   # Task 4 부터
```

---

### Task 1: `View` 추상과 `TwigView` — 컨트롤러가 Twig 를 모르게 한다

**Files:**
- Create: `src/View/ViewInterface.php`, `src/View/TwigView.php`, `src/View/View.php`, `src/Web/Middleware/ViewMiddleware.php`
- Modify: `src/Web/Kernel.php`, `src/Web/Middleware/SessionGuard.php`, `src/Web/Middleware/ErrorPageMiddleware.php`, `src/Web/Routes.php`, `src/Web/Controller/{Auth,Oauth,Board,Post,Comment,Page,Notification,Admin,AdminCms}Controller.php`
- Test: 기존 전체 (회귀 0 이 게이트)

**Interfaces:**
- Produces:
  - `ViewInterface::render(ResponseInterface, string $template, array $data = []): ResponseInterface`
  - `ViewInterface::fetch(string $template, array $data = []): string`
  - `ViewInterface::addGlobal(string $name, mixed $value): void`
  - `ViewInterface::bindRequest(ServerRequestInterface $request): void` — 요청에 묶인 준비. Twig 는 여기서 런타임 로더를 단다. PHP 는 할 일이 없다.
  - `View::fromRequest(ServerRequestInterface): ViewInterface`, `View::ATTRIBUTE = 'gnucms.view'`
  - `ViewMiddleware` — 요청 속성에 View 를 넣고 `bindRequest()` 를 부른다

- [ ] **Step 1: 인터페이스와 `View` 를 만든다**

`src/View/ViewInterface.php`

```php
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
```

`src/View/View.php`

```php
<?php

declare(strict_types=1);

namespace GnuCms\View;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class View
{
    public const ATTRIBUTE = 'gnucms.view';

    public static function fromRequest(ServerRequestInterface $request): ViewInterface
    {
        $view = $request->getAttribute(self::ATTRIBUTE);
        if (!$view instanceof ViewInterface) {
            throw new RuntimeException('요청에 View 가 없습니다. ViewMiddleware 가 먼저 돌아야 합니다.');
        }
        return $view;
    }
}
```

- [ ] **Step 2: `TwigView` 를 만든다**

`src/View/TwigView.php`

```php
<?php

declare(strict_types=1);

namespace GnuCms\View;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Views\Twig;
use Slim\Views\TwigRuntimeLoader;

/** 지금까지의 Twig 렌더링을 그대로 감싼다. Twig 를 걷어낼 때 이 파일째 지운다. */
final class TwigView implements ViewInterface
{
    private Twig $twig;
    private RouteParserInterface $routes;
    private string $basePath;

    public function __construct(Twig $twig, RouteParserInterface $routes, string $basePath)
    {
        $this->twig = $twig;
        $this->routes = $routes;
        $this->basePath = $basePath;
    }

    public function twig(): Twig
    {
        return $this->twig;
    }

    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        return $this->twig->render($response, $template . '.html.twig', $data);
    }

    public function fetch(string $template, array $data = []): string
    {
        return $this->twig->fetch($template . '.html.twig', $data);
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->getEnvironment()->addGlobal($name, $value);
    }

    public function bindRequest(ServerRequestInterface $request): void
    {
        // TwigMiddleware 가 나중에 같은 값으로 다시 달아도 문제없다. 먼저 단 것이 쓰인다.
        $this->twig->addRuntimeLoader(new TwigRuntimeLoader($this->routes, $request->getUri(), $this->basePath));
    }
}
```

- [ ] **Step 3: `ViewMiddleware` 를 만든다**

`src/Web/Middleware/ViewMiddleware.php`

```php
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
```

- [ ] **Step 4: `Kernel` 을 고친다**

`src/Web/Kernel.php` 에서 Twig 전역 설정을 전부 `$view->addGlobal()` 로 바꾼다. Twig 환경을 만드는 부분과 `theme_asset`·`cms_html` 등록은 그대로 두되 `TwigView` 로 감싼다. 미들웨어 순서는 지금과 같게 한다.

```php
        $twig = Twig::create($themes->templatePaths(), [
            'cache'            => false,
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);
        $twig->getEnvironment()->addFunction(new TwigFunction(
            'theme_asset',
            static fn (string $path): string => $themes->assetUrl($path, $basePath)
        ));
        $twig->getEnvironment()->addFilter(new TwigFilter(
            'cms_html',
            [$app->contentRenderer(), 'render'],
            ['is_safe' => ['html']]
        ));
        $view = new TwigView($twig, $slim->getRouteCollector()->getRouteParser(), $basePath);

        $view->addGlobal('current_user', [
            'is_guest' => true, 'id' => null, 'display_name' => null, 'is_admin' => false,
        ]);
        $view->addGlobal('csrf_token', '');
        // … 나머지 addGlobal 도 같은 꼴로. 값을 만드는 코드는 지금 그대로.

        $slim->add(TwigMiddleware::create($slim, $twig));
        $slim->add(new ViewMiddleware($view));
        $slim->addRoutingMiddleware();
        $slim->add(new ErrorPageMiddleware(
            $view,
            (bool) $app->config('debug', false),
            $app->config('log.file') === null ? null : (string) $app->config('log.file')
        ));
        $slim->add(new HtmlContentTypeMiddleware());
        $slim->add(new SessionGuard($app, $view));
```

`ErrorPageMiddleware` 생성자에서 `routeParser`·`basePath` 인자를 뺀다(이제 `bindRequest()` 가 한다).

- [ ] **Step 5: `SessionGuard` 와 `ErrorPageMiddleware` 를 `ViewInterface` 로**

`SessionGuard`: `private Twig $twig` → `private ViewInterface $view`; 세 개의 `addGlobal` 을 `$this->view->addGlobal(...)` 로.

`ErrorPageMiddleware`: `Twig $twig` → `ViewInterface $view`. `TwigRuntimeLoader` 등록 줄을 `$this->view->bindRequest($request);` 로. `render()` 는:

```php
    private function render(int $status, string $title, string $message, array $details = []): ResponseInterface
    {
        $response = (new Response())->withStatus($status);
        return $this->view->render($response, 'error', [
            'title'   => $title,
            'message' => $message,
            'details' => $details,
        ]);
    }
```

`use Slim\Views\Twig;`·`use Slim\Views\TwigRuntimeLoader;` 를 지운다.

- [ ] **Step 6: 컨트롤러 44곳과 `Routes.php` 의 health 를 바꾼다**

기계적이다. 각 파일에서:

```bash
cd /home/kagla/gnucms.com
for f in src/Web/Controller/*Controller.php src/Web/Routes.php; do
  sed -i -E "s/Twig::fromRequest\(\\\$request\)->render\(/View::fromRequest(\$request)->render(/g; s/'([a-z_\/]+)\.html\.twig'/'\1'/g" "$f"
  sed -i -E "s/^use Slim\\\\Views\\\\Twig;$/use GnuCms\\\\View\\\\View;/" "$f"
done
grep -rn "Twig" src/Web/Controller/ src/Web/Routes.php src/Web/Middleware/SessionGuard.php src/Web/Middleware/ErrorPageMiddleware.php
```

Expected: 마지막 grep 이 아무것도 안 낸다. `use` 줄이 원래 없던 파일(`Routes.php` 는 다른 `use` 가 많다)은 손으로 `use GnuCms\View\View;` 를 더한다. `AuthController` 의 여러 줄에 걸친 `render(` 호출도 첫 줄이 바뀌므로 함께 잡힌다.

- [ ] **Step 7: 전체 테스트**

Run: `./vendor/bin/phpunit`
Expected: OK (292 tests) — 화면은 한 글자도 안 달라졌어야 한다.

Run: `php /tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad/smoke.php default`
Expected: `검사 41개 / 실패 0개`

- [ ] **Step 8: 커밋**

```bash
git add src/View src/Web
git commit -m "refactor: 컨트롤러가 Twig 대신 View 추상으로 화면을 그린다

템플릿 이름은 확장자 없는 논리 이름이고 확장자는 엔진이 붙인다. Twig 를
아는 곳을 TwigView 와 Kernel 조립 한 줄로 좁혀, PHP 엔진을 나란히 세우고
나중에 Twig 를 걷어낼 때 컨트롤러를 다시 안 만지게 한다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: `PhpView` 와 `PhpTemplate`

**Files:**
- Create: `src/View/PhpView.php`, `src/View/PhpTemplate.php`
- Test: `tests/View/PhpViewTest.php`

**Interfaces:**
- Consumes: `ViewInterface` (Task 1), `ThemeManager::assetUrl()`, `ContentRenderer::render()`, `RouteParserInterface::urlFor()`
- Produces:
  - `new PhpView(array $paths, RouteParserInterface $routes, string $basePath, callable $assetUrl, callable $htmlRenderer)`
  - 템플릿 안의 `$this` 헬퍼: 설계 3.3 의 표 그대로. 시그니처:
    - `e(mixed $v): string`
    - `layout(string $name): void`
    - `start(string $block): void`, `stop(): void`
    - `block(string $name, string $default = ''): string`
    - `has(string $name): bool`
    - `insert(string $template, array $data = []): void`, `fetch(string $template, array $data = []): string`
    - `url(string $route, array $params = [], array $query = []): string`
    - `asset(string $path): string`
    - `html(string $content): string`
    - `icon(string $name, int $size = 20, string $cls = ''): string`
    - `date(mixed $v, string $format): string`
    - `json(mixed $v): string`
    - 공개 속성 `string $base`

- [ ] **Step 1: 실패하는 테스트를 쓴다**

`tests/View/PhpViewTest.php`

```php
<?php

declare(strict_types=1);

namespace GnuCms\Tests\View;

use GnuCms\View\PhpView;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Interfaces\RouteParserInterface;

final class PhpViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gnucms-phpview-' . getmypid();
        @mkdir($this->dir . '/sub', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,sub/}*.php', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/sub');
        @rmdir($this->dir);
    }

    private function write(string $name, string $php): void
    {
        file_put_contents($this->dir . '/' . $name . '.php', $php);
    }

    private function view(): PhpView
    {
        $routes = $this->createMock(RouteParserInterface::class);
        $routes->method('urlFor')->willReturnCallback(
            static fn (string $name, array $p = [], array $q = []): string =>
                '/r/' . $name . ($p ? '/' . implode('/', $p) : '') . ($q ? '?' . http_build_query($q) : '')
        );
        return new PhpView(
            [$this->dir],
            $routes,
            '/base',
            static fn (string $path): string => '/themes/t/' . $path,
            static fn (string $html): string => '<div class="rich">' . $html . '</div>'
        );
    }

    public function testEscapesLikeTwig(): void
    {
        $this->write('a', '<?= $this->e($v) ?>|<?= $this->e(null) ?>');
        self::assertSame('&lt;b&gt;&quot;&#039;&amp;|', $this->view()->fetch('a', ['v' => '<b>"\'&']));
    }

    public function testChildBlocksFillLayoutAndOutsideOutputIsDropped(): void
    {
        $this->write('layout', "<html><title><?= \$this->block('title', '기본') ?></title><main><?= \$this->block('body') ?></main></html>");
        $this->write('page', "<?php \$this->layout('layout') ?>버림<?php \$this->start('body') ?>본문 <?= \$this->e(\$name) ?><?php \$this->stop() ?>");
        self::assertSame(
            '<html><title>기본</title><main>본문 홍길동</main></html>',
            $this->view()->fetch('page', ['name' => '홍길동'])
        );
    }

    public function testChildBlockBeatsParentDefaultAndNestedLayoutsWork(): void
    {
        // 루트 레이아웃은 start/stop 으로 '기본값을 정의하면서 출력' 한다 (Twig 의 {% block %}).
        $this->write('layout', "[<?php \$this->start('chrome') ?>기본크롬<?php \$this->stop() ?>|<?= \$this->block('body') ?>]");
        // 중간 레이아웃은 자식이면서 부모다. 자기 블록은 조용히 잡히고, 자식 블록을 읽어 넣는다.
        $this->write('sub/layout', "<?php \$this->layout('layout') ?><?php \$this->start('chrome') ?>관리크롬(<?= \$this->block('body') ?>)<?php \$this->stop() ?>");
        $this->write('page', "<?php \$this->layout('sub/layout') ?><?php \$this->start('body') ?>글<?php \$this->stop() ?>");
        self::assertSame('[관리크롬(글)|글]', $this->view()->fetch('page'));
    }

    public function testHasSeesOnlyBlocksTheChildFilled(): void
    {
        $this->write('layout', "<?= \$this->has('search') ? 'S' : '-' ?>");
        $this->write('with', "<?php \$this->layout('layout') ?><?php \$this->start('search') ?>x<?php \$this->stop() ?>");
        $this->write('blank', "<?php \$this->layout('layout') ?><?php \$this->start('search') ?>  <?php \$this->stop() ?>");
        $this->write('none', "<?php \$this->layout('layout') ?>");
        self::assertSame('S', $this->view()->fetch('with'));
        self::assertSame('-', $this->view()->fetch('blank'), '공백만 있는 블록은 비어 있는 것이다');
        self::assertSame('-', $this->view()->fetch('none'));
    }

    public function testInsertPassesCurrentVariablesPlusExtras(): void
    {
        $this->write('part', '<?= $this->e($site) ?>/<?= $this->e($x) ?>');
        $this->write('page', "<?php \$this->insert('part', ['x' => 1]) ?>;<?php \$this->insert('part', ['x' => 2]) ?>");
        $view = $this->view();
        $view->addGlobal('site', 'S');
        self::assertSame('S/1;S/2', $view->fetch('page'));
    }

    public function testDataBeatsGlobalsWithSameName(): void
    {
        $this->write('a', '<?= $this->e($site) ?>');
        $view = $this->view();
        $view->addGlobal('site', 'global');
        self::assertSame('local', $view->fetch('a', ['site' => 'local']));
    }

    public function testUrlAssetHtmlJsonDateHelpers(): void
    {
        $this->write('a', "<?= \$this->url('posts.show', ['id' => '7'], ['q' => 'a b']) ?>|<?= \$this->asset('theme.css') ?>|<?= \$this->html('<p>x</p>') ?>|<?= \$this->json(['a' => '<']) ?>|<?= \$this->date('2026-08-30 01:02:03', 'Y.m.d H:i') ?>|<?= \$this->base ?>");
        self::assertSame(
            '/r/posts.show/7?q=a+b|/themes/t/theme.css|<div class="rich"><p>x</p></div>|{"a":"<"}|2026.08.30 01:02|/base',
            $this->view()->fetch('a')
        );
    }

    public function testIconComesFromIconsFile(): void
    {
        $this->write('_icons', "<?php return ['home' => '<path d=\"M1 1\"/>'];");
        $this->write('a', "<?= \$this->icon('home', 18, 'x') ?>|<?= \$this->icon('nope') ?>");
        $out = $this->view()->fetch('a');
        self::assertStringContainsString('<svg class="icon x" width="18" height="18"', $out);
        self::assertStringContainsString('<path d="M1 1"/>', $out);
        self::assertStringContainsString('|<svg class="icon" width="20" height="20"', $out);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->view()->fetch('does/not/exist');
    }

    public function testExceptionInsideTemplateDoesNotLeakBufferedOutput(): void
    {
        $this->write('a', "앞<?php throw new \\LogicException('x'); ?>");
        $level = ob_get_level();
        try {
            $this->view()->fetch('a');
            self::fail('예외가 나야 한다');
        } catch (\LogicException $e) {
            self::assertSame($level, ob_get_level(), '출력 버퍼가 남으면 다음 화면이 깨진다');
        }
    }
}
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter PhpViewTest`
Expected: FAIL — `Class "GnuCms\View\PhpView" not found`

- [ ] **Step 3: `PhpTemplate` 을 만든다**

`src/View/PhpTemplate.php`

```php
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
```

**icon() 의 `<svg …>` 여는 태그는 `templates/default/_icons.html.twig` 2~3행과 글자 단위로 같아야 한다.** 구현자는 그 파일을 열어 속성 순서와 공백을 맞춘다. (`{% if cls %} {{ cls }}{% endif %}` 는 `cls` 가 비면 아무것도 안 낸다.)

- [ ] **Step 4: `PhpView` 를 만든다**

`src/View/PhpView.php`

```php
<?php

declare(strict_types=1);

namespace GnuCms\View;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\Interfaces\RouteParserInterface;

/**
 * PHP 파일 템플릿 엔진. 경로 목록에서 '{이름}.php' 를 찾아 PhpTemplate 로 돌린다.
 * 지금은 경로가 하나(선택 테마)뿐이다. 나중에 PHP 테마끼리 폴백할 때 둘 이상이 된다.
 */
final class PhpView implements ViewInterface
{
    /** @var string[] */
    private array $paths;

    private RouteParserInterface $routes;

    private string $basePath;

    /** @var callable(string):string */
    private $assetUrl;

    /** @var callable(string):string */
    private $htmlRenderer;

    /** @var array<string,mixed> */
    private array $globals = [];

    /** @var array<string,string>|null _icons.php 를 한 번만 읽는다 */
    private ?array $icons = null;

    public function __construct(
        array $paths,
        RouteParserInterface $routes,
        string $basePath,
        callable $assetUrl,
        callable $htmlRenderer
    ) {
        $this->paths = array_values(array_map(static fn (string $p): string => rtrim($p, '/'), $paths));
        $this->routes = $routes;
        $this->basePath = $basePath;
        $this->assetUrl = $assetUrl;
        $this->htmlRenderer = $htmlRenderer;
    }

    public function render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        $response->getBody()->write($this->fetch($template, $data));
        return $response;
    }

    public function fetch(string $template, array $data = []): string
    {
        // 이름이 겹치면 데이터가 전역을 이긴다.
        return (new PhpTemplate($this, $data + $this->globals, $this->basePath))->run($template);
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
    }

    public function bindRequest(ServerRequestInterface $request): void
    {
        // 주소 만들기에 요청이 필요 없다. RouteParser 가 기준 경로까지 붙여 준다.
    }

    /** '{이름}.php' 의 실제 경로. 없으면 예외 — 다른 엔진으로 폴백하지 않는다. */
    public function resolve(string $template): string
    {
        if ($template === '' || str_contains($template, '..') || str_contains($template, "\0")) {
            throw new RuntimeException('템플릿 이름이 올바르지 않습니다: ' . $template);
        }
        foreach ($this->paths as $path) {
            $file = $path . '/' . $template . '.php';
            if (is_file($file)) {
                return $file;
            }
        }
        throw new RuntimeException('템플릿을 찾을 수 없습니다: ' . $template . '.php');
    }

    public function url(string $route, array $params = [], array $query = []): string
    {
        return $this->routes->urlFor($route, $params, $query);
    }

    public function asset(string $path): string
    {
        return ($this->assetUrl)($path);
    }

    public function html(string $content): string
    {
        return ($this->htmlRenderer)($content);
    }

    /** @return array<string,string> */
    public function icons(): array
    {
        if ($this->icons === null) {
            $this->icons = [];
            foreach ($this->paths as $path) {
                $file = $path . '/_icons.php';
                if (is_file($file)) {
                    $loaded = include $file;
                    $this->icons = is_array($loaded) ? $loaded : [];
                    break;
                }
            }
        }
        return $this->icons;
    }
}
```

- [ ] **Step 5: 테스트가 통과하는지 본다**

Run: `./vendor/bin/phpunit --filter PhpViewTest`
Expected: PASS (10 tests)

- [ ] **Step 6: 커밋**

```bash
git add src/View/PhpView.php src/View/PhpTemplate.php tests/View/PhpViewTest.php
git commit -m "feat: PHP 파일 템플릿 엔진 PhpView 를 만든다

템플릿은 PhpTemplate 메서드 안에서 include 되어 \$this 가 헬퍼다. 레이아웃은
자식이 먼저 돌며 블록을 잡고 부모가 그것을 내는 방식이라 Twig 의 extends
와 같이 움직인다. 다른 엔진으로 폴백하지 않는다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: 엔진 선택 — `theme.php`, `ThemeManager::engine()`, `Kernel` 조립, 테스트 스위치

**Files:**
- Modify: `src/Theme/ThemeManager.php`, `src/Web/Kernel.php`, `tests/Support/WebTestCase.php`
- Create: `templates/native/theme.php`, `public/themes/native/theme.css`(복사)
- Test: `tests/Theme/ThemeManagerTest.php` (있으면 더하고, 없으면 만든다)

**Interfaces:**
- Produces: `ThemeManager::engine(): string` — `'php'` | `'twig'`. `ThemeManager::manifest(): array`.
- `Kernel` 이 엔진에 따라 `TwigView`/`PhpView` 를 만든다. PHP 일 때 `TwigMiddleware` 를 안 단다.
- `GNUCMS_TEST_THEME` 환경변수 — `WebTestCase::makeApp()` 이 읽어 테마 설정을 저장한다.

- [ ] **Step 1: 실패하는 테스트를 쓴다**

`tests/Theme/ThemeManagerTest.php` 에 더한다(파일이 없으면 `WebTestCase` 를 상속하지 않는 순수 `TestCase` 로 만든다).

```php
    public function testEngineComesFromManifest(): void
    {
        $root = sys_get_temp_dir() . '/gnucms-theme-' . getmypid();
        @mkdir($root . '/default', 0777, true);
        @mkdir($root . '/withphp', 0777, true);
        file_put_contents($root . '/withphp/theme.php', "<?php return ['engine' => 'php'];");
        try {
            self::assertSame('twig', (new ThemeManager($root, $root, 'default'))->engine());
            self::assertSame('php', (new ThemeManager($root, $root, 'withphp'))->engine());
        } finally {
            @unlink($root . '/withphp/theme.php');
            @rmdir($root . '/withphp');
            @rmdir($root . '/default');
            @rmdir($root);
        }
    }
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter testEngineComesFromManifest`
Expected: FAIL — `Call to undefined method GnuCms\Theme\ThemeManager::engine()`

- [ ] **Step 3: `ThemeManager` 에 매니페스트를 더한다**

```php
    /** 테마 폴더의 theme.php 가 돌려주는 배열. 없으면 빈 배열(= Twig 테마). */
    public function manifest(): array
    {
        $file = $this->templateRoot . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR . 'theme.php';
        if (!is_file($file)) {
            return [];
        }
        $loaded = include $file;
        return is_array($loaded) ? $loaded : [];
    }

    /** 'php' 또는 'twig'. 매니페스트가 php 라고 하지 않으면 Twig 다. */
    public function engine(): string
    {
        return ($this->manifest()['engine'] ?? 'twig') === 'php' ? 'php' : 'twig';
    }

    /** PHP 엔진이 볼 템플릿 경로. 지금은 선택 테마 하나뿐이다. */
    public function phpTemplatePaths(): array
    {
        return [$this->templateRoot . DIRECTORY_SEPARATOR . $this->name];
    }
```

- [ ] **Step 4: `Kernel` 이 엔진을 가른다**

Task 1 의 Twig 조립 블록을 `if` 로 감싼다.

```php
        $routeParser = $slim->getRouteCollector()->getRouteParser();
        if ($themes->engine() === 'php') {
            $view = new PhpView(
                $themes->phpTemplatePaths(),
                $routeParser,
                $basePath,
                static fn (string $path): string => $themes->assetUrl($path, $basePath),
                [$app->contentRenderer(), 'render']
            );
        } else {
            $twig = Twig::create($themes->templatePaths(), [ /* 지금 그대로 */ ]);
            // theme_asset · cms_html 등록 그대로
            $view = new TwigView($twig, $routeParser, $basePath);
        }
        // addGlobal 들 — 두 엔진 공통

        if ($view instanceof TwigView) {
            $slim->add(TwigMiddleware::create($slim, $view->twig()));
        }
        $slim->add(new ViewMiddleware($view));
```

- [ ] **Step 5: `native` 매니페스트와 정적 파일**

```bash
cd /home/kagla/gnucms.com
mkdir -p templates/native public/themes/native
cp public/themes/default/theme.css public/themes/native/theme.css
cat > templates/native/theme.php <<'PHP'
<?php
// PHP 파일 템플릿 테마. 이 파일이 있으면 Kernel 이 Twig 대신 PhpView 를 세운다.
return ['engine' => 'php', 'label' => 'PHP 네이티브 (하늘빛)'];
PHP
```

- [ ] **Step 6: 테스트 스위치**

`tests/Support/WebTestCase.php::makeApp()` 의 `$schema->create();` 다음에:

```php
        // 전체 스위트를 다른 테마로 한 번 더 돌릴 때 쓴다: GNUCMS_TEST_THEME=native ./vendor/bin/phpunit
        $theme = getenv('GNUCMS_TEST_THEME');
        if (is_string($theme) && $theme !== '') {
            $app->cms()->saveSettings(['theme' => $theme]);
        }
```

- [ ] **Step 7: 검사**

Run: `./vendor/bin/phpunit`
Expected: OK (293 tests)

Run: `GNUCMS_TEST_THEME=native ./vendor/bin/phpunit 2>&1 | tail -3`
Expected: 대부분 FAIL — 템플릿이 아직 없어 `RuntimeException: 템플릿을 찾을 수 없습니다`. **500 이 아니라 그 예외 메시지가 오류 화면에 보이면 배선은 맞다.** 다음 작업부터 줄어든다.

- [ ] **Step 8: 커밋**

```bash
git add src/Theme src/Web/Kernel.php tests/ templates/native public/themes/native
git commit -m "feat: 테마가 theme.php 로 엔진을 고르고 Kernel 이 PhpView 를 세운다

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: `native` 뼈대 — 레이아웃·아이콘·오류·health, 파리티 도구

**Files:**
- Create: `templates/native/_icons.php`, `templates/native/layout.php`, `templates/native/admin/layout.php`, `templates/native/admin/_sidebar.php`, `templates/native/error.php`, `templates/native/health.php`
- Test: `tests/View/ThemeParityTest.php`

**Interfaces:**
- Consumes: `PhpView` 헬퍼 (Task 2)
- Produces: 이후 모든 화면이 쓰는 `layout`·`admin/layout` 의 블록 이름 — Twig 판과 같게 `title`, `meta_description`, `body_class`, `nav_section`, `chrome`, `site_header`, `header_search`, `extra_tabs`, `subnav`, `body`, `site_footer`, `scripts`, `admin_section`.
- `ThemeParityTest` — 데이터 프로바이더 `routes()` 가 41개 경로를 주고, 각각 `default` 와 `native` 로 그려 정규화 뒤 비교한다.

- [ ] **Step 1: 파리티 테스트를 쓴다**

`tests/View/ThemeParityTest.php` — 씨앗은 스모크 도구(`scratchpad/smoke.php` 27~88행)와 같은 것을 만든다. 게시판 4개(list/gallery/news/magazine), 글 6+1, 댓글 1, 내용 `about`, 씨앗 약관 공개, 관리자 한 명, 가입 허용.

```php
<?php

declare(strict_types=1);

namespace GnuCms\Tests\View;

use GnuCms\App;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 같은 데이터로 Twig(default)와 PHP(native)를 그려 HTML 을 비교한다.
 * 이스케이프 누락, 조건 실수, 빠진 속성이 전부 여기서 드러난다. 목표는 차이 0.
 */
final class ThemeParityTest extends WebTestCase
{
    /** @return array<string, array{string, int, bool}> 경로, 기대 상태, 관리자 로그인 여부 */
    public static function routes(): array
    {
        $guest = [
            '/', '/boards/free', '/boards/free?view=gallery', '/boards/free?view=magazine',
            '/boards/free?view=news', '/boards/free?q=테스트&category=질문', '/boards/photo',
            '/boards/news', '/boards/mag', '/boards/free/write', '/posts/{post}', '/posts/{post}/edit',
            '/comments/{comment}/edit', '/content/about', '/terms/service', '/terms/privacy',
            '/login', '/register', '/forgot-password', '/reset-password?token=abc', '/health',
        ];
        $admin = [
            '/admin', '/admin/boards', '/admin/boards/new', '/admin/boards/free/edit', '/admin/posts',
            '/admin/posts?q=테스트&board=free', '/admin/members', '/admin/members/{admin}/edit',
            '/admin/content', '/admin/content/new', '/admin/content/{page}/edit',
            '/admin/content/{page}/preview', '/admin/content/trash', '/admin/settings', '/admin/mail',
            '/admin/terms', '/admin/terms/new', '/admin/password', '/notifications',
        ];
        $cases = [];
        foreach ($guest as $path) {
            $cases[$path] = [$path, 200, false];
        }
        $cases['/no-such-page'] = ['/no-such-page', 404, false];
        foreach ($admin as $path) {
            $cases[$path] = [$path, 200, true];
        }
        return $cases;
    }

    #[DataProvider('routes')]
    public function testRouteRendersTheSameInBothEngines(string $path, int $status, bool $asAdmin): void
    {
        $html = [];
        foreach (['default', 'native'] as $theme) {
            $app = $this->makeApp(['dsn' => 'sqlite::memory:']);
            $app->cms()->saveSettings(['theme' => $theme, 'registration_enabled' => '1']);
            $ids = $this->seed($app);
            if ($asAdmin) {
                $this->loginAsAdmin($app, $ids['admin_email']);
            }
            $real = strtr($path, [
                '{post}' => (string) $ids['post'], '{comment}' => (string) $ids['comment'],
                '{page}' => (string) $ids['page'], '{admin}' => (string) $ids['admin'],
            ]);
            $response = $this->get($app, $real);
            self::assertSame($status, $response->getStatusCode(), $theme . ' ' . $real);
            $html[$theme] = $this->normalize($this->body($response), $theme);
        }
        self::assertSame($html['default'], $html['native'], $path);
    }

    /** 씨앗. smoke.php 와 같은 데이터. @return array{post:int,comment:int,page:int,admin:int,admin_email:string} */
    private function seed(App $app): array
    {
        // smoke.php 27~88행을 그대로 옮긴다. 관리자 계정은 users()->create(..., true) + verifyEmail.
        // 씨앗 약관은 ensureLegalDrafts 뒤 service·privacy 를 published 로 올린다.
        // (구현자는 smoke.php 를 열어 옮긴다. 이름·본문·순서까지 같아야 두 엔진의 HTML 이 같다.)
    }

    private function loginAsAdmin(App $app, string $email): void
    {
        $this->get($app, '/login');
        $this->post($app, '/login', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '', 'email' => $email, 'password' => 'admin-password-123',
        ]);
    }

    /** 설계 3.7 의 세 가지만 정규화한다. */
    private function normalize(string $html, string $theme): string
    {
        $html = preg_replace('/\?v=[0-9a-f]{12}/', '?v=HASH', $html) ?? $html;
        $html = str_replace('/themes/' . $theme . '/', '/themes/THEME/', $html);
        $html = preg_replace('/[ \t]+$/m', '', $html) ?? $html;
        $html = preg_replace('/\n{2,}/', "\n", $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;
        return trim($html);
    }
}
```

`seed()` 본문은 구현자가 `smoke.php` 27~88행에서 옮긴다. 두 엔진이 **같은 씨앗**을 받도록 `random_bytes` 로 만드는 `image_key` 는 상관없다(화면에 안 나온다). 글 작성 시각은 `Clock` 이 실제 시각을 쓰므로, 두 앱을 같은 초 안에 만들지 못하면 시각 표기가 달라질 수 있다 — **`tests/bootstrap.php` 의 `Clock::freeze()` 같은 고정 장치가 있는지 먼저 확인하고, 없으면 `GnuCms\Support\Clock` 에 테스트용 고정을 더한다.**

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter 'ThemeParityTest.*health'`
Expected: FAIL — native 쪽이 `템플릿을 찾을 수 없습니다: health.php`

- [ ] **Step 3: `_icons.php`**

`templates/default/_icons.html.twig` 의 `elseif name == 'x' %}<path…>` 51쌍을 `'x' => '<path…>'` 배열로 옮긴다. 한 줄에 여러 요소(`<path/><path/>`)가 있으면 그대로 한 문자열이다. 손으로 옮기지 말고 스크립트로 뽑는다:

```bash
cd /home/kagla/gnucms.com
python3 - <<'PY'
import re, io
s = io.open('templates/default/_icons.html.twig', encoding='utf-8').read()
pairs = re.findall(r"(?:if|elseif) name == '([a-z0-9-]+)' %\}(.*?)(?=\n\{%-|\n\{%)", s, re.S)
out = ["<?php", "// _icons.html.twig 에서 뽑았다. 이름 -> <svg> 안에 들어갈 요소.", "return ["]
for name, body in pairs:
    body = body.strip().replace("'", "\\'")
    out.append("    '%s' => '%s'," % (name, body))
out.append("];")
io.open('templates/native/_icons.php', 'w', encoding='utf-8').write("\n".join(out) + "\n")
print(len(pairs), "icons")
PY
php -l templates/native/_icons.php
```

Expected: `51 icons`, 문법 오류 없음. 뽑힌 수가 51이 아니면 정규식이 놓친 줄을 손으로 잡는다.

- [ ] **Step 4: `layout.php`**

`templates/default/layout.html.twig` 를 한 줄씩 옮긴다. 변환 규칙(모든 화면 이식에 공통):

| Twig | PHP |
|---|---|
| `{% extends "layout.html.twig" %}` | `<?php $this->layout('layout') ?>` (파일 첫 줄) |
| `{% block x %}…{% endblock %}` (자식) | `<?php $this->start('x') ?>…<?php $this->stop() ?>` |
| `{% block x %}기본{% endblock %}` (루트 레이아웃) | `<?php $this->start('x') ?>기본<?php $this->stop() ?>` — 같은 문법. 루트에서는 그 자리에 낸다 |
| `{{ block('x') }}` | `<?= $this->block('x') ?>` |
| `block('x')\|trim is not empty` | `$this->has('x')` |
| `block('x')\|trim == 'home'` | `trim($this->block('x')) === 'home'` |
| `{% include 'a/b.html.twig' %}` | `<?php $this->insert('a/b') ?>` |
| `{% include 'a/b.html.twig' with {'k': v} %}` | `<?php $this->insert('a/b', ['k' => $v]) ?>` |
| `{% import '_icons.html.twig' as ico %}` | 지운다 |
| `{{ ico.i('home', 18) }}` | `<?= $this->icon('home', 18) ?>` |
| `{{ x }}` | `<?= $this->e($x) ?>` |
| `{{ x\|raw }}` | `<?= $x ?>` — **값이 어디서 왔는지 확인하고**, 서버가 만든 안전한 HTML 일 때만 |
| `{{ x\|e }}` | `<?= $this->e($x) ?>` |
| `{{ x\|default('d') }}` | `<?= $this->e($x ?? 'd') ?>`. 배열 키면 `$a['k'] ?? 'd'` |
| `x is defined` | `isset($x)` / `array_key_exists('k', $a)` (값이 null 일 수 있으면 후자) |
| `x is empty` / `is not empty` | `empty($x)` / `!empty($x)` |
| `x is null` | `$x === null` |
| `x matches '/re/'` | `preg_match('/re/', (string) $x) === 1` |
| `x starts with 'p'` | `str_starts_with((string) $x, 'p')` |
| `{{ url_for('r', {k: v}) }}` | `<?= $this->url('r', ['k' => $v]) ?>` |
| `{{ url_for('r', {k: v}, {q: w}) }}` | `<?= $this->url('r', ['k' => $v], ['q' => $w]) ?>` |
| `{{ theme_asset('theme.css') }}` | `<?= $this->asset('theme.css') ?>` |
| `{{ x\|cms_html }}` | `<?= $this->html($x) ?>` |
| `{{ x\|date('Y.m.d') }}` | `<?= $this->date($x, 'Y.m.d') ?>` |
| `{{ x\|json_encode\|raw }}` | `<?= $this->json($x) ?>` |
| `{{ x\|json_encode }}` (raw 없음) | `<?= $this->e($this->json($x)) ?>` |
| `{{ x\|url_encode }}` | `<?= rawurlencode((string) $x) ?>` |
| `{{ x\|upper }}` / `\|capitalize` | `mb_strtoupper` / `mb_strtoupper(mb_substr($x,0,1)) . mb_substr($x,1)` |
| `{{ x\|slice(0, 1) }}` | 문자열 `mb_substr($x, 0, 1)`, 배열 `array_slice($x, 0, 1)` |
| `{{ x\|length }}` | 문자열 `mb_strlen`, 배열 `count` |
| `{{ x\|trim }}` / `\|striptags` / `\|round` / `\|join(', ')` | `trim` / `strip_tags` / `round` / `implode(', ', …)` |
| `{{ a\|merge(b) }}` | `array_merge($a, $b)` (키가 문자열이면 `$a + $b` 가 아니라 `array_merge`) |
| `x\|filter(v => …)` / `\|reduce((c, v) => …, 0)` / `\|sort` | `array_filter` / `array_reduce` / `sort` |
| `{% set x = … %}` | `<?php $x = …; ?>` |
| `{% set x %}…{% endset %}` | `<?php ob_start() ?>…<?php $x = ob_get_clean() ?>` |
| `{% for k, v in a %}…{% else %}…{% endfor %}` | `<?php if ($a === []): ?>…<?php else: foreach ($a as $k => $v): ?>…<?php endforeach; endif ?>` |
| `loop.index`, `loop.first`, `loop.last` | 직접 센다: `$i = 0; foreach … $i++` |
| `{% if a %}…{% elseif b %}…{% else %}…{% endif %}` | `<?php if ($a): ?>…<?php elseif ($b): ?>…<?php else: ?>…<?php endif ?>` |
| `{{ GNUCMS_ID }}` / `{{ GNUCMS }}` | `<?= $this->e(GNUCMS_ID) ?>` — 상수는 그대로 |
| `{{ base_path }}` | `<?= $this->e($this->base) ?>` |
| `{% macro %}` (아이콘 말고 다른 것) | 조각 파일로 빼서 `insert()` 하거나, 그 파일 안에서만 쓰면 `$f = function (…) { … }` 클로저 |
| `{{ a ~ b }}` | `$a . $b` |
| `{{ a ? b : c }}` / `{{ a ?: b }}` / `{{ a ?? b }}` | 같다 |
| `{{ "%02d"\|format(x) }}` | `sprintf('%02d', $x)` |

**주의 둘.** (1) Twig 의 `{{ }}` 는 자동 이스케이프다. PHP 에서 `<?= $x ?>` 를 `e()` 없이 쓰는 건 Twig 에 `|raw` 가 있던 자리뿐이다. (2) `{%- -%}` 의 공백 제어는 파리티가 태그 사이 공백을 지우므로 대개 무시해도 되지만, **텍스트 노드 안의 공백**(`<span>a {{ b }}</span>` 같은 곳)은 그대로 살린다.

`layout.html.twig` 43행 `has_search = block('header_search')|trim is not empty` — 루트 레이아웃 127행의 기본 블록이 비어 있지 않으면 Twig 는 그 기본값도 세지만 `$this->has()` 는 자식이 채운 것만 본다. 구현자는 127행을 열어 기본 본문이 비었는지 확인하고, 비어 있지 않으면 `$has_search = $this->has('header_search') || true;` 가 아니라 **그 기본 본문이 언제 비는지 Twig 와 같은 조건으로** 판단해 옮긴다.

- [ ] **Step 5: `admin/layout.php`, `admin/_sidebar.php`, `error.php`, `health.php`**

같은 규칙으로. `admin/layout.html.twig` 의 `{% include 'admin/_sidebar.html.twig' with {'section': block('admin_section')|trim} %}` 는 `<?php $this->insert('admin/_sidebar', ['section' => trim($this->block('admin_section'))]) ?>`.

- [ ] **Step 6: 파리티 — health 와 404**

Run: `./vendor/bin/phpunit --filter 'ThemeParityTest.*(health|no-such-page)'`
Expected: PASS (2)

차이가 나면 `assertSame` 의 diff 를 읽는다. 첫 번째 다른 줄이 원인이다. 정규화를 늘리지 말고 템플릿을 고친다.

- [ ] **Step 7: 커밋**

```bash
git add templates/native tests/View/ThemeParityTest.php src/Support 2>/dev/null
git commit -m "feat: native 테마의 뼈대와 두 엔진 파리티 테스트를 만든다

레이아웃·관리 레이아웃·아이콘·오류·health 를 PHP 로 옮긴다. 같은 씨앗으로
Twig 와 PHP 를 그려 HTML 을 견주는 테스트가 이후 이식의 게이트다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: 손님 화면 A — 홈·게시판 목록

**Files:**
- Create: `templates/native/home/index.php`, `home/_feed_list.php`, `home/_feed_gallery.php`, `home/_feed_magazine.php`, `home/_feed_news.php`, `boards/index.php`, `posts/index.php`, `posts/_list_list.php`, `posts/_list_gallery.php`, `posts/_list_magazine.php`, `posts/_list_news.php`, `posts/_thumb.php`, `posts/_count.php`, `posts/_meta.php`

**Interfaces:**
- Consumes: Task 4 의 레이아웃 블록 이름, 변환 규칙

- [ ] **Step 1: 대상 Twig 를 읽는다** — `templates/default/{home,boards,posts}/` 의 위 14개.
- [ ] **Step 2: 옮긴다.** 파일마다 Twig 를 옆에 두고 위에서 아래로. `home/index.html.twig` 21행의 `boards|reduce(...)` 는 `array_reduce($boards, fn ($c, $b) => $c + count($b['latest_posts']), 0)`.
- [ ] **Step 3: 파리티**

Run: `./vendor/bin/phpunit --filter 'ThemeParityTest.*(#/|boards)'`
Expected: PASS — `/`, `/boards/*` 9개

- [ ] **Step 4: 커밋**

```bash
git add templates/native
git commit -m "feat: native 테마 — 홈과 게시판 목록을 PHP 로 옮긴다

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: 손님 화면 B — 글·댓글·내용·알림

**Files:**
- Create: `templates/native/posts/show.php`, `posts/_comments.php`, `posts/comment_edit.php`, `posts/create.php`, `posts/edit.php`, `posts/_editor.php`, `pages/show.php`, `notifications/index.php`

- [ ] **Step 1: 옮긴다.** `posts/_comments.html.twig` 는 재귀 include 다 — `insert('posts/_comments', ['comments' => $child['children'], …])` 로 같은 파일을 다시 부른다. `_editor.html.twig` 의 `<script>` 안에 Twig 식이 있는지 확인한다(`{{ … }}` 가 JS 문자열 안에 있으면 `json()` 으로).
- [ ] **Step 2: 파리티**

Run: `./vendor/bin/phpunit --filter 'ThemeParityTest.*(posts|comments|content|terms|notifications)'`
Expected: PASS — 8개

- [ ] **Step 3: 커밋**

```bash
git add templates/native
git commit -m "feat: native 테마 — 글·댓글·내용·알림 화면을 PHP 로 옮긴다

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: 인증 화면

**Files:**
- Create: `templates/native/auth/login.php`, `register.php`, `_consents.php`, `_social.php`, `_social_consent.php`, `forgot.php`, `reset.php`, `reset_sent.php`, `reset_done.php`, `check_email.php`, `verified.php`, `social_email.php`, `social_email_sent.php`

- [ ] **Step 1: 옮긴다.** `_consents.html.twig` 의 `errors[field] is defined` 는 `isset($errors[$field])`. `values[field]|default(false)` 는 `$values[$field] ?? false`. `_social_consent.html.twig` 의 `|filter(doc => doc.required == 1)` 은 `array_filter($consent_documents, fn ($d) => (int) $d['required'] === 1)` — 그 뒤 `loop.last` 를 쓰므로 `array_values` 로 키를 다시 매긴다.
- [ ] **Step 2: 파리티**

Run: `./vendor/bin/phpunit --filter 'ThemeParityTest.*(login|register|forgot|reset)'`
Expected: PASS — 4개

- [ ] **Step 3: 커밋**

```bash
git add templates/native
git commit -m "feat: native 테마 — 로그인·가입·비밀번호·소셜 화면을 PHP 로 옮긴다

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: 관리 화면 A — 대시보드·게시판·글·회원

**Files:**
- Create: `templates/native/admin/index.php`, `admin/boards.php`, `admin/board_form.php`, `admin/posts.php`, `admin/members.php`, `admin/member_form.php`, `admin/_member_consents.php`

- [ ] **Step 1: 옮긴다.** `admin/posts.html.twig` 9행처럼 `{{- url_for(...) }}{% if params is not empty %}?{{ params|join('&amp;')|raw }}{% endif %}` 는 `<?= $this->url('admin.posts') ?><?= $params !== [] ? '?' . implode('&amp;', $params) : '' ?>` — `params` 가 이미 이스케이프된 조각인지 Twig 를 보고 판단한다.
- [ ] **Step 2: 파리티**

Run: `./vendor/bin/phpunit --filter 'ThemeParityTest.*admin/(boards|posts|members)|ThemeParityTest.*#/admin$'`
Expected: PASS — 8개 (`/admin` 포함)

- [ ] **Step 3: 커밋**

```bash
git add templates/native
git commit -m "feat: native 테마 — 대시보드·게시판·글·회원 관리 화면을 PHP 로 옮긴다

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 9: 관리 화면 B — 내용·약관·설정·메일·비밀번호

**Files:**
- Create: `templates/native/admin/pages.php`, `admin/page_form.php`, `admin/_editor.php`, `admin/trash.php`, `admin/settings.php`, `admin/mail.php`, `admin/legal.php`, `admin/consents.php`, `admin/password.php`, `admin/password_done.php`

- [ ] **Step 1: 옮긴다.** `page_form.html.twig` 의 `<details>`·`<script>` 블록도 그대로. `legal.html.twig` 43행 `page.uses[0].scope|default('') starts with 'form:'` 은 `str_starts_with((string) ($page['uses'][0]['scope'] ?? ''), 'form:')`, `|slice(5)` 는 `mb_substr(…, 5)`.
- [ ] **Step 2: 파리티**

Run: `./vendor/bin/phpunit --filter 'ThemeParityTest.*admin/(content|settings|mail|terms|password)'`
Expected: PASS — 11개

- [ ] **Step 3: 커밋**

```bash
git add templates/native
git commit -m "feat: native 테마 — 내용·약관·설정·메일·비밀번호 관리 화면을 PHP 로 옮긴다

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 10: 전체 파리티 0, 스위트 두 번, 문서

**Files:**
- Modify: `templates/native/README.txt`(새로), `templates/TEMPLATE_GUIDE.md`(PHP 테마 절 추가), `docs/superpowers/specs/2026-08-30-native-php-theme-design.md`(상태)

- [ ] **Step 1: 전체 파리티**

Run: `./vendor/bin/phpunit --filter ThemeParityTest`
Expected: OK (41 tests). 하나라도 실패하면 그 화면을 고친다 — 정규화를 늘리지 않는다.

- [ ] **Step 2: 스위트 두 번**

```bash
./vendor/bin/phpunit 2>&1 | tail -2
GNUCMS_TEST_THEME=native ./vendor/bin/phpunit 2>&1 | tail -2
```

Expected: 둘 다 OK. 두 번째에서만 깨지는 테스트는 (a) 테마 이름을 단언하는 테스트면 `default` 를 `getenv('GNUCMS_TEST_THEME') ?: 'default'` 로 바꾸고, (b) 화면 차이면 그건 파리티가 놓친 결함이므로 템플릿을 고친다.

- [ ] **Step 3: 스모크와 Twig 무결성**

```bash
php /tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad/smoke.php native
php /tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad/lint.php
grep -rn "use Twig\\\\\|use Slim\\\\Views" src/ | grep -v "src/View/TwigView.php\|src/Web/Kernel.php"
```

Expected: `실패 0개`, `ALL OK`, 마지막 grep 은 빈 출력.

- [ ] **Step 4: 문서**

`templates/native/README.txt` — 헬퍼 표(설계 3.3)와 "출력은 전부 `$this->e()`" 규칙, 파리티 테스트 돌리는 법. `templates/TEMPLATE_GUIDE.md` 에 "PHP 테마는 `theme.php` 로 엔진을 고른다" 한 절. 설계 문서 상태를 `구현됨` 으로.

- [ ] **Step 5: 커밋**

```bash
git add templates/native/README.txt templates/TEMPLATE_GUIDE.md docs/
git commit -m "docs: native 테마 안내와 PHP 엔진 절을 적는다

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## 다음 판으로 미루는 것

- `native` → `default` 개명, `TwigView`·`TwigMiddleware`·Twig 테마 23벌·`slim/twig-view`·`twig/twig` 제거
- PHP 테마 간 폴백(`PhpView` 경로 둘 이상)
- 라이브 사이트 테마 전환 (파리티 0 이 확인된 뒤 사람이 결정)
