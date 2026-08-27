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
    public const TABLES = ['boards', 'posts', 'comments'];

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
        return [
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
        ];
    }
}
