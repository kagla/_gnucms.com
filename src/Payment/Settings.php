<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;

/** PG별 공통 결제 설정. 이전 주문의 조회·환불에 필요한 암호화 설정 판을 보존한다. */
final class Settings
{
    private SecretCipher $cipher;
    private string $table;

    public function __construct(public readonly App $app, public readonly string $provider = 'inicis')
    {
        $this->definition();
        $this->table = 'pay_settings';
        $this->cipher = new SecretCipher((string) $app->config('auth.secret'));
    }

    public function definition(): Provider { return $this->app->paymentProviders()->get($this->provider); }

    public static function environment(string $environment): string
    {
        if (!in_array($environment, ['test', 'live'], true)) throw DomainError::validation(['environment' => '결제 환경을 확인해 주세요.']);
        return $environment;
    }

    private function row(string $id): ?array
    {
        $row = $this->app->db()->selectOne('SELECT payload FROM ' . $this->app->db()->table($this->table) . ' WHERE provider = ? AND id = ?', [$this->provider, $id]);
        return $row === null ? null : json_decode($this->cipher->decrypt($row['payload']), true, 16, JSON_THROW_ON_ERROR);
    }

    public function current(string $environment): ?array
    {
        $row = $this->row(self::environment($environment));
        return ($row['integration'] ?? '') === 'direct-v1' ? $row : null;
    }

    public function revision(string $revision): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $revision)) throw DomainError::validation(['revision' => '결제 설정 판을 확인해 주세요.']);
        $row = $this->row($revision);
        if (($row['integration'] ?? '') !== 'direct-v1') throw DomainError::serviceUnavailable('직접 연동 설정으로 생성한 주문이 아닙니다.');
        return $row;
    }

    /** 주문의 상점은 보존하고 같은 상점의 인증키·서버 주소 변경을 과거 주문에 적용한다. */
    public function credentials(string $revision): array
    {
        $row = $this->revision($revision);
        $current = $this->current($row['environment']);
        return $this->definition()->credentials($row, $current);
    }

    public function available(string $environment): bool
    {
        return $this->current($environment) !== null;
    }

    public function requireEnabled(string $environment): void
    {
        if (!$this->available($environment)) throw DomainError::serviceUnavailable('선택한 결제 환경의 연동 설정을 저장해 주세요.');
    }

    public function summary(string $environment): array
    {
        $row = $this->current($environment);
        $public = [];
        foreach ($this->definition()->fields() as $key => $field) {
            if ($field['secret']) {
                $secret = (string) ($row[$key] ?? '');
                $public[$key . '_set'] = $secret !== '';
                $public[$key . '_length'] = strlen($secret);
            }
            else $public[$key] = $row[$key] ?? '';
        }
        return ['configured' => $row !== null, 'enabled' => $this->available($environment),
            'revision' => $row['revision'] ?? '', 'environment' => $environment] + $public;
    }

    /** 관리자 화면에서 요청한 비밀 필드 하나만 반환한다. */
    public function secret(string $environment, string $field): string
    {
        $definition = $this->definition()->fields()[$field] ?? null;
        if (!is_array($definition) || empty($definition['secret'])) {
            throw DomainError::notFound('지원하지 않는 결제 인증 정보입니다.');
        }
        return (string) (($this->current($environment) ?? [])[$field] ?? '');
    }

    public function save(string $environment, array $input): void
    {
        ExecutionLock::settings($this->app->storageDir(), fn () => $this->saveUnlocked($environment, $input));
    }

    private function saveUnlocked(string $environment, array $input): void
    {
        self::environment($environment);
        $before = $this->row($environment);
        $data = $this->definition()->validate($input, $this->current($environment) ?? [], $environment)
            + ['integration' => 'direct-v1', 'environment' => $environment, 'revision' => bin2hex(random_bytes(16))];
        $payload = $this->cipher->encrypt(json_encode($data, JSON_THROW_ON_ERROR));
        $db = $this->app->db();
        $db->transaction(function () use ($db, $data, $environment, $before, $payload): void {
            $db->execute('INSERT INTO ' . $db->table($this->table) . ' (provider, id, payload) VALUES (?, ?, ?)', [$this->provider, $data['revision'], $payload]);
            if ($before === null) $db->execute('INSERT INTO ' . $db->table($this->table) . ' (provider, id, payload) VALUES (?, ?, ?)', [$this->provider, $environment, $payload]);
            else $db->update($this->table, ['payload' => $payload], 'provider = :provider AND id = :id', ['provider' => $this->provider, 'id' => $environment]);
        });
    }

}
