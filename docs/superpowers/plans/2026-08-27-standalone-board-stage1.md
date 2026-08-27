# 단독 게시판 전환 1단계 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** JSON API 표면을 걷어내고 Slim + Twig 위에서 공개 게시판을 서버 렌더링으로 읽을 수 있게 만든다.

**Architecture:** 기존 `Repository → Service` 계층은 그대로 두고 그 위의 HTTP 층만 교체한다. 도메인이 `ApiBoard\Http` 에 의존하던 고리(`ApiError`, `FileResponse`)를 먼저 끊은 뒤에야 `Http/` 를 지울 수 있다. 새 `Web/` 층은 Slim 라우트 → 컨트롤러 → Service 순서로만 흐르고, Service 는 HTTP·Twig 를 계속 모른다.

**Tech Stack:** PHP 8.1, Slim 4, slim/psr7, slim/twig-view, Twig 3, PHPUnit 10

**설계 문서:** `docs/superpowers/specs/2026-08-27-standalone-board-design.md` (1단계 = 13장의 1행)

## Global Constraints

- **PHP 8.1 이상.** 7.4 호환을 위한 회피(생성자 프로퍼티 승격 금지 등)는 더 이상 지키지 않아도 되지만, 기존 파일을 그 이유만으로 고쳐 쓰지는 않는다.
- **런타임 의존성을 허용한다.** 배포물에는 `vendor/` 를 포함한다. 설치자에게 CLI 를 요구하지 않는다.
- **`mod_rewrite` 를 가정하지 않는다.** 없으면 `index.php/b/free` 형태로 동작해야 한다.
- **MySQL 5.7 을 지원한다.** 재귀 CTE, JSON 타입 함수, 윈도 함수를 쓰지 않는다.
- **같은 테스트 스위트가 SQLite·MySQL·PostgreSQL 에서 모두 통과하는 것이 완료 조건이다.** `TEST_MYSQL_DSN`, `TEST_PGSQL_DSN` 이 없으면 SQLite 만 돌고, `tests/bootstrap.php` 가 그 사실을 STDERR 에 알린다.
- **Controller 는 Service 만 호출하고, Service 는 HTTP·세션·Twig 를 모른다.**
- 주석·화면 문구·커밋 메시지는 한국어로 쓴다. 커밋 접두사는 기존 관례(`feat:`, `fix:`, `refactor:`, `docs:`, `test:`)를 따른다.
- 네임스페이스는 `ApiBoard` 를 그대로 둔다. 개명(`aboard` / `Aboard\`)은 6단계에서 한 번에 한다. 이 단계에서 만드는 파일도 `ApiBoard\` 로 쓴다.
- 이 단계에서는 로그인이 없다. 모든 요청은 게스트(`Identity::guest()`)로 처리한다.

---

### Task 1: Composer 오토로더로 갈아타고 PHP 8.1 을 최저선으로 올린다

**Files:**
- Modify: `composer.json`
- Modify: `tests/bootstrap.php:5-6`
- Delete: `src/autoload.php`

**Interfaces:**
- Consumes: 없음
- Produces: `ApiBoard\` PSR-4 오토로딩(`src/`), `vendor/` 에 slim/slim, slim/psr7, slim/twig-view, twig/twig

- [ ] **Step 1: 자체 오토로더를 지워 실패를 만든다**

```bash
rm src/autoload.php
```

- [ ] **Step 2: 실패를 확인한다**

Run: `vendor/bin/phpunit`
Expected: FAIL — `tests/bootstrap.php` 가 `src/autoload.php` 를 require 하지 못해 `Failed opening required` 로 죽는다.

- [ ] **Step 3: composer.json 을 교체한다**

```json
{
    "name": "kagla/apiboard",
    "description": "어느 DB 에서나 도는 게시판",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.1",
        "slim/psr7": "^1.6",
        "slim/slim": "^4.12",
        "slim/twig-view": "^3.4",
        "twig/twig": "^3.8"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "ApiBoard\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "ApiBoard\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 4: 의존성을 설치한다**

Run: `composer update`
Expected: `vendor/slim/slim`, `vendor/twig/twig` 가 생기고 `composer.lock` 이 갱신된다.

- [ ] **Step 5: 테스트 부트스트랩에서 자체 오토로더 require 를 뺀다**

`tests/bootstrap.php` 의 앞 두 줄을 다음 한 줄로 바꾼다.

```php
require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 6: 테스트가 다시 통과하는지 확인한다**

Run: `vendor/bin/phpunit`
Expected: PASS. 이 시점에는 아직 기존 API 테스트가 전부 살아 있으므로 모두 초록이어야 한다.

- [ ] **Step 7: 커밋**

```bash
git add composer.json composer.lock tests/bootstrap.php
git add -A src/autoload.php
git commit -m "refactor: composer 오토로더로 갈아타고 PHP 8.1 을 최저선으로 올린다"
```

---

### Task 2: ApiError 를 Http/ 밖으로 옮긴다

도메인 계층(`Db/`, `Service/`, `Auth/Acl`, `Validation/`, `Support/`, `Install/`)이 `ApiBoard\Http\ApiError` 를 던진다. 이 고리를 끊지 않으면 `Http/` 를 지울 수 없다. 클래스 내용은 그대로 두고 네임스페이스와 이름만 바꾼다.

**Files:**
- Create: `src/Error/DomainError.php`
- Create: `tests/Error/DomainErrorTest.php`
- Delete: `src/Http/ApiError.php`, `tests/Http/ApiErrorTest.php`
- Modify: `ApiError` 를 참조하는 모든 파일 (아래 Step 5 의 명령이 찾아낸다)

**Interfaces:**
- Consumes: Task 1 의 PSR-4 오토로딩
- Produces: `ApiBoard\Error\DomainError` — `RuntimeException` 상속.
  - `__construct(string $code, string $message, int $status, array $details = [])`
  - `code(): string`, `status(): int`, `details(): array`
  - 정적 팩터리: `unauthorized(string): self` (401), `forbidden(string): self` (403), `notFound(string): self` (404), `validation(array $details): self` (422), `tooLarge(string): self` (413), `internal(string): self` (500)

- [ ] **Step 1: 실패하는 테스트를 쓴다**

Create `tests/Error/DomainErrorTest.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Error;

use ApiBoard\Error\DomainError;
use PHPUnit\Framework\TestCase;

final class DomainErrorTest extends TestCase
{
    public function testFactoriesCarryStatusAndCode(): void
    {
        self::assertSame(401, DomainError::unauthorized('로그인이 필요합니다.')->status());
        self::assertSame('UNAUTHORIZED', DomainError::unauthorized('x')->code());
        self::assertSame(403, DomainError::forbidden('x')->status());
        self::assertSame(404, DomainError::notFound('x')->status());
        self::assertSame(413, DomainError::tooLarge('x')->status());
        self::assertSame(500, DomainError::internal('x')->status());
    }

    public function testValidationCarriesDetails(): void
    {
        $error = DomainError::validation(['title' => '필수입니다.']);

        self::assertSame(422, $error->status());
        self::assertSame('VALIDATION_FAILED', $error->code());
        self::assertSame(['title' => '필수입니다.'], $error->details());
    }

    public function testMessageSurvives(): void
    {
        self::assertSame('없습니다.', DomainError::notFound('없습니다.')->getMessage());
    }
}
```

- [ ] **Step 2: 실패를 확인한다**

Run: `vendor/bin/phpunit tests/Error/DomainErrorTest.php`
Expected: FAIL — `Class "ApiBoard\Error\DomainError" not found`

- [ ] **Step 3: DomainError 를 만든다**

Create `src/Error/DomainError.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Error;

use RuntimeException;

/**
 * 사용자에게 그대로 보여줄 수 있는 오류. 이 예외가 아닌 모든 예외는
 * 프론트 컨트롤러에서 INTERNAL 로 변환되고 원문은 로그에만 남는다.
 *
 * 도메인 계층이 던지므로 HTTP 네임스페이스에 두지 않는다. status() 가
 * HTTP 상태 코드인 것은 이 예외를 화면으로 옮기는 층의 편의를 위한 것이고,
 * 도메인은 이 값을 읽지 않는다.
 */
final class DomainError extends RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $status;

    /** @var array */
    private $details;

    public function __construct(string $code, string $message, int $status, array $details = [])
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->status = $status;
        $this->details = $details;
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }

    public static function unauthorized(string $message): self
    {
        return new self('UNAUTHORIZED', $message, 401);
    }

    public static function forbidden(string $message): self
    {
        return new self('FORBIDDEN', $message, 403);
    }

    public static function notFound(string $message): self
    {
        return new self('NOT_FOUND', $message, 404);
    }

    public static function validation(array $details): self
    {
        return new self('VALIDATION_FAILED', '입력값을 확인해 주세요.', 422, $details);
    }

    public static function tooLarge(string $message): self
    {
        return new self('PAYLOAD_TOO_LARGE', $message, 413);
    }

    public static function internal(string $message): self
    {
        return new self('INTERNAL', $message, 500);
    }
}
```

- [ ] **Step 4: 새 테스트가 통과하는지 확인한다**

Run: `vendor/bin/phpunit tests/Error/DomainErrorTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: 모든 참조를 기계적으로 바꾼다**

