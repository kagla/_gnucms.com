<?php

declare(strict_types=1);

namespace ApiBoard\Account;

use ApiBoard\Db\Connection;
use ApiBoard\Support\Clock;

final class ConsentRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function record(int $userId, string $type, array $content): void
    {
        $this->db->insert('user_consents', [
            'user_id' => $userId,
            'consent_type' => $type,
            'content_id' => (int) $content['id'],
            'content_updated_at' => (string) $content['updated_at'],
            'agreed_at' => Clock::now(),
        ]);
    }

    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT * FROM ' . $this->db->q('user_consents') . ' WHERE user_id = ? ORDER BY id ASC',
            [$userId]
        );
    }
}
