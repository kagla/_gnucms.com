atlas 테마
==========

default 를 대체할 목적으로 만든 완성형 템플릿입니다. default 의 38개 Twig 파일을
같은 경로로 모두 새로 작성했고, 공개 화면과 관리 콘솔을 하나의 디자인 시스템으로
정의합니다. 빌드 도구(composer, npm)는 쓰지 않고 순수 CSS 와 바닐라 JS 만 씁니다.

테마 선택
---------
관리 콘솔 → 사이트 설정 → 템플릿에서 "atlas"를 고릅니다.
직접 바꾸려면 DB site_settings 의 theme 값을 atlas 로 저장합니다.

구성
----
templates/atlas/layout.html.twig        공개 화면 셸(헤더·드로어·푸터·맨 위로).
                                        @default 를 확장하지 않는 독립 레이아웃.
templates/atlas/admin/layout.html.twig  layout 의 chrome 블록만 교체한 관리 콘솔 셸.
templates/atlas/…                       홈·게시판·글·인증·내용·오류 등 나머지 화면.
public/themes/atlas/theme.css           토큰부터 컴포넌트까지의 디자인 시스템 전부.

블록 구조
---------
title / meta_description / body_class / nav_section / chrome / site_header /
body / site_footer / scripts / admin_section

- chrome 을 덮어쓰면 헤더·본문·푸터 배치를 통째로 바꿀 수 있습니다.
  관리 콘솔이 이 방식으로 사이드바 셸을 만듭니다.
- 관리 화면 스크립트는 chrome 안에 들어 있으므로 하위 템플릿이 scripts 블록을
  자유롭게 쓸 수 있습니다(예: admin/page_form 의 CKEditor).

유지 사항
---------
- 폼 필드 이름과 csrf_token, 라우트 이름은 default 와 동일합니다.
- 다크 모드 값은 localStorage 의 aboard-theme,
  관리 사이드바 접힘은 aboard-admin-sidebar 키를 default 와 공유합니다.
- admin/_editor.html.twig 는 default 와 동일합니다(CKEditor 연동).
