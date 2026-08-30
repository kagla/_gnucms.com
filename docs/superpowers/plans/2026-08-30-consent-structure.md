# 약관·동의 구조 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 약관 여부를 토글 하나로 정하고, 필수·선택과 차례를 "어디에 붙였나"(`consent_uses`)로 옮기며, 동의 기록을 비회원 제출까지 받도록 넓힌다.

**Architecture:** 약관은 `contents` 표에 그대로 두고 `is_consent` 플래그로 가른다. 붙임은 `consent_uses(scope, content_id, required, sort_order)` 새 표가 갖는다. 동의 기록은 `user_consents` 를 `consents_given(subject_type, subject_id, scope, …, agreed_ip, agreed_ua)` 로 넓혀 옮긴다. 관리 화면은 내용 관리(약관 제외)와 약관 관리(약관 전부 + 붙임 + 동의 현황)로 나눈다.

**Tech Stack:** PHP 8.4, Slim 4, Twig 3.28(`strict_variables => true`), PDO(SQLite·MySQL·PostgreSQL), PHPUnit 10.5

**설계 문서:** `docs/superpowers/specs/2026-08-30-consent-design.md`

## Global Constraints

- **런타임 의존성 0.** 새 Composer 패키지를 넣지 않는다. 서버에서 `composer`·`npm`·컴파일을 쓸 수 없다.
- **DB 무관.** SQLite·MySQL·PostgreSQL 에서 같은 코드가 돌아야 한다. DB 별 SQL 은 `Connection::dialect()` 를 거친다. 표·컬럼 이름은 언제나 `$this->db->q('name')` 로 감싼다.
- **마이그레이션은 멱등.** `migrate*()` 는 여러 번 돌아도 안전해야 한다. 컬럼 존재 확인은 `try { $this->db->selectOne('SELECT col FROM t LIMIT 1'); } catch (DomainError $e) { ALTER … }` 꼴로 한다.
- **Twig `strict_variables`.** 템플릿에서 쓰는 변수는 컨트롤러가 반드시 넘긴다. 없을 수 있으면 `is defined` 로 감싼다.
- **테마 20벌.** `templates/` 아래 테마 디렉터리가 20개다. 공통 조각은 `templates/default/` 에만 두고 나머지는 폴백으로 받는다. 테마별 파일을 고쳐야 하면 스크립트로 일괄 처리하고 `lint.php` 로 전 테마를 검사한다.
- **주석은 한국어.** 기존 코드의 말투를 따른다. "왜" 를 적고 "무엇" 은 코드가 말하게 둔다.
- **커밋 메시지는 한국어**, 끝에 `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.
- **옛 칸은 이번 판에서 지우지 않는다.** `contents.consent_key`·`consent_order`·`consent_required` 와 `user_consents` 표는 남겨 둔다. 되돌릴 길을 한 판 동안 남긴다.
- **라이브 DB 는 마이그레이션 전에 백업**한다: `cp storage/board.sqlite storage/board-before-consent-uses-20260830.sqlite`

## 검사 도구

세 가지를 매 작업 끝에 돌린다.

```bash
cd /home/kagla/gnucms.com
./vendor/bin/phpunit                                    # 279개
php /tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad/lint.php   # 전 테마 twig 컴파일
php /tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad/smoke.php  # 41개 경로 렌더
```

`lint.php`·`smoke.php` 가 없으면 Task 0 에서 다시 만든다.
스크래치패드 경로는 `/tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad` 다.

---

### Task 0: 검사 도구 확인

**Files:**
- Create(없으면): `/tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad/lint.php`, `/tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad/smoke.php`

- [ ] **Step 1: 도구가 있는지 본다**

```bash
SP=/tmp/claude-1001/-home-kagla-gnucms-com/c8416273-8669-48d0-9787-bf01028dc218/scratchpad
ls "$SP"/lint.php "$SP"/smoke.php 2>/dev/null
```

있으면 Step 3 으로 건너뛴다.

- [ ] **Step 2: 없으면 lint.php 를 만든다**

```php
<?php
// 모든 테마의 twig 를 컴파일해 문법 오류를 잡는다. 렌더까지는 안 한다.
declare(strict_types=1);
require '/home/kagla/gnucms.com/vendor/autoload.php';
$root = '/home/kagla/gnucms.com/templates';
$fail = 0;
foreach (scandir($root) as $theme) {
    if ($theme[0] === '.') { continue; }
    $loader = new \Twig\Loader\FilesystemLoader([$root . '/' . $theme, $root . '/default']);
    $twig = new \Twig\Environment($loader, ['strict_variables' => true, 'cache' => false]);
    $twig->addExtension(new class extends \Twig\Extension\AbstractExtension {
        public function getFunctions(): array {
            return [new \Twig\TwigFunction('url_for', fn() => '/'),
                    new \Twig\TwigFunction('theme_asset', fn() => '/'),
                    new \Twig\TwigFunction('current_path', fn() => '/')];
        }
        public function getFilters(): array {
            return [new \Twig\TwigFilter('cms_html', fn($v) => $v)];
        }
    });
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $theme));
    foreach ($it as $file) {
        if (!$file->isFile() || substr($file->getFilename(), -5) !== '.twig') { continue; }
        $name = ltrim(str_replace($root . '/' . $theme, '', $file->getPathname()), '/');
        try { $twig->load($name); }
        catch (\Twig\Error\SyntaxError $e) { $fail++; echo "SYNTAX $theme/$name: ", $e->getMessage(), "\n"; }
        catch (\Throwable $e) { /* 런타임 오류는 smoke 가 잡는다 */ }
    }
}
echo $fail === 0 ? "ALL OK\n" : "FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 3: 돌려서 통과를 확인한다**

Run: `php "$SP"/lint.php`
Expected: `ALL OK`

- [ ] **Step 4: 라이브 DB 를 백업한다**

```bash
cd /home/kagla/gnucms.com
cp storage/board.sqlite storage/board-before-consent-uses-20260830.sqlite
ls -la storage/board-before-consent-uses-20260830.sqlite
```

Expected: 파일이 만들어진다.

---

### Task 1: 스키마 — `is_consent`, `consent_uses`, `consents_given`

**Files:**
- Modify: `src/Db/Schema.php`
- Test: `tests/Db/SchemaTest.php` (없으면 만든다)

**Interfaces:**
- Produces: `contents.is_consent`(SMALLINT), 표 `consent_uses`, 표 `consents_given`. `Schema::VERSION = '9'`.

- [ ] **Step 1: 실패하는 테스트를 쓴다**

`tests/Db/SchemaTest.php` 에 더한다. 파일이 없으면 다음으로 만든다.

```php
<?php

declare(strict_types=1);

namespace GnuCms\Tests\Db;

use GnuCms\Db\Schema;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SchemaTest extends WebTestCase
{
    /** 옛 판에서 올라와도 새 칸과 새 표가 빠짐없이 생기고, 기존 동의가 그대로 옮겨진다. */
    #[DataProvider('connectionProvider')]
    public function testMigrationAddsConsentUsesAndMovesRecords(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $db = $app->db();

        // 옛 모양으로 되돌린다: 붙임 표를 지우고 판 도장을 낮춘다.
        $db->execute('DROP TABLE IF EXISTS ' . $db->q('consent_uses'));
        $db->execute('DROP TABLE IF EXISTS ' . $db->q('consents_given'));
        $db->execute('UPDATE ' . $db->q('site_settings')
            . ' SET setting_value = ? WHERE setting_key = ?', ['0', 'schema_version']);

        $id = $app->cms()->createPage([
            'slug' => 'terms', 'title' => '이용약관', 'content' => '본문',
            'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0,
            'consent_key' => 'terms', 'consent_order' => 10, 'consent_required' => 1,
        ]);
        $userId = $app->users()->create('a@example.com', password_hash('x', PASSWORD_DEFAULT), 'A', false);
        $db->insert('user_consents', [
            'user_id' => $userId, 'consent_type' => 'terms', 'content_id' => $id,
            'content_updated_at' => '2026-01-01 00:00:00', 'agreed' => 1,
            'agreed_at' => '2026-01-01 00:00:00',
        ]);

        (new Schema($db))->ensureCurrent();

        $page = $db->selectOne('SELECT is_consent FROM ' . $db->q('contents') . ' WHERE id = ?', [$id]);
        self::assertSame(1, (int) $page['is_consent']);

        $use = $db->selectOne('SELECT * FROM ' . $db->q('consent_uses')
            . ' WHERE scope = ? AND content_id = ?', ['signup', $id]);
        self::assertNotNull($use);
        self::assertSame(1, (int) $use['required']);
        self::assertSame(10, (int) $use['sort_order']);

        $given = $db->selectOne('SELECT * FROM ' . $db->q('consents_given')
            . ' WHERE subject_type = ? AND subject_id = ?', ['user', $userId]);
        self::assertNotNull($given);
        self::assertSame('signup', $given['scope']);
        self::assertSame('terms', $given['consent_type']);
        self::assertSame(1, (int) $given['agreed']);
    }
}
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter SchemaTest`
Expected: FAIL — `no such table: consent_uses` 또는 `no such column: is_consent`

- [ ] **Step 3: `Schema::TABLES` 에 새 표를 더한다**

`src/Db/Schema.php:15-18`

```php
    public const TABLES = [
        'boards', 'posts', 'comments', 'users', 'user_tokens', 'user_identities', 'site_state',
        'site_settings', 'mail_settings', 'contents', 'user_consents', 'consent_uses',
        'consents_given',
    ];
```

- [ ] **Step 4: 판 번호를 올린다**

`src/Db/Schema.php` 의 `public const VERSION = '8';` 을 `'9'` 로 바꾼다.

- [ ] **Step 5: `contents` DDL 에 `is_consent` 를 더한다**

`contentStatements()` 안, `consent_required INTEGER NOT NULL DEFAULT 1` 아래에 더한다.

```
                consent_required INTEGER      NOT NULL DEFAULT 1,
                is_consent       SMALLINT     NOT NULL DEFAULT 0
```

그리고 같은 배열의 인덱스 목록에 더한다.

```php
            'CREATE INDEX ix_contents_is_consent ON contents (is_consent)',
```

- [ ] **Step 6: 새 표 DDL 을 쓴다**

