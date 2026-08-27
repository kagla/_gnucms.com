# 단독 게시판 전환 설계

- 작성일: 2026-08-27
- 상태: 승인됨 (2026-08-27)
- 선행 문서: `2026-08-26-apiboard-design.md` (이 문서가 대체한다)

## 1. 배경

apiboard 는 헤드리스 게시판이었다. 인증은 호스트 앱이 소유하고, 게시판은 호스트 앱이
서명한 JWT 를 검증만 했다. 그 결과 게시판을 단독으로 설치하면 **회원이 될 방법이 없다.**
스키마와 권한 판정에는 "회원" 이라는 개념이 이미 들어 있는데 회원을 만들 수단이 없다.

이 문서는 방향을 바꾼다. JSON API 를 걷어내고, 웹사이트를 만들 때 그대로 얹어 쓰는
**평범한 서버 렌더링 게시판**으로 간다. 회원가입과 로그인을 게시판이 직접 소유한다.

## 2. 목표

1. **단독으로 완결된다** — 받아서 올리면 회원가입부터 글쓰기까지 된다. 호스트 앱이 없어도 된다.
2. **소셜 로그인** — 사람마다 쓰는 SNS 가 다르므로 여러 프로바이더를 지원하고, 프로바이더를
   늘리는 비용이 어댑터 하나여야 한다.
3. **DB 무관 유지** — SQLite, MySQL, PostgreSQL 에서 동일하게 동작한다. 테스트로 증명한다.
4. **무한 게시판, 무한 댓글 유지** — 기존 자산을 그대로 가져간다.
5. **설치자에게 CLI 를 요구하지 않는다** — FTP 로 올리고 브라우저로 설치한다.

## 3. 제약

- **PHP 8.1 이상.** 기존의 7.4 제약을 버린다. Slim·Twig 최신판이 요구하고, 7.4 는 이미
  보안 지원이 끝났다.
- **런타임 의존성을 허용한다.** 기존의 "의존성 0" 제약을 버린다. 대신 **배포물에 `vendor/`
  를 포함**해 내보낸다. Composer 는 빌드할 때만 쓰고 설치자는 여전히 FTP 만 쓴다.
- `mod_rewrite` 를 가정하지 않는다. 없으면 `PATH_INFO` 라우팅으로 폴백한다.
- MySQL 5.7 을 계속 지원한다. 재귀 CTE, JSON 타입 함수, 윈도 함수를 쓰지 않는다.
- 소셜 로그인과 메일 발송 때문에 **outbound HTTPS 가 필요**하다. 이를 막는 폐쇄망은 대상이
  아니다.

## 4. 기술 선택

| 대상 | 선택 | 이유 |
|---|---|---|
| 프레임워크 | Slim 4 | 소셜 로그인 때문에 어차피 Composer 가 들어온다. 그 뒤엔 PSR-15 미들웨어 생태계(세션·CSRF·플래시)를 그대로 쓰는 편이 싸다 |
| 템플릿 | Twig 3 | 게시판은 사용자 입력이 화면 전체에 뿌려진다. **자동 이스케이프가 선택 기준**이다. Latte 가 기술적으로 우위(문맥 인식 이스케이프)지만, 남이 받아 스킨을 고쳐 쓸 물건이라 아는 사람이 많은 쪽이 이긴다 |
| OAuth | `league/oauth2-client` | state·PKCE·토큰 교환을 직접 짤 이유가 없다. 게시판보다 틀리기 쉬운 영역이다 |
| 메일 | `symfony/mailer` | SMTP·sendmail 양쪽을 덮고 DSN 한 줄로 설정된다 |

순수 PHP 템플릿을 쓰지 않는 이유는 하나다. `<?= $post['title'] ?>` 한 군데를 빠뜨리면
그게 곧 XSS 이고, 그건 리뷰로 막을 것이 아니라 도구로 막아야 한다.

## 5. 기존 코드의 처분

계층이 `Repository → Service → Http` 로 나뉘어 있고 Service 가 HTTP 를 모르기 때문에,
죽는 것은 맨 위 얇은 층 하나다.

