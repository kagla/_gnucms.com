<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** 나이스페이먼츠 결제창 서버 승인 모델과 REST 조회·취소. */
final class NicepayGateway extends DirectGateway
{
    private const METHODS = ['card' => 'card', 'bank_transfer' => 'bank', 'mobile' => 'cellphone'];

    public function checkout(array $order, array $customer, string $returnUrl, string $callbackUrl, string $device = 'web'): array
    {
        if (($order['method'] ?? '') !== 'card') throw DomainError::validation(['payment_method' => '온라인 결제는 신용카드만 지원합니다.']);
        $config = $this->prepare($order, $returnUrl, $callbackUrl);
        $method = self::METHODS[$order['method']] ?? throw DomainError::validation(['payment_method' => '나이스페이 결제수단을 확인해 주세요.']);
        $fields = ['clientId' => $config['client_key'], 'method' => $method, 'orderId' => $order['id'],
            'amount' => (int) $order['total'], 'goodsName' => mb_strcut($order['order_name'], 0, 40, 'UTF-8'),
            'returnUrl' => $callbackUrl, 'buyerName' => mb_strcut($customer['name'], 0, 30, 'UTF-8'),
            'buyerTel' => preg_replace('/\D/', '', $customer['phone']),
            'buyerEmail' => mb_strcut($customer['email'], 0, 60, 'UTF-8'),
            'useEscrow' => $order['method'] === 'bank_transfer' && ($config['mode'] ?? 'general') === 'escrow'];
        if ($order['method'] === 'mobile') $fields['isDigital'] = false;
        return ['kind' => 'nicepay', 'script' => 'https://pay.nicepay.co.kr/v1/js/',
            'fields' => $fields];
    }

    protected function validateCallback(array $config, array $order, array $callback): void
    {
        if (($callback['authResultCode'] ?? '') !== '0000' || ($callback['clientId'] ?? '') !== $config['client_key']
            || ($callback['orderId'] ?? '') !== $order['id'] || self::amount($callback['amount'] ?? null) !== (int) $order['total']) {
            throw DomainError::forbidden('나이스페이 인증 결과의 상점·주문·금액이 일치하지 않습니다.');
        }
        $token = self::value($callback, 'authToken', 40);
        $tid = self::value($callback, 'tid', 30);
        if (!preg_match('/^[A-Za-z0-9]{1,30}$/D', $tid)) {
            throw DomainError::forbidden('나이스페이 거래번호 형식이 올바르지 않습니다.');
        }
        $signature = self::value($callback, 'signature', 256);
        $expected = hash('sha256', $token . $config['client_key'] . (string) $order['total'] . $config['secret_key']);
        if (!hash_equals(strtolower($expected), strtolower($signature))) {
            throw DomainError::forbidden('나이스페이 인증 서명이 일치하지 않습니다.');
        }
    }

    protected function approve(array $config, array $order, array $callback): array
    {
        $tid = $callback['tid'];
        $response = $this->api($config, 'POST', '/v1/payments/' . $tid, ['amount' => (int) $order['total']]);
        if (($response['resultCode'] ?? '') !== '0000' || ($response['tid'] ?? '') !== $tid
            || ($response['orderId'] ?? '') !== $order['id'] || self::amount($response['amount'] ?? null) !== (int) $order['total']
            || !self::signatureValid($response, $config)) {
            throw DomainError::serviceUnavailable('나이스페이 승인 결과를 확인하지 못했습니다. 거래를 조회해 주세요.');
        }
        return ['tid' => $tid];
    }

    protected function query(array $config, array $order, array $state): array
    {
        $tid = (string) ($state['approved']['tid'] ?? $order['transaction_id'] ?? '');
        if ($tid === '') throw DomainError::serviceUnavailable('나이스페이 거래번호가 없습니다.');
        return $this->normalize($this->api($config, 'GET', '/v1/payments/' . $tid), $config, $order, $tid);
    }