`consentStatements()` 바로 아래에 메서드를 더한다.

```php
    /** 약관을 어디에 붙였는지. 필수·선택과 차례는 약관이 아니라 이 붙임이 갖는다. */
    private function consentUseStatements(): array
    {
        return [
            'CREATE TABLE consent_uses (
                id          {AUTO_PK},
                scope       VARCHAR(40)  NOT NULL,
                content_id  BIGINT       NOT NULL,
                required    SMALLINT     NOT NULL DEFAULT 1,
                sort_order  INTEGER      NOT NULL DEFAULT 0,
                created_at  {DATETIME}   NOT NULL
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_consent_uses ON consent_uses (scope, content_id)',
            'CREATE INDEX ix_consent_uses_content ON consent_uses (content_id)',
        ];
    }

    /**
     * 동의 기록. 회원뿐 아니라 비회원 제출 건에도 달 수 있게 subject 로 받는다.
     * agreed_ip / agreed_ua 는 '동의를 받았다'를 입증하기 위한 증적이라
     * 동의 대상이 아니다. 대신 처리방침에 고지하고 보관기간을 지킨다.
     */
    private function consentsGivenStatements(): array
    {
        return [
            'CREATE TABLE consents_given (
                id                  {AUTO_PK},
                subject_type        VARCHAR(20)  NOT NULL,
                subject_id          BIGINT       NOT NULL,
                scope               VARCHAR(40)  NOT NULL,
                content_id          BIGINT       NOT NULL,
                consent_type        VARCHAR(100) NOT NULL,
                content_updated_at  {DATETIME}   NOT NULL,
                agreed              SMALLINT     NOT NULL DEFAULT 1,
                agreed_at           {DATETIME}   NOT NULL,
                agreed_ip           VARCHAR(45)  NULL,
                agreed_ua           VARCHAR(255) NULL
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_consents_given ON consents_given'
                . ' (subject_type, subject_id, scope, content_id)',
            'CREATE INDEX ix_consents_given_content ON consents_given (content_id)',
        ];
    }
```

- [ ] **Step 7: `statements()` 에 새 DDL 을 잇는다**

`statements()` 의 `array_merge(...)` 에 두 메서드를 더한다.

```php
            $this->contentStatements(), $this->consentStatements(),
            $this->consentUseStatements(), $this->consentsGivenStatements(),
            $this->notificationStatements());
```

- [ ] **Step 8: 마이그레이션을 쓴다**

`migrateCms()` 의 맨 끝(`agreed` 칸을 더하는 블록 다음)에 이어 붙인다.

```php
        // 약관 여부를 토글 한 칸으로 옮긴다. 옛 칸은 되돌릴 길로 한 판 동안 남긴다.
        try {
            $this->db->selectOne('SELECT is_consent FROM ' . $this->db->q('contents') . ' LIMIT 1');
        } catch (DomainError $e) {
            $this->db->execute('ALTER TABLE ' . $this->db->q('contents')
                . ' ADD COLUMN is_consent SMALLINT NOT NULL DEFAULT 0');
            $this->db->execute('UPDATE ' . $this->db->q('contents')
                . ' SET is_consent = 1 WHERE consent_key IS NOT NULL');
            try {
                $this->db->execute('CREATE INDEX ix_contents_is_consent ON '
                    . $this->db->q('contents') . ' (is_consent)');
            } catch (DomainError $e) {
                // 이미 있으면 그대로 둔다
            }
        }

        // 붙임 표. 옛 필수·차례를 회원가입 자리의 붙임으로 옮긴다.
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('consent_uses'));
        } catch (DomainError $e) {
            foreach ($this->consentUseStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
            $rows = $this->db->select('SELECT id, consent_required, consent_order FROM '
                . $this->db->q('contents') . ' WHERE is_consent = 1');
            foreach ($rows as $row) {
                $this->db->insert('consent_uses', [
                    'scope' => 'signup',
                    'content_id' => (int) $row['id'],
                    'required' => (int) $row['consent_required'],
                    'sort_order' => (int) $row['consent_order'],
                    'created_at' => Clock::now(),
                ]);
            }
        }

        // 동의 기록을 회원 밖으로 넓힌 표로 옮긴다. 옛 표는 남겨 둔다.
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('consents_given'));
        } catch (DomainError $e) {
            foreach ($this->consentsGivenStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
            $rows = $this->db->select('SELECT * FROM ' . $this->db->q('user_consents'));
            foreach ($rows as $row) {
                $this->db->insert('consents_given', [
                    'subject_type' => 'user',
                    'subject_id' => (int) $row['user_id'],
                    'scope' => 'signup',
                    'content_id' => (int) $row['content_id'],
                    'consent_type' => (string) $row['consent_type'],
                    'content_updated_at' => (string) $row['content_updated_at'],
                    'agreed' => (int) ($row['agreed'] ?? 1),
                    'agreed_at' => (string) $row['agreed_at'],
                    'agreed_ip' => null,
                    'agreed_ua' => null,
                ]);
            }
        }
```

`Clock` 이 `use` 되어 있지 않으면 파일 위쪽에 `use GnuCms\Support\Clock;` 를 더한다.

- [ ] **Step 9: 테스트가 통과하는지 본다**

Run: `./vendor/bin/phpunit --filter SchemaTest`
Expected: PASS

- [ ] **Step 10: 전체 테스트**

Run: `./vendor/bin/phpunit`
Expected: OK (280 tests 이상)

- [ ] **Step 11: 커밋**

```bash
git add src/Db/Schema.php tests/Db/SchemaTest.php
git commit -m "feat: 약관 여부 칸과 붙임·동의 기록 표를 만든다

약관 여부를 is_consent 토글 한 칸으로 옮기고, 필수·선택과 차례를
consent_uses 붙임 표로 뺀다. 같은 약관이 자리마다 다른 규칙을 가질 수 있다.
동의 기록은 비회원 제출까지 받도록 consents_given 으로 넓힌다.
옛 칸과 옛 표는 되돌릴 길로 한 판 동안 남긴다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: `ConsentUseRepository` — 붙임 읽고 쓰기

**Files:**
- Create: `src/Cms/ConsentUseRepository.php`
- Modify: `src/App.php`
- Test: `tests/Cms/ConsentUseRepositoryTest.php`

**Interfaces:**
- Consumes: 표 `consent_uses` (Task 1)
- Produces:
  - `listForScope(string $scope, bool $publishedOnly = false): array` — `contents.*` + `required`, `sort_order` 를 합쳐 차례대로
  - `listForContent(int $contentId): array` — 그 약관이 붙은 자리들
  - `attach(string $scope, int $contentId, bool $required, int $sortOrder): void` — 있으면 덮어쓴다
  - `detach(string $scope, int $contentId): void`
  - `detachContent(int $contentId): void`
  - `App::consentUses(): ConsentUseRepository`

- [ ] **Step 1: 실패하는 테스트를 쓴다**

`tests/Cms/ConsentUseRepositoryTest.php`

```php
<?php

declare(strict_types=1);

namespace GnuCms\Tests\Cms;

use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsentUseRepositoryTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testAttachDetachAndListForScope(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $uses = $app->consentUses();

        $terms = $app->cms()->createPage([
            'slug' => 'terms', 'title' => '이용약관', 'content' => '본문', 'seo_description' => null,
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'is_consent' => 1,
        ]);
        $draft = $app->cms()->createPage([
            'slug' => 'location', 'title' => '위치기반 약관', 'content' => '본문', 'seo_description' => null,
            'status' => 'draft', 'show_in_menu' => 0, 'sort_order' => 0, 'is_consent' => 1,
        ]);

        $uses->attach('signup', $terms, true, 10);
        $uses->attach('signup', $draft, false, 20);

        $all = $uses->listForScope('signup');
        self::assertCount(2, $all);
        self::assertSame('이용약관', $all[0]['title']);
        self::assertSame(1, (int) $all[0]['required']);
        self::assertSame(10, (int) $all[0]['sort_order']);

        // 공개된 것만 걸러 읽을 수 있다. 초안은 가입 화면에 붙으면 안 된다.
        self::assertCount(1, $uses->listForScope('signup', true));

        // 같은 자리에 다시 붙이면 덮어쓴다. 줄이 늘지 않는다.
        $uses->attach('signup', $terms, false, 5);
        $again = $uses->listForScope('signup');
        self::assertCount(2, $again);
        self::assertSame(0, (int) $again[0]['required']);
        self::assertSame(5, (int) $again[0]['sort_order']);

        // 같은 약관을 다른 자리에 다른 규칙으로 붙일 수 있다.
        $uses->attach('form:event', $terms, true, 1);
        self::assertCount(2, $uses->listForContent($terms));

        $uses->detach('signup', $terms);
        self::assertCount(1, $uses->listForScope('signup'));
        self::assertCount(1, $uses->listForContent($terms));
    }
}
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter ConsentUseRepositoryTest`
Expected: FAIL — `Call to undefined method GnuCms\App::consentUses()`

- [ ] **Step 3: 저장소를 만든다**

`src/Cms/ConsentUseRepository.php`

```php
<?php

declare(strict_types=1);

namespace GnuCms\Cms;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

/**
 * 약관을 어느 자리에 붙였는지 다룬다. 같은 약관이 회원가입에선 필수, 신청 폼에선
 * 선택일 수 있으므로 필수·선택과 차례는 약관이 아니라 이 붙임이 갖는다.
 */
