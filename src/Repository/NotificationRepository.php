<?php

declare(strict_types=1);

namespace GnuCms\Repository;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

final class NotificationRepository
{
    private const COLUMNS = 'id, user_id, kind, post_id, comment_id, actor_name, subject, is_read, created_at';

    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        $data['is_read'] = 0;
        $data['created_at'] = Clock::now();

        return (int) $this->db->insert('notifications', $data);
    }

    public function find(int $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->q('notifications') . ' WHERE id = ?',
            [$id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function unreadCount(string $userId): int
    {
        return (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->q('notifications')
            . ' WHERE user_id = ? AND is_read = 0',
            [$userId]
        )['c'];
    }

    public function paginate(string $userId, int $page, int $perPage): array
    {
        $total = (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->q('notifications') . ' WHERE user_id = ?',
            [$userId]
        )['c'];

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->q('notifications')
            . ' WHERE user_id = :user_id ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            ['user_id' => $userId]
        );

        return ['items' => array_map([$this, 'hydrate'], $rows), 'total' => $total];
    }

    public function markRead(int $id, string $userId): void
    {
        // user_id 를 조건에 함께 두어 남의 알림은 건드릴 수 없게 한다.
        $this->db->execute(
            'UPDATE ' . $this->db->q('notifications') . ' SET is_read = 1 WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function markAllRead(string $userId): void
    {
        $this->db->execute(
            'UPDATE ' . $this->db->q('notifications') . ' SET is_read = 1 WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (string) $row['user_id'];
        $row['post_id'] = (int) $row['post_id'];
        $row['comment_id'] = $row['comment_id'] === null ? null : (int) $row['comment_id'];
        $row['is_read'] = (bool) $row['is_read'];

        return $row;
    }
}
