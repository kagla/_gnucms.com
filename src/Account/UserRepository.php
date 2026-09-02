<?php

declare(strict_types=1);

namespace GnuCms\Account;

use GnuCms\Db\Connection;
use GnuCms\Support\Clock;
use GnuCms\Support\IpAddress;

final class UserRepository
{
    /**
     * 표시 이름 최소 폭. 한글·한자·가나는 한 글자를 2, 영문·숫자는 1 로 센다(mb_strwidth).
     * 4 면 한글 2자 또는 영문 4자 이상이다 — 국내 사이트가 흔히 쓰는 기준이다.
     */
    public const DISPLAY_NAME_MIN_WIDTH = 4;

    /** 허용 글자: 한글·영문 대소문자·숫자. 공백과 기호는 안 된다(사칭·가장 방지, 겹침 판정 단순화). */
    public const DISPLAY_NAME_PATTERN = '/^[가-힣A-Za-z0-9]+$/u';

    public static function displayNameHasBadChars(string $name): bool
    {
        return preg_match(self::DISPLAY_NAME_PATTERN, $name) !== 1;
    }

    /** 자동으로 짓는 이름에서 허용되지 않는 글자를 걷어 낸다 (kim.lee → kimlee, "홍 길동" → 홍길동). */
    public static function stripBadDisplayNameChars(string $name): string
    {
        return (string) preg_replace('/[^가-힣A-Za-z0-9]+/u', '', $name);
    }

    public static function displayNameTooShort(string $name): bool
    {
        return mb_strwidth($name, 'UTF-8') < self::DISPLAY_NAME_MIN_WIDTH;
    }

    /** 검증 문구. 폭 규칙을 사람 말로 푼다. */
    public static function displayNameRule(): string
    {
        return '한글 ' . intdiv(self::DISPLAY_NAME_MIN_WIDTH, 2) . '자 또는 영문 ' . self::DISPLAY_NAME_MIN_WIDTH . '자 이상 적어 주세요.';
    }

