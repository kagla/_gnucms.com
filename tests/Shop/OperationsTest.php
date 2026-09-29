<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Support\Clock;
use PHPUnit\Framework\Attributes\DataProvider;

final class OperationsTest extends ShopTestCase
{
    private function paidOrder(array $config): array
    {
        $this->setupShop($config);
        $product = $this->product(['price' => '10000']);
        $cart = $this->shop->cart->add([], ['product_id' => $product['id'], 'quantity' => 1]);
        $quote = $this->shop->cart->quote($cart, [], true);
        $order = $this->shop->orders->place($cart, [
            'buyer_name' => '테스트 구매자', 'email' => 'buyer@example.test', 'phone' => '010-0000-0000',
            'recipient' => '받는 사람', 'recipient_phone' => '010-1111-2222', 'postcode' => '04524',
            'address' => '서울시 중구', 'address_detail' => '101호', 'delivery_note' => '문 앞',
            'password' => bin2hex(random_bytes(12)), 'agree' => '1',
        ], bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), $this->memberId(), $quote['fingerprint']);

        return $this->shop->orders->confirmDeposit((int) $order['id'], 'admin');
    }

    #[DataProvider('connectionProvider')]
    public function testShipmentCsvChangesEveryValidOrderAtomically(array $config): void
    {
        $order = $this->paidOrder($config);
        $id = (int) $order['id'];
        $this->shop->orders->transition($id, 'paid', 'confirmed', 'admin');

        $exported = $this->shop->fulfillment->export([$id]);
        self::assertStringStartsWith("\xEF\xBB\xBF", $exported);
        self::assertStringContainsString((string) $order['number'], $exported);

        $csv = "주문번호,택배사,운송장번호\n{$order['number']},CJ대한통운,1234-5678\n";
        self::assertSame(1, $this->shop->fulfillment->importAndShip($csv, 'admin'));
        $shipped = $this->shop->orders->get($id);
        self::assertSame('shipped', $shipped['status']);
        self::assertSame('CJ대한통운', $shipped['carrier']);
        self::assertSame('1234-5678', $shipped['tracking_number']);
    }

    #[DataProvider('connectionProvider')]
    public function testRefundLedgerFeedsSalesReport(array $config): void
    {
        $order = $this->paidOrder($config);
        $id = (int) $order['id'];
        $this->shop->orders->recordRefund($id, 3000, 'admin', '부분 환불', 'refund-001', [
            'id' => 'cancel-001', 'at' => Clock::timestamp(),
            'tax' => ['taxable_amount' => 3000, 'supply_amount' => 2727, 'vat_amount' => 273, 'tax_free_amount' => 0],
        ]);
        $this->shop->orders->recordRefund($id, 3000, 'admin', '중복 요청', 'refund-001');

        $ledger = $this->shop->store->select('SELECT * FROM ' . $this->shop->store->table('yc_order_refunds'));
        self::assertCount(1, $ledger);
        self::assertSame([3000, 2727, 273, 0], array_map('intval', [
            $ledger[0]['taxable_amount'], $ledger[0]['supply_amount'], $ledger[0]['vat_amount'], $ledger[0]['tax_free_amount'],
        ]));

        $today = date('Y-m-d');
        $report = $this->shop->reports->sales($this->shop->reports->range($today, $today));
        self::assertSame(3000, $report['summary']['refunded_amount']);
        self::assertSame((int) $order['paid_amount'] - 3000, $report['summary']['net_amount']);
    }

    #[DataProvider('connectionProvider')]
    public function testSettlementImportIsIdempotentAndReconcilesTheOrder(array $config): void
    {
        $order = $this->paidOrder($config);
        $id = (int) $order['id'];
        $this->shop->store->update('yc_orders', $id, [
            'payment_provider' => 'toss', 'payment_environment' => 'test', 'payment_id' => 'payment-001',
        ]);
        $today = date('Y-m-d');
        $csv = implode(',', ['provider', 'environment', 'merchant_id', 'payment_id', 'transaction_key', 'kind',
            'amount', 'fee_supply', 'fee_vat', 'payout_amount', 'sold_date', 'payout_date']) . "\n"
            . implode(',', ['toss', 'test', '', 'payment-001', 'tx-001', 'payment', (string) $order['paid_amount'],
                '100', '10', (string) ((int) $order['paid_amount'] - 110), $today, $today]) . "\n";

        self::assertSame(['inserted' => 1, 'skipped' => 0], $this->shop->settlements->import($csv));
        self::assertSame(['inserted' => 0, 'skipped' => 1], $this->shop->settlements->import($csv));
        $result = $this->shop->settlements->reconcile($this->shop->reports->range($today, $today));
        self::assertSame(1, $result['counts']['matched']);
        self::assertSame('matched', $result['items'][0]['status']);
    }
}
