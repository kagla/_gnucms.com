# aboard 템플릿 개발 안내

이 문서는 aboard의 새 화면 템플릿을 만들고 적용하는 방법을 설명합니다.
Twig 화면과 CSS·이미지·JavaScript 같은 정적 파일은 테마별 폴더로 분리되며,
새 테마에 없는 파일은 `default` 테마에서 자동으로 가져옵니다.

## 1. 핵심 구조

```text
templates/
├── default/                    반드시 존재하는 기본 Twig 템플릿
│   ├── layout.html.twig
│   ├── home/
│   ├── boards/
│   ├── posts/
│   ├── auth/
│   ├── pages/
│   └── admin/
└── modern/                     추가 템플릿 예시
    ├── layout.html.twig
    └── home/
        └── index.html.twig

public/themes/
├── default/
│   └── theme.css
└── modern/
    └── theme.css
```

현재 선택된 테마 이름은 DB `site_settings` 테이블의 `theme` 키에 저장됩니다.
값이 없거나 폴더가 존재하지 않거나 이름이 잘못된 경우에는 항상 `default`가 사용됩니다.

테마 이름에는 다음 문자만 사용할 수 있습니다.

```text
영문 소문자, 숫자, 밑줄(_), 하이픈(-)
```

예: `modern`, `company_v2`, `shop-dark`

## 2. 가장 작은 새 테마 만들기

새 테마가 모든 Twig 파일을 복사할 필요는 없습니다. 다음 두 파일만으로 시작할 수 있습니다.

```text
templates/clean/layout.html.twig
public/themes/clean/theme.css
```

`templates/clean/layout.html.twig`:

```twig
{% extends "@default/layout.html.twig" %}
{% block body_class %}theme-clean{% endblock %}
```

`public/themes/clean/theme.css`:

```css
.theme-clean {
  --bg: #f6f7f9;
  --panel: #ffffff;
  --fg: #172033;
  --primary: #2563eb;
}

.theme-clean .surface {
  border-radius: 14px;
  box-shadow: 0 10px 30px rgba(16, 24, 40, .06);
}
```

이 상태에서 홈, 게시판, 게시글, 로그인 화면은 `default`의 Twig 파일을 사용하고
레이아웃과 CSS만 `clean` 테마의 것을 적용합니다.

## 3. Twig 파일 폴백 규칙

선택된 테마가 `modern`이고 컨트롤러가 다음 템플릿을 요청한다고 가정합니다.

```text
posts/index.html.twig
```

파일은 다음 순서로 검색됩니다.

```text
1. templates/modern/posts/index.html.twig
2. templates/default/posts/index.html.twig
```

선택 테마에 파일이 있으면 그 파일을 사용하고, 없으면 `default`의 동일한 경로를 사용합니다.

### 기본 템플릿을 명시적으로 확장하기

선택 테마의 같은 이름 파일을 다시 찾지 않고 기본 파일을 확실하게 부모로 지정하려면
`@default` 네임스페이스를 사용합니다.

```twig
{% extends "@default/layout.html.twig" %}
```

새 `layout.html.twig`에서 다음처럼 작성하면 안 됩니다.

```twig
{# 선택 테마의 layout.html.twig가 자기 자신을 다시 찾을 수 있음 #}
{% extends "layout.html.twig" %}
```

새 테마 파일에서 기본 파일을 확장할 때는 `@default/`를 사용하는 것이 안전합니다.

## 4. 화면 하나만 변경하기

게시판 목록만 다른 HTML 구조로 만들려면 다음 파일만 추가합니다.

```text
templates/modern/posts/index.html.twig
```

완전히 새로 작성할 수도 있고 기본 파일을 확장해 일부 블록만 바꿀 수도 있습니다.
기본 템플릿이 제공하는 블록 구조가 부족하면 해당 화면을 복사해 수정합니다.

기존 화면별 파일은 다음 위치에서 확인할 수 있습니다.

| 화면 | 기본 파일 |
|---|---|
| 전체 레이아웃 | `templates/default/layout.html.twig` |
| 홈 | `templates/default/home/index.html.twig` |
| 게시판 모음 | `templates/default/boards/index.html.twig` |
| 게시글 목록 | `templates/default/posts/index.html.twig` |
| 게시글 보기 | `templates/default/posts/show.html.twig` |
| 글쓰기 | `templates/default/posts/create.html.twig` |
| 로그인·가입 | `templates/default/auth/` |
| 일반 내용 | `templates/default/pages/show.html.twig` |
| 관리자 | `templates/default/admin/` |

화면에 전달되는 `board`, `post`, `list`, `values`, `errors` 같은 개별 변수는
수정하려는 기본 파일을 기준으로 확인하는 것이 가장 정확합니다.

## 5. CSS·이미지·JavaScript 사용하기

테마 전용 정적 파일은 다음 폴더에 둡니다.

```text
public/themes/{테마 이름}/
```

Twig에서는 하드코딩한 `/themes/...` 주소 대신 `theme_asset()`을 사용합니다.

