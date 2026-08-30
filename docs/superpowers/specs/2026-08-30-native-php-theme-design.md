# PHP 네이티브 테마 설계

- 작성일: 2026-08-30
- 상태: 구현됨 (2026-08-30)
- 선행 작업: `7d6822f` (claude-sky 를 default 로)

## 1. 배경

화면은 전부 Twig 로 그린다. 테마 24벌이 모두 Twig 이고, 컨트롤러 10개가
`Twig::fromRequest($request)->render()` 를 44곳에서 직접 부른다. `Kernel`·`SessionGuard`·
`ErrorPageMiddleware`·`Routes`(health) 도 Twig 환경을 직접 만진다.

Twig 를 걷어내고 **PHP 파일 템플릿만으로** 화면을 그리려 한다. 첫걸음으로 지금의 `default`
테마(하늘빛, daisyUI CDN)를 PHP 로 옮긴 `native` 테마를 만든다. 디자인은 바꾸지 않는다.
렌더 결과가 Twig 판과 같아야 한다.

## 2. 목표

1. **`native` 테마는 Twig 없이 돈다.** 58개 화면 전부 PHP 파일이다.
2. **렌더 결과가 Twig `default` 와 같다.** 같은 데이터로 41개 경로를 그려 HTML 을 비교한다.
3. **엔진을 테마가 고른다.** 당분간 Twig 테마와 PHP 테마가 함께 돈다.
4. **Twig 제거가 파일 삭제로 끝나게** 경계를 잡는다.

## 3. 결정

### 3.1 렌더러 추상 `View`

```
GnuCms\View\ViewInterface
    render(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    addGlobal(string $name, mixed $value): void
    fetch(string $template, array $data = []): string     // 문자열로. 오류 화면·테스트용

GnuCms\View\TwigView   — Slim\Views\Twig 를 감싼다. 이름에 .html.twig 를 붙인다.
GnuCms\View\PhpView    — 이 문서의 주제. 이름에 .php 를 붙인다.
```

컨트롤러는 확장자 없는 **논리 이름**을 넘긴다: `'home/index'`, `'admin/page_form'`.
`View::fromRequest($request)` 로 얻는다. `ViewMiddleware` 가 요청 속성 `view` 에 넣어 준다
(지금 `TwigMiddleware` 가 하던 일).

`SessionGuard` 와 `ErrorPageMiddleware` 는 `ViewInterface` 를 받는다. `Kernel` 이 전역
(`site`, `legal_pages`, …)을 `addGlobal()` 로 넣는다. `Twig` 클래스를 아는 곳은 `TwigView`
와 `Kernel` 의 조립 한 줄뿐이어야 한다.

### 3.2 엔진 선택

테마 폴더에 `theme.php` 가 있으면 PHP 엔진이다.

```php
<?php
// templates/native/theme.php
return ['engine' => 'php', 'label' => 'PHP 네이티브 (하늘빛)'];
```

없으면 Twig 다. `ThemeManager::engine(): string` 이 읽어 준다. `Kernel` 은 엔진에 따라
`TwigView` 또는 `PhpView` 를 만든다. `TwigMiddleware` 는 Twig 일 때만 단다.

### 3.3 PHP 템플릿은 Plates 식 `$this->` 헬퍼

템플릿 파일은 `PhpTemplate` 클래스의 메서드 안에서 `include` 된다. 전역 함수도 숨은
상태도 없다. `$this` 가 헬퍼다.

