<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use GnuCms\Auth\PasswordThrottle;
use GnuCms\Error\DomainError;
use GnuCms\Shop\Input;
use GnuCms\Shop\Settings;
use GnuCms\Shop\Store;
use GnuCms\Support\Clock;

final class Orders
{
    public const STATUSES = ['pending' => '주문 접수', 'paid' => '결제 완료', 'confirmed' => '상품 준비', 'shipped' => '배송 중', 'completed' => '배송 완료', 'cancelled' => '주문 취소'];
    public const NEXT = ['pending' => ['paid', 'cancelled'], 'paid' => ['confirmed', 'cancelled'], 'confirmed' => ['shipped', 'cancelled'], 'shipped' => ['completed'], 'completed' => [], 'cancelled' => []];
    /** 결제사(이니시스)를 거치는 수단. 무통장은 관리자가 입금을 확인한다. */
    public const PG_METHODS = ['card', 'easy_pay', 'bank_transfer', 'virtual_account'];

    public function __construct(private Store $store, private Cart $cart, private Settings $settings) {}

    public function submitted(string $key, string $owner, ?int $userId): ?array
    {
        $row = $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_orders') . ' WHERE checkout_key = ? AND owner_key = ?', [$key, $owner]);
        if ($row !== null && ($row['user_id'] === null ? $userId !== null : (int) $row['user_id'] !== $userId)) return null;
        return $row;
    }

