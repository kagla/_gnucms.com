<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use GnuCms\Error\DomainError;
use GnuCms\Payment\TaxAmounts;
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
    public const PREVIOUS = ['paid' => 'pending', 'confirmed' => 'paid', 'shipped' => 'confirmed', 'completed' => 'shipped'];
    /** 결제사(이니시스)를 거치는 수단. 무통장은 관리자가 입금을 확인한다. */
    public const PG_METHODS = ['card', 'easy_pay', 'bank_transfer', 'virtual_account', 'mobile'];

    public function __construct(private Store $store, private Cart $cart, private Settings $settings) {}

    /** 저장된 기본 배송지를 먼저 쓰고, 없으면 가장 최근 주문의 배송지를 사용한다. */
    public function defaultAddressFor(int $userId): ?array
    {
        return $this->store->selectOne('SELECT buyer_name, phone, email, recipient, recipient_phone, postcode, address, address_detail, delivery_note FROM '
            . $this->store->table('yc_orders') . ' WHERE user_id = ? ORDER BY default_address DESC, id DESC LIMIT 1', [$userId]);
    }

    public function hasPreviousAddressesFor(int $userId): bool
    {
        $orders = $this->store->table('yc_orders');
        return $this->store->selectOne('SELECT id FROM ' . $orders . ' WHERE user_id = ? LIMIT 1', [$userId]) !== null;
    }

    /** 주소별 대표 주문만 페이지 단위로 조회한다. */
    public function previousAddressesPageFor(int $userId, string $search, int $page, int $pageSize = 8): array
    {
        $orders = $this->store->table('yc_orders');
        $grouped = '(SELECT postcode, address, address_detail, '
            . 'MAX(CASE WHEN default_address = 1 THEN id ELSE 0 END) AS default_order_id, MAX(id) AS latest_order_id '
            . 'FROM ' . $orders . ' WHERE user_id = ? GROUP BY postcode, address, address_detail) a';
        $join = ' FROM ' . $grouped . ' INNER JOIN ' . $orders . ' o ON o.id = CASE WHEN a.default_order_id > 0 THEN a.default_order_id ELSE a.latest_order_id END';
        $where = '';
        $filterParams = [];
        if ($search !== '') {
            $where = ' WHERE EXISTS (SELECT 1 FROM ' . $orders . ' s WHERE s.user_id = ? AND s.postcode = a.postcode AND s.address = a.address AND s.address_detail = a.address_detail'
                . ' AND (s.recipient LIKE ? OR s.recipient_phone LIKE ? OR s.postcode LIKE ? OR s.address LIKE ? OR s.address_detail LIKE ?))';
            $like = '%' . $search . '%';
            $filterParams = [$userId, $like, $like, $like, $like, $like];
        }
        $count = (int) ($this->store->selectOne('SELECT COUNT(*) AS total' . $join . $where, [$userId, ...$filterParams])['total'] ?? 0);
        $pageSize = max(1, min(50, $pageSize));
        $totalPages = max(1, (int) ceil($count / $pageSize));
        $page = max(1, min($totalPages, $page));
        $offset = ($page - 1) * $pageSize;
        $items = $this->store->select('SELECT o.id, o.buyer_name, o.phone, o.email, o.recipient, o.recipient_phone, o.postcode, o.address, o.address_detail, o.delivery_note, o.default_address, o.created_at'
            . $join . $where . ' ORDER BY (a.default_order_id > 0) DESC, a.latest_order_id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset,
            [$userId, ...$filterParams]);
        foreach ($items as &$item) $item['created_label'] = date('Y.m.d', (int) $item['created_at']);
        unset($item);
        return ['items' => $items, 'total' => $count, 'page' => $page, 'total_pages' => $totalPages];
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
                if (!hash_equals($quote['fingerprint'], $fingerprint)) throw DomainError::validation(['quote' => '주문 내용 또는 배송 조건이 변경되었습니다. 상품·옵션·수량·배송비와 안내를 다시 확인해 주세요.']);
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
                    'taxable_amount' => $quote['tax']['taxable_amount'], 'supply_amount' => $quote['tax']['supply_amount'],
                    'vat_amount' => $quote['tax']['vat_amount'], 'tax_free_amount' => $quote['tax']['tax_free_amount'],
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
                        'image' => $item['image'] ?? '', 'unit_price' => $item['price'], 'quantity' => $item['quantity'], 'total' => $item['total'],
                        'tax_free' => $item['tax_free']]);
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
        $order['tax'] = TaxAmounts::fromOrder($order);
        $decoded = ($order['payment_detail'] ?? '') === '' ? [] : json_decode((string) $order['payment_detail'], true, 8);
        $order['payment'] = is_array($decoded) ? $decoded : [];
        return $order;
    }

    /** 관리 화면 전용 메모. 주문 조회의 get() 결과에는 포함하지 않는다. */
    public function notesFor(int $orderId): array
    {
        return $this->store->select('SELECT id, actor, note, created_at, occurred_at, after_history_id FROM ' . $this->store->table('yc_order_notes')
            . ' WHERE order_id = ? ORDER BY id', [$orderId]);
    }

    public function addNote(int $orderId, mixed $value, string $actor, mixed $afterHistoryId = null): int
    {
        $note = self::noteText($value);
        $order = $this->get($orderId);
        $history = $order['history'];
        if ($history === []) throw DomainError::validation(['after_history_id' => '메모를 넣을 처리 이력을 찾을 수 없습니다.']);
        $anchor = $afterHistoryId === null || $afterHistoryId === ''
            ? (int) $history[array_key_last($history)]['id'] : Input::filterId($afterHistoryId);
        if ($anchor === null || !in_array($anchor, array_map(static fn (array $event): int => (int) $event['id'], $history), true)) {
            throw DomainError::validation(['after_history_id' => '메모를 넣을 처리 이력을 다시 선택해 주세요.']);
        }
        return $this->store->insert('yc_order_notes', ['order_id' => $orderId, 'actor' => mb_substr($actor, 0, 100),
            'note' => $note, 'created_at' => Clock::timestamp(), 'after_history_id' => $anchor]);
    }

    public function editNote(int $orderId, int $noteId, mixed $value): void
    {
        $note = self::noteText($value);
        $changed = $this->store->execute('UPDATE ' . $this->store->table('yc_order_notes') . ' SET note = ? WHERE id = ? AND order_id = ?',
            [$note, $noteId, $orderId]);
        if ($changed === 0 && $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_order_notes')
            . ' WHERE id = ? AND order_id = ?', [$noteId, $orderId]) === null) {
            throw DomainError::notFound('처리 메모를 찾을 수 없습니다.');
        }
    }

    public function deleteNote(int $orderId, int $noteId): int
    {
        $note = $this->store->selectOne('SELECT after_history_id FROM ' . $this->store->table('yc_order_notes')
            . ' WHERE id = ? AND order_id = ?', [$noteId, $orderId]);
        if ($note === null) throw DomainError::notFound('처리 메모를 찾을 수 없습니다.');
        if ($this->store->execute('DELETE FROM ' . $this->store->table('yc_order_notes') . ' WHERE id = ? AND order_id = ?',
            [$noteId, $orderId]) !== 1) throw DomainError::notFound('처리 메모를 찾을 수 없습니다.');
        return (int) $note['after_history_id'];
    }

    private static function noteText(mixed $value): string
    {
        $note = Input::text($value, 'note', 500, false);
        if (preg_match('/[\r\n]/', $note)) throw DomainError::validation(['note' => '처리 메모는 한 줄로 입력해 주세요.']);
        return $note;
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
        } elseif ($search !== '') {
            $terms = ['number LIKE ? ESCAPE \'!\'', 'buyer_name LIKE ? ESCAPE \'!\'', 'phone LIKE ? ESCAPE \'!\'', 'recipient LIKE ? ESCAPE \'!\'',
                'EXISTS (SELECT 1 FROM ' . $this->store->table('yc_order_items') . ' i WHERE i.order_id = o.id'
                    . " AND i.kind <> 'extra' AND i.product_name LIKE ? ESCAPE '!')"];
            $like = '%' . \GnuCms\Shop\Catalog\Products::like($search) . '%';
            array_push($params, $like, $like, $like, $like, $like);
            $phoneDigits = preg_replace('/\D/', '', $search) ?? '';
            if (strlen($phoneDigits) >= 3) {
                $terms[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '(', ''), ')', ''), '+', '') LIKE ? ESCAPE '!'";
                $params[] = '%' . $phoneDigits . '%';
            }
            $where[] = '(' . implode(' OR ', $terms) . ')';
        }
        $sql = ' FROM ' . $this->store->table('yc_orders') . ' o' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS c' . $sql, $params)['c'];
        $page = max(1, min(100000, $page));
        $items = $this->store->select('SELECT *' . $sql . ' ORDER BY id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), $params);
        if (!$admin && $items !== []) {
            $ids = array_map(static fn (array $order): int => (int) $order['id'], $items);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $lines = $this->store->select('SELECT order_id, product_name, option_label, quantity FROM '
                . $this->store->table('yc_order_items') . " WHERE kind <> 'extra' AND order_id IN ({$marks}) ORDER BY id", $ids);
            $byOrder = [];
            foreach ($lines as $line) {
                $name = (string) $line['product_name'];
                if ((string) $line['option_label'] !== '') $name .= ' · ' . $line['option_label'];
                $byOrder[(int) $line['order_id']][] = $name . ' × ' . (int) $line['quantity'];
            }
            foreach ($items as &$order) {
                $order['product_summary'] = $byOrder[(int) $order['id']] ?? [];
                $order['product_count'] = count($order['product_summary']);
            }
            unset($order);
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'total_pages' => max(1, (int) ceil($total / 20))];
    }

    public function transition(int $id, string $from, string $to, string $actor, array $input = [], bool $customer = false): array
    {
        if ($to === 'paid') throw DomainError::validation(['status' => '결제 완료는 결제 확인으로만 바뀝니다.']);
        if (!in_array($to, self::NEXT[$from] ?? [], true) || ($customer && ($from !== 'pending' || $to !== 'cancelled'))) {
            throw DomainError::validation(['status' => '현재 주문 상태에서는 이 작업을 할 수 없습니다.']);
        }
        if ($from === 'pending' && $to === 'cancelled' && $actor !== 'system') {
            $current = $this->get($id);
            if ($current['payment_method'] === 'virtual_account' && isset($current['payment']['virtual_account'])) {
                throw DomainError::validation(['status' => '가상계좌가 발급된 주문은 입금 기한이 지나면 자동 취소됩니다.']);
            }
        }
        if ($to === 'cancelled' && $from !== 'pending') {
            $current = $this->get($id);
            if (in_array($current['payment_method'], self::PG_METHODS, true) && (int) $current['refunded_amount'] < (int) $current['paid_amount']) {
                throw DomainError::validation(['refund' => '결제된 주문은 환불을 먼저 처리해 주세요.']);
            }
        }
        $carrier = $to === 'shipped' ? Input::text($input['carrier'] ?? '', 'carrier', 100, false) : null;
        if ($carrier === Settings::OTHER_CARRIER) $carrier = Input::text($input['carrier_other'] ?? '', 'carrier_other', 100, false);
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

    /** 고객 취소는 결제 완료까지만 허용한다. 결제사 취소와 재고 복원을 같은 주문 잠금 아래에서 처리한다. */
    public function cancelForCustomer(int $id, int $userId, array $input, Payments $payments): array
    {
        $note = CancellationReason::note($input);
        return $this->store->transaction(function () use ($id, $userId, $input, $payments, $note): array {
            $row = $this->store->selectOne('SELECT id FROM ' . $this->store->table('yc_orders')
                . ' WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $userId]);
            if ($row === null) throw DomainError::notFound('주문을 찾을 수 없습니다.');
            $order = $this->get($id);
            if (!in_array($order['status'], ['pending', 'paid'], true)) {
                throw DomainError::validation(['status' => '상품 준비가 시작된 주문은 직접 취소할 수 없습니다. 상점에 문의해 주세요.']);
            }
            if ($order['status'] === 'pending') {
                // 승인 요청이 진행 중이면 취소 결과보다 결제 결과를 먼저 확인해야 한다.
                if ($payments->inProgress($order)) {
                    throw DomainError::validation(['status' => '결제 결과를 확인하는 중입니다. 잠시 후 다시 시도하거나 상점에 문의해 주세요.']);
                }
                return $this->transition($id, 'pending', 'cancelled', 'user:' . $userId, $input, true);
            }
            if ($payments->isPgOrder($order)) {
                if ((int) $order['paid_amount'] < 1) throw DomainError::validation(['payment' => '결제 금액을 확인할 수 없습니다. 상점에 문의해 주세요.']);
                $remaining = (int) $order['paid_amount'] - (int) $order['refunded_amount'];
                if ($remaining > 0) {
                    $key = hash('sha256', 'customer-cancel:' . $order['checkout_key']);
                    $order = $payments->refund($order, $remaining, '고객 요청 주문 취소', $key, 'user:' . $userId);
                }
                if ((int) $order['refunded_amount'] < (int) $order['paid_amount']) {
                    throw DomainError::validation(['refund' => '결제 취소가 완료되지 않았습니다. 상점에 문의해 주세요.']);
                }
            }
            // 무통장 입금은 주문만 먼저 취소한다. 실제 송금 반환과 환불 기록은 관리자가 처리한다.
            return $this->transition($id, 'paid', 'cancelled', 'user:' . $userId, ['note' => $note]);
        });
    }

    /** 잘못 기록한 상태만 한 단계 되돌린다. PG 승인과 주문 취소는 이 작업으로 되돌리지 않는다. */
    public static function previousStatus(array $order): ?string
    {
        $status = (string) ($order['status'] ?? '');
        if ($status === 'paid' && ((string) ($order['payment_id'] ?? '') !== ''
            || in_array($order['payment_method'] ?? '', self::PG_METHODS, true)
            || (int) ($order['refunded_amount'] ?? 0) > 0)) return null;
        return self::PREVIOUS[$status] ?? null;
    }

    /** 처리 메모·환불 기록처럼 같은 상태로 남긴 이력은 건너뛰고 최신 상태 변경을 찾는다. */
    public static function activeStatusHistoryId(array $order): ?int
    {
        $lastStatus = null;
        $historyId = null;
        foreach ($order['history'] as $event) {
            if ($event['status'] === $lastStatus) continue;
            $lastStatus = $event['status'];
            $historyId = (int) $event['id'];
        }
        return $lastStatus === $order['status'] ? $historyId : null;
    }

    public function undoStatus(int $id, int $historyId, string $from, string $actor, mixed $reason): array
    {
        $note = Input::text($reason, 'reason', 400, false);
        $this->store->transaction(function () use ($id, $historyId, $from, $actor, $note): void {
            $order = $this->get($id);
            if ($order['status'] !== $from) throw DomainError::validation(['status' => '주문 상태가 변경되었습니다. 새로고침 후 확인해 주세요.']);
            if (self::activeStatusHistoryId($order) !== $historyId) {
                throw DomainError::validation(['status' => '되돌릴 상태 이력이 변경되었습니다. 화면을 새로고침해 주세요.']);
            }
            $to = self::previousStatus($order);
            if ($to === null) throw DomainError::validation(['status' => '이 상태는 직접 되돌릴 수 없습니다. 결제·취소 내역을 확인해 주세요.']);

            $changes = ['status' => $to, 'updated_at' => Clock::timestamp()];
            if ($from === 'shipped') $changes += ['carrier' => '', 'tracking_number' => ''];
            if ($from === 'paid') {
                $detail = $order['payment'];
                unset($detail['confirmed_by']);
                $changes += ['paid_at' => 0, 'paid_amount' => 0,
                    'payment_detail' => $detail === [] ? '' : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                if ($order['payment_method'] === 'manual_transfer') {
                    $hours = (int) $this->settings->all()['payment']['deadline_hours']['manual_transfer'];
                    $changes['pay_by'] = Clock::timestamp() + max(1, $hours) * 3600;
                }
            }
            $columns = implode(', ', array_map(static fn (string $column): string => $column . ' = ?', array_keys($changes)));
            if ($this->store->execute('UPDATE ' . $this->store->table('yc_orders') . ' SET ' . $columns . ' WHERE id = ? AND status = ?',
                [...array_values($changes), $id, $from]) !== 1) {
                throw DomainError::validation(['status' => '주문 상태가 변경되었습니다. 새로고침 후 확인해 주세요.']);
            }

            if ($from === 'completed') {
                $items = array_values(array_filter($order['items'], static fn (array $item): bool => $item['kind'] !== 'extra'));
                usort($items, static fn (array $a, array $b): int => (int) $a['product_id'] <=> (int) $b['product_id']);
                foreach ($items as $item) {
                    if ($this->store->execute('UPDATE ' . $this->store->table('yc_products')
                        . ' SET sold_qty = sold_qty - ?, version = version + 1 WHERE id = ? AND sold_qty >= ?',
                        [(int) $item['quantity'], (int) $item['product_id'], (int) $item['quantity']]) !== 1) {
                        throw DomainError::validation(['status' => '판매수량을 되돌릴 수 없습니다. 상품 상태를 확인해 주세요.']);
                    }
                }
            }
            $this->history($id, $to, $actor, '상태 되돌림: ' . self::STATUSES[$from] . ' → ' . self::STATUSES[$to] . ' · ' . $note);
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