```twig
<link rel="stylesheet" href="{{ theme_asset('theme.css') }}">
<img src="{{ theme_asset('images/logo.png') }}" alt="사이트 로고">
<script src="{{ theme_asset('js/theme.js') }}" defer></script>
```

`modern/images/logo.png`가 없고 `default/images/logo.png`가 있다면 자동으로 기본 파일 주소가 반환됩니다.

```text
public/themes/modern/images/logo.png   없음
public/themes/default/images/logo.png  있음
                                     ↓
/themes/default/images/logo.png
```

실제 URL에는 파일 변경시간을 사용한 `?v=...` 값이 자동으로 붙습니다. CSS나 JavaScript를
수정하면 주소도 바뀌므로 Twig 캐시와 별개로 브라우저의 이전 정적 파일 캐시를 피할 수 있습니다.

폴백은 `theme_asset()`을 사용한 파일에만 적용됩니다. 정적 주소를 직접 작성하면 폴백되지 않습니다.

## 6. 사용할 수 있는 공통 Twig 값

모든 템플릿에서 다음 공통 값을 사용할 수 있습니다.

| 이름 | 설명 |
|---|---|
| `site` | 사이트 이름, 소개, 홈 문구, 현재 테마 등의 설정 |
| `current_user` | `is_guest`, `display_name`, `is_admin`을 포함한 현재 사용자 |
| `csrf_token` | POST 폼에 넣어야 하는 CSRF 토큰 |
| `site_menu` | 공개 상단 메뉴 목록 |
| `registration_available` | 현재 회원가입 가능 여부 |
| `legal_documents` | 공개된 약관 정보 |
| `oauth_providers` | 활성화된 소셜 로그인 목록 |
| `base_path` | 하위 경로 설치 시 사용하는 기준 경로 |
| `active_theme` | 실제 적용된 테마 이름 |
| `available_themes` | 발견된 테마 이름 목록 |

주요 함수와 필터는 다음과 같습니다.

```twig
{{ url_for('posts.show', {id: post.id}) }}
{{ theme_asset('theme.css') }}
{{ page.content|cms_html }}
```

- URL은 문자열로 직접 조립하지 말고 `url_for()`를 사용합니다.
- 테마 정적 파일은 `theme_asset()`을 사용합니다.
- `cms_html`은 CMS가 저장하고 정화한 콘텐츠 출력에만 사용합니다.

## 7. 폼과 보안

POST 폼에는 CSRF 토큰을 반드시 포함합니다.

```twig
<form method="post" action="{{ url_for('example.save') }}">
  <input type="hidden" name="csrf_token" value="{{ csrf_token }}">
  <button type="submit">저장</button>
</form>
```

Twig 자동 이스케이프가 활성화되어 있으므로 일반 값에는 `|raw`를 사용하지 않습니다.
템플릿에 PHP 코드, DB 조회, 파일 쓰기 또는 비즈니스 로직을 넣지 않습니다.
새 데이터가 필요하면 컨트롤러와 서비스에서 준비해 Twig 변수로 전달합니다.

## 8. 관리자 템플릿 변경하기

관리자 화면도 같은 폴백 규칙을 사용합니다.

```text
templates/modern/admin/index.html.twig
templates/modern/admin/_sidebar.html.twig
templates/modern/admin/members.html.twig
```

파일을 추가하지 않으면 `templates/default/admin/`의 화면이 사용됩니다.

기본 관리자 레이아웃은 일반 사용자용 레이아웃 변경 때문에 우연히 깨지지 않도록
`@default/layout.html.twig`를 명시적으로 확장합니다. 관리자를 바꾸려면 `admin/` 파일을
명시적으로 추가하는 방식을 권장합니다.

테마 CSS는 관리자 페이지에서도 로드될 수 있으므로 일반 화면 스타일은 테마 전용 body 클래스 아래로 제한합니다.

```css
.theme-modern .board-card { /* 일반 화면 */ }
.admin-page .admin-sidebar { /* 관리자를 의도적으로 바꿀 때만 */ }
```

## 9. 테마 선택과 가변 설정

관리자에서 다음 메뉴로 이동합니다.

```text
관리 콘솔 → 사이트 설정 → 템플릿
```

`templates/{이름}` 또는 `public/themes/{이름}` 폴더가 있으면 선택 목록에 자동으로 나타납니다.
저장하면 DB의 `site_settings.theme` 값이 바뀌고 다음 요청부터 새 테마가 적용됩니다.

현재 설정은 사이트 전체에 적용되는 단일 전역 설정입니다. 사용자별·게시판별 테마는 아직 지원하지 않습니다.

테마 폴더가 삭제되거나 저장된 이름이 잘못된 경우에는 사이트가 중단되지 않고 `default`로 돌아갑니다.

## 10. 여러 AI 또는 개발자의 병렬 작업

테마별 폴더가 분리되어 있어 병렬 개발이 가능합니다.

```text
AI/개발자 A → templates/modern-a, public/themes/modern-a
AI/개발자 B → templates/editorial, public/themes/editorial
AI/개발자 C → templates/compact, public/themes/compact
```

충돌을 줄이려면 다음 규칙을 지킵니다.