final class ConsentUseRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /** 한 자리에 붙은 약관을 차례대로. 내용 칸과 붙임 칸을 합쳐 준다. */
    public function listForScope(string $scope, bool $publishedOnly = false): array
    {
        $sql = 'SELECT c.*, u.required, u.sort_order, u.scope'
            . ' FROM ' . $this->db->q('consent_uses') . ' u'
            . ' JOIN ' . $this->db->q('contents') . ' c ON c.id = u.content_id'
            . ' WHERE u.scope = ? AND c.deleted_at IS NULL';
        if ($publishedOnly) {
            $sql .= " AND c.status = 'published'";
        }

        return $this->db->select($sql . ' ORDER BY u.sort_order ASC, c.id ASC', [$scope]);
    }

    /** 이 약관이 붙어 있는 자리들. 약관 관리 목록이 쓴다. */
    public function listForContent(int $contentId): array
    {
        return $this->db->select(
            'SELECT * FROM ' . $this->db->q('consent_uses')
            . ' WHERE content_id = ? ORDER BY scope ASC',
            [$contentId]
        );
    }

    /** 붙인다. 이미 같은 자리에 붙어 있으면 규칙만 덮어쓴다. */
    public function attach(string $scope, int $contentId, bool $required, int $sortOrder): void
    {
        $row = $this->db->selectOne(
            'SELECT id FROM ' . $this->db->q('consent_uses') . ' WHERE scope = ? AND content_id = ?',
            [$scope, $contentId]
        );
        if ($row === null) {
            $this->db->insert('consent_uses', [
                'scope' => $scope,
                'content_id' => $contentId,
                'required' => $required ? 1 : 0,
                'sort_order' => $sortOrder,
                'created_at' => Clock::now(),
            ]);
            return;
        }
        $this->db->execute(
            'UPDATE ' . $this->db->q('consent_uses') . ' SET required = ?, sort_order = ? WHERE id = ?',
            [$required ? 1 : 0, $sortOrder, (int) $row['id']]
        );
    }

    public function detach(string $scope, int $contentId): void
    {
        $this->db->delete('consent_uses', 'scope = :scope AND content_id = :content_id',
            ['scope' => $scope, 'content_id' => $contentId]);
    }

    /** 약관 표시를 뗄 때 붙임을 모두 걷는다. */
    public function detachContent(int $contentId): void
    {
        $this->db->delete('consent_uses', 'content_id = :content_id', ['content_id' => $contentId]);
    }
}
```

- [ ] **Step 4: `App` 에 붙인다**

`src/App.php` — `use GnuCms\Cms\ConsentUseRepository;` 를 더하고, 속성과 접근자를 더한다.

```php
    private ?ConsentUseRepository $consentUses = null;
```

```php
    public function consentUses(): ConsentUseRepository
    {
        if ($this->consentUses === null) {
            $this->consentUses = new ConsentUseRepository($this->db());
        }
        return $this->consentUses;
    }
```

- [ ] **Step 5: 테스트가 통과하는지 본다**

Run: `./vendor/bin/phpunit --filter ConsentUseRepositoryTest`
Expected: PASS

- [ ] **Step 6: 커밋**

```bash
git add src/Cms/ConsentUseRepository.php src/App.php tests/Cms/ConsentUseRepositoryTest.php
git commit -m "feat: 약관을 자리에 붙이는 저장소를 만든다

같은 약관을 회원가입에는 필수로, 다른 자리에는 선택으로 붙일 수 있다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: `ConsentRepository` 를 `consents_given` 으로 옮긴다

**Files:**
- Create: `src/Account/ConsentTrace.php`
- Modify: `src/Account/ConsentRepository.php`
- Test: `tests/Account/ConsentRepositoryTest.php`

**Interfaces:**
- Consumes: 표 `consents_given` (Task 1)
- Produces:
  - `ConsentTrace::__construct(?string $ip, ?string $userAgent)`, 공개 속성 `$ip`, `$userAgent`
  - `record(string $subjectType, int $subjectId, string $scope, array $content, bool $agreed, ?ConsentTrace $trace = null): void` — `$content` 는 `contents` 한 줄(`id`, `slug`, `updated_at` 을 쓴다)
  - `forSubject(string $subjectType, int $subjectId): array`
  - `forSubjectWithDocument(string $subjectType, int $subjectId): array` — `content_title`, `content_slug`, `content_current_updated_at` 를 함께
  - `forContent(int $contentId): array` — 동의 현황 화면용. 회원 이메일·표시이름을 함께
  - `countsForContent(int $contentId): array` — `['agreed' => int, 'declined' => int]`
  - `forUser(int $userId): array`, `forUserWithDocument(int $userId): array` — 옛 호출부를 위한 얇은 껍데기

- [ ] **Step 1: 실패하는 테스트를 쓴다**

`tests/Account/ConsentRepositoryTest.php`

```php
<?php

declare(strict_types=1);

namespace GnuCms\Tests\Account;

use GnuCms\Account\ConsentTrace;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsentRepositoryTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testRecordsForUserAndSubmissionWithTrace(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $consents = $app->consents();
        $id = $app->cms()->createPage([
            'slug' => 'terms', 'title' => '이용약관', 'content' => '본문', 'seo_description' => null,
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'is_consent' => 1,
        ]);
        $doc = $app->cms()->findPageById($id);
        $trace = new ConsentTrace('203.0.113.7', 'Mozilla/5.0 테스트');

        $consents->record('user', 42, 'signup', $doc, true, $trace);
        $consents->record('submission', 7, 'form:event', $doc, false, $trace);

        $user = $consents->forSubject('user', 42);
        self::assertCount(1, $user);
        self::assertSame('signup', $user[0]['scope']);
        self::assertSame('terms', $user[0]['consent_type']);
        self::assertSame(1, (int) $user[0]['agreed']);
        self::assertSame('203.0.113.7', $user[0]['agreed_ip']);

        $submission = $consents->forSubject('submission', 7);
        self::assertCount(1, $submission);
        self::assertSame(0, (int) $submission[0]['agreed']);

        // 다시 받으면 덮어쓴다. 나중에 동의를 켜고 끄는 화면이 이 길을 쓴다.
        $consents->record('submission', 7, 'form:event', $doc, true, $trace);
        self::assertCount(1, $consents->forSubject('submission', 7));
        self::assertSame(1, (int) $consents->forSubject('submission', 7)[0]['agreed']);

        // 같은 사람이라도 자리가 다르면 따로 쌓인다.
        $consents->record('user', 42, 'form:event', $doc, true, $trace);
        self::assertCount(2, $consents->forSubject('user', 42));

        $counts = $consents->countsForContent($id);
        self::assertSame(3, $counts['agreed']);
        self::assertSame(0, $counts['declined']);

        self::assertCount(3, $consents->forContent($id));
    }

    #[DataProvider('connectionProvider')]
    public function testForSubjectWithDocumentMarksChangedDocument(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $id = $app->cms()->createPage([
            'slug' => 'terms', 'title' => '이용약관', 'content' => '본문', 'seo_description' => null,
            'status' => 'published', 'show_in_menu' => 0, 'sort_order' => 0, 'is_consent' => 1,
        ]);
        $doc = $app->cms()->findPageById($id);
        $app->consents()->record('user', 1, 'signup', $doc, true, null);

        $rows = $app->consents()->forSubjectWithDocument('user', 1);
        self::assertCount(1, $rows);
        self::assertSame('이용약관', $rows[0]['content_title']);
        self::assertSame('terms', $rows[0]['content_slug']);
        self::assertSame($doc['updated_at'], $rows[0]['content_current_updated_at']);
    }
}
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter ConsentRepositoryTest`
Expected: FAIL — `Class "GnuCms\Account\ConsentTrace" not found`

- [ ] **Step 3: `ConsentTrace` 를 만든다**

`src/Account/ConsentTrace.php`

```php
<?php

declare(strict_types=1);

namespace GnuCms\Account;

/**
 * 동의를 받았다는 사실을 입증하기 위한 증적. 이것 자체는 동의 대상이 아니라
 * 정당한 이익으로 수집한다. 대신 개인정보 처리방침에 고지하고 보관기간을 지킨다.
 * 마스킹하지 않는다. 마스킹하면 증적으로서의 값이 없어져 수집만 남는다.
 */
final class ConsentTrace
{
    public ?string $ip;

    public ?string $userAgent;

    public function __construct(?string $ip, ?string $userAgent)
    {
        $ip = $ip === null ? null : trim($ip);
        $this->ip = ($ip === null || $ip === '') ? null : mb_substr($ip, 0, 45);
        $userAgent = $userAgent === null ? null : trim($userAgent);
        $this->userAgent = ($userAgent === null || $userAgent === '')
            ? null : mb_substr($userAgent, 0, 255);
    }
}
```

- [ ] **Step 4: `ConsentRepository` 를 다시 쓴다**

`src/Account/ConsentRepository.php` 를 통째로 바꾼다.

