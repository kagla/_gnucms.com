<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Payment\Journal;
use GnuCms\Payment\ExecutionLock;
use GnuCms\Support\Clock;

/** PG 승인 전 주문서. 개인정보는 결제 원장의 암호화된 payload에만 보관한다. */
final class CheckoutIntents
{
    public function __construct(private App $app, private Cart $cart, private Orders $orders, private Payments $payments) {}

    public function stage(array $lines, array $input, string $token, string $owner, int $userId,
        string $fingerprint, array $shipping, array $payment, string $flow, ?string $previousId = null): array
    {
        if (!in_array(($payment['method'] ?? ''), Orders::PG_METHODS, true) || !preg_match('/^[a-f0-9]{64}$/D', $token)
            || !preg_match('/^[a-f0-9]{64}$/D', $owner)) throw DomainError::forbidden('주문서를 다시 열어 주세요.');
        $buyer = $this->orders->validateCheckout($input);
        $quote = $this->cart->quote($lines, $shipping, true);
        if ($quote['errors'] !== []) throw DomainError::validation($quote['errors']);
        if (!hash_equals($fingerprint, $quote['fingerprint'])) throw DomainError::validation(['quote' => '주문 내용 또는 배송 조건이 변경되었습니다. 상품·옵션·수량·배송비와 안내를 다시 확인해 주세요.']);
        if ($quote['total'] < 1) throw DomainError::validation(['payment' => '결제 금액을 확인해 주세요.']);
        $details = $buyer + ['agree' => '1', 'save_default_address' => ($input['save_default_address'] ?? '') === '1' ? '1' : '0'];
        $first = (string) ($quote['items'][0]['name'] ?? '주문');
        $orderName = count($quote['items']) > 1 ? $first . ' 외 ' . (count($quote['items']) - 1) . '건' : $first;
        $snapshot = ['lines' => $lines, 'input' => $details, 'token' => $token, 'owner' => $owner,
            'user_id' => $userId, 'fingerprint' => $fingerprint, 'shipping' => $shipping,
            'flow' => $flow, 'total' => $quote['total'], 'order_name' => $orderName,
            'payment_choice' => ['provider' => $payment['provider'], 'method' => $payment['method'],
                'environment' => $payment['environment'], 'revision' => $payment['revision']]];
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
    public function decline(array $intent, string $code, string $message = ''): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/D', $code)) throw DomainError::validation(['payment' => 'PG 인증 결과를 확인해 주세요.']);
        $message = trim(preg_replace('/[\\x00-\\x1f\\x7f]/u', ' ', strip_tags($message)) ?? '');
        if ($message !== '') $message = mb_substr($message, 0, 200, 'UTF-8');
        $payment = $intent['payment'];
        (new Journal($this->app->paymentSettings($payment['provider'])))->change($payment['id'], static function (array $state) use ($code, $message): array {
            if (($state['intent']['status'] ?? '') === 'declined' && ($state['intent']['pg_status'] ?? '') === $code
                && ($state['approval'] ?? '') === 'ready') return $state;
            if (($state['intent']['status'] ?? '') !== 'ready' || ($state['approval'] ?? '') !== 'ready') {
                throw DomainError::serviceUnavailable('앞선 결제 결과를 확인 중입니다.');
            }
            $state['intent']['status'] = 'declined';
            $state['intent']['pg_status'] = $code;
            $state['intent']['failure_code'] = $code;
            $state['intent']['failure_message'] = $message;
            $state['intent']['failure_at'] = Clock::timestamp();
            return $state;
        });
    }

