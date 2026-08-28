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
