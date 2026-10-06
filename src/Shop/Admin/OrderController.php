<?php

declare(strict_types=1);

namespace GnuCms\Shop\Admin;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Commerce\CancellationReason;
use GnuCms\Shop\Commerce\Orders;
use GnuCms\Shop\Commerce\Payments;
use GnuCms\Shop\Input;
use GnuCms\Shop\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OrderController extends AdminBase
{
    public function handle(string $page, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->context($request, $page);
        $data['statuses'] = Orders::STATUSES;
        $data['payment_methods'] = Payments::METHODS;
        $this->service->payments->expireOverdue();
        if ($page === 'orders') {
            $data['status_filter'] = Input::text($data['input'][$request->getMethod() === 'POST' ? 'return_status' : 'status'] ?? '', 'status', 20);
            if (!isset(Orders::STATUSES[$data['status_filter']])) $data['status_filter'] = '';
            $data['q'] = Input::text($data['input'][$request->getMethod() === 'POST' ? 'return_q' : 'q'] ?? '', 'q', 100);
            $data['page_number'] = $this->page($data['input'][$request->getMethod() === 'POST' ? 'return_page' : 'page'] ?? '');
            if ($request->getMethod() === 'POST') {
                try {
                    $id = Input::id($data['input']['id'] ?? null);
                    $action = Input::text($data['input']['action'] ?? '', 'action', 20);
                    if ($action === 'confirm-deposit') {
                        $this->service->orders->confirmDeposit($id, $data['actor']);
                    } elseif ($action === 'transition') {
                        $from = Input::text($data['input']['from'] ?? '', 'from', 20);
                        $to = Input::text($data['input']['status'] ?? '', 'status', 20);
                        if (!in_array($to, ['confirmed', 'shipped', 'completed', 'cancelled'], true)) {
                            throw DomainError::validation(['status' => '목록에서 처리할 수 없는 상태입니다.']);
                        }
                        if ($to === 'cancelled' && $from === 'pending'
                            && in_array($this->service->orders->get($id)['payment_method'], Orders::PG_METHODS, true)) {
                            throw DomainError::validation(['status' => '결제 진행 중인 주문은 상세에서 확인해 주세요.']);
                        }
                        $this->transition($id, $data);
                    } else {
                        throw DomainError::validation(['action' => '작업을 확인해 주세요.']);
                    }
                    return $this->redirect($response, $data['admin_url'] . '/orders?' . http_build_query([
                        'status' => $data['status_filter'], 'q' => $data['q'], 'page' => $data['page_number'], 'saved' => $action === 'confirm-deposit' ? 'deposit' : 'status',
                    ]));
                } catch (DomainError $e) {
                    $data['errors'] = $e->details() ?: [$e->getMessage()];
                    $response = $response->withStatus($e->status());
                }
            }
            $data['list'] = $this->service->orders->listing(null, $data['status_filter'], $data['page_number'], true, $data['q']);
            $data['cancel_reasons'] = CancellationReason::OPTIONS;
            $data['carriers'] = Settings::carriers();
            $data['default_carrier'] = $this->service->settings->all()['shipping']['default_carrier'];
            $data['notice'] = match ($data['input']['saved'] ?? '') {
                'status' => '주문 상태를 변경했습니다.', 'deposit' => '입금을 확인했습니다.', default => '',
            };
            return $this->render($request, $response, 'orders', $data);
        }
        $id = Input::id($data['input']['id'] ?? null);
        $action = Input::text($data['input']['action'] ?? '', 'action', 20);
        if ($request->getMethod() === 'POST') {
            try {
                $order = $this->service->orders->get($id);
                $result = match ($action) {
                    'confirm-deposit' => $this->service->orders->confirmDeposit($id, $data['actor']),
                    'sync' => $this->service->payments->sync($order),
                    'refund' => $this->refund($order, $data),
                    'request-return' => $this->service->orders->requestReturn($id, $data['actor'], $data['input']),
                    'complete-return' => $this->service->orders->completeReturn($id, $data['actor'], $data['input'], $this->service->payments),
                    'close-return' => $this->service->orders->closeReturn($id, $data['actor'],
                        Input::text($data['input']['reason'] ?? '', 'reason', 400, false), $this->service->payments),
                    'refund-confirm' => $this->service->payments->confirmRefund($order, self::refundKey($data),
                        Input::text($data['input']['reference'] ?? '', 'reference', 100, false), $data['actor']),
                    'refund-unprocessed' => $this->service->payments->dismissRefund($order, self::refundKey($data)),
                    'add-note' => $this->service->orders->addNote($id, $data['input']['note'] ?? '', $data['actor'], $data['input']['after_history_id'] ?? null),
                    'edit-note' => $this->service->orders->editNote($id, Input::id($data['input']['note_id'] ?? null, 'note_id'), $data['input']['note'] ?? ''),
                    'delete-note' => $this->service->orders->deleteNote($id, Input::id($data['input']['note_id'] ?? null, 'note_id')),
                    'undo-status' => $this->service->orders->undoStatus($id, Input::id($data['input']['history_id'] ?? null, 'history_id'), Input::text($data['input']['from'] ?? '', 'from', 20, false), $data['actor'], $data['input']['reason'] ?? ''),
                    default => $this->transition($id, $data),
                };
                $focus = match ($action) {
                    'add-note' => 'yc-order-note-' . (int) $result,
                    'edit-note' => 'yc-order-note-' . (int) $data['input']['note_id'],
                    'delete-note' => (int) $result > 0 ? 'yc-order-history-' . (int) $result : 'yc-order-timeline',
                    default => '',
                };
                if ($focus === '') {
                    $beforeHistory = $order['history'];
                    $afterHistory = $this->service->orders->get($id)['history'];
                    $beforeId = $beforeHistory === [] ? 0 : (int) $beforeHistory[array_key_last($beforeHistory)]['id'];
                    $afterId = $afterHistory === [] ? 0 : (int) $afterHistory[array_key_last($afterHistory)]['id'];
                    if ($afterId > $beforeId) $focus = 'yc-order-history-' . $afterId;
                }
                return $this->redirect($response, $data['admin_url'] . '/orders/detail?id=' . $id . '&saved=' . ($action === '' ? '1' : $action)
                    . ($focus === '' ? '' : '#' . $focus));
            } catch (DomainError $e) { $data['errors'] = $e->details() ?: [$e->getMessage()]; $response = $response->withStatus($e->status()); }
        }
        $data['order'] = $this->service->orders->get($id);
        $data['return_reasons'] = \GnuCms\Shop\Commerce\ReturnReason::OPTIONS;
        $data['previous'] = Orders::previousStatus($data['order']);
        $undoHistoryId = $data['previous'] === null ? null : Orders::activeStatusHistoryId($data['order']);
        $data['undo_history_id'] = $undoHistoryId;
        $data['timeline'] = [];
        $history = $data['order']['history'];
        $historyIds = array_fill_keys(array_map(static fn (array $event): int => (int) $event['id'], $history), true);
        $notesByAnchor = [];
        foreach ($this->service->orders->notesFor($id) as $note) {
            $anchor = (int) $note['after_history_id'];
            if (!isset($historyIds[$anchor]) && $history !== []) {
                // 이전 버전의 메모는 저장된 표시 시각으로 알맞은 상태 사이에 둔다.
                $at = (int) ($note['occurred_at'] ?: $note['created_at']);
                $anchor = (int) $history[0]['id'];
                foreach ($history as $event) {
                    if ((int) $event['created_at'] <= $at) $anchor = (int) $event['id'];
                }
            }
            $notesByAnchor[$anchor][] = $note;
        }
        foreach ($history as $index => $event) {
            $nextStatus = $history[$index + 1]['status'] ?? null;
            $addNoteLabel = Orders::STATUSES[$event['status']] . ($nextStatus === null ? ' 뒤' : '와 ' . Orders::STATUSES[$nextStatus] . ' 사이') . '에 처리 메모 추가';
            $data['timeline'][] = $event + ['type' => 'history', 'can_undo' => (int) $event['id'] === $undoHistoryId,
                'add_note_label' => $addNoteLabel];
            $notes = $notesByAnchor[(int) $event['id']] ?? [];
            usort($notes, static fn (array $a, array $b): int =>
                [(int) ($a['occurred_at'] ?: $a['created_at']), (int) $a['id']]
                <=> [(int) ($b['occurred_at'] ?: $b['created_at']), (int) $b['id']]);
            foreach ($notes as $note) $data['timeline'][] = $note + ['type' => 'note'];
        }
        $data['cancel_reasons'] = CancellationReason::OPTIONS;
        $data['next'] = array_values(array_diff(Orders::NEXT[$data['order']['status']], ['paid']));
        $data['carriers'] = Settings::carriers();
        $savedCarrier = $this->service->settings->all()['shipping']['default_carrier'];
        $carrierChoice = is_string($data['input']['carrier'] ?? null) ? $data['input']['carrier'] : $savedCarrier;
        $data['carrier_other'] = is_string($data['input']['carrier_other'] ?? null) ? $data['input']['carrier_other'] : '';
        if ($carrierChoice !== '' && $carrierChoice !== Settings::OTHER_CARRIER && !isset($data['carriers'][$carrierChoice])) {
            $data['carrier_other'] = $carrierChoice;
            $carrierChoice = Settings::OTHER_CARRIER;
        }
        $data['carrier_choice'] = $carrierChoice;
        $data['is_pg'] = $this->service->payments->isPgOrder($data['order']);
        $provider = $data['is_pg'] ? $this->service->app->paymentProviders()->get($data['order']['payment_provider']) : null;
        $data['payment_provider_label'] = $provider?->label() ?? '';
        $data['escrow_payment'] = $data['is_pg'] && ($data['order']['payment']['escrow'] ?? false) === true;
        $data['partial_refund'] = $this->service->payments->supportsPartialRefund($data['order']);
        $data['refund_key'] = bin2hex(random_bytes(16));
        $data['pending_refunds'] = $this->service->payments->pendingRefunds($data['order']);
        $data['notice'] = match ($data['input']['saved'] ?? '') {
            '1' => '주문 상태를 변경했습니다.', 'confirm-deposit' => '입금을 확인했습니다.', 'sync' => '결제 상태를 확인하고 필요한 변경을 반영했습니다.', 'refund' => '환불을 처리했습니다.',
            'refund-confirm' => '환불을 결제사 기록과 맞췄습니다.', 'refund-unprocessed' => '처리되지 않은 환불 요청을 정리했습니다.',
            'add-note' => '처리 메모를 추가했습니다.', 'edit-note' => '처리 메모를 수정했습니다.', 'delete-note' => '처리 메모를 삭제했습니다.',
            'request-return' => '반품 요청을 등록했습니다.', 'complete-return' => '반품과 환불을 완료했습니다.', 'close-return' => '반품 요청을 종료했습니다.',
            'undo-status' => '주문 상태를 직전 단계로 되돌렸습니다.', default => '',
        };
        return $this->render($request, $response, 'order', $data);
    }

    private function transition(int $id, array $data): array
    {
        $from = Input::text($data['input']['from'] ?? '', 'from', 20);
        $status = Input::text($data['input']['status'] ?? '', 'status', 20);
        $input = $data['input'];
        if ($status === 'cancelled') $input['note'] = CancellationReason::note($input);
        return $this->service->orders->transition($id, $from, $status, $data['actor'], $input);
    }

    /** 대조·정리 폼이 돌려주는 결제 원장의 환불 요청 키. 화면에 뿌린 값 그대로 온다. */
    private static function refundKey(array $data): string
    {
        return Input::text($data['input']['refund_key'] ?? '', 'refund_key', 100, false);
    }

    /** 환불. 전액 환불이고 cancel_order 가 켜져 있으면 주문도 취소한다(재고 복원은 transition 이 한다). */
    private function refund(array $order, array $data): void
    {
        if ($order['status'] === 'returning') throw DomainError::validation(['refund' => '반품 처리에서 상품 회수를 확인하고 반품을 완료해 주세요.']);
        $amount = Input::int($data['input']['amount'] ?? null, 'amount', 1, 999999999);
        $reason = Input::text($data['input']['reason'] ?? '', 'reason', 200, false);
        $key = Input::text($data['input']['refund_key'] ?? '', 'refund_key', 40, false);
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) throw DomainError::validation(['refund' => '환불 요청을 다시 열어 주세요.']);
        $after = $this->service->payments->refund($order, $amount, $reason, $key, $data['actor']);
        if (($data['input']['cancel_order'] ?? '') === '1' && (int) $after['refunded_amount'] >= (int) $after['paid_amount'] && in_array('cancelled', Orders::NEXT[$after['status']], true)) {
            $this->service->orders->transition((int) $after['id'], $after['status'], 'cancelled', $data['actor'], ['note' => '환불 뒤 주문 취소 · ' . $reason]);
        }
    }
}
