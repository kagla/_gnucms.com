<?php

declare(strict_types=1);

namespace GnuCms\Repository;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

final class NotificationRepository
{
    private const COLUMNS = 'id, user_id, kind, post_id, comment_id, order_id, feedback_id, actor_name, subject, is_read, created_at';

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
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->table('notifications') . ' WHERE id = ?',
            [$id]
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /** 주문 상태와 같은 트랜잭션에서 기록한다. 외부 발송 채널은 호출하지 않는다. */
    public function recordOrderStatus(int $orderId, int $userId, string $status, string $number): void
    {
        if (!in_array($status, ['pending', 'paid', 'confirmed', 'shipped', 'completed', 'cancelled', 'refunded', 'returning', 'returned', 'return_closed'], true)) {
            return;
        }
        $user = $this->db->selectOne('SELECT id FROM ' . $this->db->table('users')
            . ' WHERE id = ? AND status = ?', [$userId, 'active']);
        if ($user === null) {
            return;
        }
        $this->create([
            'user_id' => (string) $userId, 'kind' => 'order_' . $status,
            'post_id' => null, 'comment_id' => null, 'order_id' => $orderId,
            'actor_name' => '', 'subject' => $number,
        ]);
    }

    /** 알림과 주문 양쪽의 소유권을 확인하며, 주문번호는 본인에게만 돌려준다. */
    public function ownedOrderNumber(int $orderId, string $userId): ?string
    {
        $row = $this->db->selectOne('SELECT number FROM ' . $this->db->table('yc_orders')
            . ' WHERE id = ? AND user_id = ?', [$orderId, $userId]);
        return $row === null ? null : (string) $row['number'];
    }

    /** 주문자 연락처는 주문 소유권을 확인한 사본에서만 읽는다. 배송지 번호는 반환하지 않는다. */
    public function ownedOrderContact(int $orderId, string $userId): ?array
    {
        return $this->db->selectOne('SELECT number, buyer_name, phone FROM ' . $this->db->table('yc_orders')
            . ' WHERE id = ? AND user_id = ?', [$orderId, $userId]);
    }

    /** 문의 소유권을 확인하고 현재 목록에서 그 문의가 있는 페이지를 계산한다. */
    public function ownedInquiry(int $feedbackId, string $userId): ?array
    {
        $table = $this->db->table('yc_product_feedback');
        $row = $this->db->selectOne('SELECT f.id, f.product_id, p.code FROM ' . $table . ' f JOIN '
            . $this->db->table('yc_products') . " p ON p.id = f.product_id WHERE f.id = ? AND f.user_id = ? AND f.kind = 'inquiry'", [$feedbackId, $userId]);
        if ($row === null) return null;
        $newer = (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $table
            . " WHERE product_id = ? AND kind = 'inquiry' AND id > ?", [(int) $row['product_id'], $feedbackId])['c'];
        return ['code' => (string) $row['code'], 'page' => intdiv($newer, 10) + 1, 'id' => $feedbackId];
    }

    public function afterCommit(callable $callback): void
    {
        $this->db->afterCommit($callback);
    }

    public function unreadCount(string $userId): int
    {
        return (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('notifications')
            . ' WHERE user_id = ? AND is_read = 0',
            [$userId]
        )['c'];
    }

    public function paginate(string $userId, int $page, int $perPage): array
    {
        $total = (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('notifications') . ' WHERE user_id = ?',
            [$userId]
        )['c'];

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->db->table('notifications')
            . ' WHERE user_id = :user_id ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            ['user_id' => $userId]
        );

        return ['items' => array_map([$this, 'hydrate'], $rows), 'total' => $total];
    }

    public function markRead(int $id, string $userId): void
    {
        // user_id 를 조건에 함께 두어 남의 알림은 건드릴 수 없게 한다.
        $this->db->execute(
            'UPDATE ' . $this->db->table('notifications') . ' SET is_read = 1 WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function markAllRead(string $userId): void
    {
        $this->db->execute(
            'UPDATE ' . $this->db->table('notifications') . ' SET is_read = 1 WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (string) $row['user_id'];
        $row['post_id'] = $row['post_id'] === null ? null : (int) $row['post_id'];
        $row['comment_id'] = $row['comment_id'] === null ? null : (int) $row['comment_id'];
        $row['order_id'] = $row['order_id'] === null ? null : (int) $row['order_id'];
        $row['feedback_id'] = $row['feedback_id'] === null ? null : (int) $row['feedback_id'];
        $row['is_read'] = (bool) $row['is_read'];

        return $row;
    }
}