```php
<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

final class ConsentRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * 보여 준 동의 항목은 동의하지 않았어도 줄을 남긴다. 줄이 없으면 '안 했다' 인지
     * '묻지도 않았다' 인지 나중에 가릴 수 없다. 선택 동의는 이 구분이 특히 중요하다.
     *
     * @param string $subjectType user | submission
     * @param array  $content     contents 한 줄
     */
    public function record(
        string $subjectType,
        int $subjectId,
        string $scope,
        array $content,
        bool $agreed = true,
        ?ConsentTrace $trace = null
    ): void {
        $contentId = (int) $content['id'];
        $row = $this->db->selectOne(
            'SELECT id FROM ' . $this->db->q('consents_given')
            . ' WHERE subject_type = ? AND subject_id = ? AND scope = ? AND content_id = ?',
            [$subjectType, $subjectId, $scope, $contentId]
        );
        $values = [
            'consent_type' => (string) $content['slug'],
            'content_updated_at' => (string) $content['updated_at'],
            'agreed' => $agreed ? 1 : 0,
            'agreed_at' => Clock::now(),
            'agreed_ip' => $trace === null ? null : $trace->ip,
            'agreed_ua' => $trace === null ? null : $trace->userAgent,
        ];
        if ($row === null) {
            $this->db->insert('consents_given', $values + [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'scope' => $scope,
                'content_id' => $contentId,
            ]);
            return;
        }
        $this->db->execute(
            'UPDATE ' . $this->db->q('consents_given')
            . ' SET consent_type = ?, content_updated_at = ?, agreed = ?, agreed_at = ?,'
            . ' agreed_ip = ?, agreed_ua = ? WHERE id = ?',
            [$values['consent_type'], $values['content_updated_at'], $values['agreed'],
             $values['agreed_at'], $values['agreed_ip'], $values['agreed_ua'], (int) $row['id']]
        );
    }

    public function forSubject(string $subjectType, int $subjectId): array
    {
        return $this->db->select(
            'SELECT * FROM ' . $this->db->q('consents_given')
            . ' WHERE subject_type = ? AND subject_id = ? ORDER BY id ASC',
            [$subjectType, $subjectId]
        );
    }

    /**
     * 관리 화면에 보여 줄 동의 내역. 그때 본 문서가 무엇이었는지, 그 뒤로 문서가
     * 바뀌었는지까지 같이 읽는다. 문서가 지워졌으면 제목 칸이 비어 온다.
     */
    public function forSubjectWithDocument(string $subjectType, int $subjectId): array
    {
        return $this->db->select(
            'SELECT g.consent_type, g.scope, g.agreed, g.agreed_at, g.content_updated_at,'
            . ' g.agreed_ip, c.title AS content_title, c.slug AS content_slug,'
            . ' c.updated_at AS content_current_updated_at'
            . ' FROM ' . $this->db->q('consents_given') . ' g'
            . ' LEFT JOIN ' . $this->db->q('contents') . ' c ON c.id = g.content_id'
            . ' WHERE g.subject_type = ? AND g.subject_id = ? ORDER BY g.id ASC',
            [$subjectType, $subjectId]
        );
    }

    /** 한 약관에 누가 동의했는지. 동의 현황 화면이 쓴다. */
    public function forContent(int $contentId): array
    {
        return $this->db->select(
            'SELECT g.*, u.email AS user_email, u.display_name AS user_display_name'
            . ' FROM ' . $this->db->q('consents_given') . ' g'
            . ' LEFT JOIN ' . $this->db->q('users') . " u"
            . "   ON g.subject_type = 'user' AND u.id = g.subject_id"
            . ' WHERE g.content_id = ? ORDER BY g.id DESC',
            [$contentId]
        );
    }

    /** @return array{agreed:int,declined:int} */
    public function countsForContent(int $contentId): array
    {
        $rows = $this->db->select(
            'SELECT agreed, COUNT(*) AS c FROM ' . $this->db->q('consents_given')
            . ' WHERE content_id = ? GROUP BY agreed',
            [$contentId]
        );
        $counts = ['agreed' => 0, 'declined' => 0];
        foreach ($rows as $row) {
            $counts[((int) $row['agreed']) === 1 ? 'agreed' : 'declined'] = (int) $row['c'];
        }
        return $counts;
    }

    public function forUser(int $userId): array
    {
        return $this->forSubject('user', $userId);
    }

    public function forUserWithDocument(int $userId): array
    {
        return $this->forSubjectWithDocument('user', $userId);
    }
}
```

- [ ] **Step 5: 테스트가 통과하는지 본다**

Run: `./vendor/bin/phpunit --filter ConsentRepositoryTest`
Expected: PASS

- [ ] **Step 6: 옛 호출부를 고친다**

`record()` 시그니처가 바뀌었으므로 부르는 곳 둘을 고친다. 자세한 내용은 Task 5 에서 하고, 여기서는 컴파일이 되게만 맞춘다.

```bash
grep -rn "consents->record(" src/
```

`src/Account/AccountService.php` 와 `src/Account/LinkingService.php` 각 한 줄을 임시로 바꾼다.

```php
$this->consents->record('user', $id, 'signup', $doc, $agreed, null);
```

- [ ] **Step 7: 전체 테스트**

Run: `./vendor/bin/phpunit`
Expected: OK

- [ ] **Step 8: 커밋**

```bash
git add src/Account/ConsentTrace.php src/Account/ConsentRepository.php \
        src/Account/AccountService.php src/Account/LinkingService.php \
        tests/Account/ConsentRepositoryTest.php
git commit -m "feat: 동의 기록을 회원 밖으로 넓히고 증적을 남긴다

subject_type 으로 회원과 비회원 제출 건을 함께 받는다. 동의를 받았다는 사실을
입증할 증적으로 IP 와 브라우저를 남긴다. 이 증적 자체는 동의 대상이 아니다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: `CmsService` — 토글, 약관 목록, 삭제 가드

**Files:**
- Modify: `src/Cms/CmsService.php`, `src/Cms/CmsRepository.php`
- Test: `tests/Cms/CmsServiceConsentTest.php`

**Interfaces:**
- Consumes: `ConsentUseRepository` (Task 2)
- Produces:
  - `CmsRepository::listPages(bool $consentOnly = null): array` — `null` 이면 전부, `false` 면 약관 제외, `true` 면 약관만
  - `CmsService::contents(Acl $acl): array` — 약관을 뺀 목록
  - `CmsService::consentPages(Acl $acl, string $scope = 'signup'): array` — 약관만. 그 자리의 붙임(`use`, 없으면 `null`)과 동의 수(`counts`)를 합쳐 준다
  - `CmsService::consentDocuments(string $scope = 'signup'): array` — 공개된, 그 자리에 붙은 약관을 차례대로
  - `validatePage()` 가 `is_consent` 를 받는다

- [ ] **Step 1: 실패하는 테스트를 쓴다**

`tests/Cms/CmsServiceConsentTest.php`

```php
<?php

declare(strict_types=1);

namespace GnuCms\Tests\Cms;

use GnuCms\Auth\Acl;
use GnuCms\Auth\Identity;
use GnuCms\Error\DomainError;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CmsServiceConsentTest extends WebTestCase
{
    #[DataProvider('connectionProvider')]
    public function testConsentPagesAreSeparatedFromContents(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = new Acl(Identity::user('1', '관리자', true));

        $app->cmsService()->createPage($acl, [
            'title' => '회사소개', 'slug' => 'company', 'content' => '<p>본문</p>',
            'status' => 'published', 'show_in_menu' => '1', 'sort_order' => '0',
            'image_key' => bin2hex(random_bytes(16)), 'is_consent' => '0',
        ]);
        $termsId = $app->cmsService()->createPage($acl, [
            'title' => '이용약관', 'slug' => 'terms', 'content' => '<p>본문</p>',
            'status' => 'published', 'show_in_menu' => '0', 'sort_order' => '0',
            'image_key' => bin2hex(random_bytes(16)), 'is_consent' => '1',
        ]);

        $contents = array_column($app->cmsService()->contents($acl), 'slug');
        self::assertContains('company', $contents);
        self::assertNotContains('terms', $contents, '약관은 내용 관리 목록에서 빠진다');

        $consents = $app->cmsService()->consentPages($acl);
        self::assertCount(1, $consents);
        self::assertSame('terms', $consents[0]['slug']);
        self::assertSame([], $consents[0]['uses'], '아직 어디에도 안 붙었다');
        self::assertNull($consents[0]['use']);
        self::assertSame(0, $consents[0]['counts']['agreed']);

        // 붙이면 가입 화면 목록에 나온다.
        $app->consentUses()->attach('signup', $termsId, true, 10);
        $signup = $app->cmsService()->consentDocuments('signup');
        self::assertCount(1, $signup);
        self::assertSame('terms', $signup[0]['slug']);
        self::assertSame(1, (int) $signup[0]['required']);
    }

    #[DataProvider('connectionProvider')]
    public function testAttachedConsentCannotBeDeleted(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $acl = new Acl(Identity::user('1', '관리자', true));
        $id = $app->cmsService()->createPage($acl, [
            'title' => '이용약관', 'slug' => 'terms', 'content' => '<p>본문</p>',
            'status' => 'published', 'show_in_menu' => '0', 'sort_order' => '0',
            'image_key' => bin2hex(random_bytes(16)), 'is_consent' => '1',
        ]);
        $app->consentUses()->attach('signup', $id, true, 10);

        try {
            $app->cmsService()->deletePage($acl, $id);
            self::fail('붙어 있는 약관은 지울 수 없어야 한다');
        } catch (DomainError $e) {
            self::assertArrayHasKey('is_consent', $e->details());
        }

        // 붙임을 걷으면 지울 수 있다.
        $app->consentUses()->detachContent($id);
        $app->cmsService()->deletePage($acl, $id);
        self::assertCount(0, $app->cmsService()->consentPages($acl));
    }
}
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter CmsServiceConsentTest`
Expected: FAIL — `Call to undefined method GnuCms\Cms\CmsService::consentPages()`

- [ ] **Step 3: `CmsRepository::listPages()` 를 가른다**

`src/Cms/CmsRepository.php:50` 근처의 `listPages()` 를 바꾼다.

```php
    /** @param bool|null $consentOnly null 이면 전부, true 면 약관만, false 면 약관 말고 */
    public function listPages(?bool $consentOnly = null): array
    {
        $sql = 'SELECT * FROM ' . $this->db->q('contents') . ' WHERE deleted_at IS NULL';
        if ($consentOnly === true) {
            $sql .= ' AND is_consent = 1';
        } elseif ($consentOnly === false) {
            $sql .= ' AND is_consent = 0';
        }

        return $this->db->select($sql . ' ORDER BY sort_order ASC, id ASC');
    }
```

기존 `listPages()` 본문의 `ORDER BY` 절이 다르면 그것을 그대로 쓴다.

- [ ] **Step 4: `CmsService` 를 고친다**

`consentDocuments()` 를 바꾼다.

```php
    /**
     * 한 자리에 붙은 동의 항목. 공개된 것만, 정한 차례대로. 개수 제한이 없다.
     * 필수·선택은 약관이 아니라 붙임이 갖는다.
     */
    public function consentDocuments(string $scope = 'signup'): array
    {
        return $this->uses->listForScope($scope, true);
    }
```

`contents()` 를 바꾼다.

```php
    /** 내용 관리 목록. 약관은 약관 관리에서 다루므로 여기서 뺀다. */
    public function contents(Acl $acl): array
    {
        $acl->assertGlobalAdmin();
        return $this->cms->listPages(false);
    }

    /**
     * 약관 관리 목록. 그 자리의 붙임과 동의 수를 합쳐 준다.
     *
     * 붙임을 여기서 골라 주는 이유는 Twig 의 {% set %} 이 for 밖으로 새지 않아
     * 템플릿 안에서 "이 약관이 이 자리에 붙었나" 를 고를 수 없기 때문이다.
     */
    public function consentPages(Acl $acl, string $scope = 'signup'): array
    {
        $acl->assertGlobalAdmin();
        $rows = [];
        foreach ($this->cms->listPages(true) as $page) {
            $id = (int) $page['id'];
            $uses = $this->uses->listForContent($id);
            $page['uses'] = $uses;
            $page['use'] = null;
            foreach ($uses as $use) {
                if ((string) $use['scope'] === $scope) {
                    $page['use'] = $use;
                    break;
                }
            }
            $page['counts'] = $this->consents->countsForContent($id);
            $rows[] = $page;
        }
        return $rows;
    }
