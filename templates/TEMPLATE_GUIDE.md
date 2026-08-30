# 템플릿 구조 안내

전체 개발 방법은 [`docs/template-development.md`](../docs/template-development.md)를 참고하세요.

- `default/`는 항상 존재하는 기본 템플릿입니다.
- 선택한 템플릿 폴더에 요청한 Twig 파일이 없으면 `default/`의 같은 경로를 사용합니다.
- 템플릿 이름은 영문 소문자, 숫자, `_`, `-`만 사용할 수 있습니다.

예를 들어 `modern` 템플릿에서 게시판 목록만 바꾸려면 다음 파일만 만들면 됩니다.

```text
templates/modern/posts/index.html.twig
```

기본 레이아웃을 확장하려면 명시적인 기본 템플릿 네임스페이스를 사용할 수 있습니다.

```twig
{% extends "@default/layout.html.twig" %}
```

정적 파일은 `public/themes/{템플릿 이름}/`에 두고 Twig에서 `theme_asset()`으로 연결합니다.
선택한 템플릿에 해당 파일이 없으면 `public/themes/default/`의 파일 주소가 사용됩니다.

```twig
<link rel="stylesheet" href="{{ theme_asset('theme.css') }}">
```

현재 선택값은 DB의 `site_settings` 테이블에서 `theme` 키로 저장됩니다.

## PHP 테마는 `theme.php` 로 엔진을 고른다

테마 폴더에 `theme.php` 가 있고 그 파일이 `['engine' => 'php', ...]` 를 돌려주면,
그 테마는 Twig 가 아니라 PHP 파일 템플릿으로 그립니다. `theme.php` 가 없으면 지금까지처럼
Twig 테마입니다.

```php
<?php
// templates/native/theme.php
return ['engine' => 'php', 'label' => 'PHP 네이티브 (하늘빛)'];
```

PHP 테마의 화면 파일은 `.html.twig` 가 아니라 `.php` 입니다(`posts/index.php`).
컨트롤러는 확장자 없는 논리 이름(`'posts/index'`)만 넘기고, 확장자는 엔진이 붙입니다.

- **엔진 간 폴백은 없습니다.** Twig 테마는 파일이 없으면 `default/` 로 떨어지지만,
  PHP 테마는 자기 폴더 하나만 봅니다. 그래서 PHP 테마는 화면 58개를 전부 갖춰야 합니다.
- Twig 의 `{% extends %}`·`{% block %}`·`{% include %}` 자리에는 `$this->layout()`·
  `$this->start()`/`$this->stop()`·`$this->insert()` 를 씁니다.
- 자동 이스케이프가 없으므로 **출력은 전부 `$this->e()`** 를 거쳐야 합니다.

지금 있는 PHP 테마는 `native/` 하나이며, Twig `default/` 테마를 화면 그대로 옮긴 것입니다.
자세한 헬퍼 표와 규칙은 [`native/README.txt`](native/README.txt) 를 보세요.
