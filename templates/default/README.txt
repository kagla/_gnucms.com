default 테마 (claude-sky)
================

맑은 하늘빛 블루로 꾸민 커뮤니티 테마입니다.
화면 구조(카테고리 원형 줄, 세로 커버 카드, 순위 목록, 관리 콘솔)는
아이디어스(idus.com/v2)의 탐색형 레이아웃에서 가져왔고, 색은 오늘의집 계열의
차가운 흰 바탕 + 하늘빛 파랑으로 다시 잡았습니다. 브랜드 자산(로고·이름·이미지·
문구)은 쓰지 않고 배치 언어만 참고했습니다.

컴포넌트는 daisyUI 5 를 CDN 에서 그대로 받아 씁니다.

    claude-idus   같은 배치를 주황으로 쓰고, daisyUI 를 손으로 옮긴 정적 CSS 판.
    default       이 테마(옛 이름 claude-sky). 하늘빛 블루 + daisyUI 5 CDN.
    basic         옛 기본 테마. 중립 daisyUI.

둘 중 무엇을 고를까
-------------------
- 바깥 네트워크가 막혀 있거나, 의존을 하나도 두고 싶지 않다 → claude-idus (주황)
- daisyUI 를 진짜로 쓰고 싶고 앞으로 버전을 올려 가며 쓰겠다 → default (하늘빛)

daisyUI 를 CDN 으로 쓰는 법
---------------------------
daisyUI 5 는 빌드 없이 그대로 <link> 할 수 있는 CSS 를 냅니다.
Tailwind 도, play CDN 스크립트도, npm 도 필요 없습니다.
layout.html.twig 의 <head> 에 두 줄이 전부입니다.

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daisyui@5.7.22/daisyui.css">
  <link rel="stylesheet" href="{{ theme_asset('theme.css') }}">

- daisyUI 쪽에서 오는 것: 판(Tailwind preflight 리셋), btn · card · badge ·
  alert · divider · kbd · status · input · select · textarea · toggle ·
  checkbox · fieldset · validator-hint · table · list · menu · dropdown ·
  drawer · dock · chat · stats · join · breadcrumbs · tabs · carousel ·
  hero · footer · avatar · prose, 그리고 --color-* / --radius-* 변수 체계.
- theme.css 에서 하는 것: 아이디어스 팔레트로 그 변수들을 덮어쓰고, 모양만
  아이디어스로 손보고, daisyUI 에 없는 화면(머리글 · GNB · 작품 카드 ·
  순위 목록 · 관리 콘솔 · CKEditor)을 짓는 것.

버전은 5.7.22 로 못박았습니다. 올릴 때는 layout.html.twig 의 링크만 고칩니다.

  ⚠ CDN 을 못 받는 곳(사내망·오프라인)에서는 컴포넌트 스타일이 빠집니다.
    화면 뼈대(폭·여백·격자·머리글)는 theme.css 가 들고 있어 글은 계속 읽히지만
    모양은 무너집니다. 그런 환경이라면 둘 중 하나를 고르세요.
      1) claude-idus 를 씁니다(같은 디자인, 바깥 의존 없음).
      2) daisyui.css 를 내려받아 public/themes/default/ 에 두고
         링크를 theme_asset('daisyui.css') 로 바꿉니다.
         파일 하나 복사가 전부이고, 빌드는 여전히 필요 없습니다.

CSS 레이어 — 이 테마에서 가장 중요한 것
---------------------------------------
daisyUI 의 CSS 는 @layer base / daisyui / utilities 안에 들어 있습니다.
theme.css 는 레이어 밖이라 **특이도와 무관하게 언제나 이깁니다.**
편하지만 위험합니다. 이 테마는 두 가지를 지킵니다.

1) a { color: inherit } 같은 요소 리셋을 두지 않는다.
   그 한 줄이 daisyUI 의 .btn { color: var(--btn-fg) } 까지 덮어
   <a class="btn btn-primary"> 의 글자가 배경과 같은 색으로 묻힙니다.
   리셋은 daisyUI 것(@layer base)을 그대로 씁니다.
2) .btn / .badge 의 background · color 를 직접 쓰지 않는다.
   daisyUI 는 변종을 --btn-color / --badge-color 로 정하므로,
   직접 칠하면 변종이 지고 흰 바탕에 흰 글씨가 됩니다. 모양만 손댑니다.
   예외는 색 변종이 없는 자리뿐입니다(.chip.btn-active, .view-switch .btn-active,
   .pager .join-item, .feed-head .btn, .badge-ghost).

만들면서 실제로 밟은 지뢰 세 개
-------------------------------
같은 화면을 두 방식으로 만들어 견주면서 찾은 것들입니다. 남겨 둡니다.

- daisyUI 의 .navbar-start / .navbar-end 는 width:50% 다.
  폭을 되돌리지 않으면 둘이 자리를 다 먹어 가운데 검색칸이 0px 이 된다.
  → .navbar-start, .navbar-end 에 width:auto 를 준다.
