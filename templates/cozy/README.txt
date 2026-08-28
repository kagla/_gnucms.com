cozy 테마
=========

오늘의집(ohou.se)의 UI/UX 패턴을 참고해 만든 커뮤니티 스킨입니다.
default 의 38개 Twig 파일을 같은 경로로 모두 새로 작성했습니다.
브랜드 자산(로고·이름·이미지·문구)은 쓰지 않고 레이아웃 언어만 참고했으며,
화면 안의 문구는 이 프로젝트에 맞게 새로 썼습니다.
빌드 도구(composer, npm)는 쓰지 않고 순수 CSS 와 바닐라 JS 로만 동작합니다.

테마 선택
---------
관리 콘솔 → 사이트 설정 → 템플릿에서 "cozy"를 고릅니다.

참고한 UI/UX 패턴
-----------------
- 흰 배경 + 시안 포인트 컬러, 8~16px 라운드, 얇은 회색 구분선
- 상단 GNB 2단: 로고 + 가운데 라운드 검색바 + 우측 액션 / 아래 카테고리 탭
- 스크롤을 내리면 탭 줄이 접히는 모바일 헤더
- sticky 분류 칩 탭(선택 시 진한 채움)
- 정사각형에 가까운 커버가 있는 카드 피드, 가로 스크롤 캐러셀
- 카드 하단의 프로필·닉네임·조회/댓글 아이콘 카운트
- 우하단 원형 글쓰기 플로팅 버튼
- 모바일 하단 탭바(홈 / 메뉴 / 로그인·내 계정)
- 토글 스위치, 라운드 라디오 칩, 체크 애니메이션

구성
----
templates/cozy/layout.html.twig        공개 화면 셸(GNB·드로어·하단 탭바·푸터)
templates/cozy/admin/layout.html.twig  layout 의 chrome 블록만 교체한 관리 콘솔 셸
public/themes/cozy/theme.css           토큰부터 컴포넌트까지의 디자인 시스템 전부

블록 구조
---------
title / meta_description / body_class / nav_section / chrome / site_header /
header_search / extra_tabs / subnav / body / site_footer / scripts / admin_section

- header_search: GNB 가운데 검색바. 게시판 맥락이 있는 화면에서만 채웁니다.
- extra_tabs: GNB 탭 줄에 현재 게시판을 활성 탭으로 추가합니다.
- subnav: 헤더 바로 아래 sticky 분류 칩 줄.
- chrome: 헤더·본문·푸터 배치를 통째로 교체(관리 콘솔이 사용).

유지 사항
---------
- 폼 필드 이름과 csrf_token, 라우트 이름은 default 와 동일합니다.
- 다크 모드는 localStorage 의 gnucms-theme,
  관리 사이드바 접힘은 gnucms-admin-sidebar 키를 default 와 공유합니다.
- admin/_editor.html.twig 는 default 와 동일합니다(CKEditor 연동).
- 오늘의집에는 다크 모드가 없지만, 이 프로젝트의 테마 토글 계약을 지키려고
  같은 색 언어로 다크 팔레트를 따로 설계했습니다.
