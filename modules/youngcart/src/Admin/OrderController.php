<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Commerce\Orders;
use GnuCms\Modules\YoungCart\Commerce\Payments;
use GnuCms\Modules\YoungCart\Input;
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
        $data['notice'] = match ($data['input']['saved'] ?? '') {
            '1' => '주문 상태를 변경했습니다.', 'confirm-deposit' => '입금을 확인했습니다.', 'sync' => '결제 상태를 조회했습니다.', 'refund' => '환불을 처리했습니다.', default => '',
        };
        return $this->render($request, $response, 'order', $data);
    }

    /** 작업 8 이 채운다. */
    private function refund(array $order, array $data): void
    {
        throw DomainError::validation(['refund' => '아직 지원하지 않는 작업입니다.']);
    }
}
