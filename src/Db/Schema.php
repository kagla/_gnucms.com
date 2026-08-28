<?php

declare(strict_types=1);

namespace ApiBoard\Db;

use ApiBoard\Error\DomainError;

/**
 * DDL 은 치환자 3개({AUTO_PK}, {DATETIME}, {TEXT})만 방언별로 바뀌고
 * 나머지는 세 DB 공통 문법이다.
 */
final class Schema
{
    public const TABLES = [
        'boards', 'posts', 'comments', 'users', 'user_tokens', 'user_identities', 'site_state',
        'site_settings', 'mail_settings', 'pages', 'user_consents',
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

    public function create(): void
    {
        if ($this->exists()) {
            return;
        }

        foreach ($this->statements() as $sql) {
            $this->db->execute($this->expand($sql));
        }
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

    public function migrateCms(): void
    {
        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('site_settings'));
        } catch (DomainError $e) {
            foreach ($this->settingsStatements() as $sql) {
                $this->db->execute($this->expand($sql));
            }
        }

        try {
            $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('pages'));
        } catch (DomainError $e) {
            foreach ($this->pageStatements() as $sql) {
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
            $this->db->selectOne('SELECT deleted_at FROM ' . $this->db->q('pages') . ' LIMIT 1');
        } catch (DomainError $e) {
            $this->db->execute('ALTER TABLE ' . $this->db->q('pages') . ' ADD COLUMN deleted_at '
                . $this->db->dialect()->typeMap()['{DATETIME}'] . ' NULL');
        }
        try {
            $this->db->selectOne('SELECT image_key FROM ' . $this->db->q('pages') . ' LIMIT 1');
        } catch (DomainError $e) {
            $this->db->execute('ALTER TABLE ' . $this->db->q('pages')
                . ' ADD COLUMN image_key VARCHAR(32) NULL');
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
                created_at     {DATETIME}   NOT NULL,
                updated_at     {DATETIME}   NOT NULL,
                deleted_at     {DATETIME}   NULL
            ){SUFFIX}',

            'CREATE INDEX ix_comments_post ON comments (post_id, id)',
        ], $this->accountStatements(), $this->settingsStatements(), $this->mailSettingsStatements(),
            $this->pageStatements(), $this->consentStatements());
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
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('site_name', 'aboard', '2026-01-01 00:00:00')",
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('site_tagline', '가볍게 시작하는 기초 커뮤니티', '2026-01-01 00:00:00')",
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('home_title', '가볍게 시작하고, 오래 이어지는 공간', '2026-01-01 00:00:00')",
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('home_intro', '필요한 페이지와 커뮤니티를 한곳에서 운영하세요.', '2026-01-01 00:00:00')",
            "INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES ('registration_enabled', '1', '2026-01-01 00:00:00')",
        ];
    }

    private function pageStatements(): array
    {
        return [
            'CREATE TABLE pages (
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
                image_key       VARCHAR(32)  NULL
            ){SUFFIX}',
            'CREATE UNIQUE INDEX ux_pages_slug ON pages (slug)',
            'CREATE INDEX ix_pages_public ON pages (status, show_in_menu, sort_order, id)',
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