- daisyUI 5 는 서랍을 transform 이 아니라
  translate + opacity + visibility + width 로 감춘다.
  관리 콘솔의 drawer-lg-open 은 그 넷을 모두 되돌려야 사이드바가 보인다.
- daisyUI 의 .badge-ghost 는 base-200 이라, 같은 base-200 인 분류 줄 위에서
  사라진다. --bc-10 농도로 맞춰 준다.

아이디어스에서 참고한 UX
------------------------
- 2단 머리글: 로고 + 오른쪽 아이콘 줄 / 카테고리 GNB
- 검색은 머리글에서 돋보기 하나로 줄이고, 누르면 창이 내려옵니다
  (아래 "머리글 정리" 참고)
- 왼쪽에 "전체" 단추가 붙은 가로 스크롤 카테고리 GNB
- 게시판을 원형 아이콘으로 늘어놓은 카테고리 줄
- 큰 그러데이션 배너와 통계 카드
- "지금 많이 보는 글" 순위 목록
- 1:1 비율 커버의 작품 카드 격자와 가로 스크롤 캐러셀
- 알약 모양 분류 칩과 동그란 페이지 이동 단추
- 우하단 원형 글쓰기 플로팅 버튼, 모바일 하단 탭바(daisyUI dock)
- 관리 콘솔은 인사말 띠 + 통계 카드 + 주황색 활성 메뉴

색
--
오늘의집 계열의 맑은 블루입니다. 차가운 흰 바탕 위에 파랑 하나로 끌고 갑니다.

    주 색        #0b6fc4   흰 바탕 대비 5.1:1 — 글자색으로 써도 AA 를 넘는다
    장식 원색    #35c5f0   배너·리본처럼 글자를 얹지 않는 자리에만
    바탕         #ffffff / #f6f8fa   (차가운 흰색)
    본문         #1c2531
    다크         바탕 #101720 · 주 색 #74c4f0 · 본문 #dde5ee

