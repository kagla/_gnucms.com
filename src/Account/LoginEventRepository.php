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

    /** @return array{data: array, page: int, per_page: int, total: int, total_pages: int} */
    public function paginateForAdmin(int $page, int $perPage = 50, ?int $userId = null,
        ?string $ip = null, ?string $search = null): array
    {
        $perPage = max(10, min(100, $perPage));
        $from = $this->db->table('login_events') . ' e LEFT JOIN ' . $this->db->table('users')
            . ' u ON u.id = e.user_id';
        $where = '1 = 1';
        $params = [];
        if ($userId !== null) {
            $where .= ' AND e.user_id = :user_id';
            $params['user_id'] = $userId;
        }
        if ($ip !== null && $ip !== '') {
            $where .= ' AND e.client_ip = :client_ip';
            $params['client_ip'] = $ip;
        }
        $search = $search === null ? '' : trim($search);
        if ($search !== '') {
            $fields = ['u.display_name', 'u.email', 'e.login_identifier', 'e.client_ip', 'e.user_agent'];
            $clauses = [];
            $pattern = '%' . $this->escapeLike(mb_strtolower($search)) . '%';
            foreach ($fields as $index => $field) {
                $key = 'search_' . $index;
                $clauses[] = 'LOWER(COALESCE(' . $field . ", '')) LIKE :" . $key . " ESCAPE '!'";
                $params[$key] = $pattern;
            }
            $where .= ' AND (' . implode(' OR ', $clauses) . ')';
        }

        $total = (int) $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $from . ' WHERE ' . $where,
            $params
        )['c'];
        $totalPages = $total === 0 ? 0 : (int) ceil($total / $perPage);
        $page = max(1, min($page, max(1, $totalPages)));
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->select(
            'SELECT e.id, e.user_id, e.login_identifier, e.auth_method, e.result, e.client_ip, '
            . 'e.user_agent, e.created_at, u.display_name, u.email FROM ' . $from
            . ' WHERE ' . $where . ' ORDER BY e.id DESC LIMIT ' . $perPage
            . ' OFFSET ' . $offset,
            $params
        );

        return [
            'data' => $rows, 'page' => $page, 'per_page' => $perPage,
            'total' => $total, 'total_pages' => $totalPages,
        ];
    }

    public function deleteBefore(string $cutoff): int
    {
        return $this->db->delete('login_events', 'created_at < :cutoff', ['cutoff' => $cutoff]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
