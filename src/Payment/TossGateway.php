<?php

declare(strict_types=1);

namespace GnuCms\Payment;

use GnuCms\Error\DomainError;

/** 토스페이먼츠 결제창 SDK v1과 결제 승인·조회·취소 API. */
final class TossGateway extends DirectGateway
{
    private const METHODS = ['card' => '카드', 'bank_transfer' => '계좌이체', 'mobile' => '휴대폰'];
    /** https://docs.tosspayments.com/codes/org-codes 의 카드 발급사 응답 코드. */
    private const CARD_ISSUERS = [
        '3K' => '기업 BC카드', '46' => '광주은행', '71' => '롯데카드', '30' => '한국산업은행',
        '31' => 'BC카드', '51' => '삼성카드', '38' => '새마을금고', '41' => '신한카드',
        '62' => '신협', '36' => '씨티카드', '33' => '우리BC카드', 'W1' => '우리카드',
        '37' => '우체국예금보험', '39' => '저축은행중앙회', '35' => '전북은행', '42' => '제주은행',
        '15' => '카카오뱅크', '3A' => '케이뱅크', '24' => '토스뱅크', '21' => '하나카드',
        '61' => '현대카드', '11' => 'KB국민카드', '91' => 'NH농협카드', '34' => 'Sh수협은행',
        '6D' => '다이너스 클럽', '4M' => '마스터카드', '3C' => '유니온페이',
        '7A' => '아메리칸 익스프레스', '4J' => 'JCB', '4V' => 'VISA',
    ];

    public static function cardIssuerName(string $code): string
    {
        return self::CARD_ISSUERS[$code] ?? ($code !== '' && preg_match('/^[A-Z0-9]{2}$/D', $code) ? '카드사 확인 필요' : $code);
    }

    public function checkout(array $order, array $customer, string $returnUrl, string $callbackUrl, string $device = 'web'): array
    {
        if (($order['method'] ?? '') !== 'card') throw DomainError::validation(['payment_method' => '온라인 결제는 신용카드만 지원합니다.']);
        $config = $this->prepare($order, $returnUrl, $callbackUrl);
        $method = self::METHODS[$order['method']] ?? throw DomainError::validation(['payment_method' => '토스 결제수단을 확인해 주세요.']);
        $fields = ['amount' => (int) $order['total'], 'orderId' => $order['id'],
            'orderName' => mb_substr($order['order_name'], 0, 100, 'UTF-8'),
            'successUrl' => str_replace('/pay/callback?', '/pay/toss-return?', $callbackUrl),
            'failUrl' => str_replace('/pay/callback?', '/pay/toss-return?', $callbackUrl) . '&result=fail',
            'customerName' => mb_substr($customer['name'], 0, 100, 'UTF-8'),
            'customerEmail' => mb_substr($customer['email'], 0, 100, 'UTF-8')]
            + TaxAdapter::checkoutFields($this->id(), $order);
        if ($order['method'] === 'bank_transfer') {
            $fields['useEscrow'] = ($config['mode'] ?? 'general') === 'escrow';
            if ($fields['useEscrow']) {
                $products = $order['escrow_products'] ?? [];
                if ($products === [] || array_sum(array_map(static fn (array $item): int => $item['unitPrice'] * $item['quantity'], $products)) !== (int) $order['total']) {
                    throw DomainError::validation(['payment' => '에스크로 상품·배송비 금액을 확인해 주세요.']);
                }
                $fields['escrowProducts'] = $products;
            }
        }
        return ['kind' => 'toss', 'script' => 'https://js.tosspayments.com/v1/payment',
            'client_key' => $config['client_key'], 'method' => $method, 'fields' => $fields];
    }

    protected function validateCallback(array $config, array $order, array $callback): void
    {
        if (($callback['orderId'] ?? '') !== $order['id'] || self::amount($callback['amount'] ?? null) !== (int) $order['total']) {
            throw DomainError::forbidden('토스 인증 결과의 주문과 금액이 일치하지 않습니다.');
        }
        $paymentKey = self::value($callback, 'paymentKey', 200);
        if (!preg_match('/^[A-Za-z0-9_-]{6,200}$/D', $paymentKey)) {
            throw DomainError::forbidden('토스 결제 키 형식이 올바르지 않습니다.');
        }
    }

    protected function approve(array $config, array $order, array $callback): array
    {
        $result = $this->api($config, 'POST', '/v1/payments/confirm', [
            'paymentKey' => $callback['paymentKey'], 'orderId' => $order['id'], 'amount' => (int) $order['total']]);
        if (($result['paymentKey'] ?? '') !== $callback['paymentKey'] || ($result['orderId'] ?? '') !== $order['id']
            || self::amount($result['totalAmount'] ?? null) !== (int) $order['total']) {
            throw DomainError::serviceUnavailable('토스 승인 결과가 주문과 일치하지 않습니다. 결제 상태를 확인해 주세요.');
        }
        return ['tid' => $callback['paymentKey']];
    }

    protected function query(array $config, array $order, array $state): array
    {
        $key = (string) ($state['approved']['tid'] ?? $order['transaction_id'] ?? '');
        $path = $key !== '' ? '/v1/payments/' . rawurlencode($key) : '/v1/payments/orders/' . $order['id'];
        return $this->normalize($this->api($config, 'GET', $path), $config, $order, $key);
    }