| 헬퍼 | 하는 일 | Twig 대응 |
|---|---|---|
| `$this->e($v)` | HTML 이스케이프. `null` 은 빈 문자열 | 자동 이스케이프 |
| `$this->layout('layout')` | 이 화면을 감쌀 레이아웃 | `{% extends %}` |
| `$this->start('body')` … `$this->stop()` | 블록을 잡는다 | `{% block %}` |
| `$this->block('body', '')` | 잡힌 블록을 낸다. 없으면 기본값 | `{{ block('body') }}` / 부모 블록 본문 |
| `$this->has('header_search')` | 블록이 비어 있지 않은가 | `block('x')\|trim is not empty` |
| `$this->insert('posts/_meta', ['post' => $post])` | 조각을 그려 낸다 | `{% include %}` |
| `$this->fetch('posts/_meta', [...])` | 조각을 문자열로 | `{% set x %}{% include %}{% endset %}` |
| `$this->url('posts.index', ['key' => $k], ['q' => $q])` | 라우트 주소 | `url_for()` |
| `$this->asset('theme.css')` | 테마 정적 파일 주소 | `theme_asset()` |
| `$this->html($content)` | 정화된 본문 HTML | `\|cms_html` |
| `$this->icon('home', 18, 'cls')` | 아이콘 SVG | `ico.i()` |
| `$this->date($v, 'Y.m.d')` | 날짜 | `\|date` |
| `$this->json($v)` | `<script>` 안에 넣을 JSON | `\|json_encode` |
| `$this->base` | 기준 경로 | `base_path` |

전역(`site`, `current_user`, `csrf_token`, …)과 넘겨받은 데이터는 지역 변수로 푼다
(`extract`). 이름이 겹치면 데이터가 전역을 이긴다.

**규칙: 출력은 전부 `$this->e()` 를 거친다.** 예외는 `$this->html()`·`$this->icon()`·
`$this->json()`·`$this->insert()`·`$this->block()` 의 결과처럼 이미 안전한 것뿐이다.
이 규칙을 사람이 지키는지는 파리티 테스트가 잡는다 — Twig 가 이스케이프한 곳을 PHP 가
빠뜨리면 HTML 이 달라진다.

### 3.4 레이아웃 동작

1. 화면 파일이 실행된다. `$this->layout('layout')` 은 이름만 적어 둔다. `start/stop` 이
   블록을 출력 버퍼로 잡는다. 블록 밖의 출력은 `body` 블록이 아니다 — 버린다(Twig 의
   `extends` 화면에서 블록 밖 출력이 무시되는 것과 같다).
2. 화면이 끝나면 레이아웃 파일을 같은 블록 저장소로 실행한다. 레이아웃은 `$this->block('body')`
   로 자식 블록을 낸다. 레이아웃이 다시 `$this->layout('layout')` 을 부르면(관리 콘솔
   레이아웃이 공개 레이아웃을 감싸는 경우) 한 번 더 감싼다.
3. 자식이 잡은 블록이 부모의 `block()` 기본값을 이긴다. 부모가 `start('x')` 로 같은 이름을
   잡으려 하면 **자식 것이 이미 있을 때는 그대로 두고 부모 본문을 버린다** — Twig 에서
   자식 블록이 부모 블록을 덮는 것과 같다.

`{{ parent() }}` 는 쓰는 곳이 없어 만들지 않는다.

### 3.5 아이콘

`_icons.html.twig` 는 매크로 하나에 `elseif` 51개다. PHP 는 `_icons.php` 가
`['home' => '<path …/>', …]` 배열을 돌려주고 `icon()` 이 감싼다. 이름이 없으면 빈
`<svg>` 를 낸다(Twig 판과 같다).

### 3.6 폴백은 없다

`native` 는 자기완결이다. Twig `default` 로 폴백하지 않는다. 화면 파일이 없으면
`PhpView` 가 `RuntimeException` 을 던지고 오류 화면(500)이 뜬다. 공용 조각 다섯(가입 동의
체크박스, 소셜 고지, 약관 관리, 동의 현황, 회원 동의 내역)도 `native` 안에 있다.

나중에 `native` 가 `default` 가 되면, 다른 PHP 테마가 그리로 폴백하도록 `PhpView` 에
경로 목록을 넘길 수 있게 **만들어는 두되 지금은 경로 하나만** 넣는다.

### 3.7 검증