```

`deletePage()` 의 가드를 바꾼다.

```php
    public function deletePage(Acl $acl, int $id): void
    {
        $page = $this->page($acl, $id);
        // 붙어 있는 약관을 지우면 그 자리의 가입·신청이 그때부터 막힌다.
        if ($this->uses->listForContent($id) !== []) {
            throw DomainError::validation([
                'is_consent' => '어딘가에 붙어 있는 약관은 지울 수 없습니다. 먼저 붙임을 떼어 주세요.',
            ]);
        }
        $this->cms->deletePage($id);
    }
```

`validatePage()` 의 동의 칸 블록을 바꾼다.

```php
        // 약관 여부는 폼에 있을 때만 반영한다. 그 칸이 없는 화면에서 저장해도
        // 이미 정해 둔 표시가 조용히 지워지지 않는다.
        if (array_key_exists('is_consent', $input)) {
            $data['is_consent'] = $v->bool('is_consent', false) ? 1 : 0;
        }
```

생성자에 `ConsentUseRepository` 와 `ConsentRepository` 를 받는다.

```php
    private ConsentUseRepository $uses;

    private ConsentRepository $consents;

    public function __construct(
        CmsRepository $cms,
        HtmlSanitizer $sanitizer,
        ContentImageService $images,
        ConsentUseRepository $uses,
        ConsentRepository $consents
    ) {
        $this->cms = $cms;
        $this->sanitizer = $sanitizer;
        $this->images = $images;
        $this->uses = $uses;
        $this->consents = $consents;
    }
```

파일 위쪽에 `use GnuCms\Account\ConsentRepository;` 와 `use GnuCms\Cms\ConsentUseRepository;`(같은 네임스페이스면 생략)를 더한다.

- [ ] **Step 5: `App::cmsService()` 를 고친다**

`src/App.php:392`

```php
            $this->cmsService = new CmsService(
                $this->cms(), $this->htmlSanitizer(), $this->contentImages(),
                $this->consentUses(), $this->consents()
            );
```

- [ ] **Step 6: `ensureLegalDrafts()` 를 고친다**

씨앗 약관은 `is_consent = 1` 로 만들고 회원가입 자리에 붙인다.

```php
    public function ensureLegalDrafts(Acl $acl): void
    {
        $acl->assertGlobalAdmin();
        $siteName = (string) $this->settings()['site_name'];
        $seeds = [
            ['terms', '이용약관', $siteName . ' 서비스 이용약관', $this->termsDraft($siteName), 900, 10],
            ['privacy', '개인정보 처리방침', $siteName . ' 개인정보 처리방침',
             $this->privacyDraft($siteName), 910, 20],
        ];
        foreach ($seeds as [$slug, $title, $seo, $body, $sort, $order]) {
            $page = $this->cms->findBySlug($slug);
            if ($page === null) {
                $id = $this->cms->createPage([
                    'slug' => $slug, 'title' => $title, 'seo_description' => $seo,
                    'content' => $body, 'status' => 'draft', 'show_in_menu' => 0,
                    'sort_order' => $sort, 'is_consent' => 1,
                ]);
            } else {
                $id = (int) $page['id'];
            }
            // 씨앗 둘은 회원가입에 반드시 붙는다. 없으면 가입 자체를 받지 않는다.
            $this->uses->attach('signup', $id, true, $order);
        }
    }
```

- [ ] **Step 7: 개인정보 처리방침 초안에 자동 수집 고지를 더한다**

설계 3.5 는 IP 를 동의 없이 수집하는 대신 **처리방침에 고지**하라고 정한다. 고지가 없으면
증적 수집의 근거가 무너진다. `CmsService::privacyDraft()` 가 만드는 본문에 한 절을 더한다.

```php
        . '<h2>자동으로 수집하는 정보</h2>'
        . '<p>회원가입과 각종 신청에서 동의를 받을 때, 동의를 받았다는 사실을 증명하기 위해'
        . ' 접속 IP 주소와 접속 일시, 브라우저 정보를 함께 기록합니다. 이 정보는 동의 사실'
        . ' 증명과 부정 이용 방지 목적으로만 쓰며, 다른 목적으로 이용하지 않습니다.</p>'
        . '<p>보관기간: 회원 동의 기록은 탈퇴 시 함께 파기하고, 비회원 신청 건의 동의 기록은'
        . ' 해당 신청 건의 보관기간이 지나면 파기합니다.</p>'
```

이미 있는 초안에는 소급하지 않는다. 관리자가 직접 넣도록 약관 관리 화면 설명에 적는다.

- [ ] **Step 8: 테스트가 통과하는지 본다**

Run: `./vendor/bin/phpunit --filter CmsServiceConsentTest`
Expected: PASS

- [ ] **Step 9: 전체 테스트를 돌리고 깨진 곳을 고친다**

Run: `./vendor/bin/phpunit`

`consent_key` 를 넘기던 옛 픽스처가 깨진다. `tests/Web/AuthPageTest.php`, `tests/Web/AdminPageTest.php`, `tests/Web/CmsPageTest.php` 의 `createPage([... 'consent_key' => …, 'consent_order' => …, 'consent_required' => …])` 를 `'is_consent' => 1` 로 바꾸고, 붙임이 필요한 곳에 `$app->consentUses()->attach('signup', $id, true|false, $order);` 를 더한다.

Expected: OK

- [ ] **Step 10: 커밋**

```bash
git add src/Cms/ src/App.php tests/
git commit -m "feat: 약관 목록과 내용 목록을 가른다

내용 관리에서 약관을 빼고, 약관 관리가 붙임과 동의 수를 함께 읽는다.
어딘가에 붙어 있는 약관은 지울 수 없다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: 가입 흐름 — `consent_uses` 를 읽고 증적을 남긴다

**Files:**
- Modify: `src/Account/AccountService.php`, `src/Account/LinkingService.php`, `src/Web/Controller/AuthController.php`, `src/Web/Controller/OauthController.php`
- Test: `tests/Web/AuthPageTest.php`

**Interfaces:**
- Consumes: `CmsService::consentDocuments(string $scope)` (Task 4), `ConsentTrace` (Task 3)
- Produces:
  - `AccountService::register(array $input, ?ConsentTrace $trace = null): array`
  - `LinkingService::resolve(SocialProfile $profile, ?ConsentTrace $trace = null): ?array`
  - `LinkingService::completeVerifiedEmail(SocialProfile $profile, string $email, ?ConsentTrace $trace = null): array`
  - 가입 폼의 체크박스 이름이 `agree_{content_id}` 로 바뀐다

- [ ] **Step 1: 실패하는 테스트를 쓴다**

`tests/Web/AuthPageTest.php` 의 `testOptionalConsentDoesNotBlockSignupButIsRecorded` 를 다음으로 갈음한다.

```php
    /** 선택 항목은 가입을 막지 않는다. 동의하지 않았다는 사실과 증적이 함께 남는다. */
    #[DataProvider('connectionProvider')]
    public function testOptionalConsentDoesNotBlockSignupAndTraceIsRecorded(array $dbConfig): void
    {
        $app = $this->makeApp($dbConfig);
        $app->accountService()->register([
            'email' => 'owner@example.com',
            'password' => 'owner-password-123', 'password_confirmation' => 'owner-password-123',
        ]);
        $ids = [];
        foreach ([['terms', '이용약관', true, 10], ['marketing', '마케팅 정보 수신', false, 30]] as $doc) {
            $ids[$doc[0]] = $app->cms()->createPage([
                'slug' => $doc[0], 'title' => $doc[1], 'content' => $doc[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0,
                'sort_order' => 0, 'is_consent' => 1,
            ]);
            $app->consentUses()->attach('signup', $ids[$doc[0]], $doc[2], $doc[3]);
        }

        $form = $this->body($this->get($app, '/register'));
        self::assertStringContainsString('name="agree_' . $ids['marketing'] . '"', $form);
        self::assertStringContainsString('선택', $form);

        $response = $this->post($app, '/register', [
            'csrf_token' => $_SESSION['csrf_token'], 'email' => 'member@example.com',
            'password' => 'member-password-123', 'password_confirmation' => 'member-password-123',
            'agree_' . $ids['terms'] => '1',
        ]);
        self::assertSame(200, $response->getStatusCode(), $this->body($response));
        self::assertStringContainsString('이메일을 확인해', $this->body($response));

        $member = $app->users()->findByEmail('member@example.com');
        $agreed = [];
        foreach ($app->consents()->forSubject('user', (int) $member['id']) as $row) {
            $agreed[$row['consent_type']] = (int) $row['agreed'];
            self::assertSame('signup', $row['scope']);
        }
        self::assertSame(['terms' => 1, 'marketing' => 0], $agreed);
    }
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter testOptionalConsentDoesNotBlockSignupAndTraceIsRecorded`
Expected: FAIL — 폼에 `agree_{id}` 가 없다

- [ ] **Step 3: `AccountService::register()` 를 고친다**

`src/Account/AccountService.php` — 시그니처와 동의 블록을 바꾼다.

```php
    public function register(array $input, ?ConsentTrace $trace = null): array
    {
```

동의 검증 블록:

```php
        // 첫 사람(사이트 소유자)은 약관을 만들기 전이라 동의를 받지 않는다.
        $consents = [];
        if ($this->users->countAll() > 0) {
            // 필수 두 개가 공개돼 있는지 먼저 확인한다. 없으면 가입 자체를 받지 않는다.
            $this->cms->legalDocuments();
            $consents = $this->cms->consentDocuments('signup');
            foreach ($consents as $doc) {
                // 선택 항목은 체크를 안 해도 가입을 막지 않는다. 대신 안 했다는 사실을 남긴다.
                if ((int) $doc['required'] === 1 && !$v->bool('agree_' . $doc['id'], false)) {
                    $v->fail('agree_' . $doc['id'], $doc['title'] . '에 동의해야 가입할 수 있습니다.');
                }
            }
        }
```

기록 블록:

```php
        if (!(bool) $user['is_admin']) {
            foreach ($consents as $doc) {
                $agreed = (int) $doc['required'] === 1 || $v->bool('agree_' . $doc['id'], false);
                $this->consents->record('user', $id, 'signup', $doc, $agreed, $trace);
            }
        }
```