    protected function refund(array $config, array $order, array $state, int $amount, int $remaining, string $reason, string $key, array $tax): array
    {
        $paymentKey = (string) ($state['approved']['tid'] ?? $order['transaction_id'] ?? '');
        if ($paymentKey === '') throw DomainError::validation(['refund' => '토스 결제 키가 없습니다.']);
        $body = ['cancelReason' => mb_substr($reason, 0, 200, 'UTF-8')];
        if ($amount < $remaining) $body += ['cancelAmount' => $amount] + TaxAdapter::refundFields($this->id(), $tax, $order);
        $result = $this->api($config, 'POST', '/v1/payments/' . rawurlencode($paymentKey) . '/cancel', $body,
            ['Idempotency-Key' => $key]);
        $payment = $this->normalize($result, $config, $order, $paymentKey);
        if (!$payment['valid'] || $payment['cancelled'] !== (int) $order['total'] - $remaining + $amount) {
            throw DomainError::serviceUnavailable('토스 취소 금액을 확인하지 못했습니다. PG 기록을 대조해 주세요.');
        }
        $known = [];
        foreach ($state['refunds'] ?? [] as $refund) if (isset($refund['result']['id'])) $known[] = $refund['result']['id'];
        $lastKey = is_string($result['lastTransactionKey'] ?? null) ? $result['lastTransactionKey'] : '';
        foreach ($payment['cancellations'] as $cancel) {
            if ($lastKey !== '' && $cancel['id'] !== $lastKey) continue;
            if ($cancel['amount'] === $amount && $cancel['at'] > 0 && !in_array($cancel['id'], $known, true)) {
                return $cancel;
            }
        }
        throw DomainError::serviceUnavailable('토스 취소 내역을 확인하지 못했습니다. PG 기록을 대조해 주세요.');
    }

    private function api(array $config, string $method, string $path, ?array $body = null, array $headers = []): array
    {
        $response = $this->http->request($method, 'https://api.tosspayments.com' . $path,
            $headers + ['Authorization' => 'Basic ' . base64_encode($config['secret_key'] . ':'), 'Content-Type' => 'application/json'], $body);
        if ($response['status'] !== 200) throw DomainError::serviceUnavailable('토스 결제 API 결과를 확인하지 못했습니다. 결제 상태를 조회해 주세요.');
        return $response['body'];
    }

    private function normalize(array $data, array $config, array $order, string $key): array
    {
        $total = (int) $order['total'];
        $balance = self::amount($data['balanceAmount'] ?? null);
        $status = match ($data['status'] ?? '') { 'DONE', 'PARTIAL_CANCELED' => 'PAID',
            'CANCELED' => 'CANCELLED', 'WAITING_FOR_DEPOSIT' => 'PENDING', default => 'UNKNOWN' };
        $expected = self::METHODS[$order['method']] ?? '';
        $card = is_array($data['card'] ?? null) ? $data['card'] : [];
        $easyPay = is_array($data['easyPay'] ?? null) ? $data['easyPay'] : [];
        // 카드 결제창에서 선택한 간편결제도 카드로 전액 결제한 경우에만 카드 주문으로 인정한다.
        $cardMethod = in_array($data['method'] ?? '', ['카드', 'CARD'], true)
            || (in_array($data['method'] ?? '', ['간편결제', 'EASY_PAY'], true)
                && $easyPay !== [] && self::amount($card['amount'] ?? null) === $total
                && self::amount($easyPay['amount'] ?? 0) === 0
                && self::amount($easyPay['discountAmount'] ?? 0) === 0);
        $methodValid = $order['method'] === 'card' ? $cardMethod : ($data['method'] ?? '') === $expected;
        $valid = ($data['orderId'] ?? '') === $order['id'] && ($key === '' || ($data['paymentKey'] ?? '') === $key)
            && $methodValid && ($data['currency'] ?? '') === 'KRW'
            && self::amount($data['totalAmount'] ?? null) === $total && $balance >= 0 && $balance <= $total
            && ($order['method'] !== 'bank_transfer' || (is_bool($data['useEscrow'] ?? null)
                && (($config['mode'] ?? 'general') !== 'escrow' || $data['useEscrow'] === true)));
        $cancellations = [];
        $rows = $data['cancels'] ?? [];
        if (!is_array($rows) || (!array_is_list($rows) && $rows !== [])) { $valid = false; $rows = []; }
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['cancelStatus'] ?? '') !== 'DONE') { $valid = false; continue; }
            $at = self::isoDate($row['canceledAt'] ?? null);
            $id = (string) ($row['transactionKey'] ?? '');
            $amount = self::amount($row['cancelAmount'] ?? null);
            if ($id === '' || strlen($id) > 100 || $at < 1 || $amount < 1) $valid = false;
            $cancellations[] = ['id' => $id, 'amount' => $amount, 'at' => $at, 'reason' => ''];
        }
        $paidAt = self::isoDate($data['approvedAt'] ?? null);
        if ($status === 'PAID' && $paidAt < 1) $valid = false;
        $number = (string) ($card['number'] ?? '');
        $result = ['status' => $status, 'valid' => $valid, 'transaction_id' => (string) ($data['paymentKey'] ?? ''),
            'paid_at' => $paidAt, 'cancelled' => $total - $balance, 'cancellations' => $cancellations];
        if ($order['method'] === 'bank_transfer') $result['detail'] = ['escrow' => ($data['useEscrow'] ?? false) === true];
        if ($order['method'] === 'card') $result['card'] = ['name' => self::cardIssuerName((string) ($card['issuerCode'] ?? '')),
            'last_four' => preg_match('/([0-9]{4})$/D', $number, $match) ? $match[1] : '',
            'quota' => (int) ($card['installmentPlanMonths'] ?? 0), 'interest_free' => ($card['isInterestFree'] ?? false) === true,
            'approval_number' => (string) ($card['approveNo'] ?? '')];
        return $result;
    }

    private static function isoDate(mixed $value): int
    {
        if (!is_string($value) || $value === '' || $value === '0') return 0;
        try { return (new \DateTimeImmutable($value))->getTimestamp(); } catch (\Throwable) { return 0; }
    }
}
