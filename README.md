# gnucms.com

> **이 문서는 최신 상태가 아닙니다.** 프로젝트는 헤드리스 JSON API 에서 서버 렌더링
> 사이트로 전환하는 중이며, 아래 API 관련 설명은 지금의 코드를 더 이상 반영하지
> 않습니다. 문서 전체를 새로 쓰는 작업은 다음 단계에서 진행합니다.

SQLite / MySQL / PostgreSQL 을 가리지 않고 동작하는 API 우선 게시판. PHP 7.4 이상이면 되고
런타임 의존성이 없다. 저가형 공유 호스팅에 폴더째 올리면 동작한다.

- 게시판을 몇 개 만들든 테이블은 `boards`, `posts`, `comments` 세 개뿐이다
- 댓글 깊이에 제한이 없다
- 인증은 호스트 앱이 소유한다. 게시판은 서명된 토큰을 검증만 한다

## 설치

1. 파일 전체를 올린다. 문서 루트는 `www/` 을 가리키게 한다.
   문서 루트를 바꿀 수 없는 호스팅이라면 `www/` 안의 내용을 루트에 두고 나머지 폴더를
   그 위 디렉터리에 둔다. `storage/` 가 웹으로 접근 가능한 위치에 있으면 안 된다.
2. 브라우저로 사이트를 연다. 설정 파일이 없으면 `install.php` 로 자동 이동한다.
3. 다섯 단계를 따라간다: 서버 점검 → 데이터베이스(종류를 고르고 접속 시험) → 사이트 이름·주소·발신 메일
   → 첫 관리자 → 완료. `config/config.php` 는 마지막에 쓰인다. 파일을 올린 뒤에는 바로 설치를
   끝낸다. 설정 파일이 생기기 전에는 누구나 설치기를 열어 첫 관리자가 될 수 있다.
4. 설치가 끝나면 `www/install.php` 는 스스로 삭제된다. 못 지웠다고 나오면 손으로 지운다.
5. 로그인해 관리 콘솔에서 게시판을 만든다.

### 코드를 새 판으로 올릴 때

파일만 덮어쓰면 된다. 첫 요청에서 앱이 DB 의 스키마 판을 견주어 다르면 스스로 옮긴다.
SQLite 는 옮기기 전에 `storage/backups/` 에 복사본을 남긴다(최근 5개). MySQL/PostgreSQL 은
앱이 백업하지 못하므로 올리기 전에 `mysqldump`/`pg_dump` 로 받아 둔다.

옮기지 못하면 방문자에게 503 점검 화면이 나가고 `storage/logs/error.log` 에 원인이 남는다.
원인을 고치면 60초 뒤 요청에서 다시 시도한다. 되돌리려면 `storage/board.sqlite` 를 백업
파일로 바꾼다. 관리 콘솔 > 사이트 설정 아래에서 판 번호와 백업 목록을 볼 수 있다.

## 데이터베이스

셋 중 어느 것을 골라도 기능 차이는 없다. 같은 테스트 스위트가 세 DB 에서 모두 통과하는
것이 릴리스 조건이다. 고르는 기준은 대체로 이렇다.

| DB | 언제 |
|---|---|
| SQLite | 방문자가 많지 않고 설치를 가장 단순하게 하고 싶을 때. DB 서버가 필요 없다 |
| MySQL / MariaDB | 공유 호스팅이 기본으로 주는 DB 일 때. 가장 흔한 선택 |
| PostgreSQL | 이미 PostgreSQL 을 쓰고 있을 때 |

먼저 PHP 에 해당 드라이버가 있는지 본다. 없으면 호스팅에 요청해야 한다.

```bash
php -m | grep pdo
# pdo_sqlite / pdo_mysql / pdo_pgsql 중 쓸 것이 보여야 한다
```

설치가 끝난 뒤 어느 DB 로 붙었는지는 `/health` 가 알려 준다.

```bash
curl 'https://example.com/index.php?p=/health'
# {"ok":true,"dialect":"mysql"}
```