1. 각 작업자는 고유한 테마 이름과 Git 브랜치를 사용합니다.
2. 특별한 합의 없이 `templates/default/`를 수정하지 않습니다.
3. `ThemeManager`, DB 스키마, 공통 컨트롤러는 한 작업자만 담당합니다.
4. 이미지와 CSS도 각자의 `public/themes/{이름}` 안에 둡니다.
5. 완성된 테마를 합친 후 관리자 설정에서 하나씩 선택해 검증합니다.

테마끼리는 동일한 파일을 수정하지 않으므로 같은 저장소에서도 충돌이 적습니다.
다만 동일한 작업 디렉터리를 여러 AI가 동시에 직접 수정하면 Git 인덱스나 공통 파일이 충돌할 수 있으므로,
AI마다 별도 브랜치 또는 별도 worktree를 사용하는 것이 안전합니다.

## 11. 검증 방법

전체 자동 테스트:

```bash
vendor/bin/phpunit
```

테마 폴백 관련 테스트만 실행:

```bash
vendor/bin/phpunit tests/Theme/ThemeManagerTest.php tests/Web/BoardListTest.php
```

배포 전에는 다음 항목을 확인합니다.

- 홈, 게시판 목록, 게시글 보기, 로그인 화면이 열리는가
- 선택 테마에 없는 Twig 파일이 기본 화면으로 표시되는가
- 선택 테마에 없는 이미지·JS가 기본 파일로 연결되는가
- 모바일 너비에서 메뉴와 폼이 잘리지 않는가
- 라이트·다크 모드에서 글자와 배경 대비가 충분한가
- 관리자 화면을 수정하지 않은 테마에서 기존 관리자 기능이 유지되는가
- POST 폼에 `csrf_token`이 포함되어 있는가

## 12. 현재 제공되는 테마

### default

전체 Twig 화면과 기본 정적 파일을 제공하는 필수 테마입니다. 삭제하면 안 됩니다.

### modern

`default`의 나머지 화면 구조를 폴백으로 사용하고 공통 레이아웃, 홈, 게시판 목록 화면을 재정의합니다.

```text
templates/modern/layout.html.twig
templates/modern/home/index.html.twig
templates/modern/posts/index.html.twig
public/themes/modern/theme.css
```

게시글 보기·작성·로그인 같은 나머지 화면은 `default` 파일을 그대로 사용합니다. 필요한 화면만
같은 상대 경로로 추가하면서 테마를 점진적으로 확장할 수 있습니다.

### studio

`default`의 Twig 파일 38개를 모두 독립적으로 보유하는 완성형 테마입니다. 공개 화면과 관리자
화면 모두 `public/themes/studio/theme.css`의 디자인 시스템을 사용하므로, 기본 템플릿 변경에
영향받지 않고 전체 UI를 별도로 발전시킬 수 있습니다.

### daylight

default의 Twig 파일 38개를 모두 복제한 뒤 각 파일을 독립적으로 수정한 콘텐츠 탐색형 테마입니다.
공개 화면, 인증, 게시글, CMS, 오류, 관리자 화면이 모두 templates/daylight/ 안에 있으며
default 폴백 없이 public/themes/daylight/theme.css를 사용합니다.

### harbor

default의 전체 화면을 복제하고 SVG 아이콘 파일을 추가한 빌드 없는 컴포넌트 테마입니다.
Preline UI의 카드, 배지, 입력 그룹, 상태, 모바일 오버레이 패턴을 순수 Twig·CSS·JavaScript로
구현하며 공개 화면과 관리자 화면 모두 반응형 및 라이트·다크 모드를 지원합니다.

### codex-bloom

오늘의집의 탐색 중심 UI/UX를 모티브로 만든 DaisyUI 기반 완성형 테마입니다. `default`의 Twig
38개를 모두 같은 경로에 보유하며, SVG 아이콘 매크로를 더해 공개 화면·인증·게시글·CMS·오류·
관리자 화면을 독립적으로 구성합니다.

```text
templates/codex-bloom/                 default 전체 화면 + SVG 매크로
public/themes/codex-bloom/theme.css    라이트·다크 디자인 토큰과 반응형 컴포넌트
public/vendor/daisyui/daisyui.css      로컬에 고정한 DaisyUI CSS
```

DaisyUI CSS는 런타임 CDN 호출 없이 로컬에서 제공됩니다. composer, npm, Tailwind 컴파일러나
브라우저 컴파일러가 필요하지 않습니다. `data-theme="light|dark"`와 `aboard-theme` 저장값을
공유해 첫 페인트부터 색상 모드가 일치하며, 모바일 메뉴와 관리자 사이드바 접기도 지원합니다.

### compact

고밀도 클래식 포럼 스킨입니다. 기본 레이아웃을 확장하지 않고 자체 `layout.html.twig`와
`public/themes/compact/theme.css`로 공개 화면 전체를 정의합니다.

```text
templates/compact/layout.html.twig
templates/compact/home/index.html.twig
templates/compact/posts/index.html.twig
templates/compact/posts/show.html.twig
templates/compact/posts/_comments.html.twig
templates/compact/posts/create.html.twig
public/themes/compact/theme.css
```