`ApiError` 는 `use` 문, 타입힌트(`catch (ApiError $e)`), 정적 호출(`ApiError::notFound`) 세 형태로만 나타난다. 단어 경계로 치환하면 안전하다.

`public/install.php` 도 이 예외를 쓴다. 치환 범위에 `public` 을 반드시 넣는다.

```bash
FILES=$(grep -rl '\bApiError\b' src tests public | grep -v 'src/Http/ApiError.php' | grep -v 'tests/Http/ApiErrorTest.php')
echo "$FILES"
for f in $FILES; do
  sed -i 's/use ApiBoard\\Http\\ApiError;/use ApiBoard\\Error\\DomainError;/g; s/\bApiError\b/DomainError/g' "$f"
done
grep -rn '\bApiError\b' src tests public | grep -v 'src/Http/ApiError.php' | grep -v 'tests/Http/ApiErrorTest.php'
```

Expected: 마지막 `grep` 이 아무것도 출력하지 않는다(종료 코드 1).

- [ ] **Step 6: 옛 파일을 지운다**

```bash
rm src/Http/ApiError.php tests/Http/ApiErrorTest.php
```

- [ ] **Step 7: 전체 테스트가 통과하는지 확인한다**

Run: `vendor/bin/phpunit`
Expected: PASS. 기존 API 테스트가 여전히 살아 있고 모두 초록이어야 한다. 여기서 깨지면 Step 5 의 치환이 놓친 곳이 있다는 뜻이다.

- [ ] **Step 8: 커밋**

```bash
git add -A
git commit -m "refactor: ApiError 를 Error\\DomainError 로 옮겨 도메인의 Http 의존을 끊는다"
```

---

### Task 3: 첨부 다운로드의 반환을 HTTP 에서 떼어낸다

`AttachmentService::download()` 만이 `Http\FileResponse` 를 돌려주며 도메인에 남은 마지막 HTTP 의존이다. 서술자 배열을 돌려주게 바꾸고, 파일을 실제로 내보내는 일은 Task 7 의 Web 계층이 맡는다.

이것은 기존 테스트가 덮고 있는 순수 리팩터링이다. 새 테스트를 여기서 쓰지 않는다 — 지금 쓸 자리인 `tests/Api/AttachmentApiTest.php` 는 Task 4 에서 파일째 사라진다. 살아남는 테스트는 Task 7 에서 `tests/Web/AttachmentDownloadTest.php` 로 쓴다.

**Files:**
- Modify: `src/Service/AttachmentService.php:146-166`
- Modify: `src/Routes.php` (Task 4 에서 사라지지만 그때까지 돌아야 한다)

**Interfaces:**
- Consumes: Task 2 의 `ApiBoard\Error\DomainError`
- Produces: `AttachmentService::download(Acl $acl, int $postId, int $index, ?string $password): array` — `['path' => string, 'name' => string, 'mime' => string]`

- [ ] **Step 1: 반환을 서술자로 바꿔 기존 테스트를 깨뜨린다**

`src/Service/AttachmentService.php` 에서 `use ApiBoard\Http\FileResponse;` 를 지우고, `download()` 를 다음으로 바꾼다.

```php
    /**
     * 파일을 실제로 내보내는 일은 Web 계층이 한다. 서비스는 무엇을 어떤 이름으로
     * 보낼지만 정한다.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public function download(Acl $acl, int $postId, int $index, ?string $password): array
    {
        $loaded = $this->posts->loadForRead($acl, $postId, $password);
        $files = $loaded['post']['attachments'];

        if (!isset($files[$index])) {
            throw DomainError::notFound('첨부를 찾을 수 없습니다.');
        }

        $file = $files[$index];
        $path = (string) ($file['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw DomainError::notFound('첨부 파일이 서버에 없습니다.');
        }

        return [
            'path' => $path,
            'name' => (string) ($file['name'] ?? 'download'),
            'mime' => (string) ($file['mime'] ?? 'application/octet-stream'),
        ];
    }
```

