<?php

declare(strict_types=1);

namespace StandardBoard\Repository;

use StandardBoard\Db\Connection;
use StandardBoard\Support\Clock;
use StandardBoard\Support\Json;

final class PostRepository
{
    /**
     * 기본 조회 컬럼. guest_password 가 빠져 있는 것이 핵심이다.
     * 이 목록에 없는 컬럼은 findWithSecret() 로만 얻을 수 있다.
     */
    private const COLUMNS = 'id, board_id, category, title, content, author_id, author_name,'
        . ' is_notice, is_secret, view_count, comment_count, attachments,'
        . ' created_at, updated_at, deleted_at';

    private const DEFAULTS = [
        'category'       => null,
        'author_id'      => null,
        'guest_password' => null,
        'is_notice'      => 0,
        'is_secret'      => 0,
        'view_count'     => 0,
        'comment_count'  => 0,
        'attachments'    => [],
    ];

    /** LIKE 검색의 이스케이프 문자. 백슬래시는 MySQL 문자열 리터럴에서 한 번 더 처리되므로 피한다. */
    private const LIKE_ESCAPE = '!';

    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function find(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->q('posts') . ' WHERE id = ?',
            [$id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findWithSecret(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ', guest_password FROM ' . $this->db->q('posts') . ' WHERE id = ?',
            [$id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array{rows: array, total: int} */
    public function paginate(
        int $boardId,
        int $page,
        int $perPage,
        ?string $q = null,
        ?string $category = null,
        bool $includeDeleted = false
    ): array {
        // 삭제 글 포함은 관리자 화면의 복구 목록을 위한 것이다.
        // 서비스 계층에서 관리 권한을 확인한 뒤에만 true 로 넘어온다.
        $where = $includeDeleted
            ? 'board_id = :board_id AND is_notice = 0'
            : 'board_id = :board_id AND deleted_at IS NULL AND is_notice = 0';
        $params = ['board_id' => $boardId];

        if ($q !== null && $q !== '') {
            $where .= ' AND (title LIKE :q ESCAPE \'' . self::LIKE_ESCAPE . '\''
                . ' OR content LIKE :q2 ESCAPE \'' . self::LIKE_ESCAPE . '\')';
            $pattern = '%' . $this->escapeLike($q) . '%';
            $params['q'] = $pattern;
            $params['q2'] = $pattern;
        }

        if ($category !== null && $category !== '') {
            $where .= ' AND category = :category';
            $params['category'] = $category;
        }

        $total = (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->q('posts') . ' WHERE ' . $where,
            $params
        )['c'];

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->q('posts')
            . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => array_map([$this, 'hydrate'], $rows), 'total' => $total];
    }

    public function notices(int $boardId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->q('posts')
            . ' WHERE board_id = ? AND deleted_at IS NULL AND is_notice = 1 ORDER BY id DESC',
            [$boardId]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    public function create(array $data): int
    {
        $now = Clock::now();
        $row = array_merge(self::DEFAULTS, $data, [
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        return (int) $this->db->insert('posts', $this->dehydrate($row));
    }

    public function update(int $id, array $data): void
    {
        unset($data['id'], $data['board_id'], $data['created_at']);
        $data['updated_at'] = Clock::now();

        $this->db->update('posts', $this->dehydrate($data), 'id = :id', ['id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->db->update('posts', ['deleted_at' => Clock::now()], 'id = :id', ['id' => $id]);
    }

    public function restore(int $id): void
    {
        $this->db->update('posts', ['deleted_at' => null], 'id = :id', ['id' => $id]);
    }

    public function incrementViews(int $id): void
    {
        $this->db->execute(
            'UPDATE ' . $this->db->q('posts') . ' SET view_count = view_count + 1 WHERE id = ?',
            [$id]
        );
    }

    public function setNotice(int $id, bool $isNotice): void
    {
        $this->db->update('posts', ['is_notice' => $isNotice ? 1 : 0], 'id = :id', ['id' => $id]);
    }

    public function adjustCommentCount(int $id, int $delta): void
    {
        // 0 미만으로 내려가지 않도록 GREATEST 대신 CASE 를 쓴다. 세 DB 공통 문법이다.
        $this->db->execute(
            'UPDATE ' . $this->db->q('posts')
            . ' SET comment_count = CASE WHEN comment_count + ? < 0 THEN 0 ELSE comment_count + ? END'
            . ' WHERE id = ?',
            [$delta, $delta, $id]
        );
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $value
        );
    }

    private function hydrate(array $row): array
    {
        $raw = $row['attachments'];
        $row['attachments'] = ($raw === null || $raw === '') ? [] : Json::decode((string) $raw);

        foreach (['id', 'board_id', 'is_notice', 'is_secret', 'view_count', 'comment_count'] as $column) {
            $row[$column] = (int) $row[$column];
        }

        return $row;
    }

    private function dehydrate(array $row): array
    {
        if (array_key_exists('attachments', $row) && is_array($row['attachments'])) {
            $row['attachments'] = Json::encode(array_values($row['attachments']));
        }

        foreach (['is_notice', 'is_secret'] as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = (int) (bool) $row[$column];
            }
        }

        return $row;
    }
}
