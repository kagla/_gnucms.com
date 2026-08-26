# 표준 게시판 (standard-board)

SQLite / MySQL / PostgreSQL 을 가리지 않고 동작하는 API 우선 게시판. PHP 7.4 이상이면 되고
런타임 의존성이 없다. 저가형 공유 호스팅에 폴더째 올리면 동작한다.

- 게시판을 몇 개 만들든 테이블은 `boards`, `posts`, `comments` 세 개뿐이다
- 댓글 깊이에 제한이 없다
- 인증은 호스트 앱이 소유한다. 게시판은 서명된 토큰을 검증만 한다

## 설치

1. 파일 전체를 올린다. 문서 루트는 `public/` 을 가리키게 한다.
   문서 루트를 바꿀 수 없는 호스팅이라면 `public/` 안의 내용을 루트에 두고 나머지 폴더를
   그 위 디렉터리에 둔다. `storage/` 가 웹으로 접근 가능한 위치에 있으면 안 된다.
2. 브라우저로 `install.php` 를 연다. DSN 과 관리자 계정을 입력한다.
3. **설치가 끝나면 `public/install.php` 를 지운다.**
   설치 뒤에 값을 바꾸고 싶으면 `.env` 를 쓴다. 아래 "설정" 을 참고한다.
4. `admin.php` 로 들어가 게시판을 만든다.

DSN 예시:

```
sqlite:/home/user/board/storage/board.sqlite
mysql:host=localhost;dbname=board;charset=utf8mb4
pgsql:host=localhost;dbname=board
```

## 설정

설정은 세 겹이고 뒤에 오는 것이 앞을 덮는다.

| 순서 | 출처 | 언제 쓰나 |
|---|---|---|
| 1 | `config/config.php` | 설치 마법사가 만든다. 손대지 않아도 된다 |
| 2 | `.env` (프로젝트 루트) | 환경마다 달라지는 값. **PHP 를 편집하지 않고 바꾼다** |
| 3 | 진짜 환경변수 | 도커·호스팅 패널이 주입하는 값. `.env` 보다 세다 |

`config/config.php` 는 설치 마법사가 만들어 주는 파일이다. 생성된 JWT 시크릿과
관리자 비밀번호 해시가 여기 들어 있고, `.env` 가 덮지 않은 값의 기본값 역할을 한다.
값을 바꾸고 싶으면 이 파일이 아니라 `.env` 에 적는다.
`.env.example` 을 `.env` 로 복사해 필요한 줄만 채우면 된다.

```
DB_DSN=mysql:host=localhost;dbname=board;charset=utf8mb4
DB_USERNAME=board
DB_PASSWORD=비밀번호
CORS_ALLOWED_ORIGINS=https://app.example.com,https://www.example.com
DEBUG=false
```

쓸 수 있는 이름은 `.env.example` 에 전부 적혀 있다. 요약하면
`DB_DSN` `DB_USERNAME` `DB_PASSWORD` ·
`AUTH_SECRET` `AUTH_TTL` `AUTH_LEEWAY` ·
`BOOTSTRAP_ADMIN_ENABLED` `BOOTSTRAP_ADMIN_ID` `BOOTSTRAP_ADMIN_PASSWORD_HASH` ·
`UPLOADS_DIR` `UPLOADS_MAX_BYTES` `UPLOADS_ALLOWED_EXT` ·
`CORS_ALLOWED_ORIGINS` · `LOG_FILE` · `DEBUG` 이다.
목록형(`UPLOADS_ALLOWED_EXT`, `CORS_ALLOWED_ORIGINS`)은 쉼표로 나눈다.

주의할 점 몇 가지.

- **빈 값(`KEY=`)은 "설정하지 않음" 이다.** `config/config.php` 의 값이 그대로 쓰인다.
  그래서 `.env.example` 을 통째로 복사해도 기존 설정이 바뀌지 않고, 채운 줄만 효력이
  생긴다. 아래 층의 값을 실제로 비우려면 `KEY=null` 이라고 쓴다.
- **`.env` 는 `public/` 안에 두지 않는다.** PHP 파일과 달리 평문이라 그대로
  내려받힐 수 있다. 문서 루트를 옮길 수 없어 어쩔 수 없이 같은 폴더에 두게 되면
  `public/.htaccess` 의 차단 규칙이 막아 주지만, nginx 는 `.htaccess` 를 읽지
  않으므로 서버 설정에서 직접 막아야 한다.
- 주석은 줄 전체(`#` 로 시작)만이다. `DB_PASSWORD=p@ss#word` 의 `#` 뒤는
  잘리지 않는다.
