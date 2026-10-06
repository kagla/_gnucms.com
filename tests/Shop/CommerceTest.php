<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Commerce\Orders;
use GnuCms\Shop\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

final class CommerceTest extends ShopTestCase
{
    private function buyer(array $extra = []): array
    {
        return $extra + ['buyer_name' => '테스트 구매자', 'email' => 'buyer@example.test', 'phone' => '010-0000-0000',
            'recipient' => '받는 사람', 'recipient_phone' => '010-0000-0000', 'postcode' => '04524', 'address' => '테스트 배송지',
            'address_detail' => '101호', 'delivery_note' => '', 'password' => bin2hex(random_bytes(12)), 'agree' => '1'];
    }

    private function cart(array $product, int $quantity = 1, array $extra = []): array
    {
        return $this->shop->cart->add([], $extra + ['product_id' => $product['id'], 'quantity' => $quantity]);
    }

    private function place(array $cart, array $extra = [], ?int $user = null): array
    {
        return $this->shop->orders->place($cart, $this->buyer($extra), bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), $user ?? $this->memberId(), $this->shop->cart->quote($cart, [], true)['fingerprint']);
    }

    private function reject(callable $fn, string $message = ''): void
    {
        try { $fn(); self::fail('거절해야 합니다.'); }
        catch (DomainError $e) { self::assertContains($e->status(), [404, 422]); if ($message !== '') self::assertStringContainsString($message, implode(' ', $e->details()) ?: $e->getMessage()); }
    }

    #[DataProvider('connectionProvider')]
    public function testOptionsExtrasAndTamperedPricesAreRecalculated(array $config): void
    {
        $this->setupShop($config);
        $product = $this->product(['option_group' => [1 => '색상'], 'options' => [['value1' => '검정', 'price' => '-1000', 'stock' => '5']],
            'extras' => [['value1' => '포장', 'value2' => '선물상자', 'price' => '2000', 'stock' => '5']]]);
        $select = (int) $product['options']['select'][0]['id']; $extra = (int) $product['options']['extra'][0]['id'];
        $cart = $this->cart($product, 2, ['option_id' => $select, 'extras' => [$extra => '1'], 'price' => '1', 'total' => '1']);
        $quote = $this->shop->cart->quote($cart, [], true);
        self::assertSame(20000, $quote['total']);
        self::assertSame(2, $quote['quantity'], '추가 구성은 상품 수량에 합산하지 않는다.');
        self::assertSame(2, $this->shop->cart->productQuantity($cart));
        $order = $this->place($cart);
        self::assertSame(20000, (int) $order['total']);
        self::assertSame(3, (int) $this->shop->store->get('yc_options', $select)['stock']);
        self::assertSame(4, (int) $this->shop->store->get('yc_options', $extra)['stock']);
        self::assertSame(5, (int) $this->shop->products->get((int) $product['id'])['stock']);
        self::assertSame(2, count($order['items']));
        $cart = $this->shop->cart->update($cart, [$product['id'] . ':' . $select => 0]);
        self::assertSame([], $cart, '본품 삭제 시 추가옵션도 제거');
        $this->reject(fn () => $this->cart($product), '필수 옵션');
        $this->reject(fn () => $this->cart($product, 1, ['option_id' => $extra]), '선택옵션');
        $foreign = $this->product(['option_group' => [1 => '크기'], 'options' => [['value1' => '대형', 'stock' => '5']]]);
        $this->reject(fn () => $this->cart($product, 1, ['option_id' => $foreign['options']['select'][0]['id']]), '선택옵션');
    }

    #[DataProvider('connectionProvider')]
    public function testExtraPriceRemainsVisibleWhenRequestedQuantityExceedsStock(array $config): void
    {
        $this->setupShop($config);
        $product = $this->product(['extras' => [['value2' => '선물 포장', 'price' => '2000', 'stock' => '5']]]);
        $extra = (int) $product['options']['extra'][0]['id'];
        $cart = $this->cart($product, 1, ['extras' => [$extra => 5]]);
        $key = $product['id'] . ':' . $extra;
        $this->shop->store->update('yc_options', $extra, ['stock' => 3]);

        $quote = $this->shop->cart->quote($cart);
        $extraLine = array_values(array_filter($quote['items'], static fn (array $item): bool => $item['kind'] === 'extra'))[0];

        self::assertSame(2000, $extraLine['price']);
        self::assertSame(10000, $extraLine['total']);
        self::assertStringContainsString('재고가 부족합니다', $extraLine['error']);
        $this->reject(fn () => $this->shop->cart->update($cart, [$key => 6]), '재고');
        $reduced = $this->shop->cart->update($cart, [$key => 3]);
        $reducedExtras = array_values(array_filter($this->shop->cart->quote($reduced)['items'], static fn (array $item): bool => $item['kind'] === 'extra'));
        self::assertSame(6000, $reducedExtras[0]['total']);
    }

    #[DataProvider('connectionProvider')]
    public function testMultipleSelectionsValidateTogetherAndReserveEachCombination(array $config): void
    {
        $this->setupShop($config);
        $product = $this->product(['buy_min' => '2', 'buy_max' => '4', 'option_group' => [1 => '색상', 2 => '사이즈'], 'options' => [
            ['value1' => '화이트', 'value2' => 'S', 'price' => '0', 'stock' => '3'],
            ['value1' => '화이트', 'value2' => 'M', 'price' => '1000', 'stock' => '4'],
        ], 'extras' => [['value1' => '포장', 'value2' => '선물상자', 'price' => '2000', 'stock' => '4']]]);
        [$s, $m] = array_map('intval', array_column($product['options']['select'], 'id'));
        $extra = (int) $product['options']['extra'][0]['id'];
        $foreign = $this->product(['option_group' => [1 => '색상'], 'options' => [['value1' => '화이트', 'stock' => '5']]]);
        $foreignId = (int) $foreign['options']['select'][0]['id'];
        foreach ([null, 'invalid', [], [$s => 0], [$s => -1], [$s => '1.5'], [$s => [1]], [0 => 1],
            [$s => 1, $extra => 1], [$s => 1, $foreignId => 1], array_fill(1, 101, 1), [$s => 4], [$s => 2, $m => 3]] as $selections) {
            $this->reject(fn () => $this->shop->cart->add([], ['product_id' => $product['id'], 'selections' => $selections]));
        }
        $input = ['product_id' => $product['id'], 'selections' => [$s => 2, $m => 1], 'extras' => [$extra => 1], 'price' => 1, 'total' => 1];
        $cart = $this->shop->cart->add([], $input);
        self::assertCount(3, $cart);
        self::assertSame(3, $this->shop->cart->productQuantity($cart));
        $withoutS = $this->shop->cart->update($cart, [$product['id'] . ':' . $s => 0]);
        self::assertArrayHasKey($product['id'] . ':' . $extra, $withoutS, '같은 상품의 다른 선택옵션이 남으면 공통 추가 구성도 유지한다.');
        self::assertSame([], $this->shop->cart->update($withoutS, [$product['id'] . ':' . $m => 0]));
        $withoutExtra = $this->shop->cart->update($cart, [$product['id'] . ':' . $extra => 0]);
        self::assertCount(2, $withoutExtra);
        self::assertArrayHasKey($product['id'] . ':' . $s, $withoutExtra);
        self::assertArrayHasKey($product['id'] . ':' . $m, $withoutExtra);
        self::assertSame(33000, $this->shop->cart->quote($cart, [], true)['total']);
        $this->reject(fn () => $this->shop->cart->add($cart, ['product_id' => $product['id'], 'selections' => [$m => 2]]), '최대');
        self::assertSame(1, $cart[$product['id'] . ':' . $m]['quantity']);
        foreach ([['active' => 0, 'stock' => 4], ['active' => 1, 'stock' => 0]] as $state) {
            $this->shop->store->update('yc_options', $m, $state);
            $this->reject(fn () => $this->shop->cart->add([], $input));
        }
        $this->shop->store->update('yc_options', $m, ['stock' => 4]);
        $order = $this->place($cart);
        self::assertSame(['화이트 / S', '화이트 / M', '포장 / 선물상자'], array_column($order['items'], 'option_label'));
        self::assertSame([2, 1, 1], array_map('intval', array_column($order['items'], 'quantity')));
        self::assertSame(33000, (int) $order['total']);
        self::assertSame(1, (int) $this->shop->store->get('yc_options', $s)['stock']);
        self::assertSame(3, (int) $this->shop->store->get('yc_options', $m)['stock']);
        self::assertSame(3, (int) $this->shop->store->get('yc_options', $extra)['stock']);
        $this->shop->orders->transition((int) $order['id'], 'pending', 'cancelled', 'member', ['cancel_reason' => 'change_mind'], true);
        self::assertSame(3, (int) $this->shop->store->get('yc_options', $s)['stock']);
        self::assertSame(4, (int) $this->shop->store->get('yc_options', $m)['stock']);
    }

    #[DataProvider('connectionProvider')]
    public function testOrderSnapshotsIdempotencyOwnershipAndCancellation(array $config): void
    {
        $this->setupShop($config);
        $product = $this->product(); $cart = $this->cart($product, 2);
        $key = bin2hex(random_bytes(32)); $owner = bin2hex(random_bytes(32)); $buyer = $this->buyer();
        $fingerprint = $this->shop->cart->quote($cart, [], true)['fingerprint'];
        $member = $this->memberId();
        $first = $this->shop->orders->place($cart, $buyer, $key, $owner, $member, $fingerprint);
        $again = $this->shop->orders->place([], [], $key, $owner, $member, $fingerprint);
        self::assertSame($first['id'], $again['id']);
        self::assertSame(3, (int) $this->shop->products->get((int) $product['id'])['stock']);
        self::assertSame($member, (int) $first['user_id']);
        $this->shop->store->update('yc_products', (int) $product['id'], ['name' => '변경된 상품명', 'price' => 20000]);
        self::assertSame('기본 상품', $this->shop->orders->get((int) $first['id'])['items'][0]['product_name']);
        self::assertSame(20000, (int) $first['total']);
        $this->reject(fn () => $this->shop->orders->owned($first['number'], $member + 1));
        self::assertSame($first['id'], $this->shop->orders->owned($first['number'], $member)['id']);
        $this->reject(fn () => $this->shop->products->delete((int) $product['id']), '주문 내역');
        $cancelled = $this->shop->orders->transition((int) $first['id'], 'pending', 'cancelled', 'member', ['cancel_reason' => 'change_mind'], true);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(5, (int) $this->shop->products->get((int) $product['id'])['stock']);
        $this->reject(fn () => $this->shop->orders->transition((int) $first['id'], 'pending', 'cancelled', 'member', ['cancel_reason' => 'change_mind'], true), '변경');
        self::assertSame(5, (int) $this->shop->products->get((int) $product['id'])['stock']);
        self::assertSame(2, count($cancelled['history']));
    }

    #[DataProvider('connectionProvider')]
    public function testLatestPriceStockVisibilityAndMinimumPreventCheckout(array $config): void
    {
        $this->setupShop($config);
        $product = $this->product(['buy_min' => '2', 'buy_max' => '3']); $cart = $this->cart($product);
        self::assertNotEmpty($this->shop->cart->quote($cart, [], true)['errors']);
        $this->reject(fn () => $this->place($cart), '최소');
        $this->reject(fn () => $this->cart($product, 4), '최대');
        $cart = $this->cart($product, 2); $quote = $this->shop->cart->quote($cart, [], true);
        $this->shop->store->update('yc_products', (int) $product['id'], ['price' => 11000]);
        $this->reject(fn () => $this->shop->orders->place($cart, $this->buyer(), bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), $this->memberId(), $quote['fingerprint']), '변경');
        self::assertSame(5, (int) $this->shop->products->get((int) $product['id'])['stock']);
        $this->shop->store->update('yc_products', (int) $product['id'], ['stock' => 1]);
        $this->reject(fn () => $this->place($cart), '재고');
        $this->shop->store->update('yc_products', (int) $product['id'], ['stock' => 5, 'active' => 0]);
        $this->reject(fn () => $this->place($cart), '구매할 수 없는');
        $this->shop->store->update('yc_products', (int) $product['id'], ['active' => 1, 'phone_inquiry' => 1]);
        $this->reject(fn () => $this->place($cart), '구매할 수 없는');
        self::assertSame(0, $this->shop->orders->listing(null, '', 1, true)['total']);
    }

    #[DataProvider('connectionProvider')]
    public function testShippingGroupingThresholdsQuantityAndCashOnDelivery(array $config): void
    {
        $this->setupShop($config);
        $settings = $this->shop->settings->all();
        $settings['shipping']['fee'] = 3000; $settings['shipping']['free_minimum'] = 30000;
        $this->shop->store->insert('yc_settings', ['id' => 'settings', 'payload' => json_encode($settings)]);
        $a = $this->product(['shipping_method' => '2']); $b = $this->product();
        $cart = $this->shop->cart->add($this->cart($a), ['product_id' => $b['id'], 'quantity' => 1]);
        self::assertSame(3000, $this->shop->cart->quote($cart)['shipping_fee'], '기본 배송비는 묶음당 한 번');
        $cod = $this->shop->cart->quote($cart, [$a['id'] => 'cod']);
        self::assertSame(3000, $cod['shipping_fee']); self::assertSame(3000, $cod['cod_fee']); self::assertSame(23000, $cod['total']);
        $cart = $this->shop->cart->add($cart, ['product_id' => $a['id'], 'quantity' => 1]);
        self::assertSame(0, $this->shop->cart->quote($cart)['shipping_fee']);
        foreach ([[1, 2, 0], [2, 1, 2500], [2, 2, 0], [3, 2, 2500], [4, 3, 5000]] as [$type, $qty, $fee]) {
            $p = $this->product(['shipping_type' => (string) $type, 'shipping_fee' => '2500', 'shipping_free_minimum' => '20000', 'shipping_per_qty' => '2']);
            self::assertSame($fee, $this->shop->cart->quote($this->cart($p, $qty))['shipping_fee']);
        }
        $this->reject(fn () => $this->shop->cart->quote($cart, [$a['id'] => ['cod']]), '배송비');
    }

    #[DataProvider('connectionProvider')]
    public function testLifecycleAndMemberOrderIsolation(array $config): void
    {
        $this->setupShop($config);
        $member = $this->memberId();
        $p = $this->product(); $order = $this->place($this->cart($p), [], $member); $id = (int) $order['id'];
        self::assertSame($member, (int) $order['user_id']);
        self::assertSame(1, $this->shop->orders->listing($member)['total']); self::assertSame(0, $this->shop->orders->listing($member + 1)['total']);
        $this->reject(fn () => $this->shop->orders->owned($order['number'], $member + 1));
        $this->reject(fn () => $this->shop->orders->transition($id, 'pending', 'completed', 'admin'));
        $this->shop->orders->confirmDeposit($id, 'admin');
        $this->shop->orders->transition($id, 'paid', 'confirmed', 'admin');
        $this->reject(fn () => $this->shop->orders->transition($id, 'confirmed', 'cancelled', 'user:42', [], true));
        $this->reject(fn () => $this->shop->orders->transition($id, 'confirmed', 'shipped', 'admin'));
        $this->shop->orders->transition($id, 'confirmed', 'shipped', 'admin', ['carrier' => '테스트택배', 'tracking_number' => '123456']);
        $this->shop->orders->transition($id, 'shipped', 'completed', 'admin');
        self::assertSame(1, (int) $this->shop->products->get((int) $p['id'])['sold_qty']);
        self::assertSame(5, count($this->shop->orders->get($id)['history']));
        self::assertSame('123456', $this->shop->orders->get($id)['tracking_number']);
    }

    #[DataProvider('connectionProvider')]
    public function testInvalidBuyerDoesNotCreateAnOrder(array $config): void
    {
        $this->setupShop($config);
        $cart = $this->cart($this->product());
        $this->reject(fn () => $this->place($cart, ['email' => 'invalid', 'agree' => '0', 'password' => []]));
        self::assertSame(0, $this->shop->orders->listing(null, '', 1, true)['total']);
        $order = $this->place($cart);
        self::assertSame($order['id'], $this->shop->orders->owned($order['number'], $this->memberId())['id']);
        $this->reject(fn () => $this->shop->orders->owned($order['number'], $this->memberId() + 1));
    }

    #[DataProvider('connectionProvider')]
    public function testPendingOptionCannotBeRemovedAndStaleStockCannotOverwriteReservation(array $config): void
    {
        $this->setupShop($config);
        $p = $this->product(); $cart = $this->cart($p, 2); $this->place($cart);
        $this->reject(fn () => $this->shop->products->updateStock([$p['id'] => 'invalid'], 'admin'), '입력값');
        $this->reject(fn () => $this->shop->products->updateStock([$p['id'] => ['original_stock' => 5, 'stock' => 9, 'active' => 1]], 'admin'), '재고가 변경');
        self::assertSame(3, (int) $this->shop->products->get((int) $p['id'])['stock']);
        $p = $this->product(['option_group' => [1 => '색상'], 'options' => [['value1' => '검정', 'stock' => '5']]]);
        $optionId = (int) $p['options']['select'][0]['id'];
        $cart = $this->cart($p, 2, ['option_id' => $optionId]); $order = $this->place($cart);
        $this->reject(fn () => $this->shop->options->updateStock([$optionId => ['original_stock' => 5, 'stock' => 9, 'active' => 1]], 'admin'), '재고가 변경');
        $empty = $this->shop->options->validate(10000, [], [], []);
        $this->reject(fn () => $this->shop->store->transaction(fn () => $this->shop->options->replace((int) $p['id'], $empty, 'admin')), '처리 중인 주문');
        $this->shop->orders->transition((int) $order['id'], 'pending', 'cancelled', 'admin');
        self::assertSame(5, (int) $this->shop->store->get('yc_options', $optionId)['stock']);
    }

    /** 결제 완료는 상태 흐름에 들어 있되 일반 전이로는 못 간다 — 결제 확인만이 그 자리를 채운다. */
    #[DataProvider('connectionProvider')]
    public function testPaidIsAStatusThatOnlyPaymentConfirmationCanReach(array $config): void
    {
        $this->setupShop($config);
        foreach (['pending', 'paid', 'confirmed', 'shipped', 'completed', 'returning', 'returned', 'cancelled'] as $status) self::assertArrayHasKey($status, Orders::STATUSES);
        self::assertSame(['paid', 'cancelled'], Orders::NEXT['pending']);
        self::assertSame(['shipped', 'confirmed', 'cancelled'], Orders::NEXT['paid']);
        $order = $this->place($this->cart($this->product()));
        self::assertSame([], $order['payment']);
        $this->reject(fn () => $this->shop->orders->transition((int) $order['id'], 'pending', 'paid', 'admin'), '결제 확인');
        $this->reject(fn () => $this->shop->orders->transition((int) $order['id'], 'pending', 'confirmed', 'admin'));
    }

    /** 주문 표가 없던 시절의 설치: 마이그레이션이 표만 새로 만들고 상품은 그대로 둔다. */
    #[DataProvider('connectionProvider')]
    public function testMigrateRestoresMissingOrderTablesAndKeepsTheCatalog(array $config): void
    {
        $this->setupShop($config); $product = $this->product();
        foreach (['yc_orders', 'yc_order_items', 'yc_order_history'] as $table) $this->shop->store->execute('DROP TABLE ' . $this->shop->store->table($table));
        Schema::migrate($this->app->db()); Schema::migrate($this->app->db());
        self::assertSame($product['name'], $this->shop->products->get((int) $product['id'])['name']);
        self::assertSame(1, $this->place($this->cart($product))['items'][0]['quantity']);
    }
}
