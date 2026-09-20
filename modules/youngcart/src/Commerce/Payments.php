<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart\Commerce;

use GnuCms\App;
use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Input;
use GnuCms\Modules\YoungCart\Settings;
use GnuCms\Payment\CallbackToken;
use GnuCms\Payment\ExecutionLock;
use GnuCms\Support\Clock;

/**
 * 주문과 코어 결제 계층을 잇는다. 결제 원장·자격증명 판·콜백 HMAC 은 결제 계층이 보장하고,
 * 여기서는 주문의 결제 칸(원장 키·환경·판)을 게이트웨이의 주문 배열로 바꾸고 결과를 주문에 적는다.
 */
final class Payments
{
    public const PROVIDER = 'inicis';
    public const METHODS = ['card' => '카드 결제', 'easy_pay' => '간편결제', 'bank_transfer' => '실시간 계좌이체', 'virtual_account' => '가상계좌', 'manual_transfer' => '무통장입금'];

    public function __construct(private App $app, private Settings $settings, private Orders $orders) {}

    /** 주문서에 보일 수단. 3.1 은 카드와 무통장만 안다. 켜져 있지 않은 수단은 목록에 없다. */
    public function methods(): array
    {
        $payment = $this->settings->all()['payment'];
        $methods = [];
        if ($this->app->paymentSettings()->available($payment['environment'])) $methods['card'] = self::METHODS['card'];
        if ($payment['manual']['enabled'] && $payment['manual']['account'] !== '') $methods['manual_transfer'] = self::METHODS['manual_transfer'];
        return $methods;
    }

    /** 주문 접수 때 Orders::place() 에 넘길 결제 정보. 수단이 하나도 없으면(접수 전용) 빈 배열. */
    public function forPlacing(array $input): array
    {
        $methods = $this->methods();
        if ($methods === []) return [];
        $method = Input::text($input['payment_method'] ?? '', 'payment_method', 20);
        if (!isset($methods[$method])) throw DomainError::validation(['payment_method' => '결제 수단을 선택해 주세요.']);
        $payment = $this->settings->all()['payment'];
        $hours = (int) $payment['deadline_hours'][self::deadlineKey($method)];
        $spec = ['method' => $method, 'pay_by' => Clock::timestamp() + $hours * 3600, 'detail' => []];
        if ($method === 'manual_transfer') {
            $spec['detail'] = ['depositor' => Input::text($input['depositor'] ?? '', 'depositor', 100),
                'bank' => $payment['manual']['bank'], 'account' => $payment['manual']['account'], 'holder' => $payment['manual']['holder']];
            return $spec;
        }
        $summary = $this->app->paymentSettings()->summary($payment['environment']);
        return $spec + ['id' => bin2hex(random_bytes(16)), 'environment' => $payment['environment'], 'revision' => $summary['revision']];
    }

    /** 카드·간편결제·계좌이체는 카드 기한을, 가상계좌·무통장은 제 기한을 쓴다. */
    private static function deadlineKey(string $method): string
    {
        return in_array($method, ['virtual_account', 'manual_transfer'], true) ? $method : 'card';
    }

    public function isPgOrder(array $order): bool
    {
        return in_array($order['payment_method'], Orders::PG_METHODS, true) && $order['payment_id'] !== '';
    }

    /** 게이트웨이 계약이 받는 주문 배열. id 는 결제 원장 키다. */
    public static function gatewayOrder(array $order): array
    {
        $items = $order['items'] ?? [];
        $first = (string) ($items[0]['product_name'] ?? '주문');
        $name = count($items) > 1 ? $first . ' 외 ' . (count($items) - 1) . '건' : $first;
        return ['id' => (string) $order['payment_id'], 'provider' => self::PROVIDER, 'environment' => (string) $order['payment_environment'],
            'config_revision' => (string) $order['payment_revision'], 'total' => (int) $order['total'], 'order_name' => $name,
            'transaction_id' => (string) ($order['payment']['tid'] ?? ''), 'created_at' => (int) $order['created_at']];
    }

    /** 이니시스가 인증 결과를 보낼 주소. 주문의 원장 키와 그 주문·결제사·설정 판의 HMAC 을 싣는다. */
    public function callbackUrl(array $order, string $callbackBase): string
    {
        return $callbackBase . '?order=' . $order['payment_id'] . '&state=' . CallbackToken::create($this->app, self::gatewayOrder($order));
    }

