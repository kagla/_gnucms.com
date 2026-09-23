<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Payment\Journal;
use GnuCms\Payment\ExecutionLock;
use GnuCms\Support\Clock;

/** 카드 승인 전 주문서. 개인정보는 결제 원장의 암호화된 payload에만 보관한다. */
final class CheckoutIntents
{
    public function __construct(private App $app, private Cart $cart, private Orders $orders, private Payments $payments) {}

    public function stage(array $lines, array $input, string $token, string $owner, int $userId,
        string $fingerprint, array $shipping, array $payment, string $flow, ?string $previousId = null): array
    {
        if (($payment['method'] ?? '') !== 'card' || !preg_match('/^[a-f0-9]{64}$/D', $token)
            || !preg_match('/^[a-f0-9]{64}$/D', $owner)) throw DomainError::forbidden('주문서를 다시 열어 주세요.');
        $buyer = $this->orders->validateCheckout($input);
        $quote = $this->cart->quote($lines, $shipping, true);
        if ($quote['errors'] !== []) throw DomainError::validation($quote['errors']);
        if (!hash_equals($fingerprint, $quote['fingerprint'])) throw DomainError::validation(['quote' => '상품 또는 배송비가 변경되었습니다. 최신 금액을 확인해 주세요.']);
        if ($quote['total'] < 1) throw DomainError::validation(['payment' => '카드 결제 금액을 확인해 주세요.']);
        $details = $buyer + ['agree' => '1', 'save_default_address' => ($input['save_default_address'] ?? '') === '1' ? '1' : '0'];
        $first = (string) ($quote['items'][0]['name'] ?? '주문');
        $orderName = count($quote['items']) > 1 ? $first . ' 외 ' . (count($quote['items']) - 1) . '건' : $first;
        $snapshot = ['lines' => $lines, 'input' => $details, 'token' => $token, 'owner' => $owner,
            'user_id' => $userId, 'fingerprint' => $fingerprint, 'shipping' => $shipping,
            'flow' => $flow, 'total' => $quote['total'], 'order_name' => $orderName];
        $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
        if ($previousId !== null && ($previous = $this->find($previousId, $payment['provider'])) !== null) {
            if (in_array($previous['status'] ?? '', ['approval_review', 'needs_review'], true)) {
                throw DomainError::validation(['payment' => '앞선 결제 결과를 확인 중입니다. 중복 결제를 피하려면 상점에 문의해 주세요.']);
            }
            if (($previous['status'] ?? '') === 'ready') {
                $oldGateway = $this->app->paymentGateway($previous['payment']['provider']);
                if (in_array($oldGateway->approvalState(self::gatewayOrder($previous)), ['pending', 'confirmed'], true)) {
                    throw DomainError::validation(['payment' => '앞선 결제 승인을 확인 중입니다. 중복 결제를 피하려면 상점에 문의해 주세요.']);
                }
                if (($previous['hash'] ?? '') === $hash) return $previous;
                (new Journal($this->app->paymentSettings($previous['payment']['provider'])))->change($previousId, static function (array $state): array {
                    $state['intent']['status'] = 'superseded';
                    return $state;
                });
            }
        }

        $intent = $snapshot + ['hash' => $hash, 'payment' => $payment, 'status' => 'ready', 'created_at' => Clock::timestamp()];
        (new Journal($this->app->paymentSettings($payment['provider'])))->change($payment['id'], static function (array $state) use ($intent): array {
            if ($state !== []) throw DomainError::forbidden('결제 요청이 중복되었습니다.');
            return ['intent' => $intent];
        });
        return $intent;
    }

