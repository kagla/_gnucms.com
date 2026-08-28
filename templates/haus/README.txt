haus 테마
=========

오늘의집(ohou.se)의 레이아웃/UX 모티브를 Preline UI 의 디자인 스펙으로 구현한 테마입니다.
default 의 38개 Twig 파일을 같은 경로로 모두 새로 작성했고 아이콘 매크로 하나를 추가했습니다.
브랜드 자산(로고·이름·이미지·문구)은 쓰지 않고 레이아웃 언어만 참고했습니다.

composer / npm / 컴파일 없이 정적 CSS 파일 하나로 동작합니다.

테마 선택
---------
관리 콘솔 → 사이트 설정 → 템플릿에서 "haus"를 고릅니다.

Preline UI 를 어떻게 옮겼나
---------------------------
Preline 은 Tailwind 유틸리티 기반이라 빌드 없이는 쓸 수 없습니다. 그래서 Preline 이 쓰는
디자인 스펙을 손으로 옮겨 정적 CSS 로 재현했습니다.

- 색: Tailwind 스케일 그대로 (gray-50~900 / neutral-50~900 / sky / emerald / red / amber)
- 라운드: rounded-lg(.5rem) 버튼·입력, rounded-xl(1rem) 카드, rounded-full 배지·칩
- 그림자: shadow-2xs / xs / sm / md / lg / xl
- 포커스: focus ring 2px + offset 2px (Preline 의 focus:ring-2 ring-offset-2)
- 컨테이너: max-w-[85rem] + px-4 sm:px-6 lg:px-8
- 컴포넌트: button(solid/white/ghost/soft/danger), input, select, textarea,
  checkbox, toggle switch, badge, card(header/body/footer), alert, table,
  breadcrumb, pagination, offcanvas, sidebar, stat card, segmented control
- 다크: Preline 과 같은 class 전략 — <html class="dark"> + neutral 팔레트

다크 모드
---------
- 3단 모드: 라이트 / 다크 / 시스템. 헤더 버튼을 누르면 순환하고,
  모바일 메뉴 안에는 세그먼트 컨트롤이 있습니다.
- <head> 인라인 스크립트가 첫 페인트 전에 클래스를 결정해 깜빡임이 없습니다.
- 시스템 모드에서는 OS 설정이 바뀌면 새로고침 없이 즉시 따라갑니다.
- JavaScript 가 꺼져 있어도 prefers-color-scheme 로 다크가 적용됩니다.
- 색 토큰 전부(표면·텍스트·테두리·상태·그림자·아바타 톤)를 다크에서 재정의했습니다.
- 명암비는 라이트/다크 모두 본문 AAA, 링크·버튼·상태색 AA 이상입니다.
- localStorage 키는 default 와 같은 gnucms-theme (light | dark | 없으면 시스템)입니다.
  CKEditor 는 data-theme 속성 변화를 감시하므로 세 모드 모두에서 함께 바뀝니다.

오늘의집에서 참고한 UX
----------------------
- 2단 GNB: 로고 + 가운데 라운드 검색바 + 우측 액션 / 아래 카테고리 탭
- 스크롤을 내리면 탭 줄이 접히는 모바일 헤더
- sticky 분류 칩 필터
- 커버가 있는 카드 피드와 가로 스크롤 캐러셀(scroll-snap)
- 게시판 바로가기 아이콘 줄
- 우하단 원형 글쓰기 플로팅 버튼
- 모바일 하단 탭바(홈 / 메뉴 / 로그인·내 계정)

구성
----
templates/haus/_icons.html.twig        아이콘 매크로 (40종 아웃라인 SVG)
templates/haus/layout.html.twig        공개 화면 셸
templates/haus/admin/layout.html.twig  chrome 블록만 교체한 관리 콘솔 셸
public/themes/haus/theme.css           디자인 시스템 전부

아이콘 쓰는 법
--------------
  {% import '_icons.html.twig' as ico %}
  {{ ico.i('search') }}          기본 20px
  {{ ico.i('home', 18) }}        크기 지정
  {{ ico.i('cog', 18, 'my-cls') }}

블록 구조
---------
title / meta_description / body_class / nav_section / chrome / site_header /
header_search / extra_tabs / subnav / body / site_footer / scripts / admin_section

유지 사항
---------
- 폼 필드 이름과 csrf_token, 라우트 이름은 default 와 동일합니다.
- 관리 사이드바 접힘은 gnucms-admin-sidebar 키를 default 와 공유합니다.
- admin/_editor.html.twig 는 default 와 동일합니다(CKEditor 연동).
