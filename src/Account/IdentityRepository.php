<?php

declare(strict_types=1);

namespace ApiBoard\Account;

use ApiBoard\Db\Connection;
use ApiBoard\Support\Clock;

final class IdentityRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findUser(string $provider, string $providerUid): ?array
    {
        return $this->db->selectOne(
            'SELECT u.id, u.email, u.email_verified, u.password_hash, u.display_name, u.is_admin, u.status, u.session_epoch'
            . ' FROM ' . $this->db->q('user_identities') . ' i'
            . ' JOIN ' . $this->db->q('users') . ' u ON u.id = i.user_id'
            . ' WHERE i.provider = ? AND i.provider_uid = ?',
            [$provider, $providerUid]
        );
    }

    public function attach(int $userId, string $provider, string $providerUid): void
    {
        $this->db->insert('user_identities', [
            'user_id' => $userId,
            'provider' => $provider,
            'provider_uid' => $providerUid,
            'created_at' => Clock::now(),
        ]);
    }

    public function countForUser(int $userId): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->q('user_identities') . ' WHERE user_id = ?',
            [$userId]
        );
        return (int) ($row['c'] ?? 0);
    }
}
