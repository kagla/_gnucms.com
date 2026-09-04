<?php

declare(strict_types=1);

namespace GnuCms\Auth;

use GnuCms\Db\Connection;
use GnuCms\Error\DomainError;
use GnuCms\Support\Clock;

/**
 * 비밀번호 무한 대입을 막는다. 기본은 대상(attempt_key)+IP 별 10분 안에 5회이고,
 * Turnstile 적응형 로그인을 쓰는 경우 로그인만 10회다. 잠기면 맞는 비밀번호도 검사하지 않는다.
 *
 * 세션·쿠키 기반은 공격자가 쿠키를 버리면 그만이라 IP 기준이다. 프록시 헤더는
 * 믿지 않는다(동의 증적과 같은 원칙). IP 를 모르는 환경에서도 'unknown' 으로 묶어
 * 최소한의 방어는 한다.
 */
final class PasswordThrottle
{
    public const MAX_FAILURES = 5;
    public const LOGIN_MAX_FAILURES = 10;
    public const CAPTCHA_AFTER_FAILURES = 3;
    public const WINDOW_SECONDS = 600;

    private Connection $db;
    private string $clientIp;
    private bool $adaptiveLogin;

    public function __construct(Connection $db, ?string $clientIp, bool $adaptiveLogin = false)
    {
        $this->db = $db;
        $this->clientIp = $clientIp === null || $clientIp === '' ? 'unknown' : substr($clientIp, 0, 64);
        $this->adaptiveLogin = $adaptiveLogin;
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
        if ((int) $row['fail_count'] < $this->limitFor($key)) {
            return;
        }

        throw DomainError::validation([
            $field => $this->lockedMessage($row, $this->limitFor($key)),
        ]);
    }

    /** Turnstile이 정상 설정된 로그인만 세 번째 실패 뒤 추가 확인을 요구한다. */
    public function requiresCaptcha(string $key): bool
    {
        if (!$this->adaptiveLogin || !str_starts_with($key, 'login:')) {
            return false;
        }
        $row = $this->find($key);
        if ($row === null || Clock::timestamp() - (int) $row['first_failed_at'] >= self::WINDOW_SECONDS) {
            return false;
        }

        return (int) $row['fail_count'] >= self::CAPTCHA_AFTER_FAILURES;
    }

    /**
     * find() 후 insert/update 하던 예전 방식은 동시 요청에서 두 문제를 낳았다: 여러 UPDATE 가
     * 같은 옛 값을 읽고 겹쳐 써서 카운터를 잃어버리거나(10개가 동시에 틀려도 대략 1만 올라간다),
     * 첫 실패 두 개가 동시에 insert 를 시도해 UNIQUE(attempt_key, client_ip) 충돌로
     * DomainError::internal → /login 이 500 이 됐다. 그래서 select 없이 원자적 UPDATE 를
     * 먼저 시도하고, 행이 없을 때만(rowCount 0) insert 하며, 그 insert 마저 경합에서 지면
     * (누군가 먼저 넣었다는 뜻) UPDATE 를 한 번 더 시도해 그 실패를 잃지 않는다.
     */
    public function recordFailure(string $key): void
    {
        $now = Clock::timestamp();
        $k = $this->keyOf($key);

        if ($this->tryAtomicUpdate($k, $now) === 0) {
            try {
                $this->db->insert('password_attempts', [
                    'attempt_key' => $k,
                    'client_ip' => $this->clientIp,
                    'fail_count' => 1,
                    'first_failed_at' => $now,
                ]);
            } catch (DomainError $e) {
                // 경합에서 졌다: 그 사이 다른 요청이 같은 (attempt_key, client_ip) 로 먼저
                // insert 했다(UNIQUE 인덱스 위반). 그 행을 이번 실패로 다시 갱신한다.
                $this->tryAtomicUpdate($k, $now);
            }
        }

        // 무한 증식 방지: 실패 기록마다 20번에 1번 꼴로 만료된 행을 청소한다. 만료된 행은
        // 상태가 없으므로(다음에 읽히면 창 지남으로 처리된다) 지워도 항상 안전하다.
        // 매번 청소하면 실패 기록 하나마다 DELETE 가 따라붙어 낭비이므로 확률로 돌린다.
        if (random_int(1, 20) === 1) {
            $this->sweepExpired();
        }
    }