| | 대상 |
|---|---|
| **유지** | `Db/` 전체(방언 3종), `Repository/`, `Service/` 대부분, `Auth/Identity`, `Auth/Acl`, `Validation/`, `Comment/TreeBuilder`, `Support/` |
| **폐기** | `Http/` 의 Router·Request·Response·ResponseInterface·FileResponse·Cors, `Auth/TokenIssuer`, `Auth/TokenVerifier`, `Routes.php`, `public/index.php`, `public/docs.php`, `docs/openapi.yaml`, `tests/Api/`, `tests/Docs/`, `tests/Http/`, `tests/Auth/TokenTest.php` |
| **이동** | `Http/ApiError` → `Error/DomainError` |
| **재작성** | `public/admin.php`(784줄 SPA, fetch + Bearer 전제), `Service/AuthService` |

`ApiError` 는 폐기할 수 없다. `Db/`, `Service/`, `Auth/Acl`, `Validation/`, `Support/`,
`Install/` 의 15개 파일이 이 예외를 던진다. 도메인이 `Http` 네임스페이스에 의존하는 상태로는
`Http/` 를 걷어낼 수 없으므로 `ApiBoard\Error\DomainError` 로 옮긴다. 팩터리 이름
(`notFound`, `forbidden`, `validation` …)과 동작은 그대로 두고 네임스페이스만 바꾼다.

`AttachmentService::download()` 도 `Http\FileResponse` 를 돌려주고 있다. 반환값을
`array{path, name, mime}` 서술자로 바꾸고, 파일을 실제로 내보내는 일은 Web 계층이 맡는다.
한글 파일명 처리(RFC 5987)는 그 과정에서 Web 계층으로 옮긴다.

`Http/` 를 남긴 채 Slim 을 올리면 요청 객체가 두 개인 상태가 된다. 그건 가장 나쁜 결과라
함께 둘 수 없다. `admin.php` 는 CSS 와 화면 구성을 Twig 로 옮겨 살리고, fetch·토큰 처리
부분만 버린다.

`config` 의 `auth.secret` 은 JWT 가 사라진 뒤에도 **첨부 서술자 서명**에 계속 쓰인다
(`AttachmentService::sign`). 키 이름이 더 이상 맞지 않으므로 6단계 개명 때 함께 바꾼다.
그 전까지는 이름을 유지한다. `bootstrap_admin` 은 5단계에서 없어진다.

`Acl` 이 살아남는 것이 이 전환의 핵심이다. 신원을 **어디서 얻는지**만 바뀌고 **무엇을
허용하는지**는 그대로다.

## 6. 디렉터리

```
public/
  index.php          Slim 프론트 컨트롤러. 이것 하나뿐이다
  install.php        설치 후 삭제
  .htaccess
templates/           Twig. 이 폴더가 곧 스킨이다
src/
  Db/ Repository/ Service/ Validation/ Comment/ Support/    그대로
  Auth/
    Identity.php     그대로
    Acl.php          그대로
    SessionGuard.php 신규: 세션 -> Identity
  Account/           신규: 회원 도메인
    UserRepository.php
    AccountService.php     가입·로그인·비번변경
    LinkingService.php     소셜 연결 판정
    TokenService.php       이메일 인증·비번 재설정 토큰
  Oauth/             신규
    ProviderInterface.php
    ProviderRegistry.php
    GoogleProvider.php  NaverProvider.php  KakaoProvider.php  GithubProvider.php
  Mail/              신규: MailerInterface + Symfony 구현
  Web/               신규: Slim 배선
    Controller/  Middleware/
config/
  config.php
```

### 6.1 계층 규칙

**Controller 는 Service 만 호출하고, Service 는 HTTP·세션·Twig 를 모른다.**

`Oauth/` 의 어댑터도 같다. "인가 URL 을 만든다" 와 "코드를 프로필로 바꾼다" 까지만 하고
세션·리다이렉트·회원 생성은 모른다. 그래야 가짜 프로바이더로 흐름 전체를 테스트할 수 있다.

## 7. 데이터 모델

기존 `boards`·`posts`·`comments` 는 **변경하지 않는다.** `posts.author_id` 가 이미
`VARCHAR(64)` 라 `users.id` 를 문자열로 넣으면 된다. `boards.managers` 에도 `users.id`
문자열이 들어간다.

### 7.1 users

