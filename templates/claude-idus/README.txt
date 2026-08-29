claude-idus 테마
================

아이디어스(idus.com/v2)의 탐색형 마켓 UI를 모티브로 삼아 gnucms.com 화면 전체를
다시 짠 테마입니다. templates/default 를 통째로 복사해 시작했으므로 공개 화면,
인증, 알림, 오류, 글쓰기, 관리 콘솔까지 모든 파일을 스스로 가지고 있습니다.

브랜드 자산(로고·이름·이미지·문구)은 쓰지 않고 레이아웃 언어만 참고했습니다.
composer / npm / 컴파일 없이 정적 CSS 파일 하나로 동작합니다.

테마 선택
---------
관리 콘솔 → 사이트 설정 → 템플릿에서 "claude-idus" 를 고릅니다.
되돌리려면 같은 자리에서 "default" 를 고르면 됩니다.

daisyUI 를 어떻게 옮겼나
------------------------
daisyUI 는 Tailwind 플러그인이라 빌드 없이는 설치할 수 없지만, API 가 시맨틱
클래스 이름이라 정적 CSS 로 그대로 재현할 수 있습니다. 마크업은 daisyUI 클래스
그대로이므로, 나중에 빌드 환경이 생기면 진짜 daisyUI 로 바꿔 끼울 수 있습니다.

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
- .btn 계열의 배경은 반드시 --btn-bg 로 덮어야 합니다. background 를 직접 쓰면
  btn-active / hover 가 이기지 못해 선택된 칩이 흰 바탕에 흰 글씨가 됩니다.

아이디어스에서 참고한 UX
------------------------
- 얇은 상단 안내 줄(약관·로그인) + 2단 머리글
- 머리글 가운데의 크고 둥근 검색창. 게시판 문맥이 있으면 그 게시판을 검색하고,
  홈에서는 첫 번째 게시판을 기본 대상으로 삼습니다.
- 왼쪽에 "전체" 단추가 붙은 가로 스크롤 카테고리 GNB
- 게시판을 원형 아이콘으로 늘어놓은 카테고리 줄(게시판마다 아이콘·색이 다름)
- 큰 그러데이션 배너와 통계 카드
- "지금 많이 보는 글" 순위 목록 (홈에 실려 온 최신 글의 조회수로 매김)
- 1:1 비율 커버의 작품 카드 격자와 가로 스크롤 캐러셀(scroll-snap)
- 알약 모양 분류 칩 필터와 동그란 페이지 이동 단추
- 우하단 원형 글쓰기 플로팅 버튼, 모바일 하단 탭바(daisyUI dock)
- 홈 아래의 "이렇게 즐겨보세요" 안내 블록
- 관리 콘솔은 아이디어스의 "작가 스튜디오" 결로, 인사말 띠 + 통계 카드 +
  주황색 활성 메뉴