- [ ] **Step 2: 실패를 확인한다**

Run: `vendor/bin/phpunit tests/Api/AttachmentApiTest.php`
Expected: FAIL — `src/Routes.php` 의 다운로드 라우트가 `ResponseInterface` 를 기대하는데 배열을 받는다. `testAttachmentSurvivesPostCreationAndIsDownloadable` 과 `testDownloadOfSecretPostIsDeniedToStrangers` 가 깨진다.

- [ ] **Step 3: 호출부를 고친다**

```bash
grep -n 'download' src/Routes.php
```

찾은 자리에서 `return $app->attachments()->download(...);` 를 다음으로 바꾼다.

```php
                $file = $app->attachments()->download($acl, $id, $index, $password);

                return new FileResponse($file['path'], $file['name'], $file['mime']);
```

`src/Routes.php` 상단에 `use ApiBoard\Http\FileResponse;` 를 추가한다.

- [ ] **Step 4: 전체 테스트가 통과하는지 확인한다**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 5: 커밋**

```bash
git add -A
git commit -m "refactor: 첨부 다운로드가 FileResponse 대신 서술자를 돌려준다"
```

---

### Task 4: JSON API 표면을 걷어내고 Slim 프론트 컨트롤러를 세운다

이 태스크가 끝나면 JSON API 는 사라지고 `/health` 한 화면이 Slim + Twig 위에서 뜬다.

**Files:**
- Delete: `src/Http/` (전체), `src/Routes.php`, `src/Auth/TokenIssuer.php`, `src/Auth/TokenVerifier.php`, `src/Service/AuthService.php`, `public/admin.php`, `public/docs.php`, `docs/openapi.yaml`, `tests/Api/`, `tests/Http/`, `tests/Docs/`, `tests/Auth/TokenTest.php`, `tests/Support/ApiTestCase.php`
- Modify: `src/App.php`
- Create: `src/Web/Kernel.php`, `src/Web/Routes.php`, `src/Web/Middleware/ErrorPageMiddleware.php`
- Create: `templates/layout.html.twig`, `templates/error.html.twig`, `templates/health.html.twig`
- Create: `public/index.php` (전면 재작성), `public/.htaccess` (교체)
- Create: `tests/Support/WebTestCase.php`, `tests/Web/HealthTest.php`
- Create: `storage/cache/.gitkeep`

**Interfaces:**
- Consumes: Task 2 의 `DomainError`, Task 3 의 서술자 반환
- Produces:
  - `ApiBoard\App::guestAcl(): ApiBoard\Auth\Acl`
  - `ApiBoard\Web\Kernel::create(App $app, string $templateDir, ?string $cacheDir, string $basePath): Slim\App`
  - `ApiBoard\Web\Routes::register(Slim\App $slim, App $app): void`
  - `ApiBoard\Tests\Support\WebTestCase::makeApp(array $dbConfig): App`, `::get(App $app, string $path, array $query = []): Psr\Http\Message\ResponseInterface`, `::body(ResponseInterface $r): string`, `::adminAcl(): Acl`

- [ ] **Step 1: 실패하는 테스트를 쓴다**

Create `tests/Support/WebTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Support;

use ApiBoard\App;
use ApiBoard\Auth\Acl;
use ApiBoard\Auth\Identity;
use ApiBoard\Db\Schema;
use ApiBoard\Web\Kernel;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class WebTestCase extends DatabaseTestCase
{
    protected function makeApp(array $dbConfig): App
    {
        $app = new App([
            'db'   => $dbConfig,
            'auth' => ['secret' => 'web-test-secret-that-is-long-enough'],
            'uploads' => [
                'dir'         => sys_get_temp_dir() . '/apiboard-test-uploads',
                'max_bytes'   => 1024 * 1024,
                'allowed_ext' => ['txt', 'png', 'pdf'],
            ],
            'log'   => ['file' => null],
            'debug' => true,
        ]);

        $schema = new Schema($app->db());
        $schema->drop();
        $schema->create();

        return $app;
    }

    /** 게시판·글을 만들 때 쓴다. 1단계에는 로그인이 없으므로 화면은 항상 게스트다. */
    protected function adminAcl(): Acl
    {
        return new Acl(Identity::user('1', '관리자', true));
    }

    protected function get(App $app, string $path, array $query = []): ResponseInterface
    {
        $uri = $path . ($query === [] ? '' : '?' . http_build_query($query));
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);

        return Kernel::create($app, dirname(__DIR__, 2) . '/templates', null, '')->handle($request);
    }

    protected function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }
}
```

Create `tests/Web/HealthTest.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;

final class HealthTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testHealthPageRendersDialect(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $response = $this->get($app, '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString($app->db()->dialect()->name(), $this->body($response));
    }

    /** @dataProvider connectionProvider */
    public function testUnknownPathRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $response = $this->get($app, '/없는경로');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('찾을 수 없', $this->body($response));
    }
}
```

`$app->db()->dialect()->name()` 이 실제로 존재하는지 먼저 확인한다.

```bash
grep -n 'public function' src/Db/Connection.php src/Db/Dialect/DialectInterface.php
```

이름이 다르면(예: `driver()`) 테스트를 그 이름에 맞춘다. 없으면 방언 이름 대신 `'게시판'` 같은 레이아웃 고정 문구를 검사하도록 바꾼다.

- [ ] **Step 2: 실패를 확인한다**

Run: `vendor/bin/phpunit tests/Web/HealthTest.php`
Expected: FAIL — `Class "ApiBoard\Web\Kernel" not found`

- [ ] **Step 3: 옛 표면을 지운다**

```bash
git rm -r --quiet src/Http src/Routes.php src/Auth/TokenIssuer.php src/Auth/TokenVerifier.php \
  src/Service/AuthService.php public/admin.php public/docs.php docs/openapi.yaml \
  tests/Api tests/Http tests/Docs tests/Auth/TokenTest.php tests/Support/ApiTestCase.php
```

`public/admin.php` 도 함께 지운다. 이 파일은 fetch 로 JSON API 를 부르는 정적 화면이라, API 가 사라지면 모든 동작이 실패하는 784줄이 남는다. 고장난 화면을 남겨 두는 것이 지우는 것보다 나쁘다. 살릴 가치가 있는 테마 토큰(밝음/어둠 CSS 변수)은 Step 7 의 `templates/layout.html.twig` 로 이미 옮겨진다. 관리자 화면은 5단계에서 서버 렌더링으로 새로 만든다.

`tests/Support/ApiTestCase.php` 를 참조하는 것은 `tests/Api/` 뿐이므로 함께 지워도 남는 테스트에 영향이 없다.

