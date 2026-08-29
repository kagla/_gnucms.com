claude-kurly 테마
=================

컬리(kurly.com/main)의 화면 언어를 옮긴 테마입니다. templates/default 를 통째로
복사해 시작했으므로 공개 화면, 인증, 알림, 오류, 글쓰기, 관리 콘솔까지 모든 파일을
스스로 가지고 있습니다. 브랜드 자산(로고·이름·이미지·문구)은 쓰지 않고 레이아웃
언어만 참고했습니다.

컴포넌트는 daisyUI 5 를 CDN 에서 받아 씁니다
---------------------------------------------
daisyUI 5 는 빌드 없이 그대로 <link> 할 수 있는 CSS 를 냅니다. Tailwind 도,
빌드 단계도 필요 없습니다. layout.html.twig 의 <head> 에 두 줄이 전부입니다.

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daisyui@5.7.22/daisyui.css">
  <link rel="stylesheet" href="{{ theme_asset('theme.css') }}">

- daisyUI 쪽에서 오는 것: 판(Tailwind preflight 리셋), btn · card · input ·
  select · textarea · toggle · checkbox · badge · alert · table · list · menu ·
  dropdown · drawer · dock · chat · stats · join · breadcrumbs · tabs · kbd ·
  divider · hero · footer · prose · fieldset · validator-hint 등 컴포넌트 전부,
  그리고 --color-* / --radius-* / --size-* 테마 변수 체계.
- theme.css 에서 하는 것: 컬리 팔레트·눈금으로 그 변수들을 덮어쓰고,
  머리글·전체 카테고리 줄·상품형 카드·바닥글처럼 daisyUI 에 없는 화면을 짓는 것.

버전은 5.7.22 로 못박았습니다. 올릴 때는 layout.html.twig 의 링크만 고치면 됩니다.

  ⚠ CDN 을 못 받는 곳(사내망·오프라인)에서는 컴포넌트 스타일이 빠집니다.
    화면 뼈대(폭·여백·격자·머리글)는 theme.css 가 들고 있어서 글은 계속 읽히지만
    모양은 무너집니다. 그런 환경이라면 daisyui.css 를 내려받아
    public/themes/claude-kurly/ 에 두고 링크를 theme_asset('daisyui.css') 로
    바꾸면 됩니다(파일 하나 복사가 전부, 빌드는 여전히 필요 없습니다).

CSS 레이어에 대해 꼭 알아야 할 것
---------------------------------
daisyUI 의 CSS 는 @layer base / daisyui / utilities 안에 들어 있습니다.
theme.css 는 레이어 밖이라 **특이도와 무관하게 항상 이깁니다.** 편하지만 위험합니다.

- a { color: inherit } 같은 요소 리셋을 theme.css 에 두면
  daisyUI 의 .btn { color: var(--btn-fg) } 까지 덮어서
  <a class="btn btn-primary"> 의 글자가 배경과 같은 색으로 묻힙니다.
  (실제로 그렇게 됐다가 고쳤습니다. 리셋은 daisyUI 것을 그대로 씁니다.)
- .btn / .badge 의 background·color 는 theme.css 에서 건드리지 않습니다.
  daisyUI 는 btn-primary 같은 변종을 --btn-color / --btn-fg 로 정하므로,
  background 를 직접 쓰면 변종이 지고 흰 바탕에 흰 글씨가 됩니다.
  모양(높이·모서리·굵기)만 손댑니다.
  예외는 색 변종이 아예 없는 자리뿐입니다(.chip.btn-active, 배너 위 단추).
- daisyUI 5 는 서랍을 transform 이 아니라 translate + opacity + visibility 로
  감춥니다. 관리 콘솔의 drawer-lg-open 은 그 셋을 모두 되돌려야 열립니다.

컬리에서 참고한 화면
--------------------
- 석 줄 머리글: 얇은 안내 줄 / 로고 + 주요 메뉴 + 검색 + 아이콘 / 전체 카테고리 줄
- 로고 옆에 붙는 주요 메뉴(현재 항목은 퍼플 밑줄)
- 오른쪽에 두고 돋보기를 칸 안에 넣은 검색창
- 왼쪽 "전체 카테고리"(서랍을 연다) + 오른쪽 안내 문구가 있는 GNB 바
- 넓은 퍼플 배너와 통계 상자
- 사각형에 가까운 카테고리 타일 줄
- 세로 4:5 사진의 상품형 카드 격자와 가로 스크롤 진열
- "지금 많이 보는 글" 순위 목록
- 알약 분류 칩과 각진 페이지 이동 단추
- 고객행복센터 블록이 있는 촘촘한 바닥글
- 모바일 하단 탭바(daisyUI dock)
- 관리 콘솔은 퍼플 인사말 띠 + 흰 카드 + 퍼플 활성 메뉴