테이블(`boards`, `posts`, `comments`)은 설치 마법사가 직접 만든다. 그래서 게시판이 쓸
DB 계정에는 `CREATE TABLE` 과 `CREATE INDEX` 권한이 있어야 한다.

### SQLite

준비할 것이 없다. 파일이 없으면 설치할 때 만들어진다.

```
sqlite:/home/user/board/storage/board.sqlite
```

- **절대경로로 적는다.** 상대경로는 실행 위치에 따라 다른 파일을 가리킨다.
- 파일이 아니라 **그 디렉터리에 쓰기 권한**이 있어야 한다. SQLite 는 본 파일 외에
  저널/WAL 파일을 같은 폴더에 만든다.
- **DB 파일이 웹으로 접근 가능한 위치에 있으면 안 된다.** `storage/` 는 문서 루트
  바깥이어야 한다. 주소만 알면 게시판 전체를 통째로 내려받을 수 있다.
- 동시 쓰기는 한 번에 하나다. 잠겨 있으면 5초까지 기다린 뒤 실패한다
  (`PRAGMA busy_timeout = 5000`). 글이 몰리는 게시판이면 MySQL 이나 PostgreSQL 을 쓴다.
- 백업은 파일 복사다.

### MySQL / MariaDB

DB 와 계정을 먼저 만든다. 호스팅 패널에서 만들었다면 이 단계는 건너뛴다.

```sql
CREATE DATABASE board DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'board'@'localhost' IDENTIFIED BY '비밀번호';
GRANT ALL PRIVILEGES ON board.* TO 'board'@'localhost';
FLUSH PRIVILEGES;
```

```
mysql:host=127.0.0.1;port=3306;dbname=board;charset=utf8mb4
```

- **`charset=utf8mb4` 를 반드시 붙인다.** 빠뜨리면 서버 기본 문자셋을 따르는데, 오래된
  서버는 그것이 `latin1` 이라 한글이 깨진다. 테이블 자체는 `utf8mb4_unicode_ci` 로 만든다.
- **`host=localhost` 가 `[2002] No such file or directory` 로 실패하면** PHP 가 찾는
  소켓 경로와 서버의 실제 소켓 경로가 다른 것이다. `localhost` 는 TCP 가 아니라 유닉스
  소켓으로 붙기 때문에 생기는 일이며, 흔하다. 해결책은 둘 중 하나다.

  ```bash
  # 원인 확인: 두 값이 다르면 이 문제다
  mysql -e "SHOW VARIABLES LIKE 'socket';"     # 서버의 실제 소켓
  php -i | grep pdo_mysql.default_socket        # PHP 가 찾는 소켓
  ```

  ```
  # 방법 1 (권장) — TCP 로 붙는다
  mysql:host=127.0.0.1;port=3306;dbname=board;charset=utf8mb4

  # 방법 2 — 소켓 경로를 직접 적는다
  mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=board;charset=utf8mb4
  ```

  `'board'@'localhost'` 로 만든 계정은 대개 `127.0.0.1` 로 붙어도 그대로 인증된다.
  DB 가 다른 서버에 있다면 계정을 `'board'@'%'` 로 만들고 `host` 에 그 주소를 적는다.
- **MySQL 5.7 을 지원 대상으로 설계했다.** 재귀 CTE, JSON 컬럼과 JSON 함수, 윈도 함수를
  쓰지 않는다. 그보다 낮은 버전은 대상이 아니다.
- 접속할 때마다 `sql_mode = STRICT_ALL_TABLES` 와 `time_zone = '+00:00'` 을 세션에
  설정한다. 값이 잘려 들어가는 대신 오류가 나고, 시각은 항상 UTC 로 저장된다.

### PostgreSQL

```sql
CREATE ROLE board LOGIN PASSWORD '비밀번호';
CREATE DATABASE board OWNER board ENCODING 'UTF8';
```

```
pgsql:host=127.0.0.1;port=5432;dbname=board
```