- [ ] **Step 4: App 에서 사라진 것들을 떼어낸다**

`src/App.php` 에서 다음을 지운다.

- `use ApiBoard\Auth\TokenIssuer;`, `use ApiBoard\Auth\TokenVerifier;`, `use ApiBoard\Http\Request;`, `use ApiBoard\Http\Router;`, `use ApiBoard\Service\AuthService;`
- `private $auth = null;` 프로퍼티
- `tokenIssuer()`, `auth()`, `aclFor()`, `router()` 메서드

그리고 `use ApiBoard\Auth\Identity;` 를 추가한 뒤 다음 메서드를 넣는다.

```php
    /**
     * 1단계에는 로그인이 없다. 2단계에서 SessionGuard 가 이 자리를 대신한다.
     */
    public function guestAcl(): Acl
    {
        return new Acl(Identity::guest());
    }
```

`attachments()` 가 쓰는 `$this->config('auth.secret', '')` 는 그대로 둔다. JWT 는 사라졌지만 이 값은 첨부 서술자 서명에 계속 쓰인다.

- [ ] **Step 5: 오류 미들웨어를 만든다**

Create `src/Web/Middleware/ErrorPageMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Web\Middleware;

use ApiBoard\Error\DomainError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Throwable;

/**
 * 스택의 가장 바깥이다. 도메인 오류는 그대로 화면으로 옮기고, 그 밖의 예외는
 * 500 으로 뭉갠 뒤 원문을 로그에만 남긴다. 로그에 남기지 않으면 SQL 원문 같은
 * 유일한 단서가 아무 데도 남지 않고 사라진다.
 */
final class ErrorPageMiddleware implements MiddlewareInterface
{
    /** @var Twig */
    private $twig;

    /** @var bool */
    private $debug;

    /** @var string|null */
    private $logFile;

    public function __construct(Twig $twig, bool $debug, ?string $logFile)
    {
        $this->twig = $twig;
        $this->debug = $debug;
        $this->logFile = $logFile === '' ? null : $logFile;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpNotFoundException $e) {
            return $this->render(404, '찾을 수 없습니다.', '요청하신 주소에 해당하는 것이 없습니다.');
        } catch (DomainError $e) {
            if ($e->code() === 'INTERNAL') {
                $this->log($e);

                return $this->render(500, '오류가 발생했습니다.', $this->safeMessage($e));
            }

            return $this->render($e->status(), $this->titleFor($e->status()), $e->getMessage());
        } catch (Throwable $e) {
            $this->log($e);

            return $this->render(500, '오류가 발생했습니다.', $this->safeMessage($e));
        }
    }

    private function titleFor(int $status): string
    {
        switch ($status) {
            case 401:
                return '로그인이 필요합니다.';
            case 403:
                return '권한이 없습니다.';
            case 404:
                return '찾을 수 없습니다.';
            default:
                return '요청을 처리할 수 없습니다.';
        }
    }

    private function safeMessage(Throwable $e): string
    {
        return $this->debug
            ? get_class($e) . ': ' . $e->getMessage()
            : '잠시 후 다시 시도해 주세요. 문제가 계속되면 관리자에게 알려 주세요.';
    }

    private function log(Throwable $e): void
    {
        if ($this->logFile === null) {
            return;
        }

        @error_log(
            '[' . gmdate('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL,
            3,
            $this->logFile
        );
    }

    private function render(int $status, string $title, string $message): ResponseInterface
    {
        $response = (new Response())->withStatus($status);

        return $this->twig->render($response, 'error.html.twig', [
            'title'   => $title,
            'message' => $message,
        ]);
    }
}
```

- [ ] **Step 6: 라우트 등록부와 커널을 만든다**

Create `src/Web/Routes.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Web;

use ApiBoard\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Slim\Views\Twig;

final class Routes
{
    public static function register(SlimApp $slim, App $app): void
    {
        $slim->get('/health', static function (
            ServerRequestInterface $request,
            ResponseInterface $response
        ) use ($app): ResponseInterface {
            return Twig::fromRequest($request)->render($response, 'health.html.twig', [
                'dialect' => $app->db()->dialect()->name(),
            ]);
        });
    }
}
```

Step 1 에서 확인한 실제 메서드 이름에 맞춘다.

Create `src/Web/Kernel.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Web;

use ApiBoard\App;
use ApiBoard\Web\Middleware\ErrorPageMiddleware;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

/**
 * Slim 앱을 조립한다. 미들웨어는 나중에 add 한 것이 바깥이므로
 * 오류 미들웨어를 마지막에 넣어 전부를 감싸게 한다.
 */
final class Kernel
{
    public static function create(App $app, string $templateDir, ?string $cacheDir, string $basePath): SlimApp
    {
        $slim = AppFactory::create();
        $slim->setBasePath($basePath);

        $twig = Twig::create($templateDir, [
            'cache'            => $cacheDir === null ? false : $cacheDir,
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);

        $slim->add(TwigMiddleware::create($slim, $twig));
        $slim->addRoutingMiddleware();
        $slim->add(new ErrorPageMiddleware(
            $twig,
            (bool) $app->config('debug', false),
            $app->config('log.file') === null ? null : (string) $app->config('log.file')
        ));

        Routes::register($slim, $app);

        return $slim;
    }
}
```

- [ ] **Step 7: 템플릿 셋을 만든다**

Create `templates/layout.html.twig`:

```twig
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{% block title %}게시판{% endblock %}</title>
<style>
  :root {
    color-scheme: light;
    --bg: #ffffff; --fg: #1a1a1a; --panel: #ffffff; --line: #dddddd;
    --muted: #666666; --danger: #b00020; --link: #0b57d0;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      color-scheme: dark;
      --bg: #16181c; --fg: #e6e8ea; --panel: #1c1f24; --line: #2c3038;
      --muted: #9aa1ab; --danger: #ff7b8a; --link: #7cb0ff;
    }
  }
  * { box-sizing: border-box; }
  body { margin: 0; padding: 1.5rem 1rem; background: var(--bg); color: var(--fg);
         font: 15px/1.7 system-ui, -apple-system, "Segoe UI", "Noto Sans KR", sans-serif; }
  main { max-width: 56rem; margin: 0 auto; }
  a { color: var(--link); }
  h1 { font-size: 1.4rem; margin: 0 0 1rem; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: .6rem .5rem; border-bottom: 1px solid var(--line); text-align: left; }
  th { color: var(--muted); font-weight: 600; font-size: .9rem; }
  .muted { color: var(--muted); font-size: .9rem; }
  .empty { padding: 3rem 0; text-align: center; color: var(--muted); }
</style>
</head>
<body>
<main>
{% block body %}{% endblock %}
</main>
</body>
</html>
```