파일 위쪽에 `use GnuCms\Account\ConsentTrace;` 가 필요하면 더한다(같은 네임스페이스면 생략).

- [ ] **Step 4: `LinkingService` 를 고친다**

`resolve()`·`completeVerifiedEmail()`·`connect()` 에 `?ConsentTrace $trace = null` 을 흘려보내고, `recordConsents()` 를 바꾼다.

```php
    /**
     * 소셜 가입은 폼이 없어 체크박스를 받을 수 없다. 로그인 화면의 소셜 단추 옆에
     * "계속하면 동의로 봅니다" 를 적고, 필수만 동의로 본다. 물어본 적 없는 선택
     * 항목을 동의로 볼 수는 없으니 안 함으로 남긴다.
     */
    private function recordConsents(array $user, ?ConsentTrace $trace): void
    {
        if ((bool) $user['is_admin']) {
            return;
        }
        foreach ($this->cms->consentDocuments('signup') as $doc) {
            $agreed = (int) $doc['required'] === 1;
            $this->consents->record('user', (int) $user['id'], 'signup', $doc, $agreed, $trace);
        }
    }
```

- [ ] **Step 5: 컨트롤러에서 증적을 만든다**

`src/Web/Controller/AuthController.php` 의 `register()` 에서:

```php
            $user = $this->app->accountService()->register($input, $this->consentTrace($request));
```

같은 클래스에 도우미를 더한다. 프록시 뒤에 있을 수 있으니 `REMOTE_ADDR` 을 쓰되, 신뢰하는 프록시가 있으면 그때 넓힌다.

```php
    /** 동의 증적. 프록시를 신뢰하지 않으므로 REMOTE_ADDR 만 쓴다. */
    private function consentTrace(ServerRequestInterface $request): ConsentTrace
    {
        $server = $request->getServerParams();
        $ip = isset($server['REMOTE_ADDR']) && is_scalar($server['REMOTE_ADDR'])
            ? (string) $server['REMOTE_ADDR'] : null;
        $ua = $request->getHeaderLine('User-Agent');

        return new ConsentTrace($ip, $ua === '' ? null : $ua);
    }
```

`use GnuCms\Account\ConsentTrace;` 를 더한다. `OauthController` 에서 `LinkingService` 를 부르는 곳에도 같은 도우미를 두고 넘긴다.

- [ ] **Step 6: 422 로 되돌아갈 때의 `values` 를 고친다**

`AuthController::register()` 의 catch 블록에서 `agree_terms`/`agree_privacy` 를 하드코딩하던 자리를 바꾼다.

```php
            $values = ['email' => $input['email'] ?? ''];
            foreach ($this->app->cmsService()->consentDocuments('signup') as $doc) {
                $values['agree_' . $doc['id']] = isset($input['agree_' . $doc['id']]);
            }
            return Twig::fromRequest($request)->render(
                $response->withStatus(422),
                'auth/register.html.twig',
                ['errors' => $e->details(), 'values' => $values, 'legal' => $this->registrationLegal()]
            );
```

- [ ] **Step 7: `LinkingServiceTest` 를 새 시그니처로 고친다**

`tests/Account/LinkingServiceTest.php` 는 `resolve()` 를 직접 부른다. 인자가 하나 늘었으므로
호출은 그대로 두어도 되지만, 동의를 확인하는 단언은 새 저장소 메서드로 바꾼다.

```php
        $rows = $app->consents()->forSubject('user', (int) $user['id']);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('signup', $row['scope']);
        }
```

픽스처의 약관 생성도 `'is_consent' => 1` 과 `$app->consentUses()->attach('signup', $id, true, 0)`
로 바꾼다.

- [ ] **Step 8: 테스트를 돌린다**

Run: `./vendor/bin/phpunit --filter "AuthPageTest|LinkingServiceTest"`
Expected: 아직 FAIL — 템플릿이 옛 칸을 쓴다. Task 6 에서 고친다.

- [ ] **Step 9: 커밋**

```bash
git add src/Account/ src/Web/Controller/AuthController.php src/Web/Controller/OauthController.php tests/
git commit -m "feat: 가입이 붙임을 읽고 동의 증적을 남긴다

동의 항목을 consent_uses 에서 읽고, 체크박스 이름을 내용 id 로 만든다.
동의를 받을 때 IP 와 브라우저를 증적으로 남긴다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 6: 가입 화면 템플릿

**Files:**
- Modify: `templates/default/auth/_consents.html.twig`, `templates/default/auth/_social_consent.html.twig`
- Modify: `src/Web/Kernel.php:60-67`

**Interfaces:**
- Consumes: `consent_documents` 전역이 `required`, `id` 를 갖는다 (Task 4)

- [ ] **Step 1: 전역을 자리로 읽게 고친다**

`src/Web/Kernel.php` 의 `consent_documents` 전역을 만드는 줄을 바꾼다.

```php
        $twig->getEnvironment()->addGlobal('consent_documents',
            $app->cmsService()->consentDocuments('signup'));
