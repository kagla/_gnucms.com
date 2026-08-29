codex-idus 테마
================

아이디어스의 발견형 마켓 UI에서 영감을 받아 GNUCMS 커뮤니티에 맞게 재구성한 테마입니다.
브랜드 자산이나 화면을 복제하지 않고 다음 디자인 언어를 적용했습니다.

- 따뜻한 오렌지 포인트 컬러와 크림 계열 프로모션 영역
- 유틸리티 바, 넓은 검색창, 카테고리형 게시판 탐색
- 이미지와 제목을 중심으로 한 콘텐츠 카드 및 피드
- 작은 화면의 드로어·하단 독과 큰 터치 영역
- 짙은 브라운 사이드바를 사용한 Creator Studio 관리자 화면
- 라이트/다크/시스템 모드와 키보드·접근성 상태

구성
----

templates/default의 56개 Twig 파일을 모두 복제해 공개 홈, 게시판, 게시글, 댓글,
글 작성·수정, 인증, 알림, 일반 내용, 오류, 상태 확인, 관리자 페이지 전체를 독립적으로
포함합니다. 정적 스타일은 public/themes/codex-idus/theme.css 한 파일로 동작하며 별도
npm 빌드가 필요 없습니다.

DaisyUI 호환 방식
-----------------

DaisyUI는 원래 Tailwind 플러그인이므로 npm과 빌드 과정 없이 배포할 수 없습니다.
이 테마는 외부 CDN이나 런타임 JavaScript에 의존하지 않고 DaisyUI v5의 공개된 UI 규칙을
정적 CSS로 구현했습니다.

- 테마 토큰: --color-base-100/200/300, --color-primary, --radius-* 등
- 컴포넌트 API: btn, card, badge, input, textarea, select, alert, table, stats
- 상호작용 API: drawer, dropdown, menu, tabs, toggle, checkbox, dock
- 수식자: btn-primary, btn-ghost, btn-outline, btn-sm/lg, card-compact 등
- 다크 모드: html의 data-theme="light|dark" 속성과 시스템 설정 지원

따라서 Twig에서는 DaisyUI와 같은 시맨틱 클래스 조합을 사용하면서도 composer install,
npm install, Tailwind 스캔, CSS 빌드 또는 배포 후 컴파일이 전혀 필요하지 않습니다.

적용
----

관리 콘솔 → 사이트 설정 → 템플릿에서 codex-idus를 선택하고 저장합니다.

테마 파일
---------

templates/codex-idus/                 화면별 Twig 템플릿
public/themes/codex-idus/theme.css    공통 토큰, 컴포넌트, 반응형 및 관리자 스타일

유지보수
--------

- URL은 url_for(), 정적 파일은 theme_asset()을 사용합니다.
- POST 폼의 csrf_token 및 기존 필드 이름을 유지해야 합니다.
- 새 화면을 추가할 때는 기존 시맨틱 컴포넌트 클래스(card, btn, input, table 등)를
  재사용하면 공개 화면과 관리자 화면 양쪽에 같은 스타일이 적용됩니다.