Create `templates/error.html.twig`:

```twig
{% extends "layout.html.twig" %}
{% block title %}{{ title }}{% endblock %}
{% block body %}
  <h1>{{ title }}</h1>
  <p class="muted">{{ message }}</p>
  <p><a href="{{ url_for('boards.index') }}">처음으로</a></p>
{% endblock %}
```

`boards.index` 라우트는 Task 5 에서 생긴다. 그 전까지 오류 화면이 죽지 않도록, 지금은 위 줄을 다음으로 쓴다.

```twig
  <p><a href="{{ base_path() }}/">처음으로</a></p>
```

Create `templates/health.html.twig`:

```twig
{% extends "layout.html.twig" %}
{% block title %}상태{% endblock %}
{% block body %}
  <h1>상태</h1>
  <p>데이터베이스에 <strong>{{ dialect }}</strong> 로 연결되어 있습니다.</p>
  <p class="muted">이 화면은 연결만 확인합니다. 테이블이 있는지는 보지 않습니다.</p>
{% endblock %}
```

- [ ] **Step 8: 프론트 컨트롤러를 다시 쓴다**

Create `public/index.php`:

```php
<?php

declare(strict_types=1);

use ApiBoard\App;
use ApiBoard\Web\Kernel;

ini_set('display_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

$configFile = __DIR__ . '/../config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><p>설치가 필요합니다. install.php 를 실행하세요.</p>';
    exit;
}

/** @var array $config */
$config = require $configFile;

// mod_rewrite 가 있으면 SCRIPT_NAME 이 REQUEST_URI 에 나타나지 않는다.
// 없으면 /index.php/b/free 형태로 들어오므로 그만큼을 기준 경로로 잘라낸다.
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$basePath = strpos($requestUri, $scriptName) === 0
    ? $scriptName
    : rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

$cacheDir = __DIR__ . '/../storage/cache/twig';
if (!empty($config['debug'])) {
    $cacheDir = null;
}

Kernel::create(new App($config), __DIR__ . '/../templates', $cacheDir, $basePath)->run();
```

Replace `public/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>
```

```bash
mkdir -p storage/cache && touch storage/cache/.gitkeep
```

`.gitignore` 에 `storage/cache/twig/` 를 추가한다.

- [ ] **Step 9: 테스트가 통과하는지 확인한다**

Run: `vendor/bin/phpunit`
Expected: PASS. `tests/Web/HealthTest.php` 가 초록이고, 남은 테스트(`tests/Db`, `tests/Repository`, `tests/Service` 계열, `tests/Auth/AclTest.php`, `tests/Validation`, `tests/Comment`, `tests/Support/JsonTest.php`, `tests/Install`, `tests/Error`)도 전부 초록이어야 한다.

- [ ] **Step 10: 커밋**

```bash
git add -A
git commit -m "feat: JSON API 를 걷어내고 Slim + Twig 프론트 컨트롤러를 세운다"
```

---

### Task 5: 게시판 목록 화면

**Files:**
- Create: `src/Web/Controller/BoardController.php`
- Create: `templates/boards/index.html.twig`
- Create: `tests/Web/BoardListTest.php`
- Modify: `src/Web/Routes.php`, `templates/error.html.twig`

**Interfaces:**
- Consumes: `App::guestAcl()`, `App::boardService()`, `BoardService::listBoards(Acl $acl): array`
- Produces: 라우트 이름 `boards.index` (`GET /`). 템플릿에 넘기는 변수는 `boards` 하나이며, 각 원소는 `BoardService::present()` 의 결과다: `id`, `board_key`, `name`, `description`, `categories`, `perm_read`, `perm_write`, `perm_comment`, `use_secret`, `use_file`, `use_category`, `per_page`, `sort_order`, `created_at`. (`managers` 는 관리 권한이 있을 때만 들어오므로 템플릿에서 참조하지 않는다.)

- [ ] **Step 1: 실패하는 테스트를 쓴다**

Create `tests/Web/BoardListTest.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;

final class BoardListTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testReadableBoardsAreListed(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'free',
            'name'      => '자유게시판',
        ]);

        $response = $this->get($app, '/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('자유게시판', $this->body($response));
    }

    /** @dataProvider connectionProvider */
    public function testUnreadableBoardIsHidden(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'secret',
            'name'      => '관리자전용',
            'perm_read' => 'admin',
        ]);

        $body = $this->body($this->get($app, '/'));

        self::assertStringNotContainsString('관리자전용', $body);
    }

    /** @dataProvider connectionProvider */
    public function testEmptyStateIsShown(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        self::assertStringContainsString('게시판이 없습니다', $this->body($this->get($app, '/')));
    }
}
```

- [ ] **Step 2: 실패를 확인한다**

Run: `vendor/bin/phpunit tests/Web/BoardListTest.php`
Expected: FAIL — `/` 라우트가 없어 404 가 돌아온다.

- [ ] **Step 3: 컨트롤러를 만든다**

Create `src/Web/Controller/BoardController.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Web\Controller;

use ApiBoard\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class BoardController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $boards = $this->app->boardService()->listBoards($this->app->guestAcl());

        return Twig::fromRequest($request)->render($response, 'boards/index.html.twig', [
            'boards' => $boards,
        ]);
    }
}
```

- [ ] **Step 4: 라우트를 등록한다**

`src/Web/Routes.php` 의 `register()` 안에 다음을 추가하고, `use ApiBoard\Web\Controller\BoardController;` 를 상단에 넣는다.

```php
        $slim->get('/', [new BoardController($app), 'index'])->setName('boards.index');
```

- [ ] **Step 5: 템플릿을 만든다**

Create `templates/boards/index.html.twig`:

```twig
{% extends "layout.html.twig" %}
{% block title %}게시판{% endblock %}
{% block body %}
  <h1>게시판</h1>
  {% if boards is empty %}
    <p class="empty">게시판이 없습니다.</p>
  {% else %}
    <table>
      <thead><tr><th>이름</th><th>설명</th></tr></thead>
      <tbody>
      {% for board in boards %}
        <tr>
          <td><a href="{{ url_for('posts.index', {'key': board.board_key}) }}">{{ board.name }}</a></td>
          <td class="muted">{{ board.description }}</td>
        </tr>
      {% endfor %}
      </tbody>
    </table>
  {% endif %}
{% endblock %}
```

`posts.index` 는 Task 6 에서 생긴다. 이 태스크에서는 링크를 다음으로 두고, Task 6 에서 위 형태로 바꾼다.