로그인·가입·정적 페이지·오류 화면은 `default`의 Twig 를 그대로 쓰고 CSS 로만 다시 칠합니다.
관리자 화면은 `templates/default/admin/layout.html.twig`가 `@default/layout.html.twig`를
명시적으로 확장하므로 이 테마의 영향을 받지 않습니다.

### atlas

`default`를 대체할 목적으로 만든 완성형 템플릿입니다. `default`의 Twig 38개 파일을
같은 경로로 모두 새로 작성해 공개 화면과 관리 콘솔을 하나의 디자인 시스템으로 정의합니다.
빌드 도구 없이 순수 CSS와 바닐라 JavaScript만 사용합니다.

```text
templates/atlas/                 default 와 동일한 파일 구성(38개)
templates/atlas/layout.html.twig 공개 화면 셸. @default 를 확장하지 않는 독립 레이아웃
templates/atlas/admin/layout.html.twig  layout 의 chrome 블록만 교체한 관리 콘솔 셸
public/themes/atlas/theme.css    토큰부터 컴포넌트까지의 디자인 시스템 전부
```

사용할 수 있는 블록은 다음과 같습니다.

```text
title  meta_description  body_class  nav_section  chrome
site_header  body  site_footer  scripts  admin_section
```

`chrome`을 덮어쓰면 헤더·본문·푸터 배치를 통째로 바꿀 수 있습니다. 관리 콘솔이 이 방식으로
사이드바 셸을 만들며, 관리 화면 스크립트는 `chrome` 안에 있으므로 하위 템플릿이 `scripts`
블록을 자유롭게 쓸 수 있습니다.

폼 필드 이름과 `csrf_token`, 라우트 이름은 `default`와 같습니다. 다크 모드(`aboard-theme`)와
관리 사이드바 접힘(`aboard-admin-sidebar`)의 localStorage 키도 `default`와 공유합니다.

### cozy

오늘의집(ohou.se)의 UI/UX 패턴을 참고한 커뮤니티 스킨입니다. `default`의 Twig 38개 파일을
같은 경로로 모두 새로 작성했습니다. 브랜드 자산은 쓰지 않고 레이아웃 언어만 참고했으며,
화면 문구는 이 프로젝트에 맞게 새로 썼습니다.

```text
templates/cozy/                  default 와 동일한 파일 구성(38개)
templates/cozy/layout.html.twig  GNB·드로어·하단 탭바·푸터를 가진 공개 화면 셸
public/themes/cozy/theme.css     디자인 시스템 전부
```

`layout`이 추가로 제공하는 블록은 다음과 같습니다.

```text
header_search  GNB 가운데 라운드 검색바(게시판 맥락이 있는 화면에서만 채움)
extra_tabs     GNB 탭 줄에 현재 게시판을 활성 탭으로 추가
subnav         헤더 아래 sticky 분류 칩 줄
```

주요 패턴: 2단 GNB(로고 + 가운데 검색바 + 우측 액션 / 카테고리 탭), 스크롤 시 접히는 모바일
탭 줄, sticky 분류 칩, 커버가 있는 카드 피드와 가로 스크롤 캐러셀, 원형 글쓰기 플로팅 버튼,
모바일 하단 탭바, 토글 스위치와 라디오 칩.

### haus

오늘의집(ohou.se)의 레이아웃 모티브를 Preline UI 디자인 스펙으로 구현한 테마입니다.
`default`의 Twig 38개 파일을 같은 경로로 모두 새로 작성하고 아이콘 매크로를 추가했습니다.
composer·npm·컴파일 없이 정적 CSS 하나로 동작합니다.

```text
templates/haus/                   default 와 동일한 파일 구성(38개)
templates/haus/_icons.html.twig   아웃라인 SVG 아이콘 매크로 40종
public/themes/haus/theme.css      Preline 스펙을 옮긴 디자인 시스템
```

Preline 은 Tailwind 유틸리티 기반이라 빌드 없이는 쓸 수 없으므로, Preline 이 쓰는 색 스케일
(gray/neutral/sky/emerald/red/amber), radius(`rounded-lg`/`rounded-xl`/`rounded-full`),
shadow(`shadow-2xs`~`shadow-xl`), focus ring(2px + offset 2px), 컨테이너(`max-w-[85rem]`)를
손으로 옮겨 컴포넌트 CSS 로 재현했습니다.

다크 모드는 Preline 과 같은 class 전략입니다.

- `<html class="dark">` + neutral 팔레트, 색 토큰 전부를 다크에서 재정의
- 라이트 / 다크 / 시스템 3단 모드, 헤더 버튼 순환 + 모바일 세그먼트 컨트롤
- `<head>` 인라인 스크립트로 첫 페인트 전 결정(깜빡임 없음)
- 시스템 모드에서 OS 설정 변경을 새로고침 없이 반영
- JavaScript 가 꺼져 있어도 `prefers-color-scheme` 로 동작
- localStorage 키는 `aboard-theme` (`light` | `dark` | 없으면 시스템)

아이콘은 매크로로 씁니다.

