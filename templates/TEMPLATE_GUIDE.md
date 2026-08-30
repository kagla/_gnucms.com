# 템플릿 구조 안내

전체 개발 방법은 [`docs/template-development.md`](../docs/template-development.md)를 참고하세요.

화면은 **PHP 파일 템플릿**으로 그립니다. 템플릿 엔진 라이브러리는 없습니다.

- `default/` 가 기본 테마입니다. 새 설치는 이 테마로 시작합니다.
- 테마 폴더에는 `theme.php` 가 있어야 합니다. 이 파일이 있어야 테마 목록에 오르고 고를 수 있습니다.
  화면 파일 없이 폴더만 있는 것(옛 테마 보관본 등)은 테마로 치지 않습니다.
- 테마 이름은 영문 소문자, 숫자, `_`, `-` 만 쓸 수 있습니다.
- 현재 선택값은 DB 의 `site_settings` 표에서 `theme` 키로 저장됩니다.

```php
<?php
// templates/default/theme.php
return ['label' => '기본 (하늘빛)'];
```

## 화면 파일

컨트롤러는 확장자 없는 논리 이름(`'posts/index'`)만 넘기고, `PhpView` 가 `.php` 를 붙여
선택한 테마 폴더에서 찾습니다. **테마 간 폴백은 없습니다** — 테마는 화면 전부를 갖춰야
합니다. 새 테마는 `default/` 를 통째로 복사해서 시작하세요.

템플릿 파일은 `PhpTemplate` 의 메서드 안에서 include 되므로 `$this` 가 헬퍼입니다.

| 헬퍼 | 하는 일 |
|---|---|
| `$this->e($v)` | HTML 이스케이프. **출력은 전부 이걸 거칩니다** |
| `$this->layout('layout')` | 이 화면을 감쌀 레이아웃 |
| `$this->start('body')` … `$this->stop()` | 블록을 잡습니다 |
| `$this->block('body', '')` | 잡힌 블록을 냅니다 |
| `$this->insert('posts/_meta', ['post' => $post])` | 조각을 그려 냅니다 (`$only = true` 면 전역만 물려줌) |
| `$this->exists('posts/_list_gallery')` | 조각이 있는지 |
| `$this->url('posts.index', ['key' => $k], ['q' => $q])` | 라우트 주소 (이스케이프됨) |
| `$this->asset('theme.css')` | 테마 정적 파일 주소 (이스케이프됨) |
| `$this->html($content)` | 정화된 본문 HTML |
| `$this->icon('home', 18)` | `_icons.php` 의 아이콘 SVG |
| `$this->date($v, 'Y.m.d')`, `$this->json($v)`, `$this->def($v, $기본값)` | 날짜 · JSON · 비었으면 기본값 |

정적 파일은 `public/themes/{테마 이름}/` 에 두고 `$this->asset()` 으로 연결합니다.
선택한 테마에 해당 파일이 없으면 `public/themes/default/` 의 파일 주소가 쓰입니다.

```php
<link rel="stylesheet" href="<?= $this->asset('theme.css') ?>">
```

자세한 헬퍼 규칙과 알려진 한계는 [`default/README.txt`](default/README.txt) 를 보세요.