```twig
          <td><a href="{{ base_path() }}/b/{{ board.board_key }}">{{ board.name }}</a></td>
```

`templates/error.html.twig` 의 "처음으로" 링크를 `{{ url_for('boards.index') }}` 로 되돌린다.

- [ ] **Step 6: 테스트가 통과하는지 확인한다**

Run: `vendor/bin/phpunit tests/Web/`
Expected: PASS

- [ ] **Step 7: 커밋**

```bash
git add -A
git commit -m "feat: 게시판 목록을 서버 렌더링으로 보여준다"
```

---

### Task 6: 글 목록 화면

**Files:**
- Create: `src/Web/Controller/PostController.php`
- Create: `templates/posts/index.html.twig`
- Create: `tests/Web/PostListTest.php`
- Modify: `src/Web/Routes.php`, `templates/boards/index.html.twig`

**Interfaces:**
- Consumes: `PostService::listPosts(Acl $acl, string $boardKey, array $query): array`, `BoardService::get(Acl $acl, string $key): array`
- Produces: 라우트 이름 `posts.index` (`GET /b/{key}`). 템플릿 변수:
  - `board` — `BoardService::present()` 결과
  - `list` — `listPosts()` 결과: `data`(요약 배열), `notices`(요약 배열), `page`, `per_page`, `total`, `total_pages`
  - `query` — `{'q': string|null, 'category': string|null}`
  - 요약 원소의 키: `id`, `category`, `title`, `author_id`, `author_name`, `is_notice`, `is_secret`, `view_count`, `comment_count`, `file_count`, `deleted`, `created_at`

- [ ] **Step 1: 실패하는 테스트를 쓴다**

Create `tests/Web/PostListTest.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\App;
use ApiBoard\Tests\Support\WebTestCase;

final class PostListTest extends WebTestCase
{
    private function seed(App $app, int $count): void
    {
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판', 'per_page' => 2]);

        for ($i = 1; $i <= $count; $i++) {
            $app->postService()->create($acl, 'free', [
                'title'   => '글 제목 ' . $i,
                'content' => '내용 ' . $i,
            ]);
        }
    }

    /** @dataProvider connectionProvider */
    public function testPostsAreListed(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 1);

        $response = $this->get($app, '/b/free');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('글 제목 1', $this->body($response));
        self::assertStringContainsString('자유게시판', $this->body($response));
    }

    /** @dataProvider connectionProvider */
    public function testSecondPageShowsOlderPosts(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 3);

        $body = $this->body($this->get($app, '/b/free', ['page' => '2']));

        self::assertStringContainsString('글 제목 1', $body);
        self::assertStringNotContainsString('글 제목 3', $body);
    }

    /** @dataProvider connectionProvider */
    public function testSearchFiltersPosts(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $this->seed($app, 3);

        $body = $this->body($this->get($app, '/b/free', ['q' => '제목 2']));

        self::assertStringContainsString('글 제목 2', $body);
        self::assertStringNotContainsString('글 제목 1', $body);
    }

    /** @dataProvider connectionProvider */
    public function testUnknownBoardRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $response = $this->get($app, '/b/없는게시판');

        self::assertSame(404, $response->getStatusCode());
    }

    /** @dataProvider connectionProvider */
    public function testUnreadableBoardRendersUnauthorizedPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->boardService()->create($this->adminAcl(), [
            'board_key' => 'secret',
            'name'      => '관리자전용',
            'perm_read' => 'admin',
        ]);

        $response = $this->get($app, '/b/secret');

        self::assertSame(401, $response->getStatusCode());
    }
}
```

게스트에게 401 이 가는 것은 `Acl::deny()` 의 기존 규칙이다 — 401 은 "로그인하면 될 수도 있다", 403 은 "로그인해도 안 된다".

- [ ] **Step 2: 실패를 확인한다**

Run: `vendor/bin/phpunit tests/Web/PostListTest.php`
Expected: FAIL — 모든 케이스가 404

- [ ] **Step 3: 컨트롤러를 만든다**

Create `src/Web/Controller/PostController.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Web\Controller;

use ApiBoard\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class PostController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $key = (string) $args['key'];
        $query = $request->getQueryParams();

        $board = $this->app->boardService()->get($acl, $key);
        $list = $this->app->postService()->listPosts($acl, $key, $query);

        return Twig::fromRequest($request)->render($response, 'posts/index.html.twig', [
            'board' => $board,
            'list'  => $list,
            'query' => [
                'q'        => isset($query['q']) ? (string) $query['q'] : null,
                'category' => isset($query['category']) ? (string) $query['category'] : null,
            ],
        ]);
    }
}
```

- [ ] **Step 4: 라우트를 등록한다**

`src/Web/Routes.php` 에 추가한다. `use ApiBoard\Web\Controller\PostController;` 도 넣는다.

```php
        $slim->get('/b/{key}', [new PostController($app), 'index'])->setName('posts.index');
```

- [ ] **Step 5: 템플릿을 만든다**

Create `templates/posts/index.html.twig`:

```twig
{% extends "layout.html.twig" %}
{% block title %}{{ board.name }}{% endblock %}
{% block body %}
  <p class="muted"><a href="{{ url_for('boards.index') }}">게시판</a></p>
  <h1>{{ board.name }}</h1>
  {% if board.description %}<p class="muted">{{ board.description }}</p>{% endif %}

  <form method="get" action="{{ url_for('posts.index', {'key': board.board_key}) }}">
    {% if board.use_category and board.categories is not empty %}
      <select name="category">
        <option value="">전체 분류</option>
        {% for name in board.categories %}
          <option value="{{ name }}"{% if query.category == name %} selected{% endif %}>{{ name }}</option>
        {% endfor %}
      </select>
    {% endif %}
    <input type="search" name="q" value="{{ query.q }}" placeholder="검색어">
    <button type="submit">검색</button>
  </form>

  {% if list.data is empty and list.notices is empty %}
    <p class="empty">글이 없습니다.</p>
  {% else %}
    <table>
      <thead>
        <tr>
          {% if board.use_category %}<th>분류</th>{% endif %}
          <th>제목</th><th>글쓴이</th><th>날짜</th><th>조회</th>
        </tr>
      </thead>
      <tbody>
      {% for post in list.notices|merge(list.data) %}
        <tr>
          {% if board.use_category %}<td class="muted">{{ post.category }}</td>{% endif %}
          <td>
            {% if post.is_notice %}<strong>공지</strong> {% endif %}
            {% if post.is_secret %}🔒 {% endif %}
            <a href="{{ url_for('posts.show', {'id': post.id}) }}">{{ post.title }}</a>
            {% if post.comment_count > 0 %}<span class="muted">[{{ post.comment_count }}]</span>{% endif %}
            {% if post.file_count > 0 %}<span class="muted">📎</span>{% endif %}
          </td>
          <td class="muted">{{ post.author_name }}</td>
          <td class="muted">{{ post.created_at }}</td>
          <td class="muted">{{ post.view_count }}</td>
        </tr>
      {% endfor %}
      </tbody>
    </table>

    {% if list.total_pages > 1 %}
      <p>
        {% for p in 1..list.total_pages %}
          {% if p == list.page %}
            <strong>{{ p }}</strong>
          {% else %}
            <a href="{{ url_for('posts.index', {'key': board.board_key}) }}?page={{ p }}{% if query.q %}&amp;q={{ query.q|url_encode }}{% endif %}{% if query.category %}&amp;category={{ query.category|url_encode }}{% endif %}">{{ p }}</a>
          {% endif %}
        {% endfor %}
      </p>
    {% endif %}
  {% endif %}
{% endblock %}
```

