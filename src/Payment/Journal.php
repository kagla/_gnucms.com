<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;
use GnuCms\Mail\SecretCipher;

/** 승인·취소 요청을 전송하기 전에 기록한다. 카드 정보와 PG 응답 원문은 저장하지 않는다. */
final class Journal
{
    private SecretCipher $cipher;
    private string $table;

    public function __construct(private Settings $settings)
    {
        $this->cipher = new SecretCipher((string) $settings->app->config('auth.secret'));
        $this->table = 'pay_transactions';
    }

    public function read(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw DomainError::validation(['order' => '주문번호를 확인해 주세요.']);
        $db = $this->settings->app->db();
        $row = $db->selectOne('SELECT payload FROM ' . $db->table($this->table) . ' WHERE provider = ? AND id = ?', [$this->settings->provider, $id]);
        return $row === null ? [] : json_decode($this->cipher->decrypt($row['payload']), true, 32, JSON_THROW_ON_ERROR);
    }

    /** 기존 암호화 원장의 목록 인덱스를 메모리 상한을 두고 보완한다. */
    public function indexUnindexed(int $limit = 500): int
    {
        $limit = max(1, min(1000, $limit));
        $db = $this->settings->app->db();
        $rows = $db->select('SELECT id, payload FROM ' . $db->table($this->table)
            . ' WHERE provider = ? AND event_indexed = 0 ORDER BY id LIMIT ' . $limit, [$this->settings->provider]);
        foreach ($rows as $row) {
            $state = json_decode($this->cipher->decrypt($row['payload']), true, 32, JSON_THROW_ON_ERROR);
            $this->writeIndex($db, (string) $row['id'], is_array($state) ? $state : []);
        }
        return count($rows);
    }

    public function unindexedCount(): int
    {
        $row = $this->settings->app->db()->selectOne('SELECT COUNT(*) AS c FROM '
            . $this->settings->app->db()->table($this->table) . ' WHERE provider = ? AND event_indexed = 0', [$this->settings->provider]);
        return (int) ($row['c'] ?? 0);
    }

    /** SQL 필터·정렬·페이징 후 현재 페이지의 원장만 복호화한다. */
    public function failures(string $filter, string $search, int $page): array
    {
        $where = ['provider = ?', "event_status IN ('declined', 'approval_review', 'needs_review')"];
        $params = [$this->settings->provider];
        if ($filter === 'declined') $where[] = "event_status = 'declined'";
        elseif ($filter === 'review') $where[] = "event_status IN ('approval_review', 'needs_review')";
        if ($search !== '') {
            $where[] = "(id LIKE ? ESCAPE '!' OR failure_code LIKE ? ESCAPE '!')";
            $like = '%' . \GnuCms\Shop\Catalog\Products::like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $db = $this->settings->app->db();
        $table = $db->table($this->table);
        $condition = implode(' AND ', $where);
        $total = (int) ($db->selectOne('SELECT COUNT(*) AS c FROM ' . $table . ' WHERE ' . $condition, $params)['c'] ?? 0);
        $perPage = 20;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($totalPages, $page));
        $rows = $db->select('SELECT id, event_status, event_at, environment, payment_method, amount, failure_code FROM '
            . $table . ' WHERE ' . $condition . ' ORDER BY event_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        $items = [];
        foreach ($rows as $row) {
            $state = $this->read((string) $row['id']);
            $intent = is_array($state['intent'] ?? null) ? $state['intent'] : [];
            $items[] = [
                'reference' => (string) $row['id'], 'status' => (string) $row['event_status'],
                'created_at' => (int) $row['event_at'], 'environment' => (string) $row['environment'],
                'method' => (string) $row['payment_method'], 'amount' => (int) $row['amount'],
                'code' => (string) $row['failure_code'],
                'message' => (string) ($intent['failure_message'] ?? $state['failure']['message'] ?? ''),
                'recoverable' => ($intent['status'] ?? '') === 'approval_review'
                    && ($state['approval'] ?? '') === 'confirmed'
                    && ($intent['payment']['method'] ?? '') === 'virtual_account',
            ];
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'total_pages' => $totalPages];
    }

    public function change(string $id, callable $change): array
    {
        $this->read($id);
        $db = $this->settings->app->db();
        // 결제 설정 행이 항상 있으므로 최초 주문 기록 생성도 같은 PG 내에서 직렬화된다.
        return $db->transaction(function () use ($db, $id, $change): array {
            $db->execute('UPDATE ' . $db->table('pay_settings') . ' SET payload = payload WHERE provider = ? AND id IN (?, ?)', [$this->settings->provider, 'test', 'live']);
            $before = $this->read($id);
            $after = $change($before);
            $payload = $this->cipher->encrypt(json_encode($after, JSON_THROW_ON_ERROR));
            $index = $this->indexValues($after);
            if ($before === []) {
                $db->execute('INSERT INTO ' . $db->table($this->table)
                    . ' (provider, id, payload, event_indexed, event_status, event_at, environment, payment_method, amount, failure_code)'
                    . ' VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
                    [$this->settings->provider, $id, $payload, ...array_values($index)]);
            } else {
                $db->update($this->table, ['payload' => $payload, 'event_indexed' => 1] + $index,
                    'provider = :provider AND id = :id', ['provider' => $this->settings->provider, 'id' => $id]);
            }
            return $after;
        });
    }

    private function writeIndex(\GnuCms\Db\Connection $db, string $id, array $state): void
    {
        $db->update($this->table, ['event_indexed' => 1] + $this->indexValues($state),
            'provider = :provider AND id = :id AND event_indexed = 0', ['provider' => $this->settings->provider, 'id' => $id]);
    }

    private function indexValues(array $state): array
    {
        $intent = is_array($state['intent'] ?? null) ? $state['intent'] : [];
        $payment = is_array($intent['payment'] ?? null) ? $intent['payment'] : [];
        $status = (string) ($intent['status'] ?? (($state['approval'] ?? '') === 'declined' ? 'declined' : ''));
        $failureStatus = in_array($status, ['declined', 'approval_review', 'needs_review'], true) ? $status : '';
        $failureCode = (string) ($intent['failure_code'] ?? $state['failure']['code'] ?? $intent['pg_status'] ?? '');
        return [
            'event_status' => $failureStatus,
            'event_at' => $failureStatus === '' ? 0 : (int) ($intent['failure_at'] ?? $intent['created_at'] ?? $state['created_at'] ?? 0),
            'environment' => (string) ($payment['environment'] ?? $state['environment'] ?? ''),
            'payment_method' => (string) ($payment['method'] ?? $state['method'] ?? ''),
            'amount' => (int) ($intent['total'] ?? $state['amount'] ?? 0),
            'failure_code' => substr($failureCode, 0, 32),
        ];
    }
}