    public static function isSocialPlaceholderEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        return str_ends_with($email, '@oauth.local')
            || str_ends_with($email, '@users.gnucms.charmgen.com');
    }

    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT id, email, email_verified, password_hash, display_name, is_admin, status, session_epoch,'
            . ' registered_ip, withdrawn_ip, withdrawn_at, avatar_file, avatar_source, created_at, updated_at'
            . ' FROM ' . $this->db->table('users') . ' WHERE id = ?',
            [$id]
        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->selectOne(
            'SELECT id, email, email_verified, password_hash, display_name, is_admin, status, session_epoch,'
            . ' registered_ip, withdrawn_ip, withdrawn_at, avatar_file, avatar_source, created_at, updated_at'
            . ' FROM ' . $this->db->table('users') . ' WHERE email = ?',
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
        $sql = 'SELECT id, email, display_name FROM ' . $this->db->table('users')
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
        $base = self::stripBadDisplayNameChars($base);
        $base = mb_substr(self::displayNameTooShort($base) ? '회원' : $base, 0, 100);
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

    public function createRegistered(string $email, string $passwordHash, string $displayName, ?string $registeredIp = null): int
    {
        return $this->db->transaction(function () use ($email, $passwordHash, $displayName, $registeredIp): int {
            $isFirst = $this->db->execute(
                'UPDATE ' . $this->db->table('site_settings') . ' SET setting_value = ?, updated_at = ?'
                . ' WHERE setting_key = ? AND setting_value = ?',
                ['1', Clock::now(), 'system.first_admin_claimed', '0']
            ) === 1;

            $id = $this->create($email, $passwordHash, $this->uniqueDisplayName($displayName), $isFirst);
            $this->db->update('users', ['registered_ip' => IpAddress::normalize($registeredIp)],
                'id = :id', ['id' => $id]);
            if ($isFirst) {
                $this->verifyEmail($id);
            }
            return $id;
        });
    }

    public function createSocial(string $email, string $displayName, ?string $registeredIp = null, bool $emailVerified = true): int
    {
        return $this->db->transaction(function () use ($email, $displayName, $registeredIp, $emailVerified): int {
            $now = Clock::now();

            return (int) $this->db->insert('users', [
                'email' => $email,
                'email_verified' => $emailVerified ? 1 : 0,
                'password_hash' => null,
                'display_name' => $this->uniqueDisplayName($displayName),
                // 외부 공급자 로그인만으로 첫 관리자 자리를 선점할 수 없어야 한다.
                'is_admin' => 0,
                'status' => 'active',
                'session_epoch' => 0,
                'registered_ip' => IpAddress::normalize($registeredIp),
                'withdrawn_ip' => null,
                'withdrawn_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function verifyEmail(int $id): void
    {
        $this->db->update('users', ['email_verified' => 1, 'updated_at' => Clock::now()], 'id = :id', ['id' => $id]);
    }

    public function replaceSocialPlaceholderEmail(int $id, string $email): void
    {
        $this->db->update('users', [
            'email' => strtolower(trim($email)),
            'email_verified' => 1,
            'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
    }

    public function updateAvatar(int $id, ?string $file, ?string $source): void
    {
        $this->db->update('users', [
            'avatar_file' => $file,
            'avatar_source' => $source,
            'updated_at' => Clock::now(),
        ], 'id = :id', ['id' => $id]);
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

    /** 개인정보를 익명화하되 글·댓글의 작성 관계와 보안 로그인 이력은 남긴다. */
    public function withdraw(int $id, ?string $clientIp): void
    {
        $user = $this->findById($id);
        if ($user === null) {
            return;
        }
        $now = Clock::now();
        $anonymousEmail = 'withdrawn-' . $id . '-' . bin2hex(random_bytes(6)) . '@invalid.local';
        $anonymousName = '탈퇴회원' . $id . 'x' . bin2hex(random_bytes(4));
        $oldName = (string) $user['display_name'];

        $this->db->transaction(function () use (
            $id, $clientIp, $now, $anonymousEmail, $anonymousName, $oldName, $user
        ): void {
            foreach (['posts', 'comments'] as $table) {
                $this->db->update($table, [
                    'author_name' => '탈퇴한 회원',
                    'author_ip' => null,
                ], 'author_id = :author_id', ['author_id' => (string) $id]);
            }
            $this->db->execute(
                'UPDATE ' . $this->db->table('notifications') . ' SET actor_name = ? WHERE actor_name = ?',
                ['탈퇴한 회원', $oldName]
            );
            $this->db->delete('notifications', 'user_id = :user_id', ['user_id' => (string) $id]);
            $this->db->delete('user_tokens', 'user_id = :user_id', ['user_id' => $id]);
            $this->db->delete('user_identities', 'user_id = :user_id', ['user_id' => $id]);
            $this->db->delete('consents_given',
                'subject_type = :subject_type AND subject_id = :subject_id',
                ['subject_type' => 'user', 'subject_id' => $id]);
            $this->db->update('login_events', ['login_identifier' => null],
                'user_id = :user_id', ['user_id' => $id]);
            $this->db->update('users', [
                'email' => $anonymousEmail,
                'email_verified' => 0,
                'password_hash' => null,
                'display_name' => $anonymousName,
                'is_admin' => 0,
                'status' => 'withdrawn',
                'session_epoch' => (int) $user['session_epoch'] + 1,
                'avatar_file' => null,
                'avatar_source' => null,
                'withdrawn_ip' => IpAddress::normalize($clientIp),
                'withdrawn_at' => $now,
                'updated_at' => $now,
            ], 'id = :id', ['id' => $id]);
        });
    }

    public function listForAdmin(string $query = '', int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT id, email, email_verified, display_name, is_admin, status, avatar_file, created_at'
            . ' FROM ' . $this->db->table('users');
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
        $row = $this->db->selectOne('SELECT COUNT(*) AS c FROM ' . $this->db->table('users'));
        return (int) ($row['c'] ?? 0);
    }

    public function countAdmins(): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS c FROM ' . $this->db->table('users') . ' WHERE is_admin = 1 AND status = ?',
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