`posts.show` 는 Task 7 에서 생긴다. 이 태스크에서는 제목 링크를 `{{ base_path() }}/p/{{ post.id }}` 로 두고 Task 7 에서 위 형태로 바꾼다.

`templates/boards/index.html.twig` 의 링크를 `{{ url_for('posts.index', {'key': board.board_key}) }}` 로 되돌린다.

- [ ] **Step 6: 테스트가 통과하는지 확인한다**

Run: `vendor/bin/phpunit tests/Web/`
Expected: PASS

- [ ] **Step 7: 커밋**

```bash
git add -A
git commit -m "feat: 글 목록을 검색·분류·페이징과 함께 보여준다"
```

---

### Task 7: 글 보기 화면과 첨부 다운로드

**Files:**
- Modify: `src/Web/Controller/PostController.php`
- Create: `src/Web/Controller/FileController.php`
- Create: `templates/posts/show.html.twig`, `templates/posts/_comments.html.twig`
- Create: `tests/Web/PostShowTest.php`
- Modify: `src/Web/Routes.php`, `templates/posts/index.html.twig`

**Interfaces:**
- Consumes: `PostService::get(Acl $acl, int $id, ?string $password): array`, `CommentService::listComments(Acl $acl, int $postId, ?string $password): array`, `AttachmentService::download(...): array{path,name,mime}` (Task 3)
- Produces:
  - 라우트 `posts.show` (`GET /p/{id}`), `files.download` (`GET /p/{id}/files/{index}`)
  - `posts/show.html.twig` 변수: `post`(요약 키 + `content`, `updated_at`, `attachments`), `comments`(트리)
  - 댓글 노드 키: `id`, `parent_id`, `depth`, `content`, `author_id`, `author_name`, `is_secret`, `deleted`, `created_at`, `updated_at`, `children`

- [ ] **Step 1: 실패하는 테스트를 쓴다**

Create `tests/Web/PostShowTest.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;

final class PostShowTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testPostAndCommentTreeAreRendered(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key'    => 'free',
            'name'         => '자유게시판',
            'perm_comment' => 'guest',
        ]);
        $post = $app->postService()->create($acl, 'free', ['title' => '제목', 'content' => '본문입니다']);

        $parent = $app->commentService()->create($acl, $post['id'], ['content' => '부모 댓글']);
        $app->commentService()->create($acl, $post['id'], [
            'content'   => '자식 댓글',
            'parent_id' => $parent['id'],
        ]);

        $response = $this->get($app, '/p/' . $post['id']);
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('본문입니다', $body);
        self::assertStringContainsString('부모 댓글', $body);
        self::assertStringContainsString('자식 댓글', $body);
    }

    /** @dataProvider connectionProvider */
    public function testViewCountIncreases(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $post = $app->postService()->create($acl, 'free', ['title' => '제목', 'content' => '본문']);

        $this->get($app, '/p/' . $post['id']);

        self::assertSame(1, (int) $app->posts()->find((int) $post['id'])['view_count']);
    }

    /** @dataProvider connectionProvider */
    public function testHtmlInContentIsEscaped(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $post = $app->postService()->create($acl, 'free', [
            'title'   => '제목',
            'content' => '<script>alert(1)</script>',
        ]);

        $body = $this->body($this->get($app, '/p/' . $post['id']));

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    /** @dataProvider connectionProvider */
    public function testMissingPostRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);

        self::assertSame(404, $this->get($app, '/p/99999')->getStatusCode());
    }
}
```

세 번째 테스트가 이 단계에서 Twig 를 쓰는 이유 그 자체다. 자동 이스케이프가 꺼지면 이 테스트가 깨진다.

- [ ] **Step 2: 실패를 확인한다**

Run: `vendor/bin/phpunit tests/Web/PostShowTest.php`
Expected: FAIL — 모든 케이스가 404

- [ ] **Step 3: 컨트롤러에 show 를 추가한다**

`src/Web/Controller/PostController.php` 에 다음 메서드를 추가한다.

```php
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $id = (int) $args['id'];

        // 1단계에는 비밀글 비밀번호를 받을 폼이 없다. 비밀글은 403 으로 막힌다.
        $post = $this->app->postService()->get($acl, $id, null);
        $comments = $this->app->commentService()->listComments($acl, $id, null);

        return Twig::fromRequest($request)->render($response, 'posts/show.html.twig', [
            'post'     => $post,
            'comments' => $comments,
        ]);
    }
```

- [ ] **Step 4: 첨부 다운로드 컨트롤러를 만든다**

Create `src/Web/Controller/FileController.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Web\Controller;

use ApiBoard\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

final class FileController
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $file = $this->app->attachments()->download(
            $this->app->guestAcl(),
            (int) $args['id'],
            (int) $args['index'],
            null
        );

        // 한글 파일명 때문에 RFC 5987 형식을 함께 준다. ASCII 폴백이 없으면
        // 오래된 클라이언트가 이름을 통째로 버린다.
        $ascii = (string) preg_replace('/[^\x20-\x7e]/', '_', $file['name']);
        $disposition = 'attachment; filename="' . str_replace('"', '', $ascii) . '";'
            . " filename*=UTF-8''" . rawurlencode($file['name']);

        $handle = fopen($file['path'], 'rb');

        return $response
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Length', (string) filesize($file['path']))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', $disposition)
            ->withBody(new Stream($handle));
    }
}
```

- [ ] **Step 5: 라우트를 등록한다**

`src/Web/Routes.php` 에 추가한다. `use ApiBoard\Web\Controller\FileController;` 도 넣는다.