- 오타가 있는 줄은 조용히 무시하지 않고 500 으로 알린다.
- 호스트 앱을 붙인 뒤에는 `BOOTSTRAP_ADMIN_ENABLED=false` 한 줄로 관리자
  로그인 경로를 닫을 수 있다.

`config/config.php` 없이 `.env` 만으로도 배포할 수 있다. `DB_DSN` 만 정해지면
동작하고, 그 경우 `install.php` 는 이미 설치된 것으로 보고 재설치를 거부한다.

## 호스트 앱 연동

게시판은 사용자 저장소를 갖지 않는다. 호스트 앱이 공유 시크릿으로 HS256 JWT 를 발급하고,
게시판은 서명을 검증해 그 주장을 그대로 믿는다.

`auth.secret`(`.env` 의 `AUTH_SECRET`)을 호스트 앱과 공유한 뒤, 로그인한 사용자에게 이런
토큰을 만들어 준다.

```php
function issueBoardToken(string $userId, string $displayName, bool $isAdmin): string
{
    $secret = '게시판의 AUTH_SECRET 과 같은 값';

    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload = [
        'sub'   => $userId,        // 게시판의 author_id 가 된다
        'name'  => $displayName,   // 글쓴이 이름으로 강제된다
        'admin' => $isAdmin,       // true 면 전역 관리자
        'iat'   => time(),
        'exp'   => time() + 3600,
    ];

    $encode = static function (array $data): string {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    };

    $signingInput = $encode($header) . '.' . $encode($payload);
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $signingInput, $secret, true)), '+/', '-_'), '=');

    return $signingInput . '.' . $signature;
}
```

브라우저는 이 토큰을 `Authorization: Bearer <토큰>` 헤더에 담아 게시판 API 를 직접 호출한다.
호스트 앱 도메인이 게시판 도메인과 다르면 `config.cors.allowed_origins` 에 호스트 앱 주소를
정확히 적어야 한다.

## 관리자

권한 판정 순서는 다음과 같다.

1. 토큰의 `admin` 이 `true` → **전역 관리자**. 게시판 생성/삭제 포함 전권
2. 토큰의 `sub` 가 게시판의 `managers` 에 있음 → **게시판 관리자**. 그 게시판의 글·댓글만
3. 글의 `author_id` 가 토큰의 `sub` 와 같음 → **본인**
4. 비회원 글이고 비밀번호가 맞음 → **비회원 본인**. 3번과 같은 권한
5. 그 외 → 게시판의 `perm_read` / `perm_write` / `perm_comment` 설정

호스트 앱이 아직 없다면 `bootstrap_admin` 계정으로 `admin.php` 에 로그인해 전역 관리자
토큰을 발급받는다. 호스트를 붙인 뒤에는 `.env` 에 `BOOTSTRAP_ADMIN_ENABLED=false` 를
적어 이 경로를 닫는다.

## API

| 메서드 | 경로 | 설명 |
|---|---|---|
| POST | `/auth/login` | 부트스트랩 관리자 로그인 |
| GET | `/boards` | 게시판 목록 |
| POST | `/boards` | 게시판 생성 (전역 관리자) |
| GET/PATCH/DELETE | `/boards/{key}` | 게시판 조회/수정/삭제 |
| GET | `/boards/{key}/posts` | 글 목록 (`page`, `per_page`, `q`, `category`) |
| POST | `/boards/{key}/posts` | 글 작성 |
| GET/PATCH/DELETE | `/posts/{id}` | 글 조회/수정/삭제 |
| POST | `/posts/{id}/restore` | 삭제된 글 복구 (관리자) |
| GET | `/posts/{id}/files/{index}` | 첨부 다운로드 |
| GET/POST | `/posts/{id}/comments` | 댓글 트리 조회 / 작성 |
| PATCH/DELETE | `/comments/{id}` | 댓글 수정/삭제 |
| POST | `/uploads` | 첨부 업로드 |
| POST | `/maintenance/gc` | 고아 첨부 정리 (전역 관리자) |

`mod_rewrite` 가 없는 호스팅에서는 `index.php?p=/boards/free/posts` 형태로 호출한다.

## 개발

```bash
composer install
vendor/bin/phpunit                 # SQLite 로 실행

TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=board_test;charset=utf8mb4' \
TEST_MYSQL_USER=root TEST_MYSQL_PASS=secret \
TEST_PGSQL_DSN='pgsql:host=127.0.0.1;dbname=board_test' \
TEST_PGSQL_USER=postgres TEST_PGSQL_PASS=secret \
vendor/bin/phpunit                 # 세 DB 전부
```

세 DB 로 전부 통과하는 것이 릴리스 조건이다.
