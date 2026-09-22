<?php

declare(strict_types=1);

namespace GnuCms\Tests\Payment;

use GnuCms\Db\Schema;
use GnuCms\Error\DomainError;
use GnuCms\Payment\{CallbackToken, Journal, ProviderRegistry};
use GnuCms\Shop\Commerce\Payments;
use GnuCms\Tests\Shop\ShopTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProvidersTest extends ShopTestCase
{
    private function addProvider(string $id, array $methods = ['card'], bool $partial = true): void
    {
        $this->app->paymentProviders()->register(new TestProvider($id, $methods, $partial));
        $settings = $this->app->paymentSettings($id);
        $settings->save('test', ['account' => 'account-' . $id, 'token' => bin2hex(random_bytes(16))]);
        $settings->enable('test', true);
    }

    private function selectProvider(string $id): void
    {
        $input = ['payment_provider' => $id, 'payment_environment' => 'test'];
        $settings = $this->shop->settings->all();
        foreach (\GnuCms\Shop\Settings::TYPES as $type) {
            foreach (['columns', 'rows', 'image_width', 'image_height'] as $key) $input['main_' . $type . '_' . $key] = (string) $settings['main'][$type][$key];
        }
        foreach (['category', 'type', 'search', 'related', 'detail'] as $section) {
            foreach ($settings[$section] as $key => $value) $input[$section . '_' . $key] = (string) $value;
        }
        $this->shop->settings->save($input);
    }

    private function place(): array
    {
        $cart = $this->shop->cart->add([], ['product_id' => $this->product()['id'], 'quantity' => 1]);
        $input = ['buyer_name' => '구매자', 'email' => 'buyer@example.test', 'phone' => '01000000000',
            'recipient' => '수령인', 'recipient_phone' => '01000000000', 'postcode' => '04524', 'address' => '테스트 주소',
            'password' => bin2hex(random_bytes(12)), 'agree' => '1', 'payment_method' => 'card'];
        return $this->shop->orders->place($cart, $input, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), null,
            $this->shop->cart->quote($cart, [], true)['fingerprint'], [], $this->shop->payments->forPlacing($input));
    }

    #[DataProvider('connectionProvider')]
    public function testChangingProviderKeepsCheckoutCallbackRefundAndJournalBoundToOriginalProvider(array $config): void
    {
        $this->setupShop($config);
        $this->addProvider('firstpg');
        $this->addProvider('nextpg');
        $this->selectProvider('firstpg');
        $order = $this->place();
        self::assertSame('firstpg', $order['payment_provider']);
        $state = CallbackToken::create($this->app, Payments::gatewayOrder($order));
        $this->selectProvider('nextpg');
        self::assertSame('nextpg', $this->place()['payment_provider']);
        $this->shop->payments->checkout($order, 'web', 'https://example.test/order', 'https://example.test/callback');
        self::assertTrue(CallbackToken::verify($this->app, Payments::gatewayOrder($order), $state));
        self::assertFalse(CallbackToken::verify($this->app, Payments::gatewayOrder(array_replace($order, ['payment_provider' => 'nextpg'])), $state));
        $paid = $this->shop->payments->complete($order, ['proof' => $order['payment_id']]);
        self::assertSame('paid', $paid['status']);
        // 중복 콜백은 같은 승인 요청을 다시 보내지 않는다.
        $this->shop->payments->complete($paid, ['proof' => $order['payment_id']]);
        $first = $this->app->paymentGateway('firstpg');
        self::assertCount(1, array_filter($first->calls, static fn ($call) => $call[0] === 'approve'));
        $refunded = $this->shop->payments->refund($paid, 3000, '부분 환불', str_repeat('a', 32), 'admin');
        self::assertSame(3000, (int) $refunded['refunded_amount']);
        self::assertSame(3000, (int) $this->shop->payments->sync($refunded)['refunded_amount']);
        self::assertSame([], $this->app->paymentGateway('nextpg')->calls);
        self::assertSame([], (new Journal($this->app->paymentSettings('nextpg')))->read($order['payment_id']));
        self::assertSame('confirmed', (new Journal($this->app->paymentSettings('firstpg')))->read($order['payment_id'])['approval']);
        // 새 PG를 정지해도 과거 PG의 주문 조회는 원래 설정을 사용한다.
        $this->app->paymentSettings('nextpg')->enable('test', false);
        self::assertSame([], $this->shop->payments->methods());
        self::assertSame('paid', $this->shop->payments->sync($refunded)['status']);
    }

    #[DataProvider('connectionProvider')]
    public function testProviderSettingsAndJournalAreIsolatedEvenWithIdenticalIds(array $config): void
    {
        $this->setupShop($config);
        $this->addProvider('firstpg');
        $this->addProvider('nextpg');
        $one = $this->app->paymentSettings('firstpg');
        $two = $this->app->paymentSettings('nextpg');
        self::assertSame('account-firstpg', $one->summary('test')['account']);
        self::assertArrayNotHasKey('token', $one->summary('test'));
        $id = bin2hex(random_bytes(16));
        (new Journal($one))->change($id, static fn () => ['approval' => 'pending']);
        (new Journal($two))->change($id, static fn () => ['approval' => 'ready']);
        (new Journal($one))->change($id, static fn () => ['approval' => 'confirmed']);
        self::assertSame('ready', (new Journal($two))->read($id)['approval']);
        try { $this->app->paymentGateway('firstpg')->approvalState(['id' => $id, 'provider' => 'nextpg']); self::fail('다른 PG의 승인 상태 조회 거절'); }
        catch (DomainError $e) { self::assertSame(422, $e->status()); }
        $revision = $one->summary('test')['revision'];
        $one->save('test', ['account' => 'account-firstpg', 'token' => 'rotated-fixture-token']);
        self::assertSame('rotated-fixture-token', $one->credentials($revision)['token']);
        self::assertFalse($one->available('test'));
        self::assertTrue($two->available('test'));
        try { $two->revision($revision); self::fail('다른 PG의 설정 판을 읽으면 안 됩니다.'); }
        catch (DomainError $e) { self::assertSame(503, $e->status()); }
        foreach ($this->app->db()->select('SELECT payload FROM ' . $this->app->db()->table('pay_settings')) as $row) {
            self::assertStringNotContainsString('rotated-fixture-token', $row['payload']);
            self::assertStringNotContainsString('account-firstpg', $row['payload']);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testCapabilitiesAndUnknownProviderFailBeforeCallingGateway(array $config): void
    {
        $this->setupShop($config);
        $this->addProvider('limited', ['card'], false);
        $this->selectProvider('limited');
        $order = $this->place();
        $this->shop->payments->checkout($order, 'web', 'https://example.test/order', 'https://example.test/callback');
        $paid = $this->shop->payments->complete($order, ['proof' => $order['payment_id']]);
        try { $this->shop->payments->refund($paid, 1, '부분', str_repeat('b', 32), 'admin'); self::fail('부분 환불 거절'); }
        catch (DomainError $e) { self::assertSame(422, $e->status()); }
        self::assertCount(0, array_filter($this->app->paymentGateway('limited')->calls, static fn ($call) => $call[0] === 'refund'));
        $full = $this->shop->payments->refund($paid, (int) $paid['total'], '전체', str_repeat('c', 32), 'admin');
        self::assertSame((int) $paid['total'], (int) $full['refunded_amount']);
        $this->addProvider('unsupported', ['virtual_account']);
        $this->selectProvider('unsupported');
        self::assertSame([], $this->shop->payments->methods());
        try { $this->selectProvider('../unknown'); self::fail('알 수 없는 PG 거절'); }
        catch (DomainError $e) { self::assertSame(422, $e->status()); }
        self::assertSame('unsupported', $this->shop->settings->all()['payment']['provider']);
        try { $this->app->paymentGateway('removed'); self::fail('다른 PG로 대체하면 안 됩니다.'); }
        catch (DomainError $e) { self::assertSame(422, $e->status()); }
    }

    #[DataProvider('connectionProvider')]
    public function testLegacyMigrationPreservesEncryptedSettingsPendingRefundsAndCallbackTokens(array $config): void
    {
        $this->setupShop($config);
        $settings = $this->app->paymentSettings();
        $legacyConfig = Fixtures::config();
        $settings->save('test', $legacyConfig);
        $settings->enable('test', true);
        $this->selectProvider('inicis');
        $order = $this->place();
        $token = CallbackToken::create($this->app, Payments::gatewayOrder($order));
        $id = $order['payment_id'];
        $pending = ['approval' => 'pending', 'refunds' => [str_repeat('a', 32) => ['status' => 'pending', 'amount' => 1000]]];
        (new Journal($settings))->change($id, static fn () => $pending);
        $db = $this->app->db();
        foreach (['settings', 'transactions'] as $kind) {
            $db->execute('INSERT INTO ' . $db->table('pay_inicis_' . $kind) . ' (id, payload) SELECT id, payload FROM ' . $db->table('pay_' . $kind));
            $db->execute('DROP TABLE ' . $db->table('pay_' . $kind));
        }
        $db->execute('ALTER TABLE ' . $db->table('yc_orders') . ' DROP COLUMN payment_provider');
        $schema = new Schema($db);
        $schema->migratePayments();
        $schema->migrateShop();
        $migrated = $this->shop->orders->get((int) $order['id']);
        self::assertSame('inicis', $migrated['payment_provider']);
        self::assertTrue(CallbackToken::verify($this->app, Payments::gatewayOrder($migrated), $token));
        self::assertTrue($settings->available('test'));
        self::assertSame($pending, (new Journal($settings))->read($id));
        self::assertSame($order['payment_revision'], $settings->summary('test')['revision']);
        (new Journal($settings))->change($id, static fn () => ['approval' => 'confirmed']);
        $settings->save('test', array_replace(Fixtures::config(), ['merchant_id' => 'NEWSTORE01']));
        $revision = $settings->summary('test')['revision'];
        $schema->migratePayments();
        $schema->migrateShop();
        self::assertSame('confirmed', (new Journal($settings))->read($id)['approval']);
        self::assertSame($revision, $settings->summary('test')['revision']);
        self::assertSame($legacyConfig['merchant_id'], $settings->credentials($order['payment_revision'])['merchant_id']);
    }

    public function testDuplicateProviderRegistrationIsRejected(): void
    {
        $registry = new ProviderRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $registry->register(new TestProvider('inicis'));
    }
}
