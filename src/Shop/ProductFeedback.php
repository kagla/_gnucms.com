<?php

declare(strict_types=1);

namespace GnuCms\Shop;

use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;
use PDOException;

final class ProductFeedback
{
    public function __construct(private Store $store) {}

    public function page(int $productId, string $kind, int $page, ?int $viewerId, bool $admin = false): array
    {
        $this->assertKind($kind);
        $table = $this->store->table('yc_product_feedback');
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS total FROM ' . $table . ' WHERE product_id = ? AND kind = ?', [$productId, $kind])['total'];
        $pages = max(1, (int) ceil($total / 10));
        $page = min(max(1, $page), $pages);
        $rows = $this->store->select('SELECT * FROM ' . $table . ' WHERE product_id = ? AND kind = ? ORDER BY id DESC LIMIT 10 OFFSET ' . (($page - 1) * 10), [$productId, $kind]);
        foreach ($rows as &$row) {
            $row['restricted'] = $kind === 'inquiry' && (int) $row['is_private'] === 1 && !$admin && (int) $row['user_id'] !== $viewerId;
            if ($row['restricted']) {
                $row['title'] = '비공개 문의입니다.';
                $row['content'] = '';
                $row['reply'] = '';
                $row['author'] = '';
            }
        }
        unset($row);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function canReview(int $productId, int $userId): bool
    {
        $orders = $this->store->table('yc_orders');
        $items = $this->store->table('yc_order_items');
        $feedback = $this->store->table('yc_product_feedback');
        return $this->store->selectOne('SELECT o.id FROM ' . $orders . ' o JOIN ' . $items . ' i ON i.order_id = o.id'
            . " WHERE o.user_id = ? AND i.product_id = ? AND o.status IN ('paid','confirmed','shipped','completed') LIMIT 1", [$userId, $productId]) !== null
            && $this->store->selectOne('SELECT id FROM ' . $feedback . ' WHERE product_id = ? AND review_user_id = ? LIMIT 1', [$productId, $userId]) === null;
    }

    public function create(int $productId, int $userId, string $author, string $kind, array $input): void
    {
        $this->assertKind($kind);
        $title = Input::text($input['title'] ?? '', 'title', 150, false);
        $content = Input::text($input['content'] ?? '', 'content', 3000, false);
        $rating = $kind === 'review' ? Input::int($input['rating'] ?? '', 'rating', 1, 5) : 0;
        $private = $kind === 'inquiry' ? Input::bool($input['is_private'] ?? '') : 0;
        if ($kind === 'review' && !$this->canReview($productId, $userId)) {
            throw DomainError::validation(['review' => '결제 완료된 주문의 상품에 한 번만 후기를 작성할 수 있습니다.']);
        }
        try {
            $this->store->transaction(function () use ($productId, $userId, $author, $kind, $title, $content, $rating, $private): void {
                $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_products') . ' WHERE id = ? FOR UPDATE', [$productId]);
                $now = Clock::timestamp();
                $this->store->insert('yc_product_feedback', ['product_id' => $productId, 'user_id' => $userId,
                    'review_user_id' => $kind === 'review' ? $userId : null, 'kind' => $kind,
                    'author' => mb_substr($author, 0, 100), 'title' => $title, 'content' => $content,
                    'rating' => $rating, 'is_private' => $private, 'reply' => '', 'reply_actor' => '',
                    'replied_at' => 0, 'created_at' => $now, 'updated_at' => $now]);
                if ($kind === 'review') $this->refreshRating($productId);
            });
        } catch (DomainError $e) {
            if ($kind === 'review' && $e->getPrevious() instanceof PDOException && (string) $e->getPrevious()->getCode() === '23000') {
                throw DomainError::validation(['review' => '이 상품에는 이미 후기를 작성했습니다.']);
            }
            throw $e;
        }
    }

    public function adminPage(string $kind, int $page): array
    {
        if ($kind !== '') $this->assertKind($kind);
        $feedback = $this->store->table('yc_product_feedback');
        $products = $this->store->table('yc_products');
        $where = $kind === '' ? '' : ' WHERE f.kind = ?';
        $params = $kind === '' ? [] : [$kind];
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS total FROM ' . $feedback . ' f' . $where, $params)['total'];
        $pages = max(1, (int) ceil($total / 30));
        $page = min(max(1, $page), $pages);
        $items = $this->store->select('SELECT f.*, p.code AS product_code, p.name AS product_name FROM ' . $feedback . ' f'
            . ' JOIN ' . $products . ' p ON p.id = f.product_id' . $where . ' ORDER BY f.id DESC LIMIT 30 OFFSET ' . (($page - 1) * 30), $params);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function reply(int $id, mixed $reply, string $actor): void
    {
        $row = $this->store->get('yc_product_feedback', $id);
        if ($row['kind'] !== 'inquiry') throw DomainError::validation(['reply' => '상품문의에만 답변할 수 있습니다.']);
        $reply = Input::text($reply, 'reply', 3000);
        $now = Clock::timestamp();
        $this->store->update('yc_product_feedback', $id, ['reply' => $reply, 'reply_actor' => $reply === '' ? '' : mb_substr($actor, 0, 100),
            'replied_at' => $reply === '' ? 0 : $now, 'updated_at' => $now]);
    }

    public function delete(int $id): void
    {
        $this->store->transaction(function () use ($id): void {
            $row = $this->store->get('yc_product_feedback', $id);
            if ($row['kind'] === 'review') {
                $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_products') . ' WHERE id = ? FOR UPDATE', [(int) $row['product_id']]);
            }
            $this->store->delete('yc_product_feedback', 'id = ?', [$id]);
            if ($row['kind'] === 'review') $this->refreshRating((int) $row['product_id']);
        });
    }

    private function refreshRating(int $productId): void
    {
        $row = $this->store->selectOne('SELECT COUNT(*) AS count, COALESCE(AVG(rating), 0) AS average FROM '
            . $this->store->table('yc_product_feedback') . " WHERE product_id = ? AND kind = 'review'", [$productId]);
        $this->store->update('yc_products', $productId, ['review_count' => (int) $row['count'], 'review_avg' => round((float) $row['average'], 1)]);
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, ['review', 'inquiry'], true)) throw DomainError::validation(['kind' => '작성 종류를 확인해 주세요.']);
    }
}