    public function place(array $lines, array $input, string $key, string $owner, ?int $userId, string $fingerprint, array $shipping = [], array $payment = []): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key) || !preg_match('/^[a-f0-9]{64}$/D', $owner)) throw DomainError::forbidden('주문서를 다시 열어 주세요.');
        if ($existing = $this->submitted($key, $owner, $userId)) return $this->get((int) $existing['id']);
        $buyer = $this->validate($input, $userId === null);
        if ($lines === []) throw DomainError::validation(['cart' => '주문할 상품을 담아 주세요.']);
        try {
            $id = $this->store->transaction(function () use ($lines, $buyer, $key, $owner, $userId, $fingerprint, $shipping, $payment): int {
                // 관리자의 상품/옵션 편집과 주문을 상품 ID 순으로 직렬화한다.
                $ids = array_values(array_unique(array_column($lines, 'product_id'))); sort($ids, SORT_NUMERIC);
                foreach ($ids as $id) $this->store->execute('UPDATE ' . $this->store->table('yc_products') . ' SET version = version + 1 WHERE id = ?', [(int) $id]);
                $quote = $this->cart->quote($lines, $shipping, true);
                if ($quote['errors'] !== []) throw DomainError::validation($quote['errors']);
                if (!hash_equals($quote['fingerprint'], $fingerprint)) throw DomainError::validation(['quote' => '상품 또는 배송비가 변경되었습니다. 아래 최신 주문 내용을 확인하고 다시 주문해 주세요.']);
                $now = Clock::timestamp();
                $number = gmdate('Ymd', $now) . '-' . strtoupper(bin2hex(random_bytes(6)));
                $id = $this->store->insert('yc_orders', $buyer + ['number' => $number, 'checkout_key' => $key, 'owner_key' => $owner,
                    'user_id' => $userId, 'status' => 'pending', 'subtotal' => $quote['subtotal'], 'shipping_fee' => $quote['shipping_fee'],
                    'cod_fee' => $quote['cod_fee'], 'total' => $quote['total'], 'shipping_detail' => json_encode($quote['shipping'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'order_notice' => $this->settings->all()['order_notice'], 'carrier' => '', 'tracking_number' => '',
                    'payment_method' => (string) ($payment['method'] ?? ''), 'payment_id' => (string) ($payment['id'] ?? ''),
                    'payment_environment' => (string) ($payment['environment'] ?? ''), 'payment_revision' => (string) ($payment['revision'] ?? ''),
                    'paid_at' => 0, 'paid_amount' => 0, 'refunded_amount' => 0, 'pay_by' => (int) ($payment['pay_by'] ?? 0),
                    'payment_detail' => $payment === [] ? '' : json_encode($payment['detail'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now]);
                foreach ($quote['items'] as $item) {
                    $optionId = $item['option_id'] ?: null;
                    $table = $optionId === null ? 'yc_products' : 'yc_options';
                    $stockId = $optionId ?? $item['product_id'];
                    if ($this->store->execute('UPDATE ' . $this->store->table($table) . ' SET stock = stock - ? WHERE id = ? AND stock >= ? AND active = 1', [$item['quantity'], $stockId, $item['quantity']]) !== 1) {
                        throw DomainError::validation(['stock' => $item['name'] . ': 재고가 변경되었습니다. 수량을 다시 확인해 주세요.']);
                    }
                    $this->store->insert('yc_order_items', ['order_id' => $id, 'product_id' => $item['product_id'], 'option_id' => $optionId,
                        'kind' => $item['kind'], 'product_code' => $item['code'], 'product_name' => $item['name'], 'option_label' => $item['label'],
                        'image' => $item['image'] ?? '', 'unit_price' => $item['price'], 'quantity' => $item['quantity'], 'total' => $item['total']]);
                    $this->store->logStock($item['product_id'], $optionId, -$item['quantity'], 'order', $number, $userId === null ? 'guest' : 'user:' . $userId);
                }
                $this->history($id, 'pending', $userId === null ? 'guest' : 'user:' . $userId, '주문을 접수했습니다.');
                return $id;
            });
        } catch (DomainError $e) {
            // 유일 키 제약으로 경합한 동일 요청도 최초 주문을 반환한다.
            if ($existing = $this->submitted($key, $owner, $userId)) return $this->get((int) $existing['id']);
            throw $e;
        }
        return $this->get($id);
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

    public function owned(string $number, ?int $userId, array $guestIds): array
    {
        $order = $this->store->selectOne('SELECT id, user_id FROM ' . $this->store->table('yc_orders') . ' WHERE number = ?', [$number]);
        if ($order === null || ($order['user_id'] === null ? !in_array((int) $order['id'], $guestIds, true) : ($userId === null || (int) $order['user_id'] !== $userId))) {
            throw DomainError::notFound('주문을 찾을 수 없습니다. 주문 조회에서 확인해 주세요.');
        }
        return $this->get((int) $order['id']);
    }

    public function lookup(array $input, ?string $ip): array
    {
        $throttle = new PasswordThrottle($this->store->db, $ip);
        $key = 'youngcart:order-lookup';
        $throttle->assertNotLocked($key);
        $number = Input::text($input['number'] ?? '', 'number', 32);
        $email = Input::text($input['email'] ?? '', 'email', 191);
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $order = $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_orders') . ' WHERE number = ?', [$number]);
        // 존재 여부와 회원 주문 여부를 같은 메시지로 처리한다.
        $valid = $order !== null && $order['user_id'] === null && strcasecmp($order['email'], $email) === 0 && strlen($password) <= 72;
        $verified = password_verify($password, $valid ? $order['guest_password'] : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$valid || !$verified) throw DomainError::validation(['password' => $throttle->recordFailureMessage($key, '주문번호, 이메일 또는 비밀번호가 일치하지 않습니다.')]);
        $throttle->clear($key);
        return $this->get((int) $order['id']);
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
        $note = Input::text($input['note'] ?? '', 'note', 500);
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
                        $table = $item['option_id'] === null ? 'yc_products' : 'yc_options';
                        $stockId = (int) ($item['option_id'] ?? $item['product_id']);
                        if ($this->store->execute('UPDATE ' . $this->store->table($table) . ' SET stock = stock + ? WHERE id = ?', [(int) $item['quantity'], $stockId]) !== 1) {
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

    private function validate(array $input, bool $guest): array
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
        $password = $input['password'] ?? '';
        if ($guest && (!is_string($password) || strlen($password) < 8 || strlen($password) > 72)) $errors['password'] = '비회원 주문 조회 비밀번호는 8~72바이트로 입력해 주세요.';
        if ($errors !== []) throw DomainError::validation($errors);
        $row['guest_password'] = $guest ? password_hash($password, PASSWORD_DEFAULT) : '';
        return $row;
    }
}