    /**
     * 실패를 기록하고 모든 비밀번호 화면에서 공통으로 쓸 안내 문구를 만든다.
     * 마지막 허용 실패 응답부터 바로 잠금 사실을 알려 다음 요청을 해 보게 하지 않는다.
     */
    public function recordFailureMessage(string $key, string $invalidMessage): string
    {
        $this->recordFailure($key);
        $row = $this->find($key);
        $failures = (int) ($row['fail_count'] ?? 1);

        $limit = $this->limitFor($key);
        if ($failures >= $limit) {
            return $this->lockedMessage($row, $limit);
        }

        $remaining = $limit - $failures;

        return $invalidMessage . ' (10분 내 ' . $limit . '회 제한 · 남은 횟수 ' . $remaining . '회)';
    }

    /**
     * 원자적 UPDATE. CASE 로 창(WINDOW_SECONDS) 만료 여부를 SQL 안에서 판단해
     * "창이 지났으면 1로 새로 시작, 아니면 +1" 을 select 없이 한 문장으로 끝낸다.
     * ATTR_EMULATE_PREPARES=false 인 이 프로젝트에서 PDO 는 같은 이름 파라미터를
     * 한 문장에서 여러 번 쓰는 것을 금지하므로, 값이 같아도 :now/:now2/:now3,
     * :win/:win2 처럼 자리마다 이름을 나눈다.
     */
    private function tryAtomicUpdate(string $k, int $now): int
    {
        return $this->db->execute(
            'UPDATE ' . $this->db->table('password_attempts')
            . ' SET fail_count = CASE WHEN :now - first_failed_at >= :win THEN 1 ELSE fail_count + 1 END,'
            . ' first_failed_at = CASE WHEN :now2 - first_failed_at >= :win2 THEN :now3 ELSE first_failed_at END'
            . ' WHERE attempt_key = :k AND client_ip = :ip',
            [
                'now' => $now, 'win' => self::WINDOW_SECONDS,
                'now2' => $now, 'win2' => self::WINDOW_SECONDS, 'now3' => $now,
                'k' => $k, 'ip' => $this->clientIp,
            ]
        );
    }

    /**
     * 창이 지난 행은 더 이상 아무 상태도 갖지 않는다(다음에 읽혀도 창 지남으로 처리된다).
     * 그래서 지워도 항상 안전하다 — recordFailure() 의 확률적 청소가 이 메서드를 부르고,
     * 테스트는 확률에 기대지 않고 이 메서드를 직접 불러 검증한다.
     */
    public function sweepExpired(): void
    {
        $now = Clock::timestamp();
        $this->db->delete(
            'password_attempts',
            'first_failed_at < :cutoff',
            ['cutoff' => $now - self::WINDOW_SECONDS]
        );
    }

    public function clear(string $key): void
    {
        $this->db->delete('password_attempts', 'attempt_key = ? AND client_ip = ?', [$this->keyOf($key), $this->clientIp]);
    }

    private function find(string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT id, fail_count, first_failed_at FROM ' . $this->db->table('password_attempts')
            . ' WHERE attempt_key = ? AND client_ip = ?',
            [$this->keyOf($key), $this->clientIp]
        );
    }

    /** @param array<string,mixed>|null $row */
    private function lockedMessage(?array $row, ?int $limit = null): string
    {
        $elapsed = $row === null ? 0 : Clock::timestamp() - (int) $row['first_failed_at'];
        $minutes = max(1, (int) ceil((self::WINDOW_SECONDS - $elapsed) / 60));

        return '비밀번호를 ' . ($limit ?? self::MAX_FAILURES) . '회 잘못 입력했습니다. '
            . $minutes . '분 뒤 다시 시도해 주세요.';
    }

    private function limitFor(string $key): int
    {
        return $this->adaptiveLogin && str_starts_with($key, 'login:')
            ? self::LOGIN_MAX_FAILURES
            : self::MAX_FAILURES;
    }

    private function keyOf(string $key): string
    {
        return substr($key, 0, 120);
    }
}