```php
        $slim->get('/p/{id:[0-9]+}', [new PostController($app), 'show'])->setName('posts.show');
        $slim->get('/p/{id:[0-9]+}/files/{index:[0-9]+}', [new FileController($app), 'download'])
            ->setName('files.download');
```

- [ ] **Step 6: 템플릿을 만든다**

Create `templates/posts/_comments.html.twig`:

```twig
<ul>
{% for comment in nodes %}
  <li>
    <p class="muted">
      {{ comment.deleted ? '' : comment.author_name }}
      <span>{{ comment.created_at }}</span>
      {% if comment.is_secret %}🔒{% endif %}
    </p>
    <p>{{ comment.content|nl2br }}</p>
    {% if comment.children is not empty %}
      {% include 'posts/_comments.html.twig' with {'nodes': comment.children} only %}
    {% endif %}
  </li>
{% endfor %}
</ul>
```

Create `templates/posts/show.html.twig`:

```twig
{% extends "layout.html.twig" %}
{% block title %}{{ post.title }}{% endblock %}
{% block body %}
  <h1>{{ post.title }}</h1>
  <p class="muted">
    {{ post.author_name }} · {{ post.created_at }} · 조회 {{ post.view_count }}
  </p>

  <article>{{ post.content|nl2br }}</article>

  {% if post.attachments is not empty %}
    <h2>첨부</h2>
    <ul>
    {% for file in post.attachments %}
      <li>
        <a href="{{ url_for('files.download', {'id': post.id, 'index': file.index}) }}">{{ file.name }}</a>
        <span class="muted">{{ file.size }} bytes</span>
      </li>
    {% endfor %}
    </ul>
  {% endif %}

  <h2>댓글 {{ post.comment_count }}</h2>
  {% if comments is empty %}
    <p class="empty">댓글이 없습니다.</p>
  {% else %}
    {% include 'posts/_comments.html.twig' with {'nodes': comments} only %}
  {% endif %}
{% endblock %}
```

`templates/posts/index.html.twig` 의 제목 링크를 `{{ url_for('posts.show', {'id': post.id}) }}` 로 되돌린다.

- [ ] **Step 7: 첨부 다운로드 테스트를 쓴다**

Task 3 에서 미뤄 둔 테스트다. 업로드 헬퍼는 지워진 `tests/Api/AttachmentApiTest.php` 에 있던 것과 같은 모양이다.

Create `tests/Web/AttachmentDownloadTest.php`:

```php
<?php

declare(strict_types=1);

namespace ApiBoard\Tests\Web;

use ApiBoard\Tests\Support\WebTestCase;

final class AttachmentDownloadTest extends WebTestCase
{
    /** @dataProvider connectionProvider */
    public function testAttachmentIsDownloadable(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, [
            'board_key' => 'free',
            'name'      => '자유게시판',
            'use_file'  => true,
        ]);

        $descriptor = $app->attachments()->upload($acl, 'free', $this->fakeUpload('메모.txt', '안녕하세요'));
        $post = $app->postService()->create($acl, 'free', [
            'title'       => '글',
            'content'     => '본문',
            'attachments' => [$descriptor],
        ]);

        $response = $this->get($app, '/p/' . $post['id'] . '/files/0');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('안녕하세요', $this->body($response));
        self::assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));
        // 한글 파일명은 RFC 5987 형식으로만 온전히 전달된다.
        self::assertStringContainsString("filename*=UTF-8''" . rawurlencode('메모.txt'), $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    /** @dataProvider connectionProvider */
    public function testUnknownIndexRendersNotFoundPage(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = $this->adminAcl();
        $app->boardService()->create($acl, ['board_key' => 'free', 'name' => '자유게시판']);
        $post = $app->postService()->create($acl, 'free', ['title' => '글', 'content' => '본문']);

        self::assertSame(404, $this->get($app, '/p/' . $post['id'] . '/files/7')->getStatusCode());
    }

    protected function tearDown(): void
    {
        $dir = sys_get_temp_dir() . '/apiboard-test-uploads';
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function fakeUpload(string $name, string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sbtest');
        file_put_contents($tmp, $contents);

        return [
            'name'     => $name,
            'type'     => 'text/plain',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($contents),
        ];
    }
}
```

- [ ] **Step 8: 테스트가 통과하는지 확인한다**

Run: `vendor/bin/phpunit`
Expected: PASS (전체)

- [ ] **Step 9: 세 DB 에서 모두 통과하는지 확인한다**

MySQL 과 PostgreSQL 접속 정보를 넣고 다시 돌린다.

```bash
TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=board_test' \
TEST_MYSQL_USER=board TEST_MYSQL_PASS=비밀번호 \
TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=5432;dbname=board_test' \
TEST_PGSQL_USER=board TEST_PGSQL_PASS=비밀번호 \
vendor/bin/phpunit
```

Expected: 첫 줄에 `테스트 대상 DB: sqlite, mysql, pgsql` 이 뜨고 `<-- 일부 DB 를 건너뜁니다` 가 없어야 한다. 전부 PASS.

- [ ] **Step 10: 실제 서버에서 두 경로가 모두 도는지 확인한다**

```bash
php -S 127.0.0.1:8080 -t public
```

브라우저에서 확인한다.

- `http://127.0.0.1:8080/` → 게시판 목록
- `http://127.0.0.1:8080/index.php/` → 같은 화면 (mod_rewrite 없는 환경의 폴백)

내장 서버는 `.htaccess` 를 읽지 않으므로 첫 주소가 404 면 `public/index.php` 의 `$basePath` 계산이 잘못된 것이다.

- [ ] **Step 11: 커밋**

```bash
git add -A
git commit -m "feat: 글 보기 화면과 첨부 다운로드를 서버 렌더링으로 옮긴다"
```

---

## 1단계 완료 조건

- 로그인 없이 게시판 목록 → 글 목록 → 글 보기 → 첨부 다운로드가 동작한다.
- `src/Http/` 와 `src/Routes.php` 가 없고, JSON 을 돌려주는 경로가 하나도 남아 있지 않다.
- 도메인 계층 어디에서도 `ApiBoard\Http` 를 참조하지 않는다.
- 같은 테스트 스위트가 SQLite·MySQL·PostgreSQL 에서 모두 통과한다.
- `mod_rewrite` 가 있는 경우와 없는 경우 모두 화면이 뜬다.

## 다음 단계

2단계(`users` + 세션 + 로컬 가입/로그인)는 이 계획이 끝난 뒤 별도 계획으로 쓴다. 그때 `App::guestAcl()` 이 `SessionGuard` 로 대체된다.