밝은 하늘색(#35c5f0)은 흰 바탕에서 대비가 1.7:1 밖에 안 나오므로 글자·단추에는
쓰지 않습니다. 그 자리는 언제나 --color-primary 가 맡습니다.

아바타·카드 커버의 톤은 파랑을 축으로 청록·인디고·진한 하늘을 돌려 쓰고,
자주와 호박을 한 점씩 섞어 목록이 단조로워지지 않게 했습니다.

글꼴 · 다크 모드
----------------
- 모서리는 넉넉하게
- 본문 글꼴은 웹폰트 없이 시스템 한글 글꼴 (아래 "본문 글꼴" 참고)
- 다크 모드는 <html data-theme="dark">. 첫 방문에는 OS 설정을 따르고,
  아이콘을 한 번 누르면 그때부터 고른 값으로 고정됩니다.

머리글 정리
-----------
- **폭 맞춤** — 머리글 각 줄이 본문과 같은 왼쪽·오른쪽 끝에서 시작하고 끝납니다.
  daisyUI 의 .navbar{padding:.5rem} 을 지우려고 padding:0 을 썼더니 .wrap 의
  좌우 여백까지 날아가서 로고 줄만 본문보다 --pad 만큼 튀어나왔었습니다.
  padding-block:0 으로 바꿔 위아래 여백만 지우고 좌우는 .wrap 에 맡깁니다.
      로고 89 / 전체칩 89 / 본문 89,  오른쪽 끝 아이콘 1337 / 본문 1337 (1440px 기준)

- **맨 윗줄(안내 줄) 제거** — 이용약관·개인정보 처리방침·관리 콘솔·로그아웃이
  따로 한 줄을 차지하고 있었습니다. 지우고 오른쪽 아이콘 줄로 합쳤습니다.
      돋보기 · 알림(종) · 관리(톱니, 소유자만) · 프로필 · 테마
  약관과 로그아웃은 프로필 아이콘의 메뉴 안으로 들어갔고, 약관은 바닥글에도
  그대로 있습니다. 모바일에서는 아래 탭바(홈·카테고리·알림·관리)가 같은 일을 합니다.

- **검색은 돋보기 + 창** — 머리글을 넓게 차지하던 검색칸을 아이콘 하나로 줄였습니다.
  누르면 위에서 검색창이 내려옵니다. 서랍과 같은 체크박스 방식이라
  JavaScript 가 꺼져 있어도 열리고 닫힙니다. 스크립트는 초점·스크롤 잠금·Esc·
  "/" 단축키만 거듭니다.
      "/" 를 누르면 창이 열리면서 입력칸에 초점이 갑니다. Esc 로 닫습니다.
  검색은 게시판 단위라, 게시판 문맥이 있는 화면은 그 게시판을, 홈에서는 첫 번째
  게시판을 대상으로 삼습니다. 검색할 대상이 없는 화면(로그인 등)에서는
  돋보기와 창이 아예 나오지 않습니다.

본문 글꼴 — 웹폰트를 쓰지 않는다
--------------------------------
이 테마는 웹폰트를 받지 않습니다. 기기에 있는 글꼴만 씁니다.

    --font: "Pretendard Variable", Pretendard,          <- 설치돼 있으면 쓴다
            -apple-system, BlinkMacSystemFont,
            "Apple SD Gothic Neo",                      <- macOS / iOS
            "Malgun Gothic",                            <- 윈도우
            "Noto Sans KR", "Noto Sans CJK KR", "Nanum Gothic",
            system-ui, Roboto, "Segoe UI", sans-serif

왜 이렇게 했나
  처음에는 Pretendard 를 CDN 에서 받았는데, 한글 웹폰트는 용량이 커서
  "첫 화면부터 반드시 그 글꼴로" 를 보장할 방법이 사실상 없습니다.
  그래서 어느 쪽으로 설정하든 눈에 띄는 문제가 하나씩 남았습니다.

    font-display: swap      늦게 도착하면 대체 글꼴로 먼저 그렸다가 바꿔치기한다.
                            글자 폭이 한 번에 달라져 화면이 밀린다(FOUT / CLS).
    font-display: optional  한 화면 안에서는 안 바뀌지만, 판단을 페이지마다 하므로
                            캐시가 없을 때(첫 방문·강제 새로고침·개발자도구의
                            "캐시 사용 안 함")와 있을 때가 서로 다른 글꼴로 나온다.

  실제로 잰 값 (1440px, 홈 제목 기준)
      Pretendard    592.0px
      대체 글꼴     654.3px      → 한 번에 10% 넘게 달라진다

  시스템 글꼴만 쓰면 받아 올 것이 없으니 두 문제가 함께 사라집니다.
  캐시 상태와 무관하게 언제나 같은 화면이고, CDN 의존도 하나 줄어듭니다.
  Pretendard 가 설치된 기기에서는 목록 맨 앞이라 그대로 쓰입니다(네트워크 없이).

  헤드리스 브라우저로 확인한 결과 — 다섯 경우 모두 같은 값
      첫 방문 / 로고 눌러 이동 / 새로고침 / 캐시 무시 / 다시 이동   전부 703.8px

  다시 웹폰트를 쓰고 싶다면 layout.html.twig 의 <head> 에 링크 한 줄을 넣고
  --font 는 그대로 두면 됩니다. 위의 맞바꿈을 감수한다는 뜻입니다.

로그인 비밀번호 보기 단추
-------------------------
비밀번호 칸에 눈 아이콘 단추가 있습니다. 스크립트가 켜졌을 때만 나타나므로,
꺼져 있으면 단추 없이 평소대로 가려진 칸만 남습니다. 회원가입·비밀번호
재설정에도 쓰려면 login.html.twig 의 .pw-toggle 마크업과 scripts 블록을
그대로 옮기면 됩니다(CSS 와 아이콘은 이미 공용입니다).

브라우저 자동완성
-----------------
크롬은 안쪽 <input> 에만 제 색을 칠합니다. .input 은 아이콘과 눈 단추까지
감싼 상자라서 안쪽만 칠해지면 두 색으로 갈라져 보입니다. theme.css 가
배경을 상자 색으로 덮고 글자색만 살립니다.

구성
----
templates/default/layout.html.twig   daisyUI CDN 링크 + 공개 화면 셸
templates/claude-sky/_icons.html.twig   아이콘 매크로 (기본 40종 + 8종 추가)
templates/claude-sky/home/index.html.twig  배너·카테고리·순위·피드·안내
templates/claude-sky/posts/index.html.twig 칩 필터·글 수·목록 형태 전환
templates/claude-sky/admin/layout.html.twig 관리 콘솔 셸
public/themes/default/theme.css           하늘빛 팔레트와 전용 화면 (약 1,580줄)

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
- CKEditor 4 는 자체 스킨이라 다크 모드만 따로 맞췄습니다.

확인한 것
---------
- twig 55개 전부 컴파일, 손님·관리자 41개 주소 렌더링(실패 0)
- 폭 485px 과 1425px 에서 가로 스크롤 없음
- 같은 데이터로 claude-idus 와 나란히 찍어 눈으로 견줬습니다
  (홈 · 목록 · 글 · 로그인 · 관리 콘솔 · 모바일 서랍 · 다크)
- 캐시 있음/없음/무시 다섯 경우에서 글자 폭이 모두 같은 것을 쟀습니다
- 머리글 각 줄의 좌우 끝이 본문과 같은 값인지 픽셀로 쟀습니다
- 검색창이 "/" 로 열리고 Esc 로 닫히는지, 검색이 없는 화면에는 돋보기가
  나오지 않는지 확인했습니다
