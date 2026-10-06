<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReturnsTest extends ShopTestCase
{
    private function order(array $config, bool $complete = true, array $productInput = []): array
    {
        $this->setupShop($config);
        $product = $this->product($productInput);
        $cart = $this->shop->cart->add([], ['product_id' => $product['id'], 'quantity' => 1]);
        $userId = $this->memberId();
        $order = $this->shop->orders->place($cart, ['buyer_name' => '반품 구매자', 'email' => 'returns@example.test', 'phone' => '01000000000',
            'recipient' => '수령인', 'recipient_phone' => '01000000001', 'postcode' => '00000', 'address' => '테스트 주소',
            'address_detail' => '', 'delivery_note' => '', 'agree' => '1'], bin2hex(random_bytes(32)), bin2hex(random_bytes(32)),
            $userId, $this->shop->cart->quote($cart, [], true)['fingerprint'], [], ['method' => 'manual_transfer']);
        $this->shop->orders->confirmDeposit((int) $order['id'], 'test');
        $order = $this->shop->orders->transition((int) $order['id'], 'paid', 'shipped', 'test', ['carrier' => '테스트 택배', 'tracking_number' => 'RETURN-TEST']);
        if ($complete) $order = $this->shop->orders->transition((int) $order['id'], 'shipped', 'completed', 'test');
        return [$order, $product, $userId];
    }

    private function request(array $order, int $userId): array
    {
        return $this->shop->orders->requestReturn((int) $order['id'], 'user:' . $userId,
            ['return_reason' => 'defective'], $userId);
    }

    private function complete(array $order, bool $restock = true): array
    {
        return $this->shop->orders->completeReturn((int) $order['id'], 'test',
            ['return_received' => '1', 'manual_refund_confirmed' => '1', 'return_restock' => $restock ? '1' : '0'], $this->shop->payments);
    }

    private function denied(callable $action, int $status = 422): void
    {
        try { $action(); self::fail('거절해야 합니다.'); }
        catch (DomainError $e) { self::assertSame($status, $e->status()); }
    }

    #[DataProvider('connectionProvider')]
    public function testRequestChecksOwnershipAndDoesNotTouchStockOrRefund(array $config): void
    {
        [$order, $product, $userId] = $this->order($config);
        $this->denied(fn () => $this->request($order, $userId + 1), 404);
        $request = $this->request($order, $userId);
        $count = count($request['history']);
        self::assertSame('returning', $request['status']);
        self::assertSame(0, (int) $request['refunded_amount']);
        self::assertSame(4, (int) $this->shop->products->get((int) $product['id'])['stock']);
        self::assertSame(1, (int) $this->shop->products->get((int) $product['id'])['sold_qty']);
        self::assertCount($count, $this->request($order, $userId)['history']);
    }

    #[DataProvider('connectionProvider')]
    public function testCompletionRefundsAndRestoresOnce(array $config): void
    {
        [$order, $product, $userId] = $this->order($config);
        $request = $this->request($order, $userId);
        $returned = $this->complete($request);
        self::assertSame('returned', $returned['status']);
        self::assertSame((int) $returned['paid_amount'], (int) $returned['refunded_amount']);
        $after = $this->shop->products->get((int) $product['id']);
        self::assertSame(5, (int) $after['stock']);
        self::assertSame(0, (int) $after['sold_qty']);
        self::assertTrue($returned['payment']['return']['restocked']);
        $historyCount = count($returned['history']);
        self::assertCount($historyCount, $this->complete($returned)['history']);
        self::assertSame(5, (int) $this->shop->products->get((int) $product['id'])['stock']);
        $refunds = $this->shop->store->select('SELECT * FROM ' . $this->shop->store->table('yc_order_refunds') . ' WHERE order_id = ?', [(int) $order['id']]);
        self::assertCount(1, $refunds);
        $notices = $this->shop->store->select('SELECT kind FROM ' . $this->shop->store->table('notifications') . ' WHERE order_id = ?', [(int) $order['id']]);
        self::assertSame(1, count(array_filter($notices, static fn (array $n): bool => $n['kind'] === 'order_returned')));
        self::assertSame(1, count(array_filter($notices, static fn (array $n): bool => $n['kind'] === 'order_refunded')));
        $this->denied(fn () => $this->shop->orders->transition((int) $order['id'], 'returned', 'cancelled', 'test'));
    }

    #[DataProvider('connectionProvider')]
    public function testPerishableGoodsCanFinishWithoutRestocking(array $config): void
    {
        [$order, $product, $userId] = $this->order($config);
        $returned = $this->complete($this->request($order, $userId), false);
        self::assertFalse($returned['payment']['return']['restocked']);
        self::assertSame(4, (int) $this->shop->products->get((int) $product['id'])['stock']);
        self::assertSame(0, (int) $this->shop->products->get((int) $product['id'])['sold_qty']);
    }

    #[DataProvider('connectionProvider')]
    public function testReturnDuringShippingDoesNotSubtractUncountedSales(array $config): void
    {
        [$order, $product, $userId] = $this->order($config, false);
        $returned = $this->complete($this->request($order, $userId));
        self::assertSame('returned', $returned['status']);
        self::assertSame(0, (int) $this->shop->products->get((int) $product['id'])['sold_qty']);
        self::assertSame(5, (int) $this->shop->products->get((int) $product['id'])['stock']);
    }

    #[DataProvider('connectionProvider')]
    public function testClosingRequestRestoresOriginalStatusAndAllowsNewRequest(array $config): void
    {
        [$order, $product, $userId] = $this->order($config);
        $request = $this->request($order, $userId);
        $this->denied(fn () => $this->shop->orders->closeReturn((int) $order['id'], 'test', '요청 취소', $this->shop->payments, $userId + 1), 404);
        $closed = $this->shop->orders->closeReturn((int) $order['id'], 'test', '요청 취소', $this->shop->payments, $userId);
        self::assertSame('completed', $closed['status']);
        self::assertSame(1, (int) $this->shop->products->get((int) $product['id'])['sold_qty']);
        $next = $this->request($closed, $userId);
        self::assertNotSame($request['payment']['return']['key'], $next['payment']['return']['key']);
    }

    #[DataProvider('connectionProvider')]
    public function testReceiptAndManualRefundConfirmationAreRequiredBeforeEffects(array $config): void
    {
        [$order, $product, $userId] = $this->order($config);
        $request = $this->request($order, $userId);
        $this->denied(fn () => $this->shop->orders->completeReturn((int) $order['id'], 'test', [], $this->shop->payments));
        $this->denied(fn () => $this->shop->orders->completeReturn((int) $order['id'], 'test', ['return_received' => '1'], $this->shop->payments));
        self::assertSame(0, (int) $this->shop->orders->get((int) $order['id'])['refunded_amount']);
        self::assertSame(4, (int) $this->shop->products->get((int) $product['id'])['stock']);
        $this->shop->store->update('yc_products', (int) $product['id'], ['stock' => 1000000]);
        $this->denied(fn () => $this->complete($request));
        self::assertSame(0, (int) $this->shop->orders->get((int) $order['id'])['refunded_amount']);
    }

    #[DataProvider('connectionProvider')]
    public function testStartedRefundCannotCloseReturn(array $config): void
    {
        [$order, $product, $userId] = $this->order($config);
        $request = $this->request($order, $userId);
        $this->shop->payments->refund($request, 1, '부분 처리 검증', 'test-partial-refund', 'test');
        $this->denied(fn () => $this->shop->orders->closeReturn((int) $order['id'], 'test', '요청 취소', $this->shop->payments, $userId));
        $returned = $this->complete($request);
        self::assertSame((int) $returned['paid_amount'], (int) $returned['refunded_amount']);
    }
}