```twig
{% import '_icons.html.twig' as ico %}
{{ ico.i('search') }}
{{ ico.i('home', 18) }}
```

### default (구 claude-daisy)

오늘의집(ohou.se)의 레이아웃 모티브를 daisyUI 컴포넌트 API 로 구현한 테마입니다.
`default`의 Twig 38개 파일을 같은 경로로 모두 새로 작성하고 아이콘 매크로를 추가했습니다.
composer·npm·컴파일 없이 정적 CSS 하나로 동작합니다.

```text
templates/default/                   default 와 동일한 파일 구성(38개)
templates/default/_icons.html.twig   아웃라인 SVG 아이콘 매크로 40종
public/themes/default/theme.css      daisyUI 테마 변수와 컴포넌트 전부
```

daisyUI 는 Tailwind 플러그인이라 빌드 없이는 설치할 수 없지만, API 가 시맨틱 클래스라
정적 CSS 로 재현할 수 있습니다. v5 의 테마 변수 이름(`--color-base-100`,
`--color-primary`, `--radius-box`, `--radius-field`, `--border` …)을 그대로 쓰고,
`btn` `card` `navbar` `drawer` `menu` `dropdown` `badge` `avatar` `alert` `table`
`tabs` `breadcrumbs` `join` `stats` `list` `input` `toggle` `checkbox` `fieldset`
`kbd` `divider` `hero` `carousel` `dock` 등을 구현했습니다. `drawer` 와 `dropdown` 은
daisyUI 처럼 체크박스·`:focus-within` 기반이라 JavaScript 없이 동작합니다.

다크 모드도 daisyUI 방식 그대로 `<html data-theme="dark">` 입니다.

- 라이트 / 다크 2단. 헤더의 해·달 아이콘 버튼을 눌러 바로 전환합니다
- 한 번도 누르지 않은 동안에는 `data-theme` 을 두지 않아 OS 설정을 따르고
  (daisyUI prefersdark), 한 번 누르면 그때부터 고른 값으로 고정됩니다
- `<head>` 인라인 스크립트로 첫 페인트 전 결정
- JavaScript 가 꺼져 있어도 시스템 설정대로 다크 적용
- daisyUI 색 토큰 20개를 dark 와 시스템 다크 양쪽에 정의
- 관리자 로그인 시 헤더·드로어·하단 탭바에 톱니 아이콘 관리 콘솔 링크 노출

## 13. 게시판 목록 형태 (list_type)

게시판마다 목록을 그리는 형태를 고를 수 있습니다. `default` 템플릿이 네 가지를 제공합니다.

| 값 | 이름 | 특징 |
|---|---|---|
| `list` | 목록형 | 고전적인 표. 정보 밀도가 가장 높다 |
| `gallery` | 갤러리형 | 큰 썸네일 격자. 사진 게시판용 |
| `magazine` | 매거진형 | 썸네일 + 발췌문을 나란히 |
| `news` | 뉴스형 | 사진 없이 제목과 발췌문 위주 |

### 어떻게 정해지나

1. **게시판 설정**이 기본값입니다 — 관리 콘솔 → 게시판 관리 → 설정 → 목록 형태
2. `?view=gallery` 처럼 **URL 로 잠시 바꿔** 볼 수 있습니다
3. 두 값 모두 `BoardService::LIST_TYPES` 허용 목록으로 검증합니다

> 형태 이름은 `posts/_list_{이름}.html.twig` 로 **그대로 파일 경로가 됩니다.**
> 허용 목록 밖의 값이 그 자리에 닿으면 템플릿 디렉터리 밖을 가리킬 수 있으므로,
> 새 형태를 추가할 때는 반드시 `LIST_TYPES` 에 먼저 등록하세요.

### 템플릿 구조

`posts/index.html.twig` 는 머리말·검색·형태 전환·페이지 이동만 그리고, 목록 본체는 파셜에 맡깁니다.

```twig
{% include 'posts/_list_' ~ view ~ '.html.twig' %}
```

```text
templates/default/posts/index.html.twig       공통 뼈대 + 디스패처
templates/default/posts/_list_list.html.twig      목록형
templates/default/posts/_list_gallery.html.twig   갤러리형
templates/default/posts/_list_magazine.html.twig  매거진형
templates/default/posts/_list_news.html.twig      뉴스형
templates/default/posts/_notices.html.twig        표가 아닌 형태의 상단 고정 공지
templates/default/posts/_thumb.html.twig          썸네일(없으면 대체 블록)
```

테마 폴백이 파셜 단위로 걸리므로, **테마는 바꾸고 싶은 형태만 재정의하면 됩니다.**
예를 들어 갤러리만 손보려면 `templates/{테마}/posts/_list_gallery.html.twig` 하나만 두면
나머지 세 형태는 `default` 것이 쓰입니다. 다만 그 테마가 `posts/index.html.twig` 를
자체적으로 갖고 있다면 디스패처를 직접 넣어야 파셜이 불립니다.

### 목록에 추가된 값

`PostService::summary()` 가 다음 두 가지를 더 내려줍니다.

