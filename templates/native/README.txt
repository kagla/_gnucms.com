native 테마 — PHP 파일 템플릿
=============================

Twig `default` 테마(하늘빛, daisyUI CDN)를 Twig 없이 PHP 파일만으로 옮긴 테마다.
디자인은 한 글자도 바꾸지 않았다. 같은 데이터를 주면 HTML 이 `default` 와 같아야 한다.

엔진 고르기
-----------
같은 폴더의 `theme.php` 가 `['engine' => 'php', ...]` 를 돌려준다. `Kernel` 은 그것을 보고
Twig 대신 `PhpView` 를 세운다. `theme.php` 가 없는 테마는 지금까지처럼 Twig 다.

**엔진 간 폴백은 없다.** Twig 테마는 파일이 없으면 `templates/default/` 로 떨어지지만
`PhpView` 는 `templates/native/` 한 경로만 본다. 그래서 이 테마는 화면 58개를 전부 갖는다.
화면을 새로 만들면 여기에도 같은 이름의 `.php` 를 만들어야 한다.

헬퍼 ($this)
------------
템플릿 파일은 `PhpTemplate` 의 메서드 안에서 include 된다. 전역 함수도 숨은 상태도 없고,
`$this` 가 헬퍼다. 전역(`site`, `current_user`, `csrf_token`, …)과 넘겨받은 데이터는
지역 변수로 풀린다. 이름이 겹치면 넘겨받은 데이터가 전역을 이긴다.

  $this->e($v)                       HTML 이스케이프. null 은 빈 문자열   (Twig 자동 이스케이프)
  $this->layout('layout')            이 화면을 감쌀 레이아웃              ({% extends %})
  $this->start('body') … stop()      블록을 잡는다                        ({% block %})
  $this->block('body', '')           잡힌 블록을 낸다. 없으면 기본값      ({{ block('body') }})
  $this->has('header_search')        블록이 비어 있지 않은가              (block('x')|trim is not empty)
  $this->insert('posts/_meta', [..]) 조각을 그려 낸다                     ({% include %})
  $this->fetch('posts/_meta', [..])  조각을 문자열로                      ({% set %}{% include %}{% endset %})
  $this->url('posts.index', [..], [..])  라우트 주소                      (url_for())
  $this->asset('theme.css')          테마 정적 파일 주소                  (theme_asset())
  $this->html($content)              정화된 본문 HTML                     (|cms_html)
  $this->icon('home', 18, 'cls')     아이콘 SVG                           (ico.i())
  $this->date($v, 'Y.m.d')           날짜                                 (|date)
  $this->json($v)                    <script> 안에 넣을 JSON              (|json_encode)
  $this->base                        기준 경로                            (base_path)

규칙: 출력은 전부 $this->e() 를 거친다
--------------------------------------
Twig 와 달리 자동 이스케이프가 없다. 예외는 이미 안전한 것뿐이다 —
`html()`, `icon()`, `json()`, `insert()`, `block()`, `url()`, `asset()` 의 결과.
사람이 이 규칙을 지켰는지는 파리티 테스트가 잡는다. Twig 가 이스케이프한 자리를 여기서
빠뜨리면 HTML 이 달라지기 때문이다.

Twig 를 옮길 때 주의할 것
-------------------------
- `x is empty` / `x is not empty` 는 문자열 "0" 을 비었다고 보지 않는다. PHP 의 `empty()` 는
  "0" 을 비었다고 본다. 문자열에는 `$x !== null && $x !== ''` 를 쓴다. 배열이면 `empty()` 로 족하다.
  (`if x` 같은 맨 진리성 검사는 Twig 도 PHP 와 같아서 `!empty()` 가 맞다.)
- 인라인 `<script>`·`<style>` 은 Twig 판과 글자 단위로 같아야 한다. 특히 `&nbsp;`·`&times;`
  같은 문자 참조를 실수로 진짜 유니코드 문자로 쓰기 쉽다. `cat -A` 로 확인한다.

파리티 테스트 돌리는 법
-----------------------
41개 경로(손님 20 + 관리 21)를 `default` 와 `native` 로 그려 HTML 을 견준다.

  ./vendor/bin/phpunit --filter ThemeParityTest      # 기대: OK (41 tests)

비교 전에 정규화하는 것은 다섯 가지뿐이다: 줄 끝 공백과 빈 줄, 태그 사이 공백,
`theme.css?v=` 해시와 `/themes/{이름}/`, `image_key` 난수, 그리고 테마 선택 `<select>` 에서
하니스가 심는 `default`/`native` `<option>` 의 `selected`. 그 밖의 차이는 전부 결함이다.
차이가 나면 정규화를 늘리지 말고 이쪽 템플릿을 고친다.

전체 스위트를 이 테마로 한 번 더 돌릴 수도 있다.

  GNUCMS_TEST_THEME=native ./vendor/bin/phpunit

특정 테마의 마크업을 단언하는 테스트는 `makeApp($db, [], 'codex-preline')` 처럼 테마를
못박는다. 못박은 테스트는 이 환경변수가 덮지 않는다.

알려진 한계
-----------
- `PhpTemplate::run()` 에 순환 방지가 없다. 레이아웃 둘이 서로를 `layout()` 으로 가리키면
  무한 재귀로 죽는다. 지금 58개 화면에는 그런 짝이 없어 두었다.
- `PhpTemplate::date()` 는 문자열/정수만 받는다. `DateTimeInterface` 를 넘기면 안 된다.
  지금은 컨트롤러가 전부 문자열로 넘겨 문제가 없다.
- 테마 하나만 본다(엔진 간 폴백 없음, 위 참고). PHP 테마를 하나 더 만들면 그때 폴백 경로를
  `PhpView` 에 들여야 한다.