- **`OWNER` 를 게시판 계정으로 지정한다.** PostgreSQL 15 부터는 DB 소유자가 아닌 계정이
  `public` 스키마에 테이블을 만들 수 없다. 소유자로 만들어 두면 이 문제가 없다.
  이미 만들어진 DB 를 쓴다면 `GRANT CREATE ON SCHEMA public TO board;` 가 필요하다.
- 비밀번호로 붙으려면 `pg_hba.conf` 의 `host` 줄이 `scram-sha-256` 또는 `md5` 여야 한다.
  `peer` 만 열려 있으면 TCP 접속이 거부된다.
- 접속할 때마다 `SET TIME ZONE 'UTC'` 를 실행한다. 기본키는 `BIGSERIAL` 이다.

### DB 를 나중에 바꾸려면

경우가 둘로 갈린다. **테이블이 이미 있는 DB 인지**가 갈림길이다.
테이블(`boards`, `posts`, `comments`)을 만드는 것은 설치 마법사뿐이고, 평소 실행 중에는
스키마를 만들지 않는다.

**1. 테이블이 이미 있는 DB 로 옮길 때** — 접속 정보만 바뀌었거나, 덤프를 새 서버에
옮겨 놓은 경우다. `config/config.php` 의 `db` 항목만 고치면 끝이다. 재설치할 필요 없다.

```php
'db' => [
    'dsn'      => 'pgsql:host=127.0.0.1;port=5432;dbname=board',
    'username' => 'board',
    'password' => '비밀번호',
],
```

**2. 빈 새 DB 로 옮길 때** — DSN 만 바꾸면 **깨진다.** 접속은 되지만 테이블이 없어서
첫 요청부터 `relation "boards" does not exist` 같은 500 이 난다. 설치 마법사를 다시 한 번
돌려야 하고, 순서가 중요하다.

1. **먼저 지금 `config/config.php` 의 `auth.secret` 을 복사해 둔다.** 재설치는 시크릿을
   새로 만든다. 그대로 두면 저장된 메일 비밀번호를 풀 수 없게 된다.
2. `config/config.php` 를 지운다.
3. `www/install.php` 를 다시 올리고 새 DB 로 설치한다. 2단계에서 표가 없는 빈 DB 를
   고른다.
4. 새로 생긴 `config/config.php` 의 `auth.secret` 을 1번에서 복사해 둔 값으로 되돌린다.

기존 데이터는 어느 쪽이든 따라오지 않는다. 옮기려면 `mysqldump`, `pg_dump`, 또는 SQLite
파일 복사 같은 DB 별 도구를 쓴다.

> `/health` 는 어느 방언으로 붙었는지만 확인한다. **테이블이 있는지는 보지 않으므로**
> 스키마가 비어 있어도 `{"ok":true}` 를 준다. 옮긴 뒤에는 `/boards` 를 한 번 호출해
> 200 이 오는지로 확인한다.

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

## API

전체 명세는 **OpenAPI 3.0** 문서로 있다.

- 스펙 원본: [`docs/openapi.yaml`](docs/openapi.yaml)
- 브라우저에서 보기: `www/docs.php` (Swagger UI). 예) `https://example.com/docs.php`
  - 스펙만 받으려면 `docs.php?spec`
  - 이 화면은 Swagger UI 를 CDN 에서 받아 쓴다. 게시판 자체의 런타임 의존성이 아니라
    이 파일 하나의 의존성이며, 지워도 API 는 그대로 동작한다. 외부 접속이 막힌 곳이라면
    지우고 `docs/openapi.yaml` 을 다른 뷰어에 넣으면 된다.

아래는 요약이다.

| 메서드 | 경로 | 설명 |
|---|---|---|
| POST | `/auth/login` | 부트스트랩 관리자 로그인 |
| GET | `/boards` | 게시판 목록 |
| POST | `/boards` | 게시판 생성 (전역 관리자) |
| GET/PATCH/DELETE | `/boards/{key}` | 게시판 조회/수정/삭제 (`category_renames` 로 분류 이름 변경) |
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
