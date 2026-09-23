<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Stock;
use GnuCms\Shop\Input;
use GnuCms\Shop\Settings;
use GnuCms\Shop\Store;
use GnuCms\Support\Clock;
use PDOException;

final class Orders
{
    public const STATUSES = ['pending' => '주문 접수', 'paid' => '결제 완료', 'confirmed' => '상품 준비', 'shipped' => '배송 중', 'completed' => '배송 완료', 'cancelled' => '주문 취소'];
    public const NEXT = ['pending' => ['paid', 'cancelled'], 'paid' => ['confirmed', 'cancelled'], 'confirmed' => ['shipped', 'cancelled'], 'shipped' => ['completed'], 'completed' => [], 'cancelled' => []];
    /** 결제사(이니시스)를 거치는 수단. 무통장은 관리자가 입금을 확인한다. */
    public const PG_METHODS = ['card', 'easy_pay', 'bank_transfer', 'virtual_account'];

    public function __construct(private Store $store, private Cart $cart, private Settings $settings) {}

    /** 저장된 기본 배송지를 먼저 쓰고, 없으면 가장 최근 주문의 배송지를 사용한다. */
    public function defaultAddressFor(int $userId): ?array
    {
        return $this->store->selectOne('SELECT recipient, recipient_phone, postcode, address, address_detail, delivery_note FROM '
            . $this->store->table('yc_orders') . ' WHERE user_id = ? ORDER BY default_address DESC, id DESC LIMIT 1', [$userId]);
    }

