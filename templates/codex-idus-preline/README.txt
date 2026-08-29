codex-idus-preline 테마
=======================

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
포함합니다. 정적 스타일은 public/themes/codex-idus-preline/theme.css 한 파일로 동작하며 별도
npm 빌드가 필요 없습니다.

빌드 없는 Preline UI
--------------------

Preline UI는 원래 Tailwind CSS, npm 패키지 설치와 빌드 과정을 전제로 합니다. 이 서버는
그 과정을 실행할 수 없으므로 Tailwind가 생성할 표현 계층을 theme.css에 정적으로 고정하고,
저장소에 포함된 공식 Preline JavaScript를 로컬에서 불러옵니다. CDN에 의존하지 않습니다.

- Preline 테마 토큰: --primary, --background, --foreground, --card, --navbar, --sidebar 등
- 공식 런타임: public/vendor/preline/preline.js
- 공식 data/class API: hs-dropdown, hs-dropdown-toggle, hs-dropdown-menu
- 정적 표현 계층: 버튼, 카드, 배지, 폼, 표, 알림, 내비게이션과 관리자 셸
- 무 JavaScript 폴백: 체크박스 기반 모바일 드로어와 CSS 포커스 드롭다운
- 다크 모드: html의 data-theme="light|dark" 속성과 시스템 설정 지원

따라서 composer install, npm install, Tailwind 스캔, CSS 빌드 또는 배포 후 컴파일이
전혀 필요하지 않습니다. 기존 GNUCMS Twig의 클래스와 폼 필드도 그대로 유지합니다.

적용
----

관리 콘솔 → 사이트 설정 → 템플릿에서 codex-idus-preline를 선택하고 저장합니다.

테마 파일
---------

templates/codex-idus-preline/                 화면별 Twig 템플릿
public/themes/codex-idus-preline/theme.css    공통 토큰, 컴포넌트, 반응형 및 관리자 스타일
public/vendor/preline/preline.js              저장소에 포함된 Preline 인터랙션 런타임

유지보수
--------

- URL은 url_for(), 정적 파일은 theme_asset()을 사용합니다.
- POST 폼의 csrf_token 및 기존 필드 이름을 유지해야 합니다.
- 새 화면을 추가할 때는 기존 시맨틱 컴포넌트 클래스(card, btn, input, table 등)를
  재사용하면 공개 화면과 관리자 화면 양쪽에 같은 스타일이 적용됩니다.
