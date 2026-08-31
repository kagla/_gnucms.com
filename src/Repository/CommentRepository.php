<?php

declare(strict_types=1);

namespace GnuCms\Repository;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

final class CommentRepository
{
    private const COLUMNS = 'id, board_id, post_id, parent_id, depth, content, author_id, author_name,'
        . ' is_secret, image_key, created_at, updated_at, deleted_at';

    private const DEFAULTS = [
        'parent_id'      => null,
        'author_id'      => null,
        'guest_password' => null,
        'is_secret'      => 0,
        'image_key'      => null,
    ];

    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findByPost(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->q('comments')
            . ' WHERE post_id = ? ORDER BY id ASC',
            [$postId]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    /** 비밀 댓글 소유권 판정용. 반환값을 화면에 직접 넘기지 않는다. */
    public function findByPostWithSecret(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ', guest_password FROM ' . $this->db->q('comments')
            . ' WHERE post_id = ? ORDER BY id ASC',
            [$postId]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    public function find(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->q('comments') . ' WHERE id = ?',
            [$id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function findWithSecret(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ', guest_password FROM ' . $this->db->q('comments') . ' WHERE id = ?',
            [$id]
        );

        return $row === null ? null : $this->hydrate($row);
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

    public function hasChildren(int $id): bool
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->q('comments') . ' WHERE parent_id = ?',
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
            'SELECT depth FROM ' . $this->db->q('comments') . ' WHERE id = ?',
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
}