    protected function refund(array $config, array $order, array $state, int $amount, int $remaining, string $reason, string $key): array
    {
        $tid = (string) ($state['approved']['tid'] ?? $order['transaction_id'] ?? '');
        if ($tid === '') throw DomainError::validation(['refund' => '나이스페이 거래번호가 없습니다.']);
        $body = ['reason' => mb_strcut($reason, 0, 100, 'UTF-8'),
            'orderId' => $amount === $remaining ? $order['id'] : 'c_' . substr(hash('sha256', $order['id'] . $key), 0, 40)];
        if ($amount < $remaining) $body['cancelAmt'] = $amount;
        $response = $this->api($config, 'POST', '/v1/payments/' . $tid . '/cancel', $body);
        if (($response['resultCode'] ?? '') !== '0000' || ($response['tid'] ?? '') !== $tid
            || ($response['orderId'] ?? '') !== $body['orderId']
            || !self::signatureValid($response, $config)
            || self::amount($response['balanceAmt'] ?? null) !== $remaining - $amount) {
            throw DomainError::serviceUnavailable('나이스페이 취소 결과를 확인하지 못했습니다. PG 기록을 대조해 주세요.');
        }
        $known = [];
        foreach ($state['refunds'] ?? [] as $refund) if (isset($refund['result']['id'])) $known[] = $refund['result']['id'];
        $cancelledTid = is_string($response['cancelledTid'] ?? null) ? $response['cancelledTid'] : '';
        $rows = $response['cancels'] ?? [];
        if (!is_array($rows) || (!array_is_list($rows) && $rows !== [])) {
            throw DomainError::serviceUnavailable('나이스페이 취소 내역을 확인하지 못했습니다. PG 기록을 대조해 주세요.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || in_array($row['tid'] ?? '', $known, true)) continue;
            if ($cancelledTid !== '' && ($row['tid'] ?? '') !== $cancelledTid) continue;
            if (self::amount($row['amount'] ?? null) === $amount && self::isoDate($row['cancelledAt'] ?? null) > 0) {
                return ['id' => self::value($row, 'tid', 30), 'amount' => $amount,
                    'at' => self::isoDate($row['cancelledAt'])];
            }
        }
        throw DomainError::serviceUnavailable('나이스페이 취소 내역을 확인하지 못했습니다. PG 기록을 대조해 주세요.');
    }

    private function api(array $config, string $method, string $path, ?array $body = null): array
    {
        $host = $config['environment'] === 'test' ? 'sandbox-api.nicepay.co.kr' : 'api.nicepay.co.kr';
        $response = $this->http->request($method, 'https://' . $host . $path,
            ['Authorization' => 'Basic ' . base64_encode($config['client_key'] . ':' . $config['secret_key']),
                'Content-Type' => 'application/json'], $body);
        if ($response['status'] !== 200) throw DomainError::serviceUnavailable('나이스페이 API 결과를 확인하지 못했습니다. 결제 상태를 조회해 주세요.');
        return $response['body'];
    }

    private function normalize(array $data, array $config, array $order, string $tid): array
    {
        $total = (int) $order['total'];
        $balance = self::amount($data['balanceAmt'] ?? null);
        $status = match ($data['status'] ?? '') { 'paid', 'partialCancelled' => 'PAID',
            'cancelled' => 'CANCELLED', 'ready' => 'PENDING', default => 'UNKNOWN' };
        $valid = ($data['resultCode'] ?? '') === '0000' && ($data['tid'] ?? '') === $tid
            && ($data['orderId'] ?? '') === $order['id'] && ($data['payMethod'] ?? '') === (self::METHODS[$order['method']] ?? '')
            && self::amount($data['amount'] ?? null) === $total && ($data['currency'] ?? '') === 'KRW'
            && $balance >= 0 && $balance <= $total
            && self::signatureValid($data, $config)
            && ($order['method'] !== 'bank_transfer' || ($data['useEscrow'] ?? null) === (($config['mode'] ?? 'general') === 'escrow'));
        $cancellations = [];
        $rows = $data['cancels'] ?? [];
        if (!is_array($rows) || (!array_is_list($rows) && $rows !== [])) { $valid = false; $rows = []; }
        foreach ($rows as $row) {
            if (!is_array($row)) { $valid = false; continue; }
            $id = (string) ($row['tid'] ?? '');
            $amount = self::amount($row['amount'] ?? null);
            $at = self::isoDate($row['cancelledAt'] ?? null);
            if ($id === '' || strlen($id) > 100 || $amount < 1 || $at < 1) $valid = false;
            $cancellations[] = ['id' => $id, 'amount' => $amount, 'at' => $at, 'reason' => ''];
        }
        $paidAt = self::isoDate($data['paidAt'] ?? null);
        if ($status === 'PAID' && $paidAt < 1) $valid = false;
        $card = is_array($data['card'] ?? null) ? $data['card'] : [];
        $number = (string) ($card['cardNum'] ?? '');
        $result = ['status' => $status, 'valid' => $valid, 'transaction_id' => $tid,
            'paid_at' => $paidAt, 'cancelled' => $total - $balance, 'cancellations' => $cancellations];
        if ($order['method'] === 'bank_transfer') $result['detail'] = ['escrow' => ($data['useEscrow'] ?? false) === true];
        if ($order['method'] === 'card') $result['card'] = ['name' => (string) ($card['cardName'] ?? ''),
            'last_four' => preg_match('/([0-9]{4})$/D', $number, $match) ? $match[1] : '',
            'quota' => (int) ($card['cardQuota'] ?? 0), 'interest_free' => ($card['isInterestFree'] ?? false) === true,
            'approval_number' => (string) ($data['approveNo'] ?? '')];
        return $result;
    }

    private static function isoDate(mixed $value): int
    {
        if (!is_string($value) || $value === '' || $value === '0') return 0;
        try { return (new \DateTimeImmutable($value))->getTimestamp(); } catch (\Throwable) { return 0; }
    }

    private static function signatureValid(array $response, array $config): bool
    {
        $signature = $response['signature'] ?? null;
        $tid = $response['tid'] ?? null;
        $ediDate = $response['ediDate'] ?? null;
        $amount = self::amount($response['amount'] ?? null);
        if (!is_string($signature) || !is_string($tid) || !is_string($ediDate) || $amount < 0) return false;
        return hash_equals(hash('sha256', $tid . $amount . $ediDate . $config['secret_key']), strtolower($signature));
    }
}