```
id             BIGINT       PK
email          VARCHAR(191) NOT NULL UNIQUE   -- 계정의 열쇠
email_verified SMALLINT     NOT NULL DEFAULT 0
password_hash  VARCHAR(255) NULL              -- 소셜로만 가입하면 NULL
name           VARCHAR(100) NOT NULL
is_admin       SMALLINT     NOT NULL DEFAULT 0
status         VARCHAR(10)  NOT NULL DEFAULT 'active'   -- active | blocked
session_epoch  INTEGER      NOT NULL DEFAULT 0        -- 8.4 참고
created_at     ...
updated_at     ...
```

`email` 이 UNIQUE 인 것이 계정 연결 정책 전체의 근거다. 같은 이메일이면 같은 사람이다.

길이를 191 로 두는 것은 utf8mb4 인덱스 키 길이 때문이다. `ROW_FORMAT=COMPACT` 로 만들어진
MySQL 테이블은 인덱스 키가 767 바이트를 넘을 수 없고 `VARCHAR(255)` 는 1020 바이트가 된다.
RFC 상 주소 최대 길이는 254 지만 191 을 넘는 실제 주소는 없다시피 하다. `provider_uid` 도
같은 이유로 191 이다.

`password_hash` 가 NULL 이면 소셜 전용 회원이다. 이 회원이 나중에 비밀번호를 설정하면
로컬 로그인도 가능해진다. 비밀번호를 지우는 기능은 두지 않는다 — 소셜 연결이 하나뿐인
상태에서 비번을 지우면 프로바이더 장애 시 잠긴다.

### 7.2 user_identities

```
id           BIGINT       PK
user_id      BIGINT       NOT NULL
provider     VARCHAR(20)  NOT NULL   -- google | naver | kakao | github
provider_uid VARCHAR(191) NOT NULL
created_at   ...
UNIQUE (provider, provider_uid)
```

한 회원이 여러 소셜을 연결한다. `provider_uid` 를 191 로 두는 것은 utf8mb4 인덱스 키
길이 때문이다.

**마지막 로그인 수단은 해제할 수 없다.** 비밀번호가 없고 소셜 연결이 하나뿐인 회원이
그 연결을 끊으면 계정에 접근할 수 없게 된다. `LinkingService` 가 이를 거부한다.

### 7.3 user_tokens

```
id         BIGINT      PK
user_id    BIGINT      NOT NULL
purpose    VARCHAR(20) NOT NULL   -- verify_email | reset_password
token_hash VARCHAR(64) NOT NULL UNIQUE
expires_at ...
used_at    ... NULL
created_at ...
```

**토큰은 해시로 저장한다.** 평문으로 두면 DB 가 새는 순간 그것이 곧 계정 탈취 수단이 된다.
메일에는 평문을 보내고 DB 에는 `hash('sha256', $token)` 을 넣는다. 조회는 해시로 한다.

한 번 쓰면 `used_at` 을 채워 재사용을 막는다. 같은 목적의 새 토큰을 발급하면 이전 것들을
무효화한다.

유효 기간은 `verify_email` 이 24시간, `reset_password` 가 1시간이다. 재설정 토큰이 짧은
것은 그것이 곧 계정 접근 수단이기 때문이다. 만료된 토큰으로 접근하면 재발송 화면으로
보낸다.

## 8. 인증

### 8.1 세션

- PHP 세션을 쓴다. 세션에는 **`user_id` 와 `session_epoch`** 만 담는다.
- 이름·관리자 여부를 세션에 캐시하지 않는다. 차단이나 권한 변경이 즉시 반영되어야 한다.
- 매 요청마다 `SessionGuard` 가 `users` 를 읽어 `Identity` 를 만든다. `status` 가
  `active` 가 아니거나 세션의 `session_epoch` 가 DB 의 값과 다르면 게스트로 취급하고
  세션을 파기한다.
- `session_epoch` 는 그 회원의 모든 세션을 한 번에 끊는 수단이다. 세션 저장소를 뒤질 필요
  없이 정수 하나를 올리면 된다. 비밀번호 변경, 비밀번호 재설정, 회원 차단 시 올린다.
- 로그인 성공 시 `session_regenerate_id(true)`. 고정 공격 대비.
- 쿠키는 `HttpOnly`, `SameSite=Lax`, HTTPS 면 `Secure`.
- 모든 POST 에 CSRF 토큰을 요구한다. 미들웨어에서 처리한다.