    /** 결제창 정의. 결제 대기이고 기한 안인 결제사 주문만. */
    public function checkout(array $order, string $device, string $returnUrl, string $callbackBase): array
    {
        if ($order['status'] !== 'pending' || !$this->isPgOrder($order)) throw DomainError::validation(['payment' => '결제할 수 있는 주문이 아닙니다.']);
        if ((int) $order['pay_by'] > 0 && (int) $order['pay_by'] < Clock::timestamp()) throw DomainError::validation(['payment' => '결제 기한이 지났습니다. 주문을 다시 접수해 주세요.']);
        // 결제창을 여는 순간 기한이 코앞이면, 고객이 카드 정보를 넣는 사이에 만료 취소되지 않게 민다.
        if ((int) $order['pay_by'] > 0 && (int) $order['pay_by'] - Clock::timestamp() < 900) {
            $order = $this->orders->extendDeadline((int) $order['id'], Clock::timestamp() + 900);
        }
        $gateway = $this->app->inicisGateway();
        $customer = ['name' => $order['buyer_name'], 'phone' => preg_replace('/\D/', '', $order['phone']) ?? '', 'email' => $order['email']];
        $callbackUrl = $this->callbackUrl($order, $callbackBase);
        return ExecutionLock::run($this->app->storageDir(), static fn (): array => $gateway->checkout(self::gatewayOrder($order), $customer, $returnUrl, $callbackUrl, $device));
    }

    /**
     * 콜백 처리: 승인한 뒤 조회로 결과를 확인하고 결제 완료로 적는다. 승인 실패·검증 실패는
     * 예외로 올리고 주문은 그대로 둔다 — 원장이 pending 으로 잠가 재승인을 막는다.
     */
    public function complete(array $order, array $callback): array
    {
        $gateway = $this->app->inicisGateway();
        $gw = self::gatewayOrder($order);
        ExecutionLock::run($this->app->storageDir(), static fn () => $gateway->complete($gw, $callback));
        return $this->applyFetched($order, $gateway->fetch($gw), '결제가 승인되었습니다.');
    }

    /** 관리자 결제 조회. 승인됐는데 주문이 아직 결제 대기면 결제 완료로 맞춘다. */
    public function sync(array $order): array
    {
        if (!$this->isPgOrder($order)) throw DomainError::validation(['payment' => '결제사 결제가 아닌 주문입니다.']);
        $payment = $this->app->inicisGateway()->fetch(self::gatewayOrder($order));
        if (($payment['status'] ?? '') === 'NOT_FOUND') throw DomainError::validation(['payment' => '결제사에 이 주문의 결제 기록이 없습니다.']);
        if (!($payment['valid'] ?? false)) throw DomainError::serviceUnavailable('결제사 조회 결과가 주문과 맞지 않습니다. PG 관리자 화면에서 확인해 주세요.');
        if ($order['status'] === 'pending' && $payment['status'] === 'PAID') return $this->applyFetched($order, $payment, '결제 조회로 승인을 확인했습니다.');
        // 결제사에는 승인이 남아 있는데 주문은 결제 완료가 아니다(취소·만료된 주문의 승인). 환불이 필요하다.
        if ($payment['status'] === 'PAID' && (int) $order['paid_at'] === 0) {
            $this->recordOrphanApproval($order, $payment);
            throw DomainError::validation(['payment' => '결제사에는 승인이 남아 있지만 주문은 결제 완료가 아닙니다. 환불이 필요합니다.']);
        }
        return $this->orders->get((int) $order['id']);
    }

    private function applyFetched(array $order, array $payment, string $note): array
    {
        if (($payment['status'] ?? '') !== 'PAID' || !($payment['valid'] ?? false)) throw DomainError::serviceUnavailable('승인 결과를 확인하지 못했습니다. 관리자에게 문의해 주세요.');
        try {
            return $this->orders->markPaid((int) $order['id'], 'pg:' . self::PROVIDER, (int) $order['total'],
                ['tid' => (string) $payment['transaction_id'], 'label' => self::label($order)], (int) $payment['paid_at'], $note);
        } catch (DomainError $e) {
            // 결제 대기가 아닌 주문에 승인이 도착했다. 주문은 그대로 두고 환불 필요만 적은 뒤 그대로 올린다.
            if (!array_key_exists('status', $e->details())) throw $e;
            $this->recordOrphanApproval($order, $payment);
            throw $e;
        }
    }

