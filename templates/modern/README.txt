GNUCMS.com · 클린 블루
=====================

소개·매뉴얼·다운로드 중심의 공식 사이트 테마다.
templates/modern과 www/themes/modern을 함께 배포한 다음 관리자 기본 설정에서
GNUCMS.com (클린 블루)를 선택한다. 기존 gnucmscom 테마는 그대로 보존한다.

home/index.php는 선택한 클린 블루 개선안을 실제 사이트 링크에 연결한다.
modern.css는 공통 색상·상단 메뉴·홈·하단 메뉴를 정의하며, theme.css는 기존 공개
게시판·회원·관리 화면의 구성 요소를 제공한다. 새 의존성이나 빌드 과정은 없다.
community.css와 community/ 조각은 메인·커뮤니티의 최신글과 갤러리를 함께 구성한다.
매뉴얼 화면은 extensions/manual/manual.php, 자산은 manual.css·manual-blue.css·manual.js에 있다.
쇼핑몰 공개 화면은 shop/, 자산은 youngcart*·shop-orange.css에 보관한다. 쇼핑몰 관리자 화면은
기본 테마를 사용하고, 공개 화면의 주황색 스타일은 관리자에 적용하지 않는다.

히어로 위쪽 여백은 데스크톱 28px, 모바일 24px다. 시안의 고정 높이는 적용하지 않는다.
정적 파일 주소는 기존 asset() 도우미의 내용 해시를 사용한다.
메인 다운로드 버튼은 GNUCMS vX.Y.Z 다운로드 형태로 버전을 함께 표시한다.
version.txt는 조회 전 초기값이다. release-version.js가 upstream kagla/gnucms의
/releases/latest API에서 정식 릴리스 tag_name을 조회해 다운로드 버튼과 배포본 버전을 함께 갱신한다.
브라우저 캐시는 조회 전 표시용이며 페이지를 열 때 즉시 최신 릴리스를 조회한다. 기존 6시간 캐시는
무효화하며 설치 버전별 캐시 키를 사용해 업그레이드 전 캐시가 새 버전을 덮어쓰지 않게 한다.
열린 페이지에서도 5분마다 갱신하고 탭으로 돌아오면 마지막 요청 후 1분 이상 지났을 때 재조회한다.
조회 실패 시 마지막 표시를 보존하고 5분 뒤 재시도한다. 서버 cron은 필요 없다.
메인 갤러리는 최신 6건을 데스크톱 3열 2행·모바일 2열로 표시한다.
게시판의 메인 노출 수(home_limit)도 6 이상으로 설정해야 6건을 가져온다.
사이트 메뉴, 검색, 계정·알림, 다크 모드, 약관과 관리자 접근을 유지한다.

커뮤니티 모듈
-------------
modules/site-community를 관리자 확장 관리에서 켜면 /community가 열린다.
이 모듈은 기존 게시판·글 서비스로 읽기 권한을 확인해 화면 자료만 조회한다.
메인 피드는 기존 home_limit 설정을 따르고 커뮤니티 첫 화면은 게시판별 최신 8건을 가져온다.
비밀글의 썸네일은 기존 서비스에서 제거하며 글을 읽을 때의 권한·비밀번호 확인도 그대로다.

upstream 반영
-------------
동기화 기준은 upstream/main의 24d9da7 (GNUCMS 0.9.0)이다.
광고·분석 동의 관리 head·하단 조각은 default 템플릿 fallback으로 사용하고,
privacy.js는 정적 자산 fallback을 사용한다. 공개 레이아웃과 하단 메뉴에 동의 설정을 연결하며
관리자 화면은 기존 external_service_head 차단을 따른다.
코어 컨트롤러와 templates/default, modules/manual의 원본은 이번 화면 작업에서 수정하지 않는다.
전용 템플릿·CSS·JS를 modern에 보관했으므로 기본 테마의 화면 변경이 자동으로 덮어쓰지 않는다.
업데이트 때 templates/modern, www/themes/modern, modules/site-community와 활성 테마 설정을 보존한다.
매뉴얼 본문은 modules/manual/content의 최신 자료를 그대로 사용한다.
템플릿 데이터 계약이나 결제·주문·보안 동작이 바뀌면 전용 화면과 스크립트에도 필요한 변경을
검토해 반영한다. 전용 테마를 통째로 기본 테마로 다시 복사하지 않는다.