| 이름 | 설명 |
|---|---|
| `excerpt` | 본문 앞 120자를 한 줄로 눌러 만든 발췌문. 없으면 `null` |
| `thumbnail_index` | 첫 이미지 첨부의 인덱스. 없으면 `null` |

**비밀글은 두 값 모두 `null`** 입니다. 목록에서 본문과 사진이 새는 것을 막습니다.

썸네일 주소는 템플릿에서 만듭니다.

```twig
{{ url_for('files.image', {'id': post.id, 'index': post.thumbnail_index}) }}
```

`files.image` 는 `files.download` 와 같은 권한·비밀글 검사를 거치고,
`image/jpeg·png·gif·webp` 만 `Content-Disposition: inline` 으로 내려줍니다.
그 밖의 첨부는 404 입니다 — 브라우저에서 실행될 여지를 없애기 위해서입니다.

### 홈도 같은 설정을 따른다

홈의 게시판 구역은 그 게시판의 `list_type` 을 그대로 씁니다. 설정 하나로 게시판 화면과
홈 표시가 함께 정해지므로, "사진 게시판만 사진으로" 같은 의도가 한 곳에서 관리됩니다.

| 설정 | 홈에서 |
|---|---|
| `gallery` | 커버 카드 가로 스크롤 |
| `magazine` | 작은 썸네일 + 발췌 |
| `news` | 사진 없이 제목 + 발췌 |
| `list` | 제목과 날짜만 (가장 조밀) |

```text
templates/default/home/_feed_gallery.html.twig
templates/default/home/_feed_magazine.html.twig
templates/default/home/_feed_news.html.twig
templates/default/home/_feed_list.html.twig
```

게시판 목록과 같은 방식이라, 테마는 바꾸고 싶은 형태의 파셜만 재정의하면 됩니다.

### 기존 설치 업그레이드

`boards.list_type` 컬럼이 새로 생겼습니다. `migrateAccounts()`·`migrateCms()` 와 같은 방식입니다.

```bash
php bin/migrate.php
```

여러 번 돌려도 안전하며, 컬럼이 없는 상태에서도 화면은 목록형으로 정상 동작합니다.

## 15. 글·댓글 본문 편집기

게시글과 댓글 본문은 HTML 을 허용합니다. 저장할 때 `HtmlSanitizer`(HTMLPurifier) 로 한 번,
출력할 때 `cms_html` 로 한 번 더 거릅니다. 평문으로 저장된 옛 글은 정화기가 문단과 줄바꿈으로
감싸므로 데이터를 손대지 않아도 그대로 보입니다.

```text
templates/default/posts/_editor.html.twig   글·댓글 공용 편집기 (CKEditor 4)
```

넘겨야 하는 값은 `editor_id`, `upload_url`, `discard_url`, `editor_mini` 입니다.
`editor_mini` 를 켜면 굵게·링크·이미지만 있는 최소 도구모음이 됩니다(댓글용).

### 이미지 업로드 권한

관리자 전용인 `admin.editor.images` 와 달리, 글·댓글은 **게시판 권한**으로 판단합니다.

| 라우트 | 검사 |
|---|---|
| `board.editor.images` | `assertCanWrite` (그 게시판 쓰기 권한) |
| `comment.editor.images` | `assertCanComment` (그 게시판 댓글 권한) |

저장하지 않고 떠나면 그때까지 올린 이미지를 정리하고, 저장할 때 본문에 남지 않은 이미지를
지웁니다. 이를 위해 `posts.image_key` 와 `comments.image_key` 컬럼을 씁니다.

### 댓글

댓글 쓰기는 `POST /posts/{id}/comments` 하나입니다. 검증에 실패하면 글 화면을 422 로 다시
그리면서 입력값과 오류를 보여 주고, 성공하면 `#comments` 로 돌려보냅니다.
비회원은 이름과 비밀번호를 함께 받고, 대댓글은 `parent_id`, 비밀 댓글은 `is_secret` 입니다.

### 배포할 때 (중요)

`Schema::create()` 는 테이블이 이미 있으면 아무것도 하지 않습니다. 그래서 기능이 늘며
추가된 컬럼은 배포 후 한 번 반영해 줘야 합니다.

```bash
php bin/migrate.php
```

여러 번 돌려도 안전합니다. 이걸 건너뛰면 `boards.list_type` 컬럼이 없어
**관리 화면에서 목록 형태를 저장해도 조용히 반영되지 않습니다.**

또 `src/` 와 `templates/` 는 **함께** 올려야 합니다. Twig 가 `strict_variables` 로
동작하기 때문에, 템플릿만 새로 올리면 `post.excerpt` 같은 값이 없어 화면이 오류가 됩니다.
(지금은 템플릿이 값이 없어도 비워 두고 넘어가도록 방어해 두었지만, 발췌문과 썸네일은
`src/` 를 함께 올려야 실제로 나옵니다.)


## 14. 기본 테마 교체 (classic ↔ default)

오늘의집/daisyUI 스킨을 `default` 로 승격하고, 원래 `default` 는 `classic` 으로 백업했습니다.

```text
templates/classic   예전 기본 화면 (백업)
templates/default   현재 기본 화면
```

