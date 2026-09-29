<?php

declare(strict_types=1);

namespace GnuCms\Shop;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

final class Store
{
    public function __construct(public readonly Connection $db) {}

    public function table(string $logical): string { return $this->db->table($logical); }

    public function insert(string $table, array $row): int { return (int) $this->db->insert($table, $row); }

    public function find(string $table, int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM ' . $this->db->table($table) . ' WHERE id = ?', [$id]);
    }

    public function get(string $table, int $id): array
    {
        return $this->find($table, $id) ?? throw DomainError::notFound('항목을 찾을 수 없습니다.');
    }

    public function update(string $table, int $id, array $data): int
    {
        return $this->db->update($table, $data, 'id = :id', ['id' => $id]);
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->db->delete($table, $where, $params);
    }

    public function select(string $sql, array $params = []): array { return $this->db->select($sql, $params); }
    public function selectOne(string $sql, array $params = []): ?array { return $this->db->selectOne($sql, $params); }
    public function execute(string $sql, array $params = []): int { return $this->db->execute($sql, $params); }
    public function transaction(callable $fn): mixed { return $this->db->transaction($fn); }

    /** 관리자가 열어 둔 재고 폼이 주문으로 차감된 수량을 덮어쓰지 못하게 한다. */
    public function assertStockUnchanged(mixed $input, array $row): void
    {
        if (!is_array($input)) throw DomainError::validation(['stock' => '재고 입력값을 확인해 주세요.']);
        $original = Input::int($input['original_stock'] ?? null, 'original_stock', 0, 1000000);
        if ($original !== (int) $row['stock']) {
            throw DomainError::validation(['stock' => '주문 또는 다른 작업으로 재고가 변경되었습니다. 새로고침 후 다시 저장해 주세요.']);
        }
    }

    public function logStock(int $productId, ?int $optionId, int $delta, string $kind, string $reference, string $actor): void
    {
        $this->insert('yc_stock_log', ['product_id' => $productId, 'option_id' => $optionId, 'delta' => $delta, 'kind' => $kind,
            'reference' => mb_substr($reference, 0, 100), 'actor' => mb_substr($actor, 0, 100), 'created_at' => Clock::timestamp()]);
    }
}
