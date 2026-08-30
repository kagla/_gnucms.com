<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;

final class UserRepository
{
    /** 표시 이름 최소 글자 수. 한 글자는 누구인지 알아볼 수 없고 겹치기도 쉽다. */
    public const DISPLAY_NAME_MIN = 2;

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

    /** 표시 이름으로 찾는다. 대소문자는 가리지 않는다. $exceptId 는 본인을 제외할 때 쓴다. */
    public function findByDisplayName(string $displayName, ?int $exceptId = null): ?array
    {
        $sql = 'SELECT id, email, display_name FROM ' . $this->db->q('users')
            . ' WHERE LOWER(display_name) = LOWER(?)';
        $params = [$displayName];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return $this->db->selectOne($sql . ' LIMIT 1', $params);
    }

    /**
     * 겹치지 않는 표시 이름. 가입 때 이메일 앞부분으로 이름을 지어 주는데, 같은 앞부분이
     * 흔해서(kagla@a.com, kagla@b.com) 그대로 두면 겹친다. 뒤에 2, 3, … 을 붙여 비켜 간다.
     */
    public function uniqueDisplayName(string $base): string
    {
        // 너무 짧은 자동 이름(a@x.com 의 'a')은 '회원' 으로 대신한다.
        $base = trim($base);
        $base = mb_substr(mb_strlen($base) < self::DISPLAY_NAME_MIN ? '회원' : $base, 0, 100);
        if ($this->findByDisplayName($base) === null) {
            return $base;
        }
        for ($n = 2; $n < 10000; $n++) {
            $suffix = (string) $n;
            $candidate = mb_substr($base, 0, 100 - mb_strlen($suffix)) . $suffix;
            if ($this->findByDisplayName($candidate) === null) {
                return $candidate;
            }
        }
        return mb_substr($base, 0, 100 - 13) . bin2hex(random_bytes(6));
    }

    public function createRegistered(string $email, string $passwordHash, string $displayName): int
    {
        return $this->db->transaction(function () use ($email, $passwordHash, $displayName): int {
            $isFirst = $this->db->execute(
                'UPDATE ' . $this->db->q('site_state') . ' SET state_value = ?'
                . ' WHERE state_key = ? AND state_value = ?',
                ['1', 'first_admin_claimed', '0']
            ) === 1;

            $id = $this->create($email, $passwordHash, $this->uniqueDisplayName($displayName), $isFirst);
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
                'display_name' => $this->uniqueDisplayName($displayName),
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

    /** 본인이 고치는 표시 이름. 세션은 그대로다. */
    public function updateDisplayName(int $id, string $displayName): void
    {
        $this->db->update('users', [
            'display_name' => $displayName,
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
