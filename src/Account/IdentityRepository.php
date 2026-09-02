<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

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
            'SELECT u.id, u.email, u.email_verified, u.password_hash, u.display_name, u.is_admin, u.status, u.session_epoch,'
            . ' u.avatar_file, u.avatar_source'
            . ' FROM ' . $this->db->table('user_identities') . ' i'
            . ' JOIN ' . $this->db->table('users') . ' u ON u.id = i.user_id'
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
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('user_identities') . ' WHERE user_id = ?',
            [$userId]
        );
        return (int) ($row['c'] ?? 0);
    }

    /** 관리 화면에서 회원에게 연결된 로그인 제공자를 보여 줄 때 쓴다. */
    public function listForUser(int $userId): array
    {
        return $this->db->select(
            'SELECT provider, provider_uid, created_at FROM ' . $this->db->table('user_identities')
            . ' WHERE user_id = ? ORDER BY id ASC',
            [$userId]
        );
    }

    public function belongsToUser(int $userId, string $provider, string $providerUid): bool
    {
        return $this->db->selectOne(
            'SELECT id FROM ' . $this->db->table('user_identities')
            . ' WHERE user_id = ? AND provider = ? AND provider_uid = ?',
            [$userId, $provider, $providerUid]
        ) !== null;
    }
}
