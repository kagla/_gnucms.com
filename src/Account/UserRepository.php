<?php

declare(strict_types=1);

namespace ApiBoard\Account;

use ApiBoard\Db\Connection;
use ApiBoard\Support\Clock;

final class UserRepository
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, email, email_verified, password_hash, display_name, is_admin, status, session_epoch, created_at, updated_at'
            . ' FROM ' . $this->db->q('users') . ' WHERE id = ?',
            [$id]
        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne(
            'SELECT id, email, email_verified, password_hash, display_name, is_admin, status, session_epoch, created_at, updated_at'
            . ' FROM ' . $this->db->q('users') . ' WHERE email = ?',
            [$email]
        );
    }

    public function create(string $email, string $passwordHash, string $displayName, bool $isAdmin = false): int
    {
        $now = Clock::now();

        return (int) $this->db->insert('users', [
            'email' => $email,
            'email_verified' => 0,
            'password_hash' => $passwordHash,
            'display_name' => $displayName,
            'is_admin' => $isAdmin ? 1 : 0,
            'status' => 'active',
            'session_epoch' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function createRegistered(string $email, string $passwordHash, string $displayName): int
    {
        return $this->db->transaction(function () use ($email, $passwordHash, $displayName): int {
            $isFirst = $this->db->execute(
                'UPDATE ' . $this->db->q('site_state') . ' SET state_value = ?'
                . ' WHERE state_key = ? AND state_value = ?',
                ['1', 'first_admin_claimed', '0']
            ) === 1;

            $id = $this->create($email, $passwordHash, $displayName, $isFirst);
            if ($isFirst) {
                $this->verifyEmail($id);
            }
            return $id;
        });
    }

    public function createSocial(string $email, string $displayName): int
    {
        return $this->db->transaction(function () use ($email, $displayName): int {
            $isFirst = $this->db->execute(
                'UPDATE ' . $this->db->q('site_state') . ' SET state_value = ?'
                . ' WHERE state_key = ? AND state_value = ?',
                ['1', 'first_admin_claimed', '0']
            ) === 1;
            $now = Clock::now();

            return (int) $this->db->insert('users', [
                'email' => $email,
                'email_verified' => 1,
                'password_hash' => null,
                'display_name' => $displayName,
                'is_admin' => $isFirst ? 1 : 0,
                'status' => 'active',
                'session_epoch' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function verifyEmail(int $id): void
    {
        $this->db->update('users', ['email_verified' => 1, 'updated_at' => Clock::now()], 'id = :id', ['id' => $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $user = $this->findById($id);
        if ($user === null) {
            return;
        }
        $this->db->update('users', [
            'password_hash' => $passwordHash,
            'session_epoch' => (int) $user['session_epoch'] + 1,
            'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public function listForAdmin(string $query = '', int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT id, email, email_verified, display_name, is_admin, status, created_at'
            . ' FROM ' . $this->db->q('users');
        $params = [];
        if ($query !== '') {
            $sql .= ' WHERE LOWER(email) LIKE ? OR LOWER(display_name) LIKE ?';
            $needle = '%' . mb_strtolower($query) . '%';
            $params = [$needle, $needle];
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        return $this->db->select($sql, $params);
    }

    public function countAll(): int
    {
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->q('users'));
        return (int) ($row['c'] ?? 0);
    }

    public function countAdmins(): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->q('users') . ' WHERE is_admin = 1 AND status = ?',
            ['active']
        );
        return (int) ($row['c'] ?? 0);
    }

    public function setStatus(int $id, string $status): void
    {
        $user = $this->findById($id);
        if ($user === null) {
            return;
        }
        $this->db->update('users', [
            'status' => $status,
            'session_epoch' => (int) $user['session_epoch'] + 1,
            'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public function updateForAdmin(int $id, string $email, string $displayName, string $status): void
    {
        $user = $this->findById($id);
        if ($user === null) {
            return;
        }
        $epoch = (int) $user['session_epoch'];
        if ((string) $user['status'] !== $status) {
            $epoch++;
        }
        $this->db->update('users', [
            'email' => $email,
            'display_name' => $displayName,
            'status' => $status,
            'session_epoch' => $epoch,
            'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public function setAdmin(int $id, bool $isAdmin): void
    {
        $user = $this->findById($id);
        if ($user === null) {
            return;
        }
        $this->db->update('users', [
            'is_admin' => $isAdmin ? 1 : 0,
            'session_epoch' => (int) $user['session_epoch'] + 1,
            'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
    }
}