    /** 기본 배송지를 먼저 보여 주고, 같은 수령지의 중복 주문은 한 번만 표시한다. */
    public function previousAddressesFor(int $userId): array
    {
        $orders = $this->store->select('SELECT id, recipient, recipient_phone, postcode, address, address_detail, delivery_note, default_address FROM '
            . $this->store->table('yc_orders') . ' WHERE user_id = ? ORDER BY default_address DESC, id DESC LIMIT 200', [$userId]);
        $addresses = []; $seen = [];
        foreach ($orders as $order) {
            $key = json_encode([$order['recipient'], $order['recipient_phone'], $order['postcode'], $order['address'], $order['address_detail']], JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $addresses[] = $order;
            if (count($addresses) === 10) break;
        }
        return $addresses;
    }

    public function submitted(string $key, string $owner, int $userId): ?array
    {
        return $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_orders') . ' WHERE checkout_key = ? AND owner_key = ? AND user_id = ?', [$key, $owner, $userId]);
    }

    public function place(array $lines, array $input, string $key, string $owner, int $userId, string $fingerprint, array $shipping = [], array $payment = []): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key) || !preg_match('/^[a-f0-9]{64}$/D', $owner)) throw DomainError::forbidden('주문서를 다시 열어 주세요.');
        if ($existing = $this->submitted($key, $owner, $userId)) return $this->get((int) $existing['id']);
        $buyer = $this->validateCheckout($input);
        if ($lines === []) throw DomainError::validation(['cart' => '주문할 상품을 담아 주세요.']);
        try {
            $id = $this->store->transaction(function () use ($lines, $buyer, $input, $key, $owner, $userId, $fingerprint, $shipping, $payment): int {
                // 관리자의 상품/옵션 편집과 주문을 상품 ID 순으로 직렬화한다.
                $ids = array_values(array_unique(array_column($lines, 'product_id'))); sort($ids, SORT_NUMERIC);
                foreach ($ids as $id) $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1 WHERE id = ?', [(int) $id]);
                $quote = $this->cart->quote($lines, $shipping, true);
                if ($quote['errors'] !== []) throw DomainError::validation($quote['errors']);
                if (!hash_equals($quote['fingerprint'], $fingerprint)) throw DomainError::validation(['quote' => '상품 또는 배송비가 변경되었습니다. 아래 최신 주문 내용을 확인하고 다시 주문해 주세요.']);
                $now = Clock::timestamp();
                $prefix = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Asia/Seoul'))->format('ymd-His');
                $start = random_int(0, 9999);
                do { $step = random_int(1, 9999); } while ($step % 2 === 0 || $step % 5 === 0);
                $saveDefault = ($input['save_default_address'] ?? '') === '1';
                if ($saveDefault) {
                    // 같은 회원의 동시 주문이 둘 다 기본값이 되지 않도록 회원 행으로 직렬화한다.
                    $this->store->selectOne('SELECT id FROM ' . $this->store->table('users') . ' WHERE id = ? FOR UPDATE', [$userId]);
                    $this->store->execute('UPDATE ' . $this->store->table('yc_orders') . ' SET default_address = 0 WHERE user_id = ? AND default_address = 1', [$userId]);
                }
                $orderData = $buyer + ['checkout_key' => $key, 'owner_key' => $owner,
                    'user_id' => $userId, 'default_address' => $saveDefault ? 1 : 0, 'status' => 'pending', 'subtotal' => $quote['subtotal'], 'shipping_fee' => $quote['shipping_fee'],
                    'cod_fee' => $quote['cod_fee'], 'total' => $quote['total'], 'shipping_detail' => json_encode($quote['shipping'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'order_notice' => $this->settings->all()['order_notice'], 'carrier' => '', 'tracking_number' => '',
                    'payment_method' => (string) ($payment['method'] ?? ''), 'payment_id' => (string) ($payment['id'] ?? ''),
                    'payment_provider' => (string) ($payment['provider'] ?? (!empty($payment['id']) ? 'inicis' : '')),
                    'payment_environment' => (string) ($payment['environment'] ?? ''), 'payment_revision' => (string) ($payment['revision'] ?? ''),
                    'paid_at' => 0, 'paid_amount' => 0, 'refunded_amount' => 0, 'pay_by' => (int) ($payment['pay_by'] ?? 0),
                    'payment_detail' => $payment === [] ? '' : json_encode($payment['detail'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now];
                $id = null;
                // 난수에서 시작해 0000~9999를 중복 없이 순회한다. DB 고유 제약이 동시 주문도 보호한다.
                for ($attempt = 0; $attempt < 10000; $attempt++) {
                    $number = $prefix . sprintf('%04d', ($start + $attempt * $step) % 10000);
                    try {
                        $id = $this->store->insert('yc_orders', $orderData + ['number' => $number]);
                        break;
                    } catch (DomainError $e) {
                        if (!$this->numberConflict($e, $number)) throw $e;
                    }
                }
                if ($id === null) throw DomainError::serviceUnavailable('주문번호를 발급할 수 없습니다. 잠시 후 다시 시도해 주세요.');
                foreach ($quote['items'] as $item) {
                    $optionId = $item['option_id'] ?: null;
                    $cell = Stock::cellOfItem($item);
                    if ($this->store->execute('UPDATE ' . $this->store->table($cell['table']) . ' SET stock = stock - ? WHERE id = ? AND stock >= ? AND active = 1', [$item['quantity'], $cell['id'], $item['quantity']]) !== 1) {
                        throw DomainError::validation(['stock' => $item['name'] . ': 재고가 변경되었습니다. 수량을 다시 확인해 주세요.']);
                    }
                    $this->store->insert('yc_order_items', ['order_id' => $id, 'product_id' => $item['product_id'], 'option_id' => $optionId,
                        'kind' => $item['kind'], 'product_code' => $item['code'], 'product_name' => $item['name'], 'option_label' => $item['label'],
                        'image' => $item['image'] ?? '', 'unit_price' => $item['price'], 'quantity' => $item['quantity'], 'total' => $item['total']]);
                    $this->store->logStock($item['product_id'], $optionId, -$item['quantity'], 'order', $number, 'user:' . $userId);
                }
                $this->history($id, 'pending', 'user:' . $userId, '주문을 접수했습니다.');
                return $id;
            });
        } catch (DomainError $e) {
            // 유일 키 제약으로 경합한 동일 주문서 요청은 최초 주문을 반환한다.
            if ($existing = $this->submitted($key, $owner, $userId)) return $this->get((int) $existing['id']);
            throw $e;
        }
        return $this->get($id);
    }

    private function numberConflict(DomainError $error, string $number): bool
    {
        $cause = $error->getPrevious();
        return $cause instanceof PDOException && (int) ($cause->errorInfo[1] ?? 0) === 1062
            && $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_orders') . ' WHERE number = ? FOR UPDATE', [$number]) !== null;
    }

    public function get(int $id): array
    {
        $order = $this->store->get('yc_orders', $id);
        $order['items'] = $this->store->select('SELECT * FROM ' . $this->store->table('yc_order_items') . ' WHERE order_id = ? ORDER BY id', [$id]);
        $order['history'] = $this->store->select('SELECT * FROM ' . $this->store->table('yc_order_history') . ' WHERE order_id = ? ORDER BY id', [$id]);
        $order['shipping'] = json_decode($order['shipping_detail'], true, 8, JSON_THROW_ON_ERROR);
        $decoded = ($order['payment_detail'] ?? '') === '' ? [] : json_decode((string) $order['payment_detail'], true, 8);
        $order['payment'] = is_array($decoded) ? $decoded : [];
        return $order;
    }

    public function owned(string $number, int $userId): array
    {
        $order = $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_orders') . ' WHERE number = ? AND user_id = ?', [$number, $userId]);
        if ($order === null) throw DomainError::notFound('주문을 찾을 수 없습니다. 주문 조회에서 확인해 주세요.');
        return $this->get((int) $order['id']);
    }

    /** URL 참조값에는 주문번호 대신 주문 생성 때 발급한 추측 불가능한 난수 키를 쓴다. */
    public static function reference(array $order): string
    {
        return (string) $order['checkout_key'];
    }

    public function ownedReference(string $reference, int $userId): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $reference)) {
            $order = $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_orders') . ' WHERE checkout_key = ? AND user_id = ?', [$reference, $userId]);
            if ($order !== null) return $this->get((int) $order['id']);
        }

        // 기존 주문 링크는 한 번 열 수 있게 두고, 컨트롤러에서 새 URL로 이동시킨다.
        if (preg_match('/^(.{1,32})-([a-f0-9]{32})$/D', $reference, $match)) {
            $order = $this->owned($match[1], $userId);
            $legacy = $match[1] . '-' . substr(hash_hmac('sha256', 'shop-order-url:' . $match[1], (string) $order['checkout_key']), 0, 32);
            if (hash_equals($legacy, $reference)) return $order;
        }

        throw DomainError::notFound('주문을 찾을 수 없습니다. 주문 조회에서 확인해 주세요.');
    }

    public function listing(?int $userId, string $status = '', int $page = 1, bool $admin = false, string $search = ''): array
    {
        $where = []; $params = [];
        if (!$admin) {
            if ($userId === null) return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
            $where[] = 'user_id = ?'; $params[] = $userId;
        }
        if (isset(self::STATUSES[$status])) { $where[] = 'status = ?'; $params[] = $status; }
        if ($admin && $search !== '') {
            $where[] = '(number LIKE ? ESCAPE \'!\' OR buyer_name LIKE ? ESCAPE \'!\')';
            $like = '%' . \GnuCms\Shop\Catalog\Products::like($search) . '%'; array_push($params, $like, $like);
        }
        $sql = ' FROM ' . $this->store->table('yc_orders') . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS c' . $sql, $params)['c'];
        $page = max(1, min(100000, $page));
        return ['items' => $this->store->select('SELECT *' . $sql . ' ORDER BY id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), $params),
            'total' => $total, 'page' => $page, 'total_pages' => max(1, (int) ceil($total / 20))];
    }

    public function transition(int $id, string $from, string $to, string $actor, array $input = [], bool $customer = false): array
    {
        if ($to === 'paid') throw DomainError::validation(['status' => '결제 완료는 결제 확인으로만 바뀝니다.']);
        if (!in_array($to, self::NEXT[$from] ?? [], true) || ($customer && ($from !== 'pending' || $to !== 'cancelled'))) {
            throw DomainError::validation(['status' => '현재 주문 상태에서는 이 작업을 할 수 없습니다.']);
        }
        if ($to === 'cancelled' && $from !== 'pending') {
            $current = $this->get($id);
            if (in_array($current['payment_method'], self::PG_METHODS, true) && (int) $current['refunded_amount'] < (int) $current['paid_amount']) {
                throw DomainError::validation(['refund' => '결제된 주문은 환불을 먼저 처리해 주세요.']);
            }
        }
        $carrier = $to === 'shipped' ? Input::text($input['carrier'] ?? '', 'carrier', 100, false) : null;
        $tracking = $to === 'shipped' ? Input::text($input['tracking_number'] ?? '', 'tracking_number', 100, false) : null;
        $note = $to === 'cancelled' && $customer
            ? CancellationReason::note($input)
            : Input::text($input['note'] ?? '', 'note', 500);
        $this->store->transaction(function () use ($id, $from, $to, $actor, $carrier, $tracking, $note): void {
            $changed = $this->store->execute('UPDATE ' . $this->store->table('yc_orders') . ' SET status = ?, updated_at = ? WHERE id = ? AND status = ?', [$to, Clock::timestamp(), $id, $from]);
            if ($changed !== 1) throw DomainError::validation(['status' => '주문 상태가 변경되었습니다. 새로고침 후 확인해 주세요.']);
            $order = $this->get($id);
            if ($to === 'shipped') $this->store->update('yc_orders', $id, ['carrier' => $carrier, 'tracking_number' => $tracking]);
            if ($to === 'cancelled' || $to === 'completed') {
                $items = $order['items']; usort($items, static fn ($a, $b) => [(int) $a['product_id'], (int) $a['option_id']] <=> [(int) $b['product_id'], (int) $b['option_id']]);
                foreach ($items as $item) {
                    $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1 WHERE id = ?', [(int) $item['product_id']]);
                    if ($to === 'cancelled') {
                        $cell = Stock::cellOfItem($item);
                        if ($this->store->execute('UPDATE ' . $this->store->table($cell['table']) . ' SET stock = stock + ? WHERE id = ?', [(int) $item['quantity'], $cell['id']]) !== 1) {
                            throw DomainError::validation(['stock' => '재고 복원 대상이 없습니다. 상품·옵션을 확인해 주세요.']);
                        }
                        $this->store->logStock((int) $item['product_id'], $item['option_id'] === null ? null : (int) $item['option_id'], (int) $item['quantity'], 'cancel', $order['number'], $actor);
                    } elseif ($item['kind'] !== 'extra') {
                        $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET sold_qty = sold_qty + ? WHERE id = ?', [(int) $item['quantity'], (int) $item['product_id']]);
                    }
                }
            }
            $this->history($id, $to, $actor, $note);
        });
        return $this->get($id);
    }

    public function byPaymentId(string $paymentId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $paymentId)) return null;
        $row = $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_orders') . ' WHERE payment_id = ?', [$paymentId]);
        return $row === null ? null : $this->get((int) $row['id']);
    }

    /**
     * 결제 완료. 콜백 재전송이나 통보 중복으로 두 번 불려도 한 번만 기록한다. 금액은 주문
     * 총액과 같아야 한다 — 결제 계층도 검사하지만 무통장 입금 확인은 여기만 지난다.
     */
    public function markPaid(int $id, string $actor, int $amount, array $detail, int $paidAt, string $note): array
    {
        $this->store->transaction(function () use ($id, $actor, $amount, $detail, $paidAt, $note): void {
            $order = $this->get($id);
            if ($order['status'] === 'paid' && (int) $order['paid_amount'] === $amount) return;
            if ($order['status'] !== 'pending') throw DomainError::validation(['status' => '결제 대기 중인 주문이 아닙니다.']);
            if ($amount !== (int) $order['total']) throw DomainError::validation(['amount' => '결제 금액이 주문 금액과 다릅니다.']);
            $changed = $this->store->execute('UPDATE ' . $this->store->table('yc_orders') . ' SET status = ?, paid_at = ?, paid_amount = ?, payment_detail = ?, updated_at = ? WHERE id = ? AND status = ?',
                ['paid', $paidAt, $amount, json_encode($detail + $order['payment'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Clock::timestamp(), $id, 'pending']);
            if ($changed !== 1) throw DomainError::validation(['status' => '주문 상태가 변경되었습니다. 새로고침 후 확인해 주세요.']);
            $this->history($id, 'paid', $actor, $note);
        });
        return $this->get($id);
    }

    /**
     * 결제 대기가 아닌 주문(취소·만료 뒤)에 결제사 승인이 확인됐을 때. 상태·결제 금액은 건드리지
     * 않고 거래번호와 「환불 필요」 표시만 남겨 관리자가 찾을 수 있게 한다. 같은 거래번호로 두 번
     * 불려도(콜백 재전송·반복 조회) 이력은 한 번만 쌓인다.
     */
    public function recordOrphanApproval(int $id, string $actor, string $tid, string $label): array
    {
        $this->store->transaction(function () use ($id, $actor, $tid, $label): void {
            $order = $this->get($id);
            if (($order['payment']['needs_review'] ?? false) === true && (string) ($order['payment']['tid'] ?? '') === $tid) return;
            $detail = ['tid' => $tid, 'label' => $label, 'needs_review' => true] + $order['payment'];
            $this->store->execute('UPDATE ' . $this->store->table('yc_orders') . ' SET payment_detail = ?, updated_at = ? WHERE id = ?',
                [json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Clock::timestamp(), $id]);
            $this->history($id, $order['status'], $actor,
                '결제사 승인이 확인됐지만 주문이 결제 대기 상태가 아닙니다. 환불이 필요합니다. 거래번호 ' . $tid);
        });
        return $this->get($id);
    }

    /** 결제창을 여는 동안 기한이 지나지 않게 미는 것뿐이다. 상태도 이력도 바꾸지 않는다. */
    public function extendDeadline(int $id, int $until): array
    {
        $this->store->execute('UPDATE ' . $this->store->table('yc_orders') . ' SET pay_by = ?, updated_at = ? WHERE id = ? AND status = ? AND pay_by > 0 AND pay_by < ?',
            [$until, Clock::timestamp(), $id, 'pending', $until]);
        return $this->get($id);
    }

    /**
     * 무통장입금과 접수 전용(결제 수단이 아예 없는) 주문의 입금 확인. 관리자만 부른다.
     * 결제사(PG) 주문은 카드사 승인으로만 결제 완료가 되므로 여기서 거절한다.
     */
    public function confirmDeposit(int $id, string $actor): array
    {
        $order = $this->get($id);
        if (in_array($order['payment_method'], self::PG_METHODS, true)) throw DomainError::validation(['payment' => '무통장입금 주문만 입금을 확인합니다.']);
        // markPaid() 는 결제사 콜백 재전송처럼 같은 결과가 두 번 오면 조용히 넘어간다. 관리자의
        // 입금 확인은 사람이 다시 누른 것이므로 이미 처리된 주문이면 여기서 먼저 거절한다.
        if ($order['status'] !== 'pending') throw DomainError::validation(['status' => '이미 처리된 주문입니다.']);
        return $this->markPaid($id, $actor, (int) $order['total'], ['confirmed_by' => $actor], Clock::timestamp(), '입금을 확인했습니다.');
    }

    /**
     * 환불 누계와 이력. 결제사 환불은 Payments 가 먼저 성공시키고 부른다. 상태는 바꾸지 않는다.
     * $key 는 그 환불 요청의 고유 키다. 이미 기록한 키면 금액도 이력도 더하지 않는다 — 결제
     * 계층도 같은 키의 재요청을 캐시된 결과로 돌려주므로(PG 를 다시 부르지 않는다) 여기서
     * 세지 않으면 한 번의 환불이 두 번 빠진다.
     */
    public function recordRefund(int $id, int $amount, string $actor, string $reason, string $key): array
    {
        $this->store->transaction(function () use ($id, $amount, $actor, $reason, $key): void {
            $order = $this->get($id);
            $keys = is_array($order['payment']['refund_keys'] ?? null) ? $order['payment']['refund_keys'] : [];
            if (in_array($key, $keys, true)) return;
            $remaining = (int) $order['paid_amount'] - (int) $order['refunded_amount'];
            if ($amount < 1 || $amount > $remaining) throw DomainError::validation(['refund' => '환불 금액을 확인해 주세요.']);
            $keys[] = $key;
            $detail = ['refund_keys' => array_values($keys)] + $order['payment'];
            $this->store->execute('UPDATE ' . $this->store->table('yc_orders') . ' SET refunded_amount = refunded_amount + ?, payment_detail = ?, updated_at = ? WHERE id = ?',
                [$amount, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Clock::timestamp(), $id]);
            $this->history($id, $order['status'], $actor, '환불 ' . number_format($amount) . '원: ' . $reason);
        });
        return $this->get($id);
    }

    /**
     * 결제 기한이 지난 미결제 주문을 취소하고 재고를 돌려놓는다. cron 없이 관리자 주문 화면과
     * 주문서(접수 직전)에서 부른다. $inProgress 가 참인 주문(결제사 승인이 진행·완료됨)은
     * 건드리지 않는다 — 돈이 움직였을 수 있으므로 관리자의 결제 조회가 먼저다.
     * @param callable(array):bool $inProgress
     */
    public function expire(int $now, callable $inProgress, int $limit = 50): int
    {
        $rows = $this->store->select('SELECT id FROM ' . $this->store->table('yc_orders') . ' WHERE status = ? AND pay_by > 0 AND pay_by < ? ORDER BY pay_by LIMIT ' . $limit, ['pending', $now]);
        $count = 0;
        foreach ($rows as $row) {
            $order = $this->get((int) $row['id']);
            // 진행 여부를 확인하지 못하면(원장·PG 설정 오류) 진행 중으로 본다. 만료 취소는 나중에 다시 할 수 있지만
            // 승인된 주문을 잘못 취소하면 돈이 남는다. 이 호출은 주문 화면을 여는 길목이라 던져서도 안 된다.
            try {
                if ($inProgress($order)) continue;
            } catch (\Throwable) {
                continue;
            }
            try {
                $this->transition((int) $row['id'], 'pending', 'cancelled', 'system', ['note' => '결제 기한이 지나 자동으로 취소했습니다.']);
                $count++;
            } catch (DomainError) {
                // 경합으로 이미 바뀐 주문은 건너뛴다.
            }
        }
        return $count;
    }

    private function history(int $id, string $status, string $actor, string $note): void
    {
        $this->store->insert('yc_order_history', ['order_id' => $id, 'status' => $status, 'actor' => mb_substr($actor, 0, 100), 'note' => $note, 'created_at' => Clock::timestamp()]);
    }

    /** 결제창을 열기 전에도 주문자·배송지 입력을 동일한 규칙으로 확인한다. */
    public function validateCheckout(array $input): array
    {
        $row = []; $errors = [];
        $fields = ['buyer_name' => ['주문자 이름', 100, false], 'email' => ['이메일', 191, false], 'phone' => ['연락처', 30, false],
            'recipient' => ['받는 분', 100, false], 'recipient_phone' => ['받는 분 연락처', 30, false], 'postcode' => ['우편번호', 10, false],
            'address' => ['주소', 250, false], 'address_detail' => ['상세주소', 250, true], 'delivery_note' => ['배송 요청', 500, true]];
        foreach ($fields as $key => [$label, $max, $optional]) {
            try { $row[$key] = Input::text($input[$key] ?? '', $key, $max, $optional); }
            catch (DomainError $e) { $errors[$key] = $label . ': ' . implode(' ', $e->details()); }
        }
        if (isset($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = '이메일 주소를 확인해 주세요.';
        foreach (['phone', 'recipient_phone'] as $key) {
            if (isset($row[$key]) && (!preg_match('/^[0-9+() -]+$/D', $row[$key]) || strlen(preg_replace('/\D/', '', $row[$key])) < 8)) $errors[$key] = '연락처를 확인해 주세요.';
        }
        if (isset($row['postcode']) && !preg_match('/^[0-9]{5}$/D', $row['postcode'])) $errors['postcode'] = '우편번호 5자리를 입력해 주세요.';
        if (($input['agree'] ?? '') !== '1') $errors['agree'] = '주문 내용과 배송을 위한 정보 제공을 확인해 주세요.';
        if ($errors !== []) throw DomainError::validation($errors);
        return $row;
    }
}
