default 테마 — PHP 파일 템플릿
==============================

하늘빛 블루 + daisyUI 5 CDN 으로 꾸민 기본 테마다. 화면은 전부 `.php` 파일이고
템플릿 엔진 라이브러리는 쓰지 않는다. 새 테마는 이 폴더를 통째로 복사해서 시작한다.

테마의 표식: theme.php
----------------------
같은 폴더의 `theme.php` 가 `['label' => '…']` 를 돌려준다. 이 파일이 있어야 테마 목록에
오르고 고를 수 있다. 화면 없이 폴더만 있는 것(옛 테마 보관본 등)은 테마로 치지 않는다.

**테마 간 폴백은 없다.** `PhpView` 는 선택한 테마 폴더 한 곳만 본다. 그래서 테마는 화면
전부 갖는다. 화면을 새로 만들면 모든 테마에 같은 이름의 `.php` 를 만들어야 한다.

헬퍼 ($this)
------------
템플릿 파일은 `PhpTemplate` 의 메서드 안에서 include 된다. 전역 함수도 숨은 상태도 없고,
`$this` 가 헬퍼다. 전역(`site`, `current_user`, `csrf_token`, …)과 넘겨받은 데이터는
지역 변수로 풀린다. 이름이 겹치면 넘겨받은 데이터가 전역을 이긴다.

  $this->e($v)                       HTML 이스케이프. null 은 빈 문자열
  $this->layout('layout')            이 화면을 감쌀 레이아웃
  $this->start('body') … stop()      블록을 잡는다. 루트 레이아웃에서는 그 자리에 낸다
  $this->block('body', '')           잡힌 블록을 낸다. 없으면 기본값
  $this->has('header_search')        블록이 비어 있지 않은가 (공백만 있으면 빈 것)
  $this->def($v, '기본')             비었으면 기본값 (''·false·null·[] 이 '비었다'. "0" 과 0 은 아니다)
  $this->insert($t, $data=[], $only=false)  조각을 그려 낸다
  $this->fetch($t, $data=[], $only=false)   조각을 문자열로
  $this->exists('posts/_list_gallery')      조각이 있는가
  $this->url('posts.index', [..], [..])  라우트 주소 (이스케이프됨)
  $this->asset('theme.css')          테마 정적 파일 주소 (이스케이프됨)
  $this->html($content)              정화된 본문 HTML
  $this->icon('home', 18, 'cls')     _icons.php 의 아이콘 SVG (모르는 이름은 원)
  $this->date($v, 'Y.m.d')           날짜
  $this->json($v)                    <script> 안에 넣을 JSON
  $this->base                        기준 경로

`insert()`/`fetch()` 는 지금 화면의 변수(전역 + 컨트롤러가 넘긴 데이터)에 `$data` 를 덧붙여
조각에 물려준다. **루프 지역 변수나 템플릿 안에서 만든 변수는 자동으로 안 넘어간다** —
`['post' => $post]` 처럼 명시로 넘긴다. `$only = true` 면 전역과 `$data` 만 넘긴다.

레이아웃 동작
-------------
1. 화면 파일이 먼저 돈다. `layout()` 은 이름만 적어 두고, `start/stop` 이 블록을 잡는다.
   블록 밖의 출력은 버린다.
2. 화면이 끝나면 레이아웃을 같은 블록 저장소로 돈다. 레이아웃은 `block('body')` 로 자식
   블록을 낸다. 레이아웃이 또 `layout()` 을 부르면(관리 콘솔 → 공개 레이아웃) 한 번 더 감싼다.
3. 먼저 잡은(=자식) 블록이 이긴다. 루트 레이아웃의 `start('x') 기본값 stop()` 은 자식이 채웠으면
   그것을, 아니면 기본값을 그 자리에 낸다.

규칙: 출력은 전부 $this->e() 를 거친다
--------------------------------------
자동 이스케이프가 없다. 예외는 이미 안전한 것뿐이다 —
`html()`, `icon()`, `json()`, `insert()`, `block()`, `url()`, `asset()` 의 결과.
빈 값 검사는 문자열이면 `$x !== null && $x !== ''` 를 쓴다. `empty()` 는 "0" 을 비었다고 본다
(배열이면 `empty()` 로 족하다).

테스트
------
  ./vendor/bin/phpunit                       # 전체
  ./vendor/bin/phpunit --filter PhpViewTest  # 엔진만

특정 테마의 마크업을 단언하는 테스트는 `makeApp($db, [], '테마이름')` 처럼 테마를 못박는다.
`GNUCMS_TEST_THEME=이름` 으로 전체 스위트를 다른 테마로 돌릴 수 있다(못박은 테스트는 안 덮인다).

알려진 한계
-----------
- 레이아웃 둘이 서로를 `layout()` 으로 가리키면 예외를 던진다 (`레이아웃이 서로를 감쌉니다`).
- `stop()` 없이 끝난 `start()` 도 예외다. 조용히 삼키면 이후 화면이 통째로 빈 채 나가기 때문이다.
- `date()` 는 문자열/정수만 받는다. `DateTimeInterface` 를 넘기면 안 된다. `date(null)` 은 빈 문자열이다.
- 테마 간 폴백이 없다. 테마를 하나 더 만들면 그때 `ThemeManager::templatePaths()` 에 default 를
  뒤에 더해 폴백을 열 수 있다 (`PhpView` 는 경로 목록을 차례로 찾는다).
