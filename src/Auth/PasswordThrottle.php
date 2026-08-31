<?php

declare(strict_types=1);

namespace GnuCms\Auth;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/**
 * 비밀번호 무한 대입을 막는다. 대상(attempt_key)+IP 별로 10분 안에 5번 틀리면
 * 잠시 잠그고, 잠긴 동안은 맞는 비밀번호도 검사하지 않는다. 성공하면 기록을 지운다.
 *
 * 세션·쿠키 기반은 공격자가 쿠키를 버리면 그만이라 IP 기준이다. 프록시 헤더는
 * 믿지 않는다(동의 증적과 같은 원칙). IP 를 모르는 환경에서도 'unknown' 으로 묶어
 * 최소한의 방어는 한다.
 */
final class PasswordThrottle
{
    public const MAX_FAILURES = 5;
    public const WINDOW_SECONDS = 600;

    private Connection $db;
    private string $clientIp;

    public function __construct(Connection $db, ?string $clientIp)
    {
        $this->db = $db;
        $this->clientIp = $clientIp === null || $clientIp === '' ? 'unknown' : substr($clientIp, 0, 64);
    }

    /**
     * 잠겨 있으면 422 를 던진다. 문구는 폼의 해당 칸($field) 밑에 붙는다.
     * 창이 지난 기록은 지우고 통과시킨다.
     */
    public function assertNotLocked(string $key, string $field = 'password'): void
    {
        $row = $this->find($key);
        if ($row === null) {
            return;
        }

        $elapsed = Clock::timestamp() - (int) $row['first_failed_at'];
        if ($elapsed >= self::WINDOW_SECONDS) {
            $this->clear($key);

            return;
        }
        if ((int) $row['fail_count'] < self::MAX_FAILURES) {
            return;
        }

        $minutes = max(1, (int) ceil((self::WINDOW_SECONDS - $elapsed) / 60));
        throw DomainError::validation([
            $field => '너무 많이 틀렸습니다. ' . $minutes . '분 뒤 다시 시도해 주세요.',
        ]);
    }

    public function recordFailure(string $key): void
    {
        $now = Clock::timestamp();
        $row = $this->find($key);

        if ($row === null) {
            $this->db->insert('password_attempts', [
                'attempt_key' => $this->keyOf($key),
                'client_ip' => $this->clientIp,
                'fail_count' => 1,
                'first_failed_at' => $now,
            ]);

            return;
        }

        // 창이 지났으면 1부터 다시 센다. 아니면 하나 올린다.
        $expired = $now - (int) $row['first_failed_at'] >= self::WINDOW_SECONDS;
        $this->db->update(
            'password_attempts',
            $expired
                ? ['fail_count' => 1, 'first_failed_at' => $now]
                : ['fail_count' => (int) $row['fail_count'] + 1],
            'id = :id',
            ['id' => $row['id']]
        );
    }

    public function clear(string $key): void
    {
        $this->db->delete('password_attempts', 'attempt_key = ? AND client_ip = ?', [$this->keyOf($key), $this->clientIp]);
    }

    private function find(string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT id, fail_count, first_failed_at FROM ' . $this->db->q('password_attempts')
            . ' WHERE attempt_key = ? AND client_ip = ?',
            [$this->keyOf($key), $this->clientIp]
        );
    }

    private function keyOf(string $key): string
    {
        return substr($key, 0, 120);
    }
}