### 8.2 로컬 가입

1. 이메일·비밀번호·이름을 받는다.
2. `email_verified = 0` 으로 회원을 만들고 `verify_email` 토큰을 메일로 보낸다.
3. 링크를 클릭하면 `email_verified = 1`.

**미인증 회원은 로그인할 수 없다.** "로그인은 되는데 글은 못 쓰는" 중간 상태를 만들면 그
상태가 권한 판정 전체에 스며든다. 로그인 화면에서 미인증 계정임을 알리고 인증 메일
재발송만 제공한다.

이미 가입된 이메일로 다시 가입을 시도하면, **가입 성공과 구별되지 않는 화면**을 보여주고
"이미 계정이 있습니다" 안내 메일을 그 주소로 보낸다. 화면 응답으로 계정 존재 여부를
알려주지 않는다.

### 8.3 소셜 로그인

```
GET  /auth/{provider}           state 생성 -> 세션 저장 -> 프로바이더로 리다이렉트
GET  /auth/{provider}/callback  state 검증 -> 코드 교환 -> 프로필
```

프로필은 `(provider, uid, email|null, emailVerified, name)` 으로 정규화된다.
콜백 이후 판정은 다음 순서다.

1. `(provider, uid)` 가 `user_identities` 에 있으면 → 그 회원으로 로그인. 끝.
2. 없고, 프로필에 **검증된** 이메일이 있으면
   - 그 이메일의 회원이 있으면 → `user_identities` 행을 추가해 **자동 연결** 후 로그인
   - 없으면 → 새 회원 생성(`email_verified = 1`, `password_hash = NULL`) 후 로그인
3. 이메일이 없거나 **미검증**이면 → 로그인을 완료하지 않는다. 이메일 입력 화면을 보여주고,
   입력받은 주소로 `verify_email` 토큰을 보낸다. 프로바이더 프로필은 세션에만 두고 30분 뒤 버린다.
   DB 에 미완성 회원 행을 만들지 않는다. **링크 클릭 시점에** 연결 또는 생성을 확정한다.

3번이 이 설계에서 가장 중요한 부분이다. 카카오와 네이버는 이메일이 선택 동의 항목이라
아예 주지 않을 수 있고, 검증되지 않은 이메일을 그대로 믿으면 남의 이메일을 자기 소셜
계정에 등록해 계정을 가져갈 수 있다. **자동 연결은 오직 검증된 이메일로만 한다.**
그 외에는 링크 클릭이라는 소유 증명을 거친다. 입력한 주소가 이미 있는 계정이더라도,
링크를 클릭했다는 것은 그 메일함에 접근할 수 있다는 뜻이므로 연결해도 안전하다.

### 8.4 비밀번호 찾기

이메일을 받아 `reset_password` 토큰을 보낸다. **계정이 없어도 같은 화면을 보여준다.**
응답으로 가입 여부가 드러나지 않게 한다. 링크에서 새 비밀번호를 설정하면 `users.session_epoch` 를 올려
그 회원의 기존 세션을 전부 끊는다(8.1 참고). 계정을 빼앗겼다가 되찾는 경우가 이 기능의
존재 이유이므로, 공격자의 세션이 살아 있으면 재설정이 무의미하다.

### 8.5 관리자

`config.php` 의 부트스트랩 관리자를 **없앤다.** 로컬 계정이 생겼으므로 관리자를 설정
파일에 박아 둘 이유가 사라졌다. `install.php` 가 첫 관리자를 `users` 에 직접 만든다
(`is_admin = 1`, `email_verified = 1`). 진입점이 둘에서 하나로 줄어든다.

권한 판정 순서는 기존과 같다. `Acl` 은 변경하지 않는다.

1. 전역 관리자(`users.is_admin`) → 전부 허용
2. 게시판 관리자(`boards.managers` 에 `users.id` 포함) → 그 게시판의 글·댓글
3. 본인(`author_id`)
4. 비회원 본인(글 비밀번호)
5. 그 외 → 게시판의 `perm_*`

## 9. 비회원 글

유지한다. 다만 **권장 기본값이 아니다.**

