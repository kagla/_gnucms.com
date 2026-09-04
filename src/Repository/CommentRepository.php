<?php

declare(strict_types=1);

namespace GnuCms\Repository;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

final class CommentRepository
{
    private const COLUMNS = 'id, board_id, post_id, parent_id, depth, content, author_id, author_name, author_ip,'
        . ' is_secret, image_key, created_at, updated_at, deleted_at';

    private const DEFAULTS = [
        'parent_id'      => null,
        'author_id'      => null,
        'guest_password' => null,
        'author_ip'      => null,
        'is_secret'      => 0,
        'image_key'      => null,
    ];

    /** LIKE 검색의 이스케이프 문자. DB 방언마다 다르게 다루는 백슬래시는 피한다. */
    private const LIKE_ESCAPE = '!';

    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findByPost(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->table('comments')
            . ' WHERE post_id = ? ORDER BY id ASC',
            [$postId]
        );

        return $this->hydrateMany($rows);
    }

    /** 비밀 댓글 소유권 판정용. 반환값을 화면에 직접 넘기지 않는다. */
    public function findByPostWithSecret(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ', guest_password FROM ' . $this->db->table('comments')
            . ' WHERE post_id = ? ORDER BY id ASC',
            [$postId]
        );

        return $this->hydrateMany($rows);
    }

    public function find(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->table('comments') . ' WHERE id = ?',
            [$id]
        );

        return $row === null ? null : $this->hydrateMany([$row])[0];
    }

    public function findWithSecret(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ', guest_password FROM ' . $this->db->table('comments') . ' WHERE id = ?',
            [$id]
        );

        return $row === null ? null : $this->hydrateMany([$row])[0];
    }

    public function create(array $data): int
    {
        $row = array_merge(self::DEFAULTS, $data);
        $row['depth'] = $this->depthFor($row['parent_id'] === null ? null : (int) $row['parent_id']);

        $now = Clock::now();
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $row['deleted_at'] = null;
        $row['is_secret'] = (int) (bool) $row['is_secret'];

        return (int) $this->db->insert('comments', $row);
    }

    public function update(int $id, array $data): void
    {
        unset($data['id'], $data['board_id'], $data['post_id'], $data['parent_id'], $data['depth'], $data['created_at']);
        $data['updated_at'] = Clock::now();

        $this->db->update('comments', $data, 'id = :id', ['id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->db->update('comments', ['deleted_at' => Clock::now()], 'id = :id', ['id' => $id]);
    }

    public function deleteByBoard(int $boardId): void
    {
        $this->db->delete('comments', 'board_id = :board_id', ['board_id' => $boardId]);
    }

    /**
     * 한 회원이 남긴 댓글을 최신순으로. 지운 댓글과 읽을 수 없는 게시판은 뺀다.
     *
     * @param int[] $boardIds 읽을 수 있는 게시판 번호. 빈 배열이면 아무것도 없다
     * @return array{rows: array, total: int}
     */
    public function paginateByAuthor(int $authorId, array $boardIds, int $page, int $perPage): array
    {
        return $this->paginateForList($boardIds, $page, $perPage, $authorId);
    }

    /**
     * 읽을 수 있는 모든 게시판의 댓글을 최신순으로 모은다.
     *
     * @param int[] $boardIds
     * @return array{rows: array, total: int}
     */
    public function paginateAll(array $boardIds, int $page, int $perPage): array
    {
        return $this->paginateForList($boardIds, $page, $perPage, null);
    }

    /**
     * 한 게시판의 공개 댓글을 검색한다. 글 제목을 같은 조회에서 붙여 검색 결과가
     * 어느 글에 달린 댓글인지 추가 조회 없이 보여 줄 수 있게 한다.
     *
     * 비밀글과 비밀댓글은 검색어 일치 여부 자체가 내용을 짐작하게 할 수 있으므로
     * 권한과 관계없이 검색 결과에서 제외한다.
     *
     * @return array{rows: array, total: int}
     */
    public function searchByBoard(
        int $boardId,
        string $q,
        int $page,
        int $perPage,
        ?string $category = null
    ): array {
        $comments = $this->db->table('comments');
        $posts = $this->db->table('posts');
        $where = 'c.board_id = :board_id AND c.deleted_at IS NULL AND c.is_secret = 0'
            . ' AND p.deleted_at IS NULL AND p.is_secret = 0'
            . ' AND c.content LIKE :q ESCAPE \'' . self::LIKE_ESCAPE . '\'';
        $params = [
            'board_id' => $boardId,
            'q' => '%' . $this->escapeLike($q) . '%',
        ];
        if ($category !== null && $category !== '') {
            $where .= ' AND p.category = :category';
            $params['category'] = $category;
        }

        $from = $comments . ' c INNER JOIN ' . $posts . ' p ON p.id = c.post_id AND p.board_id = c.board_id';
        $total = (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $from . ' WHERE ' . $where,
            $params
        )['c'];

        $offset = max(0, ($page - 1) * $perPage);
        $columns = 'c.' . str_replace(', ', ', c.', self::COLUMNS);
        $rows = $this->db->select(
            'SELECT ' . $columns . ', p.title AS post_title FROM ' . $from
            . ' WHERE ' . $where . ' ORDER BY c.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $this->hydrateMany($rows), 'total' => $total];
    }

    /** @param int[] $boardIds @return array{rows: array, total: int} */
    private function paginateForList(array $boardIds, int $page, int $perPage, ?int $authorId): array
    {
        if ($boardIds === []) {
            return ['rows' => [], 'total' => 0];
        }

        $params = [];
        $marks = [];
        foreach (array_values($boardIds) as $i => $id) {
            $marks[] = ':b' . $i;
            $params['b' . $i] = (int) $id;
        }
        // 이 목록은 요청자 권한으로 그려지지만, 비밀글은 글 자체가 403 인데 댓글 본문만
        // 새는 구멍이 있어 글 단위로 막는다. 지운 글도 같다 — 링크가 404 인 줄을 보여
        // 주지 않기 위해서다.
        // 이 필터는 권한과 무관하게 걸리므로 글쓴이 본인과 관리자에게도 그 줄이 보이지
        // 않는다 (의도된 선택).
        $where = 'deleted_at IS NULL';
        if ($authorId !== null) {
            $where .= ' AND author_id = :author_id';
            $params['author_id'] = $authorId;
        }
        $where .= ' AND board_id IN (' . implode(', ', $marks) . ')'
            . ' AND post_id IN (SELECT id FROM ' . $this->db->table('posts')
            . ' WHERE deleted_at IS NULL AND is_secret = 0)';

        $total = (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('comments') . ' WHERE ' . $where,
            $params
        )['c'];

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->table('comments')
            . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $this->hydrateMany($rows), 'total' => $total];
    }

    public function hasChildren(int $id): bool
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('comments') . ' WHERE parent_id = ?',
            [$id]
        );

        return (int) $row['c'] > 0;
    }

    private function depthFor(?int $parentId): int
    {
        if ($parentId === null) {
            return 0;
        }

        $row = $this->db->selectOne(
            'SELECT depth FROM ' . $this->db->table('comments') . ' WHERE id = ?',
            [$parentId]
        );

        return $row === null ? 0 : (int) $row['depth'] + 1;
    }

    private function hydrate(array $row): array
    {
        foreach (['id', 'board_id', 'post_id', 'depth', 'is_secret'] as $column) {
            $row[$column] = (int) $row[$column];
        }
        $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];

        return $row;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $value
        );
    }

    /** 회원 작성자의 현재 프로필 이미지를 한 번의 추가 조회로 붙인다. */
    private function hydrateMany(array $rows): array
    {
        $rows = array_map([$this, 'hydrate'], $rows);
        $ids = [];
        foreach ($rows as $row) {
            $id = (string) ($row['author_id'] ?? '');
            if ($id !== '' && ctype_digit($id) && (int) $id > 0) $ids[(int) $id] = (int) $id;
        }
        $avatars = [];
        if ($ids !== []) {
            $marks = implode(', ', array_fill(0, count($ids), '?'));
            foreach ($this->db->select('SELECT id, avatar_file FROM ' . $this->db->table('users')
                . ' WHERE id IN (' . $marks . ')', array_values($ids)) as $user) {
                $avatars[(string) $user['id']] = $user['avatar_file'];
            }
        }
        foreach ($rows as &$row) $row['author_avatar_file'] = $avatars[(string) ($row['author_id'] ?? '')] ?? null;
        unset($row);
        return $rows;
    }
}
