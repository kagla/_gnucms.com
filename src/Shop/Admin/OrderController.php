<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Commerce\Orders;
use GnuCms\Shop\Commerce\Payments;
use GnuCms\Shop\Input;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OrderController extends AdminBase
{
    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, $page);
        if ($redirect = $this->requireReady($response, $data)) return $redirect;
        $data['statuses'] = Orders::STATUSES;
        $data['payment_methods'] = Payments::METHODS;
        $this->service->payments->expireOverdue();
        if ($page === 'orders') {
            $data['status_filter'] = Input::text($data['input']['status'] ?? '', 'status', 20);
            $data['q'] = Input::text($data['input']['q'] ?? '', 'q', 100);
            $data['list'] = $this->service->orders->listing(null, $data['status_filter'], $this->page($data['input']['page'] ?? ''), true, $data['q']);
            return $this->render($request, $response, 'orders', $data);
        }
        $id = Input::id($data['input']['id'] ?? null);
        $action = Input::text($data['input']['action'] ?? '', 'action', 20);
        if ($request->getMethod() === 'POST') {
            try {
                $order = $this->service->orders->get($id);
                match ($action) {
                    'confirm-deposit' => $this->service->orders->confirmDeposit($id, $data['actor']),
                    'sync' => $this->service->payments->sync($order),
                    'refund' => $this->refund($order, $data),
                    'refund-confirm' => $this->service->payments->confirmRefund($order, self::refundKey($data),
                        Input::text($data['input']['reference'] ?? '', 'reference', 100, false), $data['actor']),
                    'refund-unprocessed' => $this->service->payments->dismissRefund($order, self::refundKey($data)),
                    default => $this->service->orders->transition($id, Input::text($data['input']['from'] ?? '', 'from', 20),
                        Input::text($data['input']['status'] ?? '', 'status', 20), $data['actor'], $data['input']),
                };
                return $this->redirect($response, $data['admin_url'] . '/orders/detail?id=' . $id . '&saved=' . ($action === '' ? '1' : $action));
            } catch (DomainError $e) { $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status()); }
        }
        $data['order'] = $this->service->orders->get($id);
        $data['next'] = array_values(array_diff(Orders::NEXT[$data['order']['status']], ['paid']));
        $data['is_pg'] = $this->service->payments->isPgOrder($data['order']);
        $data['refund_key'] = bin2hex(random_bytes(16));
        $data['pending_refunds'] = $this->service->payments->pendingRefunds($data['order']);
        $data['notice'] = match ($data['input']['saved'] ?? '') {
            '1' => '주문 상태를 변경했습니다.', 'confirm-deposit' => '입금을 확인했습니다.', 'sync' => '결제 상태를 조회했습니다.', 'refund' => '환불을 처리했습니다.',
            'refund-confirm' => '환불을 결제사 기록과 맞췄습니다.', 'refund-unprocessed' => '처리되지 않은 환불 요청을 정리했습니다.', default => '',
        };
        return $this->render($request, $response, 'order', $data);
    }

    /** 대조·정리 폼이 돌려주는 결제 원장의 환불 요청 키. 화면에 뿌린 값 그대로 온다. */
    private static function refundKey(array $data): string
    {
        return Input::text($data['input']['refund_key'] ?? '', 'refund_key', 100, false);
    }

    /** 환불. 전액 환불이고 cancel_order 가 켜져 있으면 주문도 취소한다(재고 복원은 transition 이 한다). */
    private function refund(array $order, array $data): void
    {
        $amount = Input::int($data['input']['amount'] ?? null, 'amount', 1, 999999999);
        $reason = Input::text($data['input']['reason'] ?? '', 'reason', 200, false);
        $key = Input::text($data['input']['refund_key'] ?? '', 'refund_key', 40, false);
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) throw DomainError::validation(['refund' => '환불 요청을 다시 열어 주세요.']);
        $after = $this->service->payments->refund($order, $amount, $reason, $key, $data['actor']);
        if (($data['input']['cancel_order'] ?? '') === '1' && (int) $after['refunded_amount'] >= (int) $after['paid_amount'] && in_array('cancelled', Orders::NEXT[$after['status']], true)) {
            $this->service->orders->transition((int) $after['id'], $after['status'], 'cancelled', $data['actor'], ['note' => '환불 뒤 주문을 취소했습니다.']);
        }
    }
}