색과 눈금
---------
- 주 색은 컬리 퍼플 #5f0080 입니다. 흰 바탕 대비 11.7:1 로 글자색으로 써도
  넉넉합니다. 큰 숫자·강조용으로 --sale(#fa622f) 를 따로 두었고, 작은 글씨에는
  더 어두운 --sale-ink 를 씁니다.
- 모서리는 얕습니다(--radius-box .375rem, --radius-field .1875rem,
  --radius-selector .125rem). 컬리는 거의 각진 화면입니다.
  다만 토글·상태점처럼 조작하는 부품은 따로 둥글게 두었습니다. 각진 토글은
  무엇인지 알아보기 어렵기 때문입니다.
- 본문 폭은 1050px 입니다. 컬리처럼 좁고 또렷하게 씁니다.
- 본문 글꼴은 Pretendard 를 CDN 에서 받고, 못 받으면 시스템 한글 글꼴로
  조용히 떨어집니다(레이아웃은 그대로).

다크 모드
---------
daisyUI 방식 그대로 <html data-theme="dark"> 를 씁니다.

- 라이트 / 다크 2단. 머리글의 해·달 아이콘으로 전환합니다.
- 한 번도 누르지 않은 첫 방문에는 data-theme 을 두지 않아 OS 설정을 따릅니다.
- <head> 인라인 스크립트가 첫 페인트 전에 정하므로 깜빡임이 없습니다.
- JavaScript 가 꺼져 있어도 시스템 설정대로 다크가 적용됩니다.
- daisyUI 색 토큰 20개를 라이트·다크·시스템 다크 세 곳 모두에 정의했습니다.

구성
----
templates/claude-kurly/_icons.html.twig       아이콘 매크로 (기본 40종 + 장바구니·
                                              배송·쿠폰·위치·격자·하트·별 등 9종 추가)
templates/claude-kurly/layout.html.twig       공개 화면 셸 (topbar + navbar + GNB
                                              + drawer + dock + 컬리형 바닥글)
templates/claude-kurly/home/index.html.twig   배너·카테고리 타일·인기 순위·진열·안내
templates/claude-kurly/boards/index.html.twig 홈과 같은 구성(게시판 모음 주소)
templates/claude-kurly/posts/index.html.twig  칩 필터·글 수·목록 형태 전환
templates/claude-kurly/admin/layout.html.twig 관리 콘솔 셸 (drawer + lg 고정)
public/themes/claude-kurly/theme.css          컬리 팔레트와 전용 화면 (약 1,290줄)

블록 구조
---------
title / meta_description / body_class / nav_section / chrome / site_header /
header_search / extra_tabs / subnav / body / site_footer / scripts / admin_section

header_search 는 layout 이 기본값을 가집니다. board 변수가 있는 화면(글 보기·
쓰기·고치기)은 자동으로 그 게시판을 검색하고, home 과 posts/index 는 자기 것으로
덮어씁니다. extra_tabs 는 컬리 머리글의 주요 메뉴(head-nav) 안으로 들어갑니다.

유지 사항
---------
- 폼 필드 이름과 csrf_token, 라우트 이름은 default 와 동일합니다.
- 다크 모드는 gnucms-theme, 관리 사이드바 접힘은 gnucms-admin-sidebar 키를
  default 와 공유합니다.
- admin/_editor.html.twig 와 posts/_editor.html.twig 는 default 와 동일합니다
  (CKEditor 연동). CKEditor 4 는 자체 스킨이라 다크 모드만 따로 맞췄습니다.
- layout 의 서랍 메뉴는 boards|default([]) 를 씁니다. 게시판 목록이 문맥에 있는
  화면(홈)에서만 게시판이 함께 나오고, 다른 화면에서는 조용히 비어 있습니다.

확인한 것
---------
- templates/claude-kurly 의 twig 55개 전부가 컴파일됩니다.
- 손님·관리자 화면 41개 주소를 실제로 렌더링해 200/404 를 확인했습니다.
- 폭 485px 과 1425px 에서 가로 스크롤이 생기지 않습니다.
- 헤드리스 브라우저로 라이트·다크, 모바일 서랍, 관리 콘솔까지 눈으로 확인했습니다.
