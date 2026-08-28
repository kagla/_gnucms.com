<?php

declare(strict_types=1);

namespace ApiBoard\Account;

use ApiBoard\Db\Connection;
use ApiBoard\Support\Clock;

final class TokenRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function replace(int $userId, string $purpose, string $hash, string $expiresAt): void
    {
        $this->db->update(
            'user_tokens',
            ['used_at' => Clock::now()],
            'user_id = :user_id AND purpose = :purpose AND used_at IS NULL',
            ['user_id' => $userId, 'purpose' => $purpose]
        );
        $this->db->insert('user_tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => $hash,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_at' => Clock::now(),
        ]);
    }

    public function consume(string $hash, string $purpose): ?array
    {
        $row = $this->db->selectOne(
            'SELECT id, user_id, expires_at FROM ' . $this->db->q('user_tokens')
            . ' WHERE token_hash = ? AND purpose = ? AND used_at IS NULL',
            [$hash, $purpose]
        );
        if ($row === null || strtotime((string) $row['expires_at'] . ' UTC') < Clock::timestamp()) {
            return null;
        }
        $updated = $this->db->update(
            'user_tokens',
            ['used_at' => Clock::now()],
            'id = :id AND used_at IS NULL',
            ['id' => (int) $row['id']]
        );

        return $updated === 1 ? $row : null;
    }
}