색과 글꼴
---------
- 주 색은 손으로 만든 것의 주황 #d63f00 입니다. 흰 바탕에서 4.5:1 을 넘겨서
  글자색으로 써도 AA 를 만족합니다. 배너처럼 글자를 얹지 않는 자리에는
  --brand-vivid(#ff6b2c) 를 씁니다.
- 무채색은 차가운 회색 대신 따뜻한 종이색(#faf8f6 / #231f1d)입니다.
- 본문 글꼴은 Pretendard 를 CDN 에서 받아 쓰고, 못 받으면 시스템 한글 글꼴로
  조용히 떨어집니다(레이아웃은 그대로).

다크 모드
---------
daisyUI 방식 그대로 <html data-theme="dark"> 를 씁니다.

- 라이트 / 다크 2단. 머리글의 해·달 아이콘 버튼으로 전환합니다.
  모바일 드로어에도 같은 토글이 있습니다.
- 한 번도 누르지 않은 첫 방문에는 data-theme 을 두지 않아 OS 설정을 따릅니다.
- <head> 인라인 스크립트가 첫 페인트 전에 정하므로 깜빡임이 없습니다.
- JavaScript 가 꺼져 있어도 시스템 설정대로 다크가 적용됩니다.
- 다크에서도 색이 차가워지지 않게 20개 토큰 전부를 따뜻한 쪽으로 다시 잡았습니다.

구성
----
templates/claude-idus/_icons.html.twig        아이콘 매크로 (기본 40종 + 하트·별·
                                              격자·선물·팔레트·불꽃·가린눈 등 8종 추가)
templates/claude-idus/layout.html.twig        공개 화면 셸 (topbar + navbar + GNB
                                              + drawer + dock)
templates/claude-idus/home/index.html.twig    배너·카테고리 원형·인기 순위·피드·안내
templates/claude-idus/boards/index.html.twig  홈과 같은 구성(게시판 모음 주소)
templates/claude-idus/posts/index.html.twig   칩 필터·글 수·목록 형태 전환
templates/claude-idus/admin/layout.html.twig  관리 콘솔 셸 (drawer + lg:drawer-open)
public/themes/claude-idus/theme.css           테마 변수와 컴포넌트 전부 (약 1,540줄)

아이콘 쓰는 법
--------------
  {% import '_icons.html.twig' as ico %}
  {{ ico.i('search') }}          기본 20px
  {{ ico.i('palette', 22) }}     크기 지정

블록 구조
---------
title / meta_description / body_class / nav_section / chrome / site_header /
header_search / extra_tabs / subnav / body / site_footer / scripts / admin_section

로그인 화면의 비밀번호 칸에는 눈 아이콘(보기/숨기기) 단추가 있습니다. 스크립트가
켜졌을 때만 나타나므로, 꺼져 있으면 단추 없이 평소대로 가려진 칸만 남습니다.
같은 단추를 회원가입·비밀번호 재설정에도 쓰려면 login.html.twig 의 .pw-toggle
마크업과 scripts 블록을 그대로 옮기면 됩니다(CSS 와 아이콘은 이미 공용입니다).

header_search 는 layout 이 기본값을 가집니다. board 변수가 있는 화면(글 보기·
쓰기·고치기)은 자동으로 그 게시판을 검색하고, home 과 posts/index 는 자기 것으로
덮어씁니다.

관리 콘솔 머리글 폭
-------------------
띠(배경·아래 선)는 화면 끝까지 가되, 안쪽 내용은 본문(.admin-body)과 같은 폭에서
멈춥니다. 그렇지 않으면 넓은 화면에서 "사이트 보기"가 본문과 한참 떨어져 오른쪽
끝으로 날아갑니다. .admin-navbar-inner 의 max-width 는 .admin-body 와 같은 값을
써야 하므로, 본문 폭을 바꾸면 여기도 같이 바꿔야 합니다(지금은 둘 다 68rem).

  잰 값 — 뷰포트 1440px 과 2100px 모두
      제목 왼쪽 292 / 본문 카드 왼쪽 292
      아이콘 오른쪽 1324 / 본문 카드 오른쪽 1324
      띠 자체는 화면 끝까지 (1425 / 2085)

로그인 화면의 링크 줄
---------------------
"비밀번호 찾기"는 비밀번호 라벨 옆이 아니라 카드 맨 아래, 회원가입 오른쪽에
가는 세로선으로 나뉘어 놓입니다.

      아직 회원이 아니신가요? 회원가입 | 비밀번호 찾기

이 줄의 링크는 밑줄 없이 색과 굵기로만 구분하고 가리켰을 때만 밑줄이 켜집니다.
(회원가입만 .link, 비밀번호 찾기는 .link-hover 라 둘이 달라 보이던 것을 맞췄습니다.)

유지 사항
---------
- 폼 필드 이름과 csrf_token, 라우트 이름은 default 와 동일합니다.
- 다크 모드는 gnucms-theme, 관리 사이드바 접힘은 gnucms-admin-sidebar 키를
  default 와 공유합니다.
- admin/_editor.html.twig 와 posts/_editor.html.twig 는 default 와 동일합니다
  (CKEditor 연동).
- layout 의 드로어 메뉴는 boards|default([]) 를 씁니다. 게시판 목록이 문맥에
  있는 화면(홈)에서만 게시판이 함께 나오고, 다른 화면에서는 조용히 비어 있습니다.

확인한 것
---------
- templates/claude-idus 의 twig 55개 전부가 컴파일됩니다.
- 손님·관리자 화면 41개 주소를 실제로 렌더링해 200/404 를 확인했습니다.
  (홈, 게시판 4형태, 검색·분류, 글 보기·쓰기·고치기, 댓글 수정, 내용, 약관,
   로그인·가입·비밀번호, 상태, 오류, 관리 콘솔 전체)
- 폭 485px 과 1425px 에서 가로 스크롤이 생기지 않는 것을 확인했습니다.