**HTML 파리티.** 같은 씨앗 데이터로 41개 경로(손님 20 + 관리 21)를 `default` 와 `native`
로 그려 비교한다. 비교 전에 다음만 정규화한다.

- 줄 끝 공백과 빈 줄
- 태그 사이 공백 (`>\s+<` → `><`)
- `theme.css?v=…` 의 해시와 `/themes/{이름}/` 의 테마 이름
- `image_key` 입력의 32자리 16진 난수 (렌더마다 새로 만드는 값이라 마스킹한다)
- 테마 선택 `<select>` 에서 `<option value="default">`·`<option value="native">` 의 `selected`
  (테스트가 엔진을 가르려고 `theme` 설정을 회차마다 `default`/`native` 로 다르게 저장하는데
  `admin/settings` 가 그 값을 정직하게 보여주므로, 하니스가 심은 선택이 화면에 새어 나온다.
  `image_key` 와 같은 성격의, 하니스 자신이 만든 차이다. 다른 테마 이름은 건드리지 않는다)

그 밖의 차이는 전부 결함이다. 목표는 **41개 경로 차이 0**.

**단위 테스트.** `PhpView` 의 레이아웃·블록 덮어쓰기·이스케이프·`insert`·`url`·파일 없음.
`ThemeManager::engine()`. `View::fromRequest()`.

**회귀.** 기존 phpunit 전체를 `GNUCMS_TEST_THEME=native` 로 한 번 더 돌린다.
`WebTestCase::makeApp()` 이 그 값을 읽어 테마를 정한다. 두 번 다 통과해야 한다.

## 4. 파일

```
src/View/ViewInterface.php
src/View/TwigView.php
src/View/PhpView.php            경로·전역·헬퍼 조립. render()/fetch()
src/View/PhpTemplate.php        한 번의 렌더. include 가 이 안에서 일어난다. $this 가 헬퍼
src/View/View.php               fromRequest()
src/Web/Middleware/ViewMiddleware.php
src/Theme/ThemeManager.php      engine()
src/Web/Kernel.php              엔진에 따라 조립
src/Web/Middleware/SessionGuard.php, ErrorPageMiddleware.php   ViewInterface
src/Web/Controller/*.php, src/Web/Routes.php                    논리 이름으로

templates/native/theme.php
templates/native/**/*.php       58개
templates/native/_icons.php
public/themes/native/theme.css  default 의 것을 복사

tests/View/PhpViewTest.php
tests/View/ThemeParityTest.php
tests/Support/WebTestCase.php   GNUCMS_TEST_THEME
```

## 5. 범위 밖

- 새 디자인. CSS·JS 는 한 글자도 안 바꾼다.
- Twig 테마 스물세 벌 수정.
- 엔진 간 폴백.
- 템플릿 캐시·컴파일.
- Twig 와 `slim/twig-view` 제거 — 다음 판. 파리티 0 뒤에 `native` 를 `default` 로 바꾸고
  `TwigView`·`TwigMiddleware`·Twig 테마·composer 의존 둘을 지운다.

## 6. 작업 순서

1. `View` 추상과 `TwigView` — 컨트롤러가 논리 이름을 쓰게 바꾼다. **이 시점에 Twig 판이
   그대로 돌아야 한다**(회귀 0).
2. `PhpView`·`PhpTemplate` 과 단위 테스트.
3. `ThemeManager::engine()`, `Kernel` 조립, `theme.php`.
4. `native` 뼈대: `layout`, `admin/layout`, `_icons`, `error`, `health`. 파리티 도구.
5. 화면 묶음별 이식 — 손님(홈·게시판·글·댓글·알림·내용), 인증(로그인·가입·비밀번호·소셜),
   관리(대시보드·게시판·글·회원·내용·약관·설정·메일·비밀번호). 묶음마다 파리티가 게이트다.
6. 전체 파리티 0 + phpunit 두 번 통과.