    private function recordOrphanApproval(array $order, array $payment): void
    {
        $this->orders->recordOrphanApproval((int) $order['id'], 'pg:' . self::PROVIDER, (string) $payment['transaction_id'], self::label($order));
    }

    private static function label(array $order): string
    {
        return self::METHODS[$order['payment_method']] ?? (string) $order['payment_method'];
    }

    /** 환불. 결제사 주문은 PG 환불이 먼저 성공해야 기록하고, 무통장은 밖에서 돌려준 돈을 기록만 한다. */
    public function refund(array $order, int $amount, string $reason, string $key, string $actor): array
    {
        if (!in_array($order['status'], ['paid', 'confirmed'], true)) throw DomainError::validation(['refund' => '결제 완료 상태의 주문만 환불할 수 있습니다.']);
        $remaining = (int) $order['paid_amount'] - (int) $order['refunded_amount'];
        if ($amount < 1 || $amount > $remaining) throw DomainError::validation(['refund' => '환불 금액을 확인해 주세요.']);
        if ($this->isPgOrder($order)) {
            $gateway = $this->app->inicisGateway();
            $gw = self::gatewayOrder($order);
            ExecutionLock::run($this->app->storageDir(), static fn (): array => $gateway->cancel($gw, $amount, $remaining, $reason, $key));
        }
        return $this->orders->recordRefund((int) $order['id'], $amount, $actor, $reason, $key);
    }

    /**
     * 결과를 확인하지 못한 환불 요청. 결제 계층은 응답을 받지 못한 요청을 보류로 잠그고 자동
     * 재전송하지 않으므로, 관리자가 결제사 기록과 대조할 때까지 여기 남는다. PG 를 부르지 않는다.
     * @return array<string,array{amount:int,remaining:int,reason:string,at:int}>
     */
    public function pendingRefunds(array $order): array
    {
        if (!$this->isPgOrder($order)) return [];
        return $this->app->inicisGateway()->pendingRefunds(self::gatewayOrder($order));
    }

    /** 보류 중인 환불을 결제사 조회에서 확인한 취소 거래번호에 연결하고 주문에 기록한다. */
    public function confirmRefund(array $order, string $key, string $reference, string $actor): array
    {
        if (!$this->isPgOrder($order)) throw DomainError::validation(['refund' => '결제사 결제가 아닌 주문입니다.']);
        $gateway = $this->app->inicisGateway();
        $gw = self::gatewayOrder($order);
        $pending = $gateway->pendingRefunds($gw)[$key] ?? null;
        if ($pending === null) throw DomainError::validation(['refund' => '대조할 환불 요청이 없습니다. 화면을 새로고침해 주세요.']);
        $gateway->confirmRefund($gw, $key, $reference);
        return $this->orders->recordRefund((int) $order['id'], (int) $pending['amount'], $actor, '결제사 확인: ' . $reference, $key);
    }

    /** 결제사가 처리하지 않은 것으로 확인된 환불 요청을 닫는다. 주문 금액은 그대로다. */
    public function dismissRefund(array $order, string $key): array
    {
        if (!$this->isPgOrder($order)) throw DomainError::validation(['refund' => '결제사 결제가 아닌 주문입니다.']);
        $this->app->inicisGateway()->confirmUnprocessedRefund(self::gatewayOrder($order), $key);
        return $this->orders->get((int) $order['id']);
    }

    /** 결제사 승인이 진행 중이거나 끝난 주문인지. 만료 취소가 이런 주문을 건너뛴다. */
    public function inProgress(array $order): bool
    {
        if (!$this->isPgOrder($order)) return false;
        return in_array($this->app->inicisGateway()->approvalState(self::gatewayOrder($order)), ['pending', 'confirmed'], true);
    }

    public function expireOverdue(): int
    {
        return $this->orders->expire(Clock::timestamp(), fn (array $order): bool => $this->inProgress($order));
    }
}