    public static function gatewayOrder(array $intent): array
    {
        $payment = $intent['payment'];
        return Payments::gatewayOrder(['payment_id' => $payment['id'], 'payment_provider' => $payment['provider'],
            'payment_environment' => $payment['environment'], 'payment_revision' => $payment['revision'],
            'payment_method' => $payment['method'], 'total' => $intent['total'], 'order_name' => $intent['order_name'],
            'created_at' => $intent['created_at'], 'pay_by' => $payment['pay_by'], 'payment' => []]);
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
            $waitingForDeposit = $payment['method'] === 'virtual_account' && ($verified['status'] ?? '') === 'PENDING';
            if ((!$waitingForDeposit && ($verified['status'] ?? '') !== 'PAID') || !($verified['valid'] ?? false)) {
                throw DomainError::serviceUnavailable('결제 승인 결과를 확인하지 못했습니다.');
            }
        } catch (\Throwable $error) {
            try {
                $approval = $gateway->approvalState($gw);
                if (in_array($approval, ['pending', 'confirmed'], true)) {
                    $failureCode = $error instanceof DomainError ? $error->code() : 'UNEXPECTED';
                    $failureMessage = $error instanceof DomainError ? $error->getMessage() : '결제 처리 중 예상하지 못한 오류가 발생했습니다.';
                    $failureMessage = trim(preg_replace('/[\\x00-\\x1f\\x7f]/u', ' ', $failureMessage) ?? '');
                    $failureMessage = mb_substr($failureMessage, 0, 200, 'UTF-8');
                    $journal->change($payment['id'], static function (array $state) use ($failureCode, $failureMessage): array {
                        if (($state['intent']['status'] ?? '') !== 'superseded') {
                            $state['intent']['status'] = 'approval_review';
                            $state['intent']['failure_code'] = $failureCode;
                            $state['intent']['failure_message'] = $failureMessage;
                            $state['intent']['failure_at'] = Clock::timestamp();
                        }
                        return $state;
                    });
                } elseif ($approval === 'declined') {
                    $journal->change($payment['id'], static function (array $state): array {
                        if (($state['intent']['status'] ?? '') !== 'superseded') {
                            $state['intent']['status'] = 'declined';
                            $state['intent']['failure_message'] = (string) ($state['failure']['message'] ?? '결제사가 결제를 거절했습니다.');
                            $state['intent']['failure_code'] = (string) ($state['failure']['code'] ?? '');
                            $state['intent']['failure_at'] = Clock::timestamp();
                        }
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
            // 가상계좌의 입금 기한은 이니시스 발급 정보와 일치해야 한다.
            if (!$waitingForDeposit) $placementPayment['pay_by'] = max((int) $payment['pay_by'], Clock::timestamp() + 900);
            if ($waitingForDeposit) $placementPayment['detail'] = $verified['detail'] ?? [];
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
        if ($waitingForDeposit) return $order;
        return $this->orders->markPaid((int) $order['id'], 'pg:' . $payment['provider'], (int) $intent['total'],
            ['tid' => (string) $verified['transaction_id'], 'label' => Payments::METHODS[$payment['method']]]
                + (isset($verified['card']) ? ['card' => $verified['card']] : []) + ($verified['detail'] ?? []),
            (int) $verified['paid_at'], '결제가 승인되었습니다.');
    }

    /** 승인됐지만 확인을 마치지 못한 결제를 새 결제 없이 조회하고 주문으로 접수한다. */
    public function reconcile(array $intent): array
    {
        $payment = $intent['payment'] ?? [];
        if (!is_array($payment) || !is_string($payment['id'] ?? null) || !is_string($payment['provider'] ?? null)) {
            throw DomainError::forbidden('결제 참조정보를 확인할 수 없습니다.');
        }
        if (($payment['method'] ?? '') !== 'virtual_account') {
            throw DomainError::validation(['payment' => '가상계좌 결제만 이 화면에서 복구할 수 있습니다.']);
        }
        $journal = new Journal($this->app->paymentSettings($payment['provider']));
        $state = $journal->read($payment['id']);
        if (($state['intent']['status'] ?? '') !== 'approval_review' || ($state['approval'] ?? '') !== 'confirmed') {
            throw DomainError::validation(['payment' => '승인 완료 후 확인이 보류된 결제만 복구할 수 있습니다.']);
        }
        [$order, $verified] = ExecutionLock::run($this->app->storageDir(), function () use ($intent, $payment, $state): array {
            $gateway = $this->app->paymentGateway($payment['provider']);
            $verified = $gateway->fetch(self::gatewayOrder($intent));
            if (!in_array($verified['status'] ?? '', ['PENDING', 'PAID'], true) || !($verified['valid'] ?? false)) {
                throw DomainError::serviceUnavailable('이니시스 거래조회 결과가 주문 내용과 일치하지 않습니다.');
            }
            $issued = $state['approved']['virtual_account'] ?? null;
            if (!is_array($issued) || ($verified['detail']['virtual_account'] ?? null) !== $issued) {
                throw DomainError::serviceUnavailable('발급된 가상계좌 정보를 확인할 수 없습니다.');
            }
            $existing = $this->orders->submitted((string) $intent['token'], (string) $intent['owner'], (int) $intent['user_id']);
            if ($existing !== null) {
                if (($existing['payment_id'] ?? '') !== $payment['id']) throw DomainError::validation(['payment' => '같은 주문서로 다른 결제가 접수되어 있습니다.']);
                $order = $this->orders->get((int) $existing['id']);
            } else {
                $placementPayment = $payment;
                $placementPayment['detail'] = ['virtual_account' => $issued];
                $order = $this->orders->place($intent['lines'], $intent['input'], $intent['token'], $intent['owner'],
                    (int) $intent['user_id'], $intent['fingerprint'], $intent['shipping'], $placementPayment);
            }
            return [$order, $verified];
        });
        if (($verified['status'] ?? '') === 'PAID' && ($order['status'] ?? '') === 'pending') {
            $order = $this->orders->markPaid((int) $order['id'], 'pg:' . $payment['provider'], (int) $intent['total'],
                ['tid' => (string) $verified['transaction_id'], 'label' => Payments::METHODS['virtual_account']]
                    + ($verified['detail'] ?? []), (int) $verified['paid_at'], '가상계좌 입금이 확인되었습니다.');
        }
        $journal->change($payment['id'], static function (array $current): array {
            $current['intent']['status'] = 'submitted';
            unset($current['intent']['failure_code'], $current['intent']['failure_message']);
            return $current;
        });
        return $order;
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
                $state['intent']['failure_at'] = Clock::timestamp();
                return $state;
            });
            error_log('GNUCMS checkout payment needs review: ' . $id . ' (' . $error::class . ')');
            throw DomainError::serviceUnavailable('결제는 승인되었으나 주문 접수와 자동 취소를 확인하지 못했습니다. 관리자에게 문의해 주세요.');
        }
    }
}