    public function find(string $id, string $provider = 'inicis'): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) return null;
        try { $state = (new Journal($this->app->paymentSettings($provider)))->read($id); }
        catch (DomainError) { return null; }
        $intent = $state['intent'] ?? null;
        return is_array($intent) && ($intent['payment']['id'] ?? '') === $id && ($intent['payment']['provider'] ?? '') === $provider ? $intent : null;
    }

    /** PG 인증 실패는 승인이 시작되지 않은 요청에만 기록한다. 다음 결제는 새 주문번호를 사용한다. */
    public function decline(array $intent, string $code): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/D', $code)) throw DomainError::validation(['payment' => 'PG 인증 결과를 확인해 주세요.']);
        $payment = $intent['payment'];
        (new Journal($this->app->paymentSettings($payment['provider'])))->change($payment['id'], static function (array $state) use ($code): array {
            if (($state['intent']['status'] ?? '') === 'declined' && ($state['intent']['pg_status'] ?? '') === $code
                && ($state['approval'] ?? '') === 'ready') return $state;
            if (($state['intent']['status'] ?? '') !== 'ready' || ($state['approval'] ?? '') !== 'ready') {
                throw DomainError::serviceUnavailable('앞선 결제 결과를 확인 중입니다.');
            }
            $state['intent']['status'] = 'declined';
            $state['intent']['pg_status'] = $code;
            return $state;
        });
    }

    public static function gatewayOrder(array $intent): array
    {
        $payment = $intent['payment'];
        return Payments::gatewayOrder(['payment_id' => $payment['id'], 'payment_provider' => $payment['provider'],
            'payment_environment' => $payment['environment'], 'payment_revision' => $payment['revision'],
            'payment_method' => $payment['method'], 'total' => $intent['total'], 'order_name' => $intent['order_name'],
            'created_at' => $intent['created_at'], 'payment' => []]);
    }

    /** 결제창만 만든다. 임시 주문서에는 아직 yc_orders ID가 없다. */
    public function checkout(array $intent, string $device, string $returnUrl, string $callbackBase): array
    {
        $payment = $intent['payment'];
        $order = ['status' => 'pending', 'pay_by' => $payment['pay_by'], 'buyer_name' => $intent['input']['buyer_name'],
            'phone' => $intent['input']['phone'], 'email' => $intent['input']['email'],
            'payment_id' => $payment['id'], 'payment_provider' => $payment['provider'],
            'payment_environment' => $payment['environment'], 'payment_revision' => $payment['revision'],
            'payment_method' => $payment['method'], 'total' => $intent['total'], 'order_name' => $intent['order_name'],
            'created_at' => $intent['created_at'], 'payment' => []];
        if ((int) $payment['pay_by'] - Clock::timestamp() < 900) {
            throw DomainError::validation(['payment' => '결제 기한이 가까워졌습니다. 주문서를 다시 열어 주세요.']);
        }
        $customer = ['name' => $order['buyer_name'], 'phone' => preg_replace('/\D/', '', $order['phone']) ?? '', 'email' => $order['email']];
        $gateway = $this->app->paymentGateway($payment['provider']);
        $callbackUrl = $this->payments->callbackUrl($order, $callbackBase);
        return ExecutionLock::run($this->app->storageDir(), static fn (): array => $gateway->checkout(self::gatewayOrder($intent), $customer, $returnUrl, $callbackUrl, $device));
    }

    /** PG 승인·조회 후에만 주문을 접수한다. 승인 후 접수가 불가능하면 전액 취소를 요청한다. */
    public function complete(array $intent, array $callback): array
    {
        $payment = $intent['payment'];
        $journal = new Journal($this->app->paymentSettings($payment['provider']));
        $state = $journal->read($payment['id']);
        if (in_array($state['intent']['status'] ?? '', ['refunded', 'needs_review'], true)) {
            throw DomainError::serviceUnavailable('결제 결과를 확인 중입니다. 관리자에게 문의해 주세요.');
        }
        $gateway = $this->app->paymentGateway($payment['provider']);
        $gw = self::gatewayOrder($intent);
        try {
            ExecutionLock::run($this->app->storageDir(), static fn () => $gateway->complete($gw, $callback));
            $verified = $gateway->fetch($gw);
            if (($verified['status'] ?? '') !== 'PAID' || !($verified['valid'] ?? false)) {
                throw DomainError::serviceUnavailable('결제 승인 결과를 확인하지 못했습니다.');
            }
        } catch (\Throwable $error) {
            try {
                if (in_array($gateway->approvalState($gw), ['pending', 'confirmed'], true)) {
                    $journal->change($payment['id'], static function (array $state): array {
                        if (($state['intent']['status'] ?? '') !== 'superseded') $state['intent']['status'] = 'approval_review';
                        return $state;
                    });
                }
            } catch (\Throwable) { /* 원장이 열리지 않아도 원래 결제 오류를 보존한다. */ }
            throw $error;
        }
        $state = $journal->read($payment['id']);
        if (($state['intent']['status'] ?? '') === 'superseded') {
            $this->cancelUnplaced($journal, $gateway, $gw, $intent, '변경된 주문서의 이전 결제');
            throw DomainError::validation(['payment' => '변경 전 주문서의 결제는 취소했습니다.']);
        }
        $existing = $this->orders->submitted($intent['token'], $intent['owner'], (int) $intent['user_id']);
        if ($existing !== null && $existing['payment_id'] !== $payment['id']) {
            $this->cancelUnplaced($journal, $gateway, $gw, $intent, '이미 다른 결제로 접수된 주문서');
            throw DomainError::validation(['payment' => '이미 접수된 주문서입니다. 중복 결제는 취소했습니다.']);
        }
        try {
            $placementPayment = $payment;
            $placementPayment['pay_by'] = max((int) $payment['pay_by'], Clock::timestamp() + 900);
            $order = $this->orders->place($intent['lines'], $intent['input'], $intent['token'], $intent['owner'],
                (int) $intent['user_id'], $intent['fingerprint'], $intent['shipping'], $placementPayment);
        } catch (\Throwable $error) {
            $existing = $this->orders->submitted($intent['token'], $intent['owner'], (int) $intent['user_id']);
            if ($existing !== null && $existing['payment_id'] === $payment['id']) $order = $this->orders->get((int) $existing['id']);
            else {
                $this->cancelUnplaced($journal, $gateway, $gw, $intent, '승인 후 상품·재고 변경');
                throw $error;
            }
        }
        if ($order['payment_id'] !== $payment['id']) {
            $this->cancelUnplaced($journal, $gateway, $gw, $intent, '이미 다른 결제로 접수된 주문서');
            throw DomainError::validation(['payment' => '이미 접수된 주문서입니다. 중복 결제는 취소했습니다.']);
        }
        return $this->orders->markPaid((int) $order['id'], 'pg:' . $payment['provider'], (int) $intent['total'],
            ['tid' => (string) $verified['transaction_id'], 'label' => Payments::METHODS[$payment['method']]],
            (int) $verified['paid_at'], '결제가 승인되었습니다.');
    }

    private function cancelUnplaced(Journal $journal, \GnuCms\Payment\Gateway $gateway, array $gw, array $intent, string $reason): void
    {
        $id = $intent['payment']['id'];
        try {
            ExecutionLock::run($this->app->storageDir(), static fn () => $gateway->cancel($gw,
                (int) $intent['total'], (int) $intent['total'], $reason, 'checkout-' . $id));
            $journal->change($id, static function (array $state): array {
                $state['intent']['status'] = 'refunded';
                return $state;
            });
        } catch (\Throwable $error) {
            $journal->change($id, static function (array $state): array {
                $state['intent']['status'] = 'needs_review';
                return $state;
            });
            error_log('GNUCMS checkout payment needs review: ' . $id . ' (' . $error::class . ')');
            throw DomainError::serviceUnavailable('결제는 승인되었으나 주문 접수와 자동 취소를 확인하지 못했습니다. 관리자에게 문의해 주세요.');
        }
    }
}
