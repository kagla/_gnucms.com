<?php

declare(strict_types=1);

namespace GnuCms\Modules\YoungCart\Commerce;

use GnuCms\Auth\PasswordThrottle;
use GnuCms\Error\DomainError;
use GnuCms\Modules\YoungCart\Input;
use GnuCms\Modules\YoungCart\Settings;
use GnuCms\Modules\YoungCart\Store;
use GnuCms\Support\Clock;

final class Orders
{
    public const STATUSES = ['pending' => '주문 접수', 'confirmed' => '상품 준비', 'shipped' => '배송 중', 'completed' => '배송 완료', 'cancelled' => '주문 취소'];
    public const NEXT = ['pending' => ['confirmed', 'cancelled'], 'confirmed' => ['shipped', 'cancelled'], 'shipped' => ['completed'], 'completed' => [], 'cancelled' => []];

    public function __construct(private Store $store, private Cart $cart, private Settings $settings) {}

    public function submitted(string $key, string $owner, ?int $userId): ?array
    {
        $row = $this->store->selectOne('SELECT * FROM ' . $this->store->table('yc_orders') . ' WHERE checkout_key = ? AND owner_key = ?', [$key, $owner]);
        if ($row !== null && ($row['user_id'] === null ? $userId !== null : (int) $row['user_id'] !== $userId)) return null;
        return $row;
    }

    public function place(array $lines, array $input, string $key, string $owner, ?int $userId, string $fingerprint, array $shipping = []): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key) || !preg_match('/^[a-f0-9]{64}$/D', $owner)) throw DomainError::forbidden('주문서를 다시 열어 주세요.');
        if ($existing = $this->submitted($key, $owner, $userId)) return $this->get((int) $existing['id']);
        $buyer = $this->validate($input, $userId === null);
        if ($lines === []) throw DomainError::validation(['cart' => '주문할 상품을 담아 주세요.']);
        try {
            $id = $this->store->transaction(function () use ($lines, $buyer, $key, $owner, $userId, $fingerprint, $shipping): int {
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
                    'order_notice' => $this->settings->all()['order_notice'], 'carrier' => '', 'tracking_number' => '', 'created_at' => $now, 'updated_at' => $now]);
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
            $like = '%' . \GnuCms\Modules\YoungCart\Catalog\Products::like($search) . '%'; array_push($params, $like, $like);
        }
        $sql = ' FROM ' . $this->store->table('yc_orders') . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $total = (int) $this->store->selectOne('SELECT COUNT(*) AS c' . $sql, $params)['c'];
        $page = max(1, min(100000, $page));
        return ['items' => $this->store->select('SELECT *' . $sql . ' ORDER BY id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), $params),
            'total' => $total, 'page' => $page, 'total_pages' => max(1, (int) ceil($total / 20))];
    }

    public function transition(int $id, string $from, string $to, string $actor, array $input = [], bool $customer = false): array
    {
        if (!in_array($to, self::NEXT[$from] ?? [], true) || ($customer && ($from !== 'pending' || $to !== 'cancelled'))) {
            throw DomainError::validation(['status' => '현재 주문 상태에서는 이 작업을 할 수 없습니다.']);
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
