nova 테마
=========

잡지 마스트헤드처럼 사이트 이름을 크게 두고, 따뜻한 종이색과 잉크색, 테라코타 강조색만 쓰는 편집형 스킨입니다. 보라색·그라데이션 히어로·햄버거 메뉴는 쓰지 않습니다.

테마 선택
---------
관리 콘솔 → 사이트 설정 → 템플릿에서 "nova"를 고르거나, DB site_settings 테이블의 theme 값을 nova로 저장합니다.

  UPDATE site_settings SET value = 'nova' WHERE `key` = 'theme';

다음 요청부터 공개 화면에 적용됩니다. 폴더가 없거나 이름이 잘못되면 default로 돌아갑니다.

추가한 파일
-----------
templates/nova/layout.html.twig          @default/layout를 확장. body_class는 theme-nova.
templates/nova/home/index.html.twig      홈 — 목차형 게시판·최신글 목록.
templates/nova/posts/index.html.twig     게시글 목록 — 모바일 우선 스택 리스트.
templates/nova/posts/show.html.twig      게시글 보기. 댓글은 default의 _comments를 포함.
templates/nova/README.txt                이 파일.
public/themes/nova/theme.css             .theme-nova 전용 스타일(라이트/다크).

로그인·글쓰기·관리자 화면은 파일을 넣지 않았습니다. default Twig를 그대로 쓰고 CSS만 다시 칠합니다. 관리자는 @default/layout을 쓰므로 nova 헤더의 영향을 받지 않습니다.
