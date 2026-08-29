codex-preline 테마
====================

default 템플릿을 독립적으로 복사한 뒤 Preline UI의 레이아웃과 컴포넌트
패턴으로 다시 디자인한 gnucms 기본 테마입니다.

- 공개 홈, 게시판 목록/본문/작성/수정, 댓글
- 내용 페이지와 미리보기
- 로그인, 가입, 인증, 비밀번호 재설정, 소셜 로그인
- 알림과 오류/상태 화면
- 관리자 대시보드, 게시판/내용/회원/메일/약관/보안 설정

모든 화면이 같은 색상 토큰, 카드, 버튼, 입력 폼, 표, 배지와 내비게이션을
공유합니다. 모바일에서는 드로어와 하단 내비게이션, 관리자 카드형 표를 사용합니다.

빌드 없는 Preline
------------------

composer, npm, Tailwind CDN, 컴파일러를 사용하지 않습니다.

- `public/themes/codex-preline/theme.css`: Preline 스타일을 정적 CSS로 구현
- `public/vendor/preline/preline.js`: 저장소에 포함된 Preline 인터랙션 사용
- `templates/codex-preline/layout.html.twig`: Preline dropdown 초기화 및 공통 셸

다크 모드
---------

첫 방문에는 운영체제 설정을 따릅니다. 화면의 테마 버튼을 누르면 선택한 라이트/다크
모드를 로컬 저장소에 보관합니다. 첫 페인트 전에 모드를 적용해 화면 깜빡임을 줄이고,
JavaScript가 꺼진 환경에서도 `prefers-color-scheme`으로 다크 모드를 지원합니다.

원본과 호환성
-------------

폼 필드, CSRF 토큰, 라우트 이름, Twig 블록과 CKEditor 연동은 default 테마와
동일합니다. 따라서 서버 기능을 바꾸지 않고 디자인만 전환할 수 있습니다.
