default 테마
============

오늘의집(ohou.se)의 레이아웃/UX 모티브를 daisyUI 컴포넌트 API 로 구현한 테마입니다.
원래 default 를 classic 으로 백업하고 이 테마를 default 로 승격했습니다.
모든 테마는 없는 파일을 default 에서 가져오므로, 여기가 사이트의 기준입니다.
브랜드 자산(로고·이름·이미지·문구)은 쓰지 않고 레이아웃 언어만 참고했습니다.

composer / npm / 컴파일 없이 정적 CSS 파일 하나로 동작합니다.

테마 선택
---------
기본값이라 따로 고르지 않아도 적용됩니다.
옛 화면으로 돌아가려면 관리 콘솔 → 사이트 설정 → 템플릿에서 "classic" 을 고릅니다.

daisyUI 를 어떻게 옮겼나
------------------------
daisyUI 는 Tailwind 플러그인이라 빌드 없이는 설치할 수 없지만, API 가 시맨틱
클래스 이름이라 정적 CSS 로 그대로 재현할 수 있습니다.

- 테마 변수: daisyUI v5 이름 체계를 그대로 사용
  --color-base-100/200/300, --color-base-content,
  --color-primary/secondary/accent/neutral (+ -content),
  --color-info/success/warning/error (+ -content),
  --radius-box / --radius-field / --radius-selector, --border, --size-field
- 구현한 컴포넌트
  btn(primary/outline/ghost/error/active/disabled/sm/lg/block/circle/square)
  navbar(start/center/end), drawer(toggle/content/side/overlay/button/lg-open),
  menu(title/active), dropdown(end/content), card(body/title/actions/compact),
  badge(primary/ghost/success/error/soft/sm/xs), avatar(placeholder),
  alert(success/info/error/soft), table(zebra), tabs(border/tab-active),
  breadcrumbs, join(item/block), stats(stat/figure/title/value/desc/vertical),
  list(row), input/textarea/select(bordered/block), toggle, checkbox,
  fieldset(legend/label), validator-hint, kbd, divider, hero, footer,
  carousel(item), dock(label/active), status, prose
- drawer 와 dropdown 은 daisyUI 처럼 체크박스·:focus-within 기반이라
  JavaScript 없이 열리고 닫힙니다.

다크 모드
---------
daisyUI 방식 그대로 <html data-theme="dark"> 를 씁니다.
이 프로젝트가 원래 data-theme 을 쓰고 있어 CKEditor 연동까지 그대로 맞물립니다.

- 라이트 / 다크 2단. 헤더의 해·달 아이콘 버튼을 누르면 바로 전환됩니다.
  모바일 드로어에도 같은 토글 버튼이 있습니다(현재 테마 이름을 함께 표시).
- 아이콘은 지금 적용된 테마를 보여 주고, aria-label 과 title 은
  "다크 모드로 전환"처럼 누르면 무엇이 되는지 알려 줍니다.
- 한 번도 누르지 않은 첫 방문에는 data-theme 을 두지 않아 OS 설정을 따르고
  (daisyUI 의 prefersdark), 그동안은 OS 설정 변경도 즉시 반영합니다.
  아이콘을 한 번 누르면 그때부터 고른 값으로 고정됩니다.
- <head> 인라인 스크립트가 첫 페인트 전에 정하므로 깜빡임이 없습니다.
- JavaScript 가 꺼져 있어도 시스템 설정대로 다크가 적용됩니다.
- daisyUI 색 토큰 20개 전부를 dark 와 시스템 다크 양쪽에 정의했습니다.
- 명암비: 본문 AAA, 본문 파생 3단계·primary·success·error·info 모두 AA 이상.

오늘의집에서 참고한 UX
----------------------
- 2단 헤더: 로고 + 가운데 라운드 검색바 + 우측 액션 / 아래 카테고리 탭
- 스크롤을 내리면 탭 줄이 접히는 모바일 헤더
- sticky 분류 칩 필터
- 커버가 있는 카드 피드와 가로 스크롤 캐러셀(scroll-snap)
- 게시판 바로가기 아이콘 줄
- 우하단 원형 글쓰기 플로팅 버튼
- 모바일 하단 탭바(daisyUI dock)
- 관리자로 로그인하면 헤더·드로어·하단 탭바 세 곳 모두에 톱니 아이콘이 붙은
  관리 콘솔 바로가기가 나옵니다.

구성
----
templates/default/_icons.html.twig        아이콘 매크로 (40종 아웃라인 SVG)
templates/default/layout.html.twig        공개 화면 셸 (drawer + navbar + dock)
templates/default/admin/layout.html.twig  chrome 블록만 교체한 관리 콘솔
                                               (daisyUI drawer + lg:drawer-open)
public/themes/default/theme.css           테마 변수와 컴포넌트 전부

아이콘 쓰는 법
--------------
  {% import '_icons.html.twig' as ico %}
  {{ ico.i('search') }}          기본 20px
  {{ ico.i('home', 18) }}        크기 지정

블록 구조
---------
title / meta_description / body_class / nav_section / chrome / site_header /
header_search / extra_tabs / subnav / body / site_footer / scripts / admin_section

유지 사항
---------
- 폼 필드 이름과 csrf_token, 라우트 이름은 default 와 동일합니다.
- 다크 모드는 aboard-theme, 관리 사이드바 접힘은 aboard-admin-sidebar 키를
  default 와 공유합니다.
- admin/_editor.html.twig 는 default 와 동일합니다(CKEditor 연동).