```

- [ ] **Step 2: `_consents.html.twig` 를 고친다**

`templates/default/auth/_consents.html.twig` 를 통째로 바꾼다.

```twig
{# 가입 동의 항목. 관리자가 약관 관리에서 붙인 것만, 정한 차례대로 나온다.
   필수는 체크해야 가입되고, 선택은 안 해도 가입된다. 어느 쪽이든 기록은 남는다. #}
{% if consent_documents is not empty %}
  <fieldset class="fieldset consent">
    {% for doc in consent_documents %}
      {% set field = 'agree_' ~ doc.id %}
      {% set is_required = doc.required == 1 %}
      <label class="label check-row{% if errors[field] is defined %} is-invalid{% endif %}">
        <input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="{{ field }}" value="1"{{ values[field]|default(false) ? ' checked' : '' }}{{ is_required ? ' required' : '' }}>
        <span><a class="link" href="{{ url_for('content.show', {slug: doc.slug}) }}" target="_blank" rel="noopener">{{ doc.title }}</a> 동의
          <span class="badge {{ is_required ? 'badge-error' : 'badge-ghost' }} badge-soft badge-xs">{{ is_required ? '필수' : '선택' }}</span></span>
      </label>
      {% if errors[field] is defined %}<p class="validator-hint">{{ errors[field] }}</p>{% endif %}
    {% endfor %}
  </fieldset>
{% endif %}
```

- [ ] **Step 3: `_social_consent.html.twig` 를 고친다**

`consent_required` 를 `required` 로 바꾼다.

```twig
{% set required_consents = consent_documents|filter(doc => doc.required == 1) %}
```

나머지는 그대로 둔다.

- [ ] **Step 4: 테스트를 돌린다**

Run: `./vendor/bin/phpunit`
Expected: OK

- [ ] **Step 5: lint 와 smoke**

Run: `php "$SP"/lint.php && php "$SP"/smoke.php`
Expected: `ALL OK`, `실패 0개`

- [ ] **Step 6: 커밋**

```bash
git add templates/default/auth/ src/Web/Kernel.php
git commit -m "feat: 가입 화면이 붙임에서 필수·선택을 읽는다

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 7: 내용 편집 폼의 토글 (20개 테마)

**Files:**
- Modify: `templates/*/admin/page_form.html.twig` (20벌)

**Interfaces:**
- Consumes: `validatePage()` 가 `is_consent` 를 받는다 (Task 4)

- [ ] **Step 1: default 와 claude-sky 의 동의 칸을 걷어낸다**

두 파일에서 `consent_key`·`consent_order`·`consent_required` 를 쓰는 `<div class="grid-2">` 블록과 토글 줄을 지운다. 대신 `toggle-list` 안에 토글 하나를 둔다.

```twig
        {# 켜면 약관 관리 목록으로 옮겨간다. 어디에 붙일지·필수인지는 거기서 정한다. #}
        <label class="label toggle-row">
          <input class="toggle toggle-primary" type="checkbox" name="is_consent" value="1"{{ (values.is_consent is defined ? values.is_consent : 0) ? ' checked' : '' }}>
          <span><strong>이 내용은 약관이다</strong><small>켜면 동의 항목으로 고를 수 있게 됩니다.</small></span>
        </label>
```

- [ ] **Step 2: 나머지 18개 테마에도 같은 일을 한다**

나머지 테마의 `page_form.html.twig` 에는 동의 칸이 애초에 없다. 확인한다.

```bash
cd /home/kagla/gnucms.com
grep -l "consent_key\|consent_required" templates/*/admin/page_form.html.twig
```

Expected: `default` 와 `claude-sky` 두 개만 나왔다면 Step 1 로 이미 끝났다. 다른 파일이 나오면 같은 방식으로 고친다.

`is_consent` 토글은 두 테마에만 넣는다. 나머지 18벌은 그 칸을 안 보내므로 `array_key_exists('is_consent', $input)` 가 막아 준다 — 저장해도 표시가 지워지지 않는다.

- [ ] **Step 3: lint**

Run: `php "$SP"/lint.php`
Expected: `ALL OK`

- [ ] **Step 4: 커밋**

```bash
git add templates/
git commit -m "feat: 내용 편집 폼에 약관 여부 토글 하나만 남긴다

키를 사람이 짓지 않는다. 어디에 붙일지와 필수·선택은 약관 관리에서 정한다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 8: 약관 관리 화면을 고쳐 쓰고 옛 라우트를 걷는다

**Files:**
- Modify: `src/Web/Routes.php`, `src/Web/Controller/AdminCmsController.php`, `src/Web/Controller/PageController.php`
- Modify: `templates/*/admin/legal.html.twig` (20벌), `templates/*/pages/show.html.twig` (20벌)
- Delete: `templates/*/admin/legal_form.html.twig` (20벌)
- Test: `tests/Web/CmsPageTest.php`

**Interfaces:**
- Consumes: `CmsService::consentPages(Acl)` (Task 4), `ConsentUseRepository::attach/detach` (Task 2)
- Produces:
  - `GET /admin/terms` → `admin.terms` — 약관 전부
  - `POST /admin/terms/uses` → `admin.terms.uses` — 붙임을 저장
  - `POST /admin/terms/setup` → `admin.terms.setup` — 그대로
  - `AdminCmsController::consentUses(Request, Response)`

- [ ] **Step 1: 실패하는 테스트를 쓴다**

`tests/Web/CmsPageTest.php` 의 약관 관련 단언을 바꾼다.

```php
        // 약관 관리에는 약관 전부가 나오고, 내용 관리에는 안 나온다.
        $legalPage = $this->get($app, '/admin/terms');
        self::assertSame(200, $legalPage->getStatusCode());
        self::assertStringContainsString('이용약관', $this->body($legalPage));
        self::assertStringContainsString('>/content/terms<', $this->body($legalPage));
        self::assertStringNotContainsString('이용약관', $this->body($this->get($app, '/admin/content')));

        // 옛 주소는 없어졌다.
        self::assertSame(404, $this->get($app, '/admin/terms/service')->getStatusCode());
        self::assertSame(404, $this->get($app, '/admin/legal')->getStatusCode());

        // 붙임을 화면에서 저장할 수 있다.
        $terms = $app->cms()->findBySlug('terms');
        $saved = $this->post($app, '/admin/terms/uses', [
            'csrf_token' => $_SESSION['csrf_token'],
            'scope' => 'signup',
            'use' => [(string) $terms['id'] => '1'],
            'required' => [(string) $terms['id'] => '1'],
            'sort_order' => [(string) $terms['id'] => '10'],
        ]);
        self::assertSame(303, $saved->getStatusCode(), $this->body($saved));
        $uses = $app->consentUses()->listForScope('signup');
        self::assertCount(1, $uses);
        self::assertSame(1, (int) $uses[0]['required']);
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter CmsPageTest`
Expected: FAIL

- [ ] **Step 3: 라우트를 고친다**

`src/Web/Routes.php` 에서 다음을 **지운다.**

```php
        $slim->get('/admin/terms/{type:service|privacy}', [$cms, 'legalEditForm'])->setName('admin.terms.edit');
        $slim->post('/admin/terms/{type:service|privacy}', [$cms, 'legalUpdate']);
        $slim->get('/admin/terms/{type:service|privacy}/preview', [$cms, 'legalPreview'])->setName('admin.terms.preview');
```

그리고 `/admin/legal` 과 `/admin/legal/{oldType}` 되돌림 블록 전체를 지운다.

다음을 **더한다.**

```php
        $slim->post('/admin/terms/uses', [$cms, 'consentUses'])->setName('admin.terms.uses');
```

- [ ] **Step 4: 컨트롤러를 고친다**

`src/Web/Controller/AdminCmsController.php` 에서 `legalEditForm`·`legalUpdate`·`legalPreview`·`requiredLegalPage`·`legalSlug` 를 지운다. `legal()` 을 바꾼다.

```php
    /** 약관 관리. 약관 전부와 자리별 붙임을 보여 준다. */
    public function legal(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        return Twig::fromRequest($request)->render($response, 'admin/legal.html.twig', [
            'pages' => $this->app->cmsService()->consentPages($acl),
            'scope' => 'signup',
            'saved' => ($request->getQueryParams()['saved'] ?? '') === '1',
        ]);
    }

    /** 한 자리의 붙임을 통째로 다시 쓴다. 체크가 풀린 약관은 떼어 낸다. */
    public function consentUses(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = $this->input($request);
        $this->assertCsrf($input);
        $acl = $this->app->guestAcl();
        $scope = isset($input['scope']) && is_string($input['scope']) && $input['scope'] !== ''
            ? $input['scope'] : 'signup';
        $use = is_array($input['use'] ?? null) ? $input['use'] : [];
        $required = is_array($input['required'] ?? null) ? $input['required'] : [];
        $order = is_array($input['sort_order'] ?? null) ? $input['sort_order'] : [];

        $uses = $this->app->consentUses();
        foreach ($this->app->cmsService()->consentPages($acl) as $page) {
            $id = (int) $page['id'];
            if (empty($use[$id])) {
                $uses->detach($scope, $id);
                continue;
            }
            $uses->attach($scope, $id, !empty($required[$id]), (int) ($order[$id] ?? 0));
        }

        return $this->redirect($request, $response, 'admin.terms', ['saved' => '1']);
    }
```

`legalSetup()` 은 그대로 둔다.

`assertRegularContent()` 가 남아 있으면 지운다(이미 지웠다면 넘어간다).

- [ ] **Step 5: `PageController` 와 `pages/show.html.twig` 에서 `legal_type` 갈림을 걷는다**

`src/Web/Controller/PageController.php::show()` 에서 `legal_type` 과 `preview_legal_type` 을 빼고, `AdminCmsController::preview()` 에서도 뺀다.

20개 테마의 `pages/show.html.twig` 에서 톱니가 언제나 내용 편집으로 가게 바꾼다.

```bash
cd /home/kagla/gnucms.com
for f in templates/*/pages/show.html.twig; do
  python3 - "$f" <<'PY'
import io,re,sys
p=sys.argv[1]; s=io.open(p,encoding='utf-8').read()
s=s.replace("legal_type ? url_for('admin.terms.edit', {type: legal_type}) : url_for('admin.content.edit', {id: page.id})",
            "url_for('admin.content.edit', {id: page.id})")
s=s.replace("preview_legal_type ? url_for('admin.terms.edit', {type: preview_legal_type}) : url_for('admin.content.edit', {id: page.id})",
            "url_for('admin.content.edit', {id: page.id})")
io.open(p,'w',encoding='utf-8').write(s)
PY
done
grep -rn "legal_type" templates/ || echo "legal_type 남은 것 없음"
```

- [ ] **Step 6: 약관 관리 템플릿을 다시 쓴다**

`templates/default/admin/legal.html.twig` 의 표를 통째로 바꾼다. 나머지 19벌은 지우고 default 로 폴백시킨다.

```twig
{% extends "admin/layout.html.twig" %}
{% import '_icons.html.twig' as ico %}
{% block title %}약관 관리 · {{ site.site_name }}{% endblock %}
{% block admin_section %}legal{% endblock %}
{% block body %}
<div class="breadcrumbs"><ul><li><a href="{{ url_for('admin.index') }}">사이트 관리</a></li><li aria-current="page">약관</li></ul></div>
{% if saved %}<div class="alert alert-success">저장했습니다.</div>{% endif %}
<section class="card">
  <div class="card-body">
    <h1 class="card-title">약관 관리</h1>
    <p class="card-sub">내용 편집에서 &lsquo;이 내용은 약관이다&rsquo; 를 켠 것이 여기에 나옵니다. 회원가입에서 받을 항목과 필수·선택을 여기서 정합니다.</p>
    {% if pages is empty %}
      <p class="cell-sub">아직 약관이 없습니다. 이용약관과 개인정보 처리방침 초안을 만들어 시작하세요.</p>
      <form method="post" action="{{ url_for('admin.terms.setup') }}">
        <input type="hidden" name="csrf_token" value="{{ csrf_token }}">
        <button class="btn btn-primary" type="submit">씨앗 약관 만들기</button>
      </form>
    {% else %}
      <form method="post" action="{{ url_for('admin.terms.uses') }}">
        <input type="hidden" name="csrf_token" value="{{ csrf_token }}">
        <input type="hidden" name="scope" value="{{ scope }}">
        <div class="table-wrap">
          <table class="table table-zebra">
            <thead><tr><th>약관</th><th>공개 주소</th><th>회원가입</th><th>필수</th><th>차례</th><th>동의</th><th class="right">관리</th></tr></thead>
            <tbody>
            {% for page in pages %}
              {# page.use 는 CmsService 가 이 자리의 붙임을 골라 넣어 준 것이다.
                 Twig 의 set 은 for 밖으로 새지 않아 여기서 고를 수 없다. #}
              {% set attached = page.use is not null %}
              {% set attached_required = attached ? page.use.required : 1 %}
              {% set attached_order = attached ? page.use.sort_order : 0 %}
              <tr>
                <td data-label="약관"><span class="cell-title">{{ page.title }}</span>
                  {% if page.status != 'published' %}<span class="badge badge-warning badge-soft badge-xs">초안</span>{% endif %}</td>
                <td data-label="공개 주소"><code class="kbd kbd-sm">/content/{{ page.slug }}</code></td>
                <td data-label="회원가입"><input class="checkbox checkbox-sm" type="checkbox" name="use[{{ page.id }}]" value="1"{{ attached ? ' checked' : '' }}></td>
                <td data-label="필수"><input class="checkbox checkbox-sm" type="checkbox" name="required[{{ page.id }}]" value="1"{{ attached_required ? ' checked' : '' }}></td>
                <td data-label="차례"><input class="input input-bordered input-sm" type="number" name="sort_order[{{ page.id }}]" value="{{ attached_order }}" min="-9999" max="9999"></td>
                <td data-label="동의">{{ page.counts.agreed }} · 미동의 {{ page.counts.declined }}</td>
                <td data-label="관리" class="right">
                  <div class="row-actions">
                    <a class="btn btn-outline btn-sm" href="{{ url_for('admin.content.edit', {id: page.id}) }}">수정</a>
                    <a class="btn btn-outline btn-sm" href="{{ url_for('admin.terms.consents', {id: page.id}) }}">동의 현황</a>
                  </div>
                </td>
              </tr>
            {% endfor %}
            </tbody>
          </table>
        </div>
        <div class="card-actions form-actions">
          <button class="btn btn-primary" type="submit">변경사항 저장</button>
        </div>
      </form>
    {% endif %}
  </div>
</section>
{% endblock %}
```

`admin.terms.consents` 라우트는 Task 9 에서 만든다. **Task 9 를 마치기 전에는 이 화면이 뜨지 않으므로 두 작업을 이어서 한다.**

- [ ] **Step 7: 테마별 약관 템플릿을 지운다**

```bash
cd /home/kagla/gnucms.com
rm templates/*/admin/legal_form.html.twig
for f in templates/*/admin/legal.html.twig; do
  case "$f" in templates/default/*) continue;; esac
  rm "$f"
done
ls templates/*/admin/legal*.twig
```

Expected: `templates/default/admin/legal.html.twig` 하나만 남는다.

- [ ] **Step 8: Task 9 로 넘어간다**

이 작업은 Task 9 와 함께 커밋한다.

---

### Task 9: 동의 현황 화면

**Files:**
- Create: `templates/default/admin/consents.html.twig`
- Modify: `src/Web/Routes.php`, `src/Web/Controller/AdminCmsController.php`
- Test: `tests/Web/CmsPageTest.php`

**Interfaces:**
- Consumes: `ConsentRepository::forContent(int)` (Task 3)
- Produces: `GET /admin/terms/{id:[0-9]+}/consents` → `admin.terms.consents`

- [ ] **Step 1: 실패하는 테스트를 쓴다**

`tests/Web/CmsPageTest.php` 에 더한다.

```php
        // 한 약관에 누가 동의했는지 따로 볼 수 있다.
        $app->consents()->record('user', 1, 'signup', $app->cms()->findBySlug('terms'), true, null);
        $view = $this->get($app, '/admin/terms/' . $terms['id'] . '/consents');
        self::assertSame(200, $view->getStatusCode());
        self::assertStringContainsString('동의 현황', $this->body($view));
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter CmsPageTest`
Expected: FAIL — 404

- [ ] **Step 3: 라우트를 더한다**

`src/Web/Routes.php` 의 `admin.terms.uses` 아래에 더한다. `{id:[0-9]+}` 라 `/admin/terms/uses` 와 겹치지 않는다.

```php
        $slim->get('/admin/terms/{id:[0-9]+}/consents', [$cms, 'consents'])->setName('admin.terms.consents');
```

- [ ] **Step 4: 컨트롤러를 더한다**

```php
    /** 한 약관에 누가 동의했고 누가 안 했는지. */
    public function consents(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $acl = $this->app->guestAcl();
        $page = $this->app->cmsService()->page($acl, (int) $args['id']);

        return Twig::fromRequest($request)->render($response, 'admin/consents.html.twig', [
            'page' => $page,
            'rows' => $this->app->consents()->forContent((int) $page['id']),
            'counts' => $this->app->consents()->countsForContent((int) $page['id']),
        ]);
    }
```

- [ ] **Step 5: 템플릿을 만든다**

`templates/default/admin/consents.html.twig`

```twig
{% extends "admin/layout.html.twig" %}
{% import '_icons.html.twig' as ico %}
{% block title %}{{ page.title }} 동의 현황 · {{ site.site_name }}{% endblock %}
{% block admin_section %}legal{% endblock %}
{% block body %}
<div class="breadcrumbs"><ul><li><a href="{{ url_for('admin.index') }}">사이트 관리</a></li><li><a href="{{ url_for('admin.terms') }}">약관</a></li><li aria-current="page">{{ page.title }}</li></ul></div>
<section class="card">
  <div class="card-body">
    <h1 class="card-title">{{ page.title }} 동의 현황</h1>
    <p class="card-sub">동의 {{ counts.agreed }}건 · 동의 안 함 {{ counts.declined }}건. 보여 준 항목은 동의하지 않았어도 남습니다.</p>
    {% if rows is empty %}
      <p class="cell-sub">아직 기록이 없습니다.</p>
    {% else %}
      <div class="table-wrap">
        <table class="table table-zebra">
          <thead><tr><th>대상</th><th>자리</th><th>동의</th><th>시각</th><th>그때 본 판</th><th>증적</th></tr></thead>
          <tbody>
          {% for row in rows %}
            <tr>
              <td data-label="대상">
                {% if row.subject_type == 'user' %}
                  <span class="cell-title">{{ row.user_email|default('지워진 회원') }}</span>
                {% else %}
                  <span class="cell-title">제출 #{{ row.subject_id }}</span>
                {% endif %}
              </td>
              <td data-label="자리"><code class="kbd kbd-sm">{{ row.scope }}</code></td>
              <td data-label="동의"><span class="badge badge-sm badge-soft {{ row.agreed ? 'badge-success' : 'badge-ghost' }}">{{ row.agreed ? '동의' : '안 함' }}</span></td>
              <td data-label="시각">{{ row.agreed_at|date('Y.m.d H:i') }}</td>
              <td data-label="그때 본 판">{{ row.content_updated_at|date('Y.m.d H:i') }}
                {% if page.updated_at > row.content_updated_at %}<span class="badge badge-warning badge-soft badge-xs">그 뒤 바뀜</span>{% endif %}</td>
              <td data-label="증적"><span class="cell-sub">{{ row.agreed_ip|default('-') }}</span></td>
            </tr>
          {% endfor %}
          </tbody>
        </table>
      </div>
    {% endif %}
  </div>
</section>
{% endblock %}
```

- [ ] **Step 6: 테스트를 돌린다**

Run: `./vendor/bin/phpunit`
Expected: OK

- [ ] **Step 7: lint 와 smoke**

Run: `php "$SP"/lint.php && php "$SP"/smoke.php`

smoke 의 경로 목록에서 `/admin/terms/service` 를 빼고 `/admin/terms` 만 남긴다.

Expected: `ALL OK`, `실패 0개`

- [ ] **Step 8: 커밋**

```bash
git add src/Web/ templates/ tests/
git commit -m "feat: 약관 관리를 약관 전부를 다루는 화면으로 바꾼다

이용약관·개인정보 둘만 알던 화면이 약관 전부를 보여 주고, 회원가입에 무엇을
필수로 붙일지 여기서 정한다. 약관마다 누가 동의했는지 따로 볼 수 있다.
편집 경로가 내용 편집 폼 하나로 정리되면서 옛 약관 편집 주소는 걷어낸다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 10: 회원 수정 화면의 동의 내역을 새 표로

**Files:**
- Modify: `src/Web/Controller/AdminController.php`, `templates/default/admin/_member_consents.html.twig`
- Test: `tests/Web/AdminPageTest.php`

**Interfaces:**
- Consumes: `ConsentRepository::forSubjectWithDocument('user', int)` (Task 3)

- [ ] **Step 1: 테스트를 고친다**

`tests/Web/AdminPageTest.php::testMemberFormShowsConsentHistory` 의 픽스처를 새 시그니처로 바꾼다.

```php
        foreach ([['terms', '이용약관', true], ['marketing', '마케팅 정보 수신', false]] as $doc) {
            $id = $app->cms()->createPage([
                'slug' => $doc[0], 'title' => $doc[1], 'content' => $doc[1] . ' 본문',
                'seo_description' => null, 'status' => 'published', 'show_in_menu' => 0,
                'sort_order' => 0, 'is_consent' => 1,
            ]);
            $app->consentUses()->attach('signup', $id, $doc[2], 0);
        }
```

그리고 기록을 남기는 두 줄을 바꾼다.

```php
        $app->consents()->record('user', $memberId, 'signup', $app->cms()->findBySlug('terms'), true, null);
        $app->consents()->record('user', $memberId, 'signup', $app->cms()->findBySlug('marketing'), false, null);
```

- [ ] **Step 2: 실패를 확인한다**

Run: `./vendor/bin/phpunit --filter testMemberFormShowsConsentHistory`
Expected: FAIL

- [ ] **Step 3: 컨트롤러를 고친다**

`src/Web/Controller/AdminController.php::renderMemberForm()`

```php
            'member_consents' => $this->app->consents()
                ->forSubjectWithDocument('user', (int) $values['id']),
```

- [ ] **Step 4: 템플릿에 자리와 증적을 더한다**

`templates/default/admin/_member_consents.html.twig` 의 `<thead>` 와 각 행에 자리 칸을 더한다.

```twig
        <thead><tr><th>항목</th><th>자리</th><th>동의</th><th>동의 시각</th><th>그때 본 문서</th></tr></thead>
```

`<td data-label="항목">` 다음에 더한다.

```twig
            <td data-label="자리"><code class="kbd kbd-sm">{{ row.scope }}</code></td>
```

- [ ] **Step 5: 테스트를 돌린다**

Run: `./vendor/bin/phpunit`
Expected: OK

- [ ] **Step 6: lint 와 smoke**

Run: `php "$SP"/lint.php && php "$SP"/smoke.php`
Expected: `ALL OK`, `실패 0개`

- [ ] **Step 7: 라이브를 확인한다**

```bash
cd /home/kagla/gnucms.com
curl -s -o /dev/null -w 'GET / → %{http_code}\n' https://gnucms.gnuboard.net/
php -r '$p=new PDO("sqlite:/home/kagla/gnucms.com/storage/board.sqlite");
foreach($p->query("SELECT setting_value FROM site_settings WHERE setting_key=\"schema_version\"") as $r) echo "도장=",$r["setting_value"],"\n";
echo "contents: "; foreach($p->query("PRAGMA table_info(contents)") as $r) echo $r["name"]," "; echo "\n";
echo "consent_uses: "; foreach($p->query("SELECT scope, content_id, required, sort_order FROM consent_uses") as $r) echo $r["scope"],"/",$r["content_id"],"(필수",$r["required"],",차례",$r["sort_order"],") "; echo "\n";
echo "consents_given: ", count(iterator_to_array($p->query("SELECT id FROM consents_given"))), "건\n";'
curl -s https://gnucms.gnuboard.net/register | grep -o "name=\"agree_[0-9]*\"\|badge-xs\">[^<]*"
```

Expected: 도장이 `9.` 로 시작한다. `contents` 에 `is_consent` 가 있다. `consent_uses` 에 회원가입 붙임 세 줄이 있다. 가입 화면의 체크박스 이름이 `agree_2` 처럼 숫자다.

- [ ] **Step 8: 커밋**

```bash
git add src/Web/Controller/AdminController.php templates/default/admin/_member_consents.html.twig tests/
git commit -m "feat: 회원 동의 내역을 새 기록 표에서 읽는다

어느 자리에서 받은 동의인지 함께 보여 준다.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

## 다음 판으로 미루는 것

- `contents.consent_key`·`consent_order`·`consent_required` 칸 지우기
- `user_consents` 표 지우기
- 폼(신청서) 기능 자체
- 동의 철회·재동의 화면
- 만 14세 미만 법정대리인 동의
- `ux_contents_consent` 인덱스 지우기 (스펙 4.1 이 명시했으나 칸과 함께 다음 판으로)
- 가입 게이트가 아직 slug terms/privacy 에 매여 있음 (`legalDocuments()`) — 붙임 기반 게이트로 전환
- 회원 탈퇴 기능이 생기면 consents_given 파기를 함께 (처리방침 문구의 보관기간 약속 이행)
