<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Products;
use GnuCms\Shop\Catalog\Stock;
use GnuCms\Shop\Input;
use GnuCms\Shop\Settings;
use GnuCms\Shop\Store;

/** 세션에는 식별자와 수량만 저장한다. 금액·판매 상태는 매 조회마다 다시 읽는다. */
final class Cart
{
    public const MAX_LINES = 100;
    public const MAX_QUANTITY = 9999;

    public function __construct(private Products $products, private Settings $settings, private Store $store) {}

    /** 상단 배지는 본상품·선택옵션의 수량만 센다. 추가옵션 여부는 한 번에 조회한다. */
    public function productQuantity(array $cart): int
    {
        $ids = array_values(array_unique(array_filter(array_column($cart, 'option_id'))));
        $extras = [];
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $extras = array_fill_keys(array_column($this->store->select('SELECT id FROM ' . $this->store->table('yc_options')
                . " WHERE kind = 'extra' AND id IN (" . $marks . ')', $ids), 'id'), true);
        }
        $quantity = 0;
        foreach ($cart as $item) if (!isset($extras[(int) $item['option_id']])) $quantity += (int) $item['quantity'];
        return $quantity;
    }

    public function add(array $cart, array $input): array
    {
        $productId = Input::id($input['product_id'] ?? null);
        $product = $this->products->get($productId);
        $selectIds = array_map('intval', array_column($product['options']['select'], 'id'));
        if (array_key_exists('selections', $input)) {
            if (!is_array($input['selections']) || $input['selections'] === [] || count($input['selections']) > self::MAX_LINES) {
                throw DomainError::validation(['selections' => '구매할 옵션을 1~' . self::MAX_LINES . '개 선택해 주세요.']);
            }
            $add = [];
            foreach ($input['selections'] as $id => $qty) {
                $id = Input::id($id);
                if (!in_array($id, $selectIds, true)) throw DomainError::validation(['selections' => '선택옵션을 다시 선택해 주세요.']);
                $add[$id] = Input::int($qty, 'selections', 1, self::MAX_QUANTITY);
            }
        } else {
            // Keep single-option forms and the no-JavaScript fallback compatible.
            $optionId = Input::int($input['option_id'] ?? null, 'option_id', 0, PHP_INT_MAX, 0);
            if ($optionId !== 0 && !in_array($optionId, $selectIds, true)) {
                throw DomainError::validation(['option_id' => '선택옵션을 다시 선택해 주세요.']);
            }
            $add = [$optionId => Input::int($input['quantity'] ?? null, 'quantity', 1, self::MAX_QUANTITY)];
        }
        $extras = $input['extras'] ?? [];
        if (!is_array($extras) || count($extras) > self::MAX_LINES) throw DomainError::validation(['extras' => '추가옵션을 확인해 주세요.']);
        foreach ($extras as $id => $qty) {
            $qty = Input::int($qty, 'extras', 0, self::MAX_QUANTITY, 0);
            if ($qty === 0) continue;
            $id = Input::id($id);
            if (!in_array($id, array_map('intval', array_column($product['options']['extra'], 'id')), true)) {
                throw DomainError::validation(['extras' => '추가옵션을 다시 선택해 주세요.']);
            }
            $add[$id] = $qty;
        }
        foreach ($add as $id => $qty) {
            $key = $productId . ':' . $id;
            $cart[$key] = ['product_id' => $productId, 'option_id' => $id, 'quantity' => ($cart[$key]['quantity'] ?? 0) + $qty];
        }
        $quote = $this->quote($cart);
        if ($quote['errors'] !== []) throw DomainError::validation($quote['errors']);
        return $cart;
    }

    /** 담기 실패 후 화면을 갱신할 재고. 예약하지 않으며 기존 장바구니 수량은 따로 알린다. */
    public function availability(int $productId, array $cart): array
    {
        $product = $this->products->get($productId);
        $selling = (int) $product['active'] === 1 && (int) ($product['categories'][1]['active'] ?? 0) === 1
            && (int) $product['phone_inquiry'] !== 1 && (int) $product['sold_out'] !== 1;
        $items = [];
        $rows = $product['options']['select'] === [] ? [null] : [];
        foreach ([...$rows, ...$product['options']['select'], ...$product['options']['extra']] as $row) {
            $id = $row === null ? 0 : (int) $row['id'];
            $stock = $selling && ($row === null || (int) $row['active'] === 1) ? Stock::cell($product, $row)['stock'] : 0;
            $items[$id] = ['stock' => max(0, min(self::MAX_QUANTITY, $stock)),
                'in_cart' => (int) ($cart[$productId . ':' . $id]['quantity'] ?? 0)];
        }
        return ['product_id' => $productId, 'items' => (object) $items];
    }

    public function update(array $cart, array $quantities): array
    {
        foreach ($quantities as $key => $quantity) {
            if (!isset($cart[$key])) throw DomainError::validation(['cart' => '장바구니가 변경되었습니다. 새로고침해 주세요.']);
            $qty = Input::int($quantity, 'quantity', 0, self::MAX_QUANTITY);
            if ($qty === 0) unset($cart[$key]);
            else $cart[$key]['quantity'] = $qty;
        }
        // 기본 상품의 마지막 행을 지우면 함께 담은 추가옵션도 지운다.
        $parents = [];
        foreach ($cart as $line) {
            $product = $this->products->find((int) $line['product_id']);
            if ($product === null) continue;
            $extraIds = array_map('intval', array_column($this->products->get((int) $line['product_id'])['options']['extra'], 'id'));
            if (!in_array((int) $line['option_id'], $extraIds, true)) $parents[$line['product_id']] = true;
        }
        foreach ($cart as $key => $line) if (!isset($parents[$line['product_id']])) unset($cart[$key]);
        return $cart;
    }

    public function quote(array $cart, array $shipping = [], bool $checkout = false): array
    {
        if (count($cart) > self::MAX_LINES) throw DomainError::validation(['cart' => '장바구니에는 최대 100개 항목을 담을 수 있습니다.']);
        $items = []; $errors = []; $groups = []; $products = [];
        foreach ($cart as $key => $line) {
            $item = ['key' => $key, 'product_id' => (int) $line['product_id'], 'option_id' => (int) $line['option_id'],
                'quantity' => (int) $line['quantity'], 'name' => '판매가 종료된 상품', 'code' => '', 'image' => null,
                'label' => '', 'kind' => 'base', 'price' => 0, 'total' => 0, 'error' => ''];
            try {
                $product = $products[$item['product_id']] ??= $this->products->get($item['product_id']);
                $item['name'] = $product['name']; $item['code'] = $product['code'];
                $item['image'] = $product['images'][0]['filename'] ?? null;
                $option = null;
                if ($item['option_id'] !== 0) {
                    foreach (['select', 'extra'] as $kind) foreach ($product['options'][$kind] as $row) {
                        if ((int) $row['id'] === $item['option_id']) $option = $row;
                    }
                    if ($option === null) throw DomainError::validation(['option' => '선택한 옵션은 더 이상 판매하지 않습니다.']);
                    $item['kind'] = $option['kind'];
                    $item['label'] = implode(' / ', array_filter([$option['value1'], $option['value2'], $option['value3']], static fn ($v) => $v !== ''));
                    if ((int) $option['active'] !== 1) throw DomainError::validation(['option' => '선택한 옵션은 더 이상 판매하지 않습니다.']);
                } elseif ($product['options']['select'] !== []) {
                    throw DomainError::validation(['option' => '필수 옵션을 선택해 주세요.']);
                }
                if ((int) $product['active'] !== 1 || (int) ($product['categories'][1]['active'] ?? 0) !== 1 || (int) $product['phone_inquiry'] === 1) {
                    throw DomainError::validation(['product' => '현재 구매할 수 없는 상품입니다.']);
                }
                $stock = Stock::cell($product, $option)['stock'];
                if ((int) $product['sold_out'] === 1 || $item['quantity'] > $stock) throw DomainError::validation(['stock' => '재고가 부족합니다. 구매 가능 수량: ' . ((int) $product['sold_out'] === 1 ? 0 : $stock) . '개']);
                Input::int($item['quantity'], 'quantity', 1, self::MAX_QUANTITY);
                $item['price'] = $item['kind'] === 'extra' ? (int) $option['price'] : (int) $product['price'] + (int) ($option['price'] ?? 0);
                if ($item['price'] < 0) throw DomainError::validation(['price' => '상품 가격을 확인할 수 없습니다.']);
                $item['total'] = $item['price'] * $item['quantity'];
                $groups[$item['product_id']] ??= ['product' => $product, 'quantity' => 0, 'subtotal' => 0];
                $groups[$item['product_id']]['quantity'] += $item['kind'] === 'extra' ? 0 : $item['quantity'];
                $groups[$item['product_id']]['subtotal'] += $item['total'];
            } catch (DomainError $e) {
                $item['error'] = implode(' ', $e->details() ?: [$e->getMessage()]);
                $errors[$key] = $item['name'] . ($item['label'] === '' ? '' : ' / ' . $item['label']) . ': ' . $item['error'];
            }
            $items[] = $item;
        }
        foreach ($groups as $id => $group) {
            $min = max(1, (int) $group['product']['buy_min']); $max = (int) $group['product']['buy_max'];
            if ($group['quantity'] === 0 || ($checkout && $group['quantity'] < $min) || ($max > 0 && $group['quantity'] > $max)) {
                $errors['quantity_' . $id] = $group['product']['name'] . ': 기본 상품 수량은 최소 ' . $min . '개' . ($max > 0 ? ', 최대 ' . $max . '개' : '') . '입니다.';
            }
        }
        $delivery = $this->shipping($groups, $shipping);
        $subtotal = array_sum(array_column($items, 'total'));
        $quote = ['items' => $items, 'errors' => $errors, 'subtotal' => $subtotal, 'shipping_fee' => $delivery['prepaid'],
            'cod_fee' => $delivery['cod'], 'shipping' => $delivery['lines'], 'total' => $subtotal + $delivery['prepaid'],
            'quantity' => array_sum(array_column(array_filter($items, static fn ($item) => $item['kind'] !== 'extra'), 'quantity'))];
        // 가격뿐 아니라 배송 방식·옵션·수량·구매 안내가 바뀌어도 다시 검토하게 한다.
        $quote['fingerprint'] = hash('sha256', json_encode([$items, $delivery, $this->settings->all()['order_notice']], JSON_THROW_ON_ERROR));
        return $quote;
    }

    private function shipping(array $groups, array $choices): array
    {
        $settings = $this->settings->all()['shipping'];
        $lines = []; $default = [];
        foreach ($groups as $id => $group) {
            if ($group['quantity'] === 0) continue;
            $p = $group['product']; $method = (int) $p['shipping_method'];
            $choice = $choices[$id] ?? 'prepaid';
            if (!in_array($choice, ['prepaid', 'cod'], true)) throw DomainError::validation(['shipping' => '배송비 결제 방식을 확인해 주세요.']);
            $mode = $method === 1 || ($method === 2 && $choice === 'cod') ? 'cod' : 'prepaid';
            $type = (int) $p['shipping_type'];
            $fee = match ($type) {
                0 => 0,
                1 => 0,
                2 => $group['subtotal'] >= (int) $p['shipping_free_minimum'] ? 0 : (int) $p['shipping_fee'],
                3 => (int) $p['shipping_fee'],
                4 => (int) ceil($group['quantity'] / max(1, (int) $p['shipping_per_qty'])) * (int) $p['shipping_fee'],
            };
            $lines[$id] = ['product_id' => $id, 'name' => $p['name'], 'mode' => $mode, 'selectable' => $method === 2,
                'fee' => $fee, 'shared' => $type === 0];
            if ($type === 0) $default[$mode][$id] = $group['subtotal'];
        }
        foreach ($default as $mode => $amounts) {
            $fee = (int) $settings['free_minimum'] > 0 && array_sum($amounts) >= (int) $settings['free_minimum'] ? 0 : (int) $settings['fee'];
            $lines[array_key_first($amounts)]['fee'] = $fee;
        }
        $sums = ['prepaid' => 0, 'cod' => 0, 'lines' => array_values($lines)];
        foreach ($lines as $line) $sums[$line['mode']] += $line['fee'];
        return $sums;
    }
}