`default` 는 모든 테마의 폴백이므로, 파일을 일부만 가진 테마는 없는 화면을 `default` 에서
가져옵니다. 그래서 승격 이후 **부분 테마는 새 마크업에 옛 CSS 가 얹혀 어긋날 수 있습니다.**

승격 시점에 파일이 38개 미만이던 테마:

```text
aurora(4)  compact(6)  modern(3)  nova(4)
```

이 테마들을 계속 쓰려면 필요한 화면을 채워 완전한 세트로 만들거나, 정리하는 편이 낫습니다.
완전한 세트를 가진 테마(atlas, haus, cozy, classic 등)는 영향이 없습니다.

`classic/admin/layout.html.twig` 는 `@default/layout.html.twig` 대신 `layout.html.twig` 를
확장하도록 바꿨습니다. `@default` 는 언제나 `templates/default` 를 가리키므로, 그대로 뒀다면
classic 이 새 기본 화면을 상속해 버립니다.

## 16. 알림함과 글·댓글 수정·삭제

### 16.1 알림함

회원에게만 쌓이는 사이트 안 알림입니다. 메일은 보내지 않습니다.

```text
src/Db/Schema.php                       notifications 표 + migrateNotifications()
src/Repository/NotificationRepository.php
src/Service/NotificationService.php     알림 만들기·세기·읽음 처리
src/Web/Controller/NotificationController.php
templates/default/notifications/index.html.twig
```

알림이 생기는 때는 두 가지뿐입니다.

| 종류 | 받는 사람 | 문구 |
| --- | --- | --- |
| `comment` | 글쓴이 | 내 글에 댓글을 달았습니다 |
| `reply` | 부모 댓글 작성자 | 내 댓글에 답글을 달았습니다 |

- 두 대상이 같은 사람이면 한 번만 보냅니다. 내가 쓴 댓글로 나에게는 알리지 않습니다.
- 비회원은 받을 사람을 특정할 수 없으므로 알림을 받지 못합니다. 다만 비회원이 **쓴** 댓글은
  회원 글쓴이에게 정상적으로 알림을 만듭니다.
- 알림 만들기가 실패해도 댓글 등록 자체는 막지 않습니다.
- 안 읽은 개수는 `SessionGuard` 가 Twig 전역 `unread_notifications` 로 넣어 줍니다.
  세지 못하는 상황(표가 아직 없을 때 등)에서는 0 으로 떨어져 배지가 사라질 뿐 화면은 멀쩡합니다.
- 글 삭제는 되돌릴 수 있는 소프트 삭제라 알림을 함께 지우지 않습니다.

경로:

```text
GET  /notifications              알림함
GET  /notifications/{id}         읽음 처리 후 해당 댓글로 이동 (#comment-{id})
POST /notifications/read-all     모두 읽음 (csrf 필요)
```

머리글에는 `.bell-link` 종 아이콘과 `.bell-dot` 배지가, 모바일 서랍에는 메뉴 항목이 붙습니다.

### 16.2 글·댓글 수정·삭제

```text
GET/POST /posts/{id}/edit        글 수정
POST     /posts/{id}/delete      글 삭제
GET/POST /comments/{id}/edit     댓글 수정
POST     /comments/{id}/delete   댓글 삭제
```

- 회원 글·댓글은 작성자와 관리자만, 비회원 것은 비밀번호를 아는 사람만 고칠 수 있습니다.
  화면은 `needs_password` 로 비밀번호 칸을 보일지 정하고, 판단은 서버가 다시 합니다.
- 비밀번호가 틀리면(401/403) 오류 화면 대신 수정 폼으로 돌아오며 422 와 함께 이유를 보여 줍니다.
- 비밀 댓글은 수정 화면으로도 새어 나가지 않습니다. `CommentService::getForEdit()` 가
  목록과 같은 기준(`maskSecrets`)으로 걸러 가려진 댓글은 404 로 답합니다.

### 16.3 답글 폼 이동

답글 버튼을 누르면 댓글 폼이 그 댓글 바로 아래로 옮겨 갑니다(`.comment-form.is-reply`).
CKEditor 는 iframe 이라 DOM 을 그냥 옮기면 내용이 날아가므로,
`posts/_editor.html.twig` 가 내어 주는 `window.aboardEditor['<textarea id>']` 를 씁니다.

```js
window.aboardEditor['comment-content'].remount(function(){ /* 여기서 폼을 옮긴다 */ });
window.aboardEditor['comment-content'].focus();
```

`remount()` 는 편집기를 끄고(내용은 textarea 로 되돌아갑니다) 콜백을 실행한 뒤 다시 켭니다.

### 16.4 스키마 판 올리기

`notifications` 표는 `Schema::VERSION` 을 `3` 으로 올려 배포합니다. `Kernel::create()` 가
부팅할 때 `ensureCurrent()` 로 판을 견주므로 마이그레이션 명령을 잊어도 스스로 맞춥니다.
표를 새로 더할 때는 `migrateCms()` 처럼 "표가 있는지 SELECT 해 보고 없으면 만든다" 꼴로 씁니다.

### 16.5 목록 썸네일