- `boards.perm_write` 의 기본값은 `member` 로 둔다(현재와 같다).
- 관리자 화면에서 `guest` 를 선택할 때 경고를 표시한다: 검증할 것이 늘고 스팸이 들어온다.
- 스팸 대응(캡차, 도배 제한)은 이번 범위 밖이다. 다만 비회원 글 경로에 훅 지점을
  표시해 두어 나중에 붙일 자리를 남긴다.

## 10. 화면

Twig 템플릿. `templates/` 가 곧 스킨이다.

| 구분 | 화면 |
|---|---|
| 공개 | 게시판 목록, 글 목록(검색·페이징·분류), 글 보기(댓글 트리), 글 쓰기/수정, 댓글 쓰기/수정 |
| 계정 | 로그인, 가입, 인증 안내, 비번 찾기, 비번 재설정, 내 설정(이름·비번·소셜 연결 관리) |
| 관리자 | 게시판 목록/생성/수정/삭제, 회원 목록(검색·차단·관리자 지정), 사이트 설정 |

`admin.php` 의 테마 토큰(밝음/어둠 CSS 변수)은 그대로 옮겨 쓴다.

### 10.1 라우팅

`mod_rewrite` 가 있으면 `/b/free/123`, 없으면 `index.php/b/free/123` 으로 동작한다.
Slim 을 `PATH_INFO` 기반으로 태우고, 기준 경로는 실행 시점에 판별한다.

## 11. 오류 처리

- 도메인 예외(권한 없음, 검증 실패, 없는 자원)는 Service 가 던지고 미들웨어 하나가 받는다.
- 검증 실패는 입력값을 유지한 채 폼을 다시 그린다. 플래시 메시지로 사유를 전한다.
- 권한 없음은 게스트에게 로그인 화면으로, 로그인 사용자에게 403 화면으로 간다.
  (기존 `Acl` 의 401/403 구분을 그대로 화면으로 옮긴다.)
- `debug = false` 면 예외 원문을 화면에 내보내지 않고 로그에만 남긴다.

## 12. 테스트

기존 규칙을 유지한다. **같은 테스트 스위트가 세 DB 에서 모두 통과하는 것이 릴리스 조건이다.**

| 대상 | 방식 |
|---|---|
| `Db/`, `Repository/`, `Service/`, `Acl`, `Validator`, `TreeBuilder` | 기존 테스트 유지 |
| Slim 라우트 | 앱을 in-process 로 호출해 상태코드·HTML 을 검증. `tests/Api/` 를 `tests/Web/` 로 대체 |
| 소셜 로그인 | `ProviderInterface` 의 가짜 구현으로 8.3 의 1~3 경로를 전부 검증. 외부 호출 없음 |
| 메일 | `MailerInterface` 의 수집 구현으로 인증·재설정 흐름 검증 |
| CSRF·세션 | 토큰 없는 POST 거부, 로그인 시 세션 ID 재생성, 차단된 회원의 세션 무효화 |

반드시 있어야 할 테스트:

- 미검증 이메일을 주는 프로바이더가 기존 계정에 **자동 연결되지 않는다** (8.3 의 핵심)
- 마지막 로그인 수단(소셜 연결 1개 + 비번 없음) 해제가 거부된다
- 이미 쓴 토큰과 만료된 토큰이 거부된다
- 존재하지 않는 이메일로 비번 찾기를 해도 응답이 같다
- 비밀번호를 바꾸면 그 회원의 기존 세션이 끊긴다(`session_epoch`)

## 13. 구현 단계

한 번에 갈 크기가 아니다. 각 단계 끝에서 테스트가 통과하고 화면이 동작해야 한다.

| 단계 | 내용 | 끝났다는 기준 |
|---|---|---|
| 1 | Composer 도입, Slim + Twig 뼈대, `Http/` 제거, 글 목록·보기를 서버 렌더링으로 이식 | 로그인 없이 공개 게시판을 읽을 수 있다 |
| 2 | `users` + 세션 + 로컬 가입/로그인, `SessionGuard` 로 `Acl` 연결 | 회원가입 후 글을 쓸 수 있다 |
| 3 | 메일, 이메일 인증, 비밀번호 찾기 | 인증·재설정 흐름이 가짜 메일러 테스트로 검증된다 |
| 4 | `Oauth/` 와 프로바이더 4종, 계정 연결 판정(8.3) | 가짜 프로바이더로 1~3 경로가 전부 검증된다 |
| 5 | 관리자 화면 재작성(게시판·회원), `install.php` 수정 | `admin.php` 없이 운영할 수 있다 |
| 6 | 개명, 배포물 빌드(`vendor/` 포함 zip), 문서 | 빈 호스팅에 FTP 로 올려 설치가 된다 |

