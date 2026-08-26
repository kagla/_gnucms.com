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
4. `admin.php` 로 들어가 게시판을 만든다.

DSN 예시:

```
sqlite:/home/user/board/storage/board.sqlite
mysql:host=localhost;dbname=board;charset=utf8mb4
pgsql:host=localhost;dbname=board
```

## 호스트 앱 연동

게시판은 사용자 저장소를 갖지 않는다. 호스트 앱이 공유 시크릿으로 HS256 JWT 를 발급하고,
게시판은 서명을 검증해 그 주장을 그대로 믿는다.

`config/config.php` 의 `auth.secret` 을 호스트 앱과 공유한 뒤, 로그인한 사용자에게 이런
토큰을 만들어 준다.

```php
function issueBoardToken(string $userId, string $displayName, bool $isAdmin): string
{
    $secret = 'config.php 의 auth.secret 과 같은 값';

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

호스트 앱이 아직 없다면 `config.php` 의 `bootstrap_admin` 계정으로 `admin.php` 에 로그인해
전역 관리자 토큰을 발급받는다. 호스트를 붙인 뒤에는 `bootstrap_admin` 을 `null` 로 두어
이 경로를 닫는다.

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