목록 썸네일은 세 단계로 고릅니다.

1. 이미지 첨부가 있으면 그 사진 (`post.thumbnail_index` → `files.image` 경로)
2. 없으면 본문에 넣은 첫 사진 (`post.thumbnail_url`)
3. 그것도 없으면 제목 첫 글자

2번은 **우리 편집기가 올린 사진만** 씁니다(`/media/editor/{키}/{파일}` 또는
`/media/editor/{연}/{월}/{파일}`). 본문에는 다른 사이트의 이미지 주소도 들어올 수 있는데,
그것을 목록에서 불러오면 방문자의 IP 와 참조 주소가 그 사이트로 새어 나갑니다.

비밀글은 `excerpt` 와 마찬가지로 `thumbnail_index`·`thumbnail_url` 이 모두 `null` 입니다.

## 17. 사진 크기 (원본 · 축소본)

원본을 그대로 내려보내면 글 한 편에 수 MB, 목록 한 화면에 수십 MB 가 오갑니다.
화면에 필요한 만큼 줄인 파일을 따로 만들어 두고, 원본은 눌렀을 때만 받아 갑니다.

```text
{32자리}.jpg          원본        눌렀을 때만
{32자리}-view.jpg     본문        960px
{32자리}-thumb.jpg    목록 카드   480px
```

- 이름 규칙은 `ContentImageService::VARIANTS` 한 곳에서 정합니다. 목록에 없는 이름은
  경로에서 걸러집니다. 아무 크기나 만들어 달라고 하면 그것만으로 서버가 버티지 못합니다.
- 축소본은 **처음 요청될 때 만들어 그 자리에 저장**합니다. 미리 만들지 않으므로
  올릴 때 느려지지 않고, 쓰이지 않는 크기는 아예 만들어지지 않습니다.
- 줄일 수 없는 그림(이미 작거나, 움직이는 GIF 이거나, GD 가 없는 곳)은 원본을 그대로 줍니다.
  화면이 깨지는 것보다 낫습니다.
- 원본을 지우면 축소본도 함께 지웁니다(`sync()` / `discard*()`).

### 17.1 본문

`cms_html` 필터는 이제 `ContentRenderer` 를 거칩니다. 정화한 뒤 편집기 사진을 이렇게 바꿉니다.

```html
<a class="zoom" href="원본" target="_blank" rel="noopener" data-zoom>
  <img src="…-view.jpg" loading="lazy" decoding="async">
</a>
```

**저장된 내용은 그대로입니다.** 바꾸는 일은 내보낼 때만 합니다. 그래서 수정 화면은
화면에 보이는 HTML 이 아니라 원래 내용을 따로 받아 편집기에 넣어야 합니다
(`<template data-source="{댓글번호}">`). 이걸 빠뜨리면 축소본 주소가 본문에 눌러앉습니다.

누르면 `layout.html.twig` 의 덮개(`.lens`)가 원본을 띄웁니다. 스크립트가 없으면
링크가 새 창으로 원본을 열어 주므로 기능은 남습니다.

### 17.2 첨부 이미지

첨부는 이름을 우리가 정하지 않으므로 같은 폴더에 `.{폭}-{원래이름}` 으로 둡니다.
점으로 시작하면 `AttachmentService::collectGarbage()` 가 건드리지 않습니다
(그쪽은 글에 연결되지 않은 파일을 지우는데, 축소본은 글이 직접 가리키지 않습니다).

```text
GET /posts/{id}/images/{index}          원본
GET /posts/{id}/images/{index}/thumb    목록 카드용
```

## 18. 메인(첫 화면)의 최신 글

### 18.1 순서

두 단계로 정해집니다.

1. **게시판 묶음의 차례** — `boards.sort_order` 오름차순, 같으면 `id` 오름차순.
   관리 화면의 "정렬 순서"가 그 값입니다. 작을수록 위로 옵니다.
2. **묶음 안의 글** — `posts.id` 내림차순, 즉 **최근에 쓴 글이 위**입니다.
   목록 화면과 달리 공지를 위로 끌어올리지 않고 쓴 차례 그대로 섞입니다.

읽을 수 없는 게시판(`perm_read`)은 묶음째 나오지 않습니다.

### 18.2 메인에서 빼기

게시판마다 **"메인에 낼 최신 글 수"(`boards.home_limit`)** 를 둡니다. `0` 으로 두면
그 게시판은 첫 화면에서 통째로 빠집니다. 게시판 자체는 그대로 열리고 메뉴에도 남습니다.

```text
관리 → 게시판 → (게시판) 수정 → 메인에 낼 최신 글 수
  0      첫 화면에 내지 않음
  1~10   그 수만큼 최신 글을 냄 (기본 5)
```

- `Schema::VERSION` 을 `4` 로 올려 배포합니다. 컬럼이 없던 설치는 부팅할 때
  `ensureCurrent()` 가 기본값 5로 채우므로 예전 동작이 그대로 이어집니다.
- 판단은 `BoardController::index()` 한 곳에서 합니다. 0 이면 그 게시판은 아예 건너뜁니다
  (빈 묶음을 그리지 않습니다).
