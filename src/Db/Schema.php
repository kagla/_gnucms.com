<?php

declare(strict_types=1);

namespace GnuCms\Db;

use GnuCms\Error\DomainError;

/**
 * DDL 은 치환자 3개({AUTO_PK}, {DATETIME}, {TEXT})만 방언별로 바뀌고
 * 나머지는 세 DB 공통 문법이다.
 */
final class Schema
{
    public const TABLES = [
        'boards', 'posts', 'comments', 'users', 'user_tokens', 'user_identities', 'site_state',
        'site_settings', 'mail_settings', 'contents', 'user_consents',
    ];

    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function exists(): bool
    {
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('boards'));

            return true;
        } catch (DomainError $e) {
            // Throwable 이 아니라 DomainError 로 좁혀 잡는다. Connection 은 PDOException 을
            // DomainError 로 감싸므로 "테이블 없음" 은 여기로 온다. Throwable 까지 잡으면
            // Connection 이나 Schema 자체의 버그(TypeError 등)가 "테이블 없음" 으로
            // 둔갑해 조용히 묻힌다.
            return false;
        }
    }

    /**
     * 코드가 요구하는 스키마 판. 컬럼을 늘릴 때마다 하나씩 올린다.
     * DB 에 적힌 값이 이 값보다 낮으면 ensureCurrent() 가 마이그레이션을 돌린다.
     */
    public const VERSION = '7';

    /**
     * DB 스키마를 코드에 맞춘다. 이미 최신이면 설정값 하나만 읽고 끝난다.
     *
     * 컬럼이 늘어난 뒤 마이그레이션을 잊으면 목록 조회가 통째로 실패해 사이트가 멈춘다.
     * 사람이 기억해야 하는 절차로 두지 않고 부팅할 때 스스로 맞춘다.
     * 각 migrate* 는 여러 번 돌려도 안전하므로 동시 요청이 겹쳐도 문제되지 않는다.
     */
    public function ensureCurrent(): void
    {
        try {
            $row = $this->db->selectOne(
                'SELECT setting_value FROM ' . $this->db->q('site_settings') . ' WHERE setting_key = ?',
                ['schema_version']
            );
        } catch (DomainError $e) {
            // site_settings 자체가 없는 아주 오래된 설치. 전체 마이그레이션이 만들어 준다.
            $row = null;
        }

        if ($row !== null && (string) $row['setting_value'] === self::VERSION) {
            return;
        }

        $this->migrateAll();
    }

    /** 지금까지의 마이그레이션을 순서대로 모두 적용한다. */
    public function migrateAll(): void
    {
        $this->migrateAccounts();
        // 표 이름을 먼저 옮겨야 migrateCms() 가 빈 표를 새로 만들지 않는다.
        $this->migrateContentTableName();
        $this->migrateCms();
        $this->migrateDefaultTheme();
        $this->migrateBoards();
        $this->migrateEditorImages();
        $this->migrateNotifications();
        $this->ensureSiteSetting('schema_version', self::VERSION);
        $this->db->execute(
            'UPDATE ' . $this->db->q('site_settings')
            . ' SET setting_value = ? WHERE setting_key = ?',
            [self::VERSION, 'schema_version']
        );
    }

    public function create(): void
    {
        if ($this->exists()) {
            return;
        }

        foreach ($this->statements() as $sql) {
            $this->db->execute($this->expand($sql));
        }

        // 새로 만든 스키마는 이미 최신이다. 첫 요청에서 헛돌지 않게 표시해 둔다.
        $this->ensureSiteSetting('schema_version', self::VERSION);
    }

    /** 기존 게시판 설치에 회원 테이블만 안전하게 추가한다. */
    public function migrateAccounts(): void
    {
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('users'));
        } catch (DomainError $e) {
            foreach ($this->accountStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
            return;
        }

        try {
            $this->db->selectOne('SELECT email_verified FROM ' . $this->db->q('users') . ' LIMIT 1');
        } catch (DomainError $e) {
            $this->db->execute(
                'ALTER TABLE ' . $this->db->q('users')
                . ' ADD COLUMN email_verified SMALLINT NOT NULL DEFAULT 0'
            );
        }

        try {
            $this->db->selectOne('SELECT display_name FROM ' . $this->db->q('users') . ' LIMIT 1');
        } catch (DomainError $e) {
            $this->renameUserDisplayNameColumn();
        }

        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('user_tokens'));
        } catch (DomainError $e) {
            foreach ($this->tokenStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
        }

        $this->migrateOauth();
        $this->migrateFirstAdminState();
    }

    /**
     * 기존 설치에 게시판 목록 형태(list_type) 컬럼을 추가한다.
     * migrateAccounts()/migrateCms() 와 같은 방식으로, 업그레이드할 때 한 번 부른다.
     */
    public function migrateBoards(): void
    {
        $this->addColumnIfMissing('boards', 'list_type', 'VARCHAR(20) NOT NULL DEFAULT \'list\'');
        $this->addColumnIfMissing('boards', 'home_limit', 'INTEGER NOT NULL DEFAULT 5');
    }

    /** 글·댓글 본문 편집기가 올린 이미지를 묶어 두는 키. 업그레이드할 때 한 번 부른다. */
    public function migrateEditorImages(): void
    {
        foreach (['posts', 'comments'] as $table) {
            $this->addColumnIfMissing($table, 'image_key', 'VARCHAR(32) NULL');
        }
    }

    /** 알림함 표. 기존 설치에는 없으므로 업그레이드할 때 만든다. */
    public function migrateNotifications(): void
    {
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('notifications'));
        } catch (DomainError $e) {
            foreach ($this->notificationStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
        }
    }

    /**
     * 컬럼이 없으면 더한다. 표 자체가 없으면 아직 설치 전이므로 그냥 넘어간다
     * (표를 만드는 일은 create() 의 몫이다).
     */
    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q($table));
        } catch (DomainError $e) {
            return;
        }

        try {
            $this->db->selectOne('SELECT ' . $column . ' FROM ' . $this->db->q($table) . ' LIMIT 1');
        } catch (DomainError $e) {
            $this->db->execute('ALTER TABLE ' . $this->db->q($table)
                . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    public function migrateCms(): void
    {
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('site_settings'));
        } catch (DomainError $e) {
            foreach ($this->settingsStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
        }
        $this->ensureSiteSetting('theme', 'codex-preline');

        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('contents'));
        } catch (DomainError $e) {
            foreach ($this->contentStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
        }

        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('mail_settings'));
        } catch (DomainError $e) {
            foreach ($this->mailSettingsStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
        }
        try {
            $this->db->selectOne('SELECT deleted_at FROM ' . $this->db->q('contents') . ' LIMIT 1');
        } catch (DomainError $e) {
            $this->db->execute('ALTER TABLE ' . $this->db->q('contents') . ' ADD COLUMN deleted_at '
                . $this->db->dialect()->typeMap()['{DATETIME}'] . ' NULL');
        }
        try {
            $this->db->selectOne('SELECT image_key FROM ' . $this->db->q('contents') . ' LIMIT 1');
        } catch (DomainError $e) {
            $this->db->execute('ALTER TABLE ' . $this->db->q('contents')
                . ' ADD COLUMN image_key VARCHAR(32) NULL');
        }

        // 약관을 내용과 한 표에서 다루기 위한 표시. 값이 있으면 가입 화면의 동의 항목이 된다.
        try {
            $this->db->selectOne('SELECT consent_key FROM ' . $this->db->q('contents') . ' LIMIT 1');
        } catch (DomainError $e) {
            $this->db->execute('ALTER TABLE ' . $this->db->q('contents')
                . ' ADD COLUMN consent_key VARCHAR(20) NULL');
            $this->db->execute('ALTER TABLE ' . $this->db->q('contents')
                . ' ADD COLUMN consent_order INTEGER NOT NULL DEFAULT 0');
            // 이미 있던 이용약관·개인정보 처리방침을 그대로 동의 항목으로 삼는다.
            foreach ([['terms', 10], ['privacy', 20]] as [$slug, $order]) {
                $this->db->execute(
                    'UPDATE ' . $this->db->q('contents')
                    . ' SET consent_key = ?, consent_order = ? WHERE slug = ?',
                    [$slug, $order, $slug]
                );
            }
            try {
                $this->db->execute('CREATE UNIQUE INDEX ux_contents_consent ON '
                    . $this->db->q('contents') . ' (consent_key)');
            } catch (DomainError $e) {
                // 이미 있으면 그대로 둔다
            }
        }

        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('user_consents'));
        } catch (DomainError $e) {
            foreach ($this->consentStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
        }
    }

    public function drop(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            try {
                $this->db->execute('DROP TABLE IF EXISTS ' . $this->db->q($table));
            } catch (DomainError $e) {
                // 이미 없는 경우는 성공으로 본다.
            }
        }
    }

    private function expand(string $sql): string
    {
        $map = $this->db->dialect()->typeMap();
        $map['{SUFFIX}'] = $this->db->dialect()->tableSuffix();

        return strtr($sql, $map);
    }

    /** @return string[] */
    private function statements(): array
    {
        return array_merge([
            'CREATE TABLE boards (
                id            {AUTO_PK},
                board_key     VARCHAR(50)  NOT NULL,
                name          VARCHAR(100) NOT NULL,
                description   {TEXT}       NULL,
                categories    {TEXT}       NULL,
                managers      {TEXT}       NULL,
                perm_read     VARCHAR(10)  NOT NULL DEFAULT \'guest\',
                perm_write    VARCHAR(10)  NOT NULL DEFAULT \'member\',
                perm_comment  VARCHAR(10)  NOT NULL DEFAULT \'member\',
                use_secret    SMALLINT     NOT NULL DEFAULT 0,
                use_file      SMALLINT     NOT NULL DEFAULT 0,
                use_category  SMALLINT     NOT NULL DEFAULT 0,
                list_type     VARCHAR(20)  NOT NULL DEFAULT \'list\',
                home_limit    INTEGER      NOT NULL DEFAULT 5,
                per_page      INTEGER      NOT NULL DEFAULT 20,
                sort_order    INTEGER      NOT NULL DEFAULT 0,
                created_at    {DATETIME}   NOT NULL,
                updated_at    {DATETIME}   NOT NULL
            ){SUFFIX}',

            'CREATE UNIQUE INDEX ux_boards_key ON boards (board_key)',

            'CREATE TABLE posts (
                id             {AUTO_PK},
                board_id       BIGINT       NOT NULL,
                category       VARCHAR(50)  NULL,
                title          VARCHAR(200) NOT NULL,
                content        {TEXT}       NOT NULL,
                author_id      VARCHAR(64)  NULL,
                author_name    VARCHAR(100) NOT NULL,
                guest_password VARCHAR(255) NULL,
                is_notice      SMALLINT     NOT NULL DEFAULT 0,
                is_secret      SMALLINT     NOT NULL DEFAULT 0,
                view_count     INTEGER      NOT NULL DEFAULT 0,
                comment_count  INTEGER      NOT NULL DEFAULT 0,
                attachments    {TEXT}       NULL,
                image_key      VARCHAR(32)  NULL,
                created_at     {DATETIME}   NOT NULL,
                updated_at     {DATETIME}   NOT NULL,
                deleted_at     {DATETIME}   NULL
            ){SUFFIX}',

            'CREATE INDEX ix_posts_list ON posts (board_id, deleted_at, is_notice, id)',
            'CREATE INDEX ix_posts_category ON posts (board_id, category)',

            'CREATE TABLE comments (
                id             {AUTO_PK},
                board_id       BIGINT       NOT NULL,
                post_id        BIGINT       NOT NULL,
                parent_id      BIGINT       NULL,
                depth          SMALLINT     NOT NULL DEFAULT 0,
                content        {TEXT}       NOT NULL,
                author_id      VARCHAR(64)  NULL,
                author_name    VARCHAR(100) NOT NULL,
                guest_password VARCHAR(255) NULL,
                is_secret      SMALLINT     NOT NULL DEFAULT 0,
                image_key      VARCHAR(32)  NULL,
                created_at     {DATETIME}   NOT NULL,
                updated_at     {DATETIME}   NOT NULL,
                deleted_at     {DATETIME}   NULL
            ){SUFFIX}',

            'CREATE INDEX ix_comments_post ON comments (post_id, id)',
        ], $this->accountStatements(), $this->settingsStatements(), $this->mailSettingsStatements(),
            $this->contentStatements(), $this->consentStatements(), $this->notificationStatements());
    }

    private function accountStatements(): array
    {
        return array_merge([
            $this->usersTableStatement(),
            'CREATE UNIQUE INDEX ux_users_email ON users (email)',
        ], $this->tokenStatements(), $this->identityStatements(), $this->stateStatements(false));
    }

    private function usersTableStatement(): string
    {
        return 'CREATE TABLE users (
                id             {AUTO_PK},
                email          VARCHAR(191) NOT NULL,
                email_verified SMALLINT     NOT NULL DEFAULT 0,
                password_hash  VARCHAR(255) NULL,
                display_name   VARCHAR(100) NOT NULL,
                is_admin       SMALLINT     NOT NULL DEFAULT 0,
                status         VARCHAR(10)  NOT NULL DEFAULT \'active\',
                session_epoch  INTEGER      NOT NULL DEFAULT 0,
                created_at     {DATETIME}   NOT NULL,
                updated_at     {DATETIME}   NOT NULL
            ){SUFFIX}';
    }

    private function tokenStatements(): array
    {
        return [
            'CREATE TABLE user_tokens (
                id         {AUTO_PK},
                user_id    BIGINT      NOT NULL,
                purpose    VARCHAR(20) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at {DATETIME}  NOT NULL,
                used_at    {DATETIME}  NULL,
                created_at {DATETIME}  NOT NULL
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_user_tokens_hash ON user_tokens (token_hash)',
            'CREATE INDEX ix_user_tokens_user ON user_tokens (user_id, purpose)',
        ];
    }

    private function identityStatements(): array
    {
        return [
            'CREATE TABLE user_identities (
                id           {AUTO_PK},
                user_id      BIGINT       NOT NULL,
                provider     VARCHAR(20)  NOT NULL,
                provider_uid VARCHAR(191) NOT NULL,
                created_at   {DATETIME}   NOT NULL
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_user_identities_provider ON user_identities (provider, provider_uid)',
            'CREATE INDEX ix_user_identities_user ON user_identities (user_id)',
        ];
    }

    private function stateStatements(bool $claimed): array
    {
        return [
            'CREATE TABLE site_state (
                state_key   VARCHAR(50)  NOT NULL,
                state_value VARCHAR(191) NOT NULL
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_site_state_key ON site_state (state_key)',
            "INSERT INTO site_state (state_key, state_value) VALUES ('first_admin_claimed', '"
                . ($claimed ? '1' : '0') . "')",
        ];
    }

    private function settingsStatements(): array
    {
        return [
            'CREATE TABLE site_settings (
                setting_key   VARCHAR(50)  NOT NULL,
                setting_value {TEXT}       NOT NULL,
                updated_at    {DATETIME}   NOT NULL
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_site_settings_key ON site_settings (setting_key)',
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('site_name', '" . GNUCMS . "', '2026-01-01 00:00:00')",
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('site_tagline', '가볍게 시작하는 기초 커뮤니티', '2026-01-01 00:00:00')",
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('home_title', '가볍게 시작하고, 오래 이어지는 공간', '2026-01-01 00:00:00')",
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('home_intro', '필요한 페이지와 커뮤니티를 한곳에서 운영하세요.', '2026-01-01 00:00:00')",
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('registration_enabled', '1', '2026-01-01 00:00:00')",
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('theme', 'codex-preline', '2026-01-01 00:00:00')",
        ];
    }

    /**
     * pages 표를 contents 로 옮긴다.
     * 관리 화면(내용 관리)도 주소(/content/{slug})도 이미 '내용' 인데 표만 pages 였다.
     * 표 이름만 바꾸면 인덱스는 그대로 따라오지만, 새로 설치한 곳과 이름이 갈리므로
     * 인덱스도 새 이름으로 다시 만든다. 여러 번 돌려도 안전하다.
     */
    private function migrateContentTableName(): void
    {
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('contents'));
            return; // 이미 새 이름이다
        } catch (DomainError $e) {
            // 아직 옛 이름이거나, 둘 다 없다
        }

        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('pages'));
        } catch (DomainError $e) {
            return; // 옛 표도 없다. migrateCms() 가 새로 만든다
        }

        $this->db->execute(
            'ALTER TABLE ' . $this->db->q('pages') . ' RENAME TO ' . $this->db->q('contents')
        );

        $mysql = $this->db->dialect()->name() === 'mysql';
        foreach ([
            ['ux_pages_slug', 'CREATE UNIQUE INDEX ux_contents_slug ON contents (slug)'],
            ['ix_pages_public', 'CREATE INDEX ix_contents_public ON contents (status, show_in_menu, sort_order, id)'],
        ] as [$oldIndex, $createSql]) {
            try {
                $this->db->execute($mysql
                    ? 'DROP INDEX ' . $this->db->q($oldIndex) . ' ON ' . $this->db->q('contents')
                    : 'DROP INDEX ' . $this->db->q($oldIndex));
            } catch (DomainError $e) {
                // 옛 인덱스가 없으면 그대로 둔다
            }
            try {
                $this->db->execute($this->expand($createSql));
            } catch (DomainError $e) {
                // 이미 새 이름이면 그대로 둔다
            }
        }
    }

    /** 기존 기본 테마 사용자만 새 기본 디자인으로 옮기고, 직접 고른 테마는 보존한다. */
    private function migrateDefaultTheme(): void
    {
        $this->db->execute(
            'UPDATE ' . $this->db->q('site_settings')
            . ' SET setting_value = ?, updated_at = ? WHERE setting_key = ? AND setting_value = ?',
            ['codex-preline', '2026-08-28 00:00:00', 'theme', 'default']
        );
    }

    private function ensureSiteSetting(string $key, string $value): void
    {
        $existing = $this->db->selectOne(
            'SELECT setting_key FROM ' . $this->db->q('site_settings') . ' WHERE setting_key = ?',
            [$key]
        );
        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO ' . $this->db->q('site_settings')
                . ' (setting_key, setting_value, updated_at) VALUES (?, ?, ?)',
                [$key, $value, '2026-08-28 00:00:00']
            );
        }
    }

    private function contentStatements(): array
    {
        return [
            'CREATE TABLE contents (
                id              {AUTO_PK},
                slug            VARCHAR(100) NOT NULL,
                title           VARCHAR(200) NOT NULL,
                content         {TEXT}       NOT NULL,
                seo_description VARCHAR(300) NULL,
                status          VARCHAR(10)  NOT NULL DEFAULT \'draft\',
                show_in_menu    SMALLINT     NOT NULL DEFAULT 0,
                sort_order      INTEGER      NOT NULL DEFAULT 0,
                created_at      {DATETIME}   NOT NULL,
                updated_at      {DATETIME}   NOT NULL,
                published_at    {DATETIME}   NULL,
                deleted_at      {DATETIME}   NULL,
                image_key       VARCHAR(32)  NULL,
                consent_key     VARCHAR(20)  NULL,
                consent_order   INTEGER      NOT NULL DEFAULT 0
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_contents_slug ON contents (slug)',
            'CREATE INDEX ix_contents_public ON contents (status, show_in_menu, sort_order, id)',
            'CREATE UNIQUE INDEX ux_contents_consent ON contents (consent_key)',
        ];
    }

    private function mailSettingsStatements(): array
    {
        return [
            'CREATE TABLE mail_settings (
                setting_key   VARCHAR(50) NOT NULL,
                setting_value {TEXT}      NOT NULL,
                updated_at    {DATETIME}  NOT NULL
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_mail_settings_key ON mail_settings (setting_key)',
        ];
    }

    /**
     * 알림함. 회원에게만 쌓이므로 user_id 는 users.id 를 문자열로 담는
     * posts.author_id / comments.author_id 와 같은 형태로 맞춘다.
     */
    private function notificationStatements(): array
    {
        return [
            'CREATE TABLE notifications (
                id          {AUTO_PK},
                user_id     VARCHAR(64)  NOT NULL,
                kind        VARCHAR(20)  NOT NULL,
                post_id     BIGINT       NOT NULL,
                comment_id  BIGINT       NULL,
                actor_name  VARCHAR(100) NOT NULL,
                subject     VARCHAR(200) NOT NULL,
                is_read     SMALLINT     NOT NULL DEFAULT 0,
                created_at  {DATETIME}   NOT NULL
            ){SUFFIX}',
            'CREATE INDEX ix_notifications_user ON notifications (user_id, is_read, id)',
        ];
    }

    private function consentStatements(): array
    {
        return [
            'CREATE TABLE user_consents (
                id                  {AUTO_PK},
                user_id             BIGINT       NOT NULL,
                consent_type        VARCHAR(20)  NOT NULL,
                content_id          BIGINT       NOT NULL,
                content_updated_at  {DATETIME}   NOT NULL,
                agreed_at           {DATETIME}   NOT NULL
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_user_consents_type ON user_consents (user_id, consent_type)',
            'CREATE INDEX ix_user_consents_content ON user_consents (content_id)',
        ];
    }

    private function migrateFirstAdminState(): void
    {
        try {
            $state = $this->db->selectOne('SELECT state_value FROM ' . $this->db->q('site_state')
                . " WHERE state_key = 'first_admin_claimed'");
            if ($state !== null) {
                return;
            }
        } catch (DomainError $e) {
            $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('users'));
            foreach ($this->stateStatements((int) ($row['c'] ?? 0) > 0) as $sql) {
                $this->db->execute($this->expand($sql));
            }
            return;
        }
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('users'));
        $this->db->execute(
            'INSERT INTO ' . $this->db->q('site_state') . ' (state_key, state_value) VALUES (?, ?)',
            ['first_admin_claimed', (int) ($row['c'] ?? 0) > 0 ? '1' : '0']
        );
    }

    private function migrateOauth(): void
    {
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('user_identities'));
        } catch (DomainError $e) {
            foreach ($this->identityStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
        }

        $name = $this->db->dialect()->name();
        if ($name === 'sqlite') {
            $columns = $this->db->select('PRAGMA table_info(users)');
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'password_hash' && (int) ($column['notnull'] ?? 0) === 1) {
                    $this->rebuildSqliteUsers();
                    break;
                }
            }
        } elseif ($name === 'mysql') {
            $this->db->execute('ALTER TABLE ' . $this->db->q('users') . ' MODIFY password_hash VARCHAR(255) NULL');
        } elseif ($name === 'pgsql') {
            $this->db->execute('ALTER TABLE ' . $this->db->q('users') . ' ALTER COLUMN password_hash DROP NOT NULL');
        }
    }

    private function renameUserDisplayNameColumn(): void
    {
        if ($this->db->dialect()->name() === 'mysql') {
            $this->db->execute('ALTER TABLE ' . $this->db->q('users')
                . ' CHANGE ' . $this->db->q('name') . ' ' . $this->db->q('display_name')
                . ' VARCHAR(100) NOT NULL');
            return;
        }
        $this->db->execute('ALTER TABLE ' . $this->db->q('users')
            . ' RENAME COLUMN ' . $this->db->q('name') . ' TO ' . $this->db->q('display_name'));
    }

    private function rebuildSqliteUsers(): void
    {
        $this->db->transaction(function (): void {
            $this->db->execute('ALTER TABLE users RENAME TO users_before_oauth');
            $this->db->execute($this->expand($this->usersTableStatement()));
            $columns = 'id, email, email_verified, password_hash, display_name, is_admin, status, session_epoch, created_at, updated_at';
            $this->db->execute('INSERT INTO users (' . $columns . ') SELECT ' . $columns . ' FROM users_before_oauth');
            $this->db->execute('DROP TABLE users_before_oauth');
            $this->db->execute('CREATE UNIQUE INDEX ux_users_email ON users (email)');
        });
    }
}