1단계에서 `Http/` 를 먼저 걷어내는 것이 중요하다. 요청 객체가 둘인 기간을 최대한 짧게
가져가야 한다.

## 14. 이번 범위 밖

스팸 차단·캡차, 애플 로그인, 좋아요/추천, 신고, 알림, 리치 에디터, 전문 검색, 댓글 페이징,
게시판별 스킨, 다국어.

애플 로그인은 다른 프로바이더와 키 관리 방식이 달라(비밀키 파일 + JWT 서명) 어댑터
하나로 끝나지 않는다. `ProviderInterface` 는 나중에 추가할 수 있는 모양으로 둔다.

## 15. 이름

**`aboard` 로 바꾼다.** `apiboard` 는 API 가 사라진 뒤로 맞지 않는다.

`aboard` 는 "올라탄, 탑승한" 을 뜻하는 영어 단어이고(`All aboard!`, `Welcome aboard`),
그 안에 `a board` 를 품고 있다. 사람이 올라타는 곳이라는 뜻과 게시판이라는 뜻이 한 단어에
겹친다. 6글자로 후보 중 가장 짧으면서 뜻이 비어 있지 않은 유일한 이름이었다.

**알고 감수하는 약점:** 영어권에서 `abroad`(해외)와 철자를 혼동하기 쉽다. 주 사용자층이
국내라 감수한다. 흔한 단어라 검색이 어려운 것도 같은 이유로 감수한다.

버린 후보와 이유를 남긴다. 같은 논의를 다시 하지 않기 위해서다.

| 후보 | 버린 이유 |
|---|---|
| `crud-board` | `crud` 는 영어에서 "때·오물" 을 뜻하는 실제 단어다. 그리고 CRUD 는 구현 패턴이지 제품이 아니다 |
| `cru-board` | 약어를 잘라 뜻이 사라졌다. Delete 가 없는 것으로 오독된다 |
| `custom-board` | `custom` 은 "주문 제작된" 이지 "고쳐 쓰는" 이 아니다. 그 뜻은 `customizable` 이고 이름으로 쓰기엔 길다. 무엇보다 이 게시판은 안 고치고 쓰는 물건이다 |
| `aiboard` | 이 설계에 AI 기능이 하나도 없다. 지키지 못할 약속을 이름에 넣지 않는다 |
| `101board` | **PHP 네임스페이스는 숫자로 시작할 수 없다**(`namespace 101Board;` 는 Parse error). 패키지 이름과 네임스페이스가 갈라진다. 어순도 관례(`Board 101`)와 반대라 뜻이 안 산다 |
| `zeroboard` | 제로보드는 한국 PHP 게시판의 이름이다. 같은 계보에서 다시 쓸 수 없다 |
| `justboard` | 제품을 가장 정확히 설명하지만 9자로 길다. 짧은 이름을 우선했다 |

확인한 것: `kagla/aboard` 가 Packagist 에 비어 있고, `aboard` 라는 이름의 PHP 패키지가
없다. GitHub 저장소 이름은 계정별 네임스페이스라 충돌하지 않는다.

바꿀 것은 다음과 같고 **6단계에서 한 번에** 바꾼다. 중간에 하면 충돌만 는다.

| 대상 | 현재 | 이후 |
|---|---|---|
| 네임스페이스 | `ApiBoard\` | `Aboard\` |
| 테스트 네임스페이스 | `ApiBoard\Tests\` | `Aboard\Tests\` |
| Composer 패키지 | `kagla/apiboard` | `kagla/aboard` |
| 업로드 임시 디렉터리 | `apiboard-test-uploads` | `aboard-test-uploads` |
| 설정 키 | `auth.secret` | `security.secret` (첨부 서명 전용이 되었다) |
| SQLite 기본 파일 | `storage/board.sqlite` | 그대로 |
| 문서·화면 문구 | apiboard | aboard |
| 도메인 | `apiboard.gnuboard.net` | 운영자가 정한다 |
