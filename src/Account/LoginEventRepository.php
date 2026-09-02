<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;
use GnuCms\Support\IpAddress;

final class LoginEventRepository
{
    public function __construct(private Connection $db)
    {
    }

    public function record(?int $userId, ?string $identifier, string $method, string $result,
        ?string $ip, ?string $userAgent): void
    {
        $identifier = $identifier === null ? null : strtolower(trim($identifier));
        $this->db->insert('login_events', [
            'user_id' => $userId,
            'login_identifier' => $identifier === null || $identifier === ''
                ? null : mb_substr($identifier, 0, 191),
            'auth_method' => mb_substr($method, 0, 20),
            'result' => mb_substr($result, 0, 20),
            'client_ip' => IpAddress::normalize($ip),
            'user_agent' => $userAgent === null || trim($userAgent) === ''
                ? null : mb_substr(trim($userAgent), 0, 255),
            'created_at' => Clock::now(),
        ]);
    }

    public function recentForUser(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->select(
            'SELECT id, auth_method, result, client_ip, user_agent, created_at FROM '
            . $this->db->table('login_events') . ' WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit,
            [$userId]
        );
    }
}
