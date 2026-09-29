<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Db\Schema;
use GnuCms\Tests\Payment\Fixtures;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PaymentSettingsTest extends WebTestCase
{
    private App $app;
    private string $root;

    private function setupApp(array $config): void
    {
        $this->root = sys_get_temp_dir() . '/gnucms-pay-web-' . bin2hex(random_bytes(5));
        $config['prefix'] = 'pw' . bin2hex(random_bytes(4)) . '_';
        $this->app = $this->makeApp($config, ['storage' => ['dir' => $this->root], 'auth' => ['secret' => bin2hex(random_bytes(32))]]);
    }

    private function signIn(bool $admin): void
    {
        $id = $this->app->users()->create(($admin ? 'admin' : 'member') . '@example.com', '', $admin ? '운영자' : '일반회원', $admin);
        $this->get($this->app, '/login');
        session_start();
        $_SESSION['user_id'] = $id;
        $_SESSION['session_epoch'] = 0;
        session_write_close();
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) (new Schema($this->app->db()))->drop();
        if (isset($this->root) && is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    #[DataProvider('connectionProvider')]
    public function testSettingsPageRequiresAdminAndCsrfAndSavesEncryptedMerchantConfiguration(array $config): void
    {
        $this->setupApp($config);
        $this->assertLoginRedirect($this->get($this->app, '/admin/settings/payment'), '/admin/settings/payment');
        $this->signIn(false);
        self::assertSame(403, $this->get($this->app, '/admin/settings/payment')->getStatusCode());
        $this->signIn(true);
        $page = $this->get($this->app, '/admin/settings/payment');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('테스트 환경', $this->body($page));
        self::assertStringNotContainsString('name="merchant_id"', $this->body($page));
        self::assertStringContainsString('href="/admin/settings/payment"', $this->body($page));
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        $merchant = Fixtures::config('inicis');
        self::assertSame(403, $this->post($this->app, '/admin/settings/payment', ['action' => 'save', 'environment' => 'test'] + $merchant)->getStatusCode());
        $saved = $this->post($this->app, '/admin/settings/payment', ['action' => 'save', 'environment' => 'test', 'csrf_token' => $_SESSION['csrf_token']] + $merchant);
        self::assertSame(200, $saved->getStatusCode(), strip_tags($this->body($saved)));
        self::assertStringContainsString('설정을 저장했습니다', $this->body($saved));
        self::assertStringNotContainsString($merchant['api_key'], $this->body($saved));
        self::assertStringContainsString('일반 신용카드 결제에 적용됩니다', $this->body($saved));
        $settings = $this->app->paymentSettings();
        self::assertTrue($settings->summary('test')['configured']);
        self::assertTrue($settings->available('test'));
        $raw = $this->app->db()->selectOne('SELECT payload FROM ' . $this->app->db()->table('pay_settings') . " WHERE id = 'test'")['payload'];
        self::assertStringNotContainsString($merchant['api_key'], $raw);
        self::assertFalse($settings->available('live'));
        $invalid = $this->post($this->app, '/admin/settings/payment', ['action' => 'save', 'environment' => 'live', 'csrf_token' => $_SESSION['csrf_token']] + array_replace($merchant, ['merchant_id' => 'bad']));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('상점 코드를 확인해 주세요', $this->body($invalid));
        self::assertFalse($settings->summary('live')['configured']);
        self::assertSame(422, $this->post($this->app, '/admin/settings/payment', ['action' => 'unknown', 'environment' => 'test', 'csrf_token' => $_SESSION['csrf_token']])->getStatusCode());
    }
    #[DataProvider('connectionProvider')]
    public function testEachProviderUsesItsOwnFieldsSecretsAndExecutionPermit(array $config): void
    {
        $this->setupApp($config);
        $this->app->paymentProviders()->register(new \GnuCms\Tests\Payment\TestProvider());
        $this->signIn(true);
        $page = $this->get($this->app, '/admin/settings/payment', ['provider' => 'testpg', 'environment' => 'test']);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('name="account"', $this->body($page));
        self::assertStringNotContainsString('name="sign_key"', $this->body($page));
        self::assertStringContainsString('name="provider" value="testpg"', $this->body($page));
        self::assertStringContainsString('provider=testpg&amp;environment=live', $this->body($page));
        $token = bin2hex(random_bytes(20));
        $form = ['provider' => 'testpg', 'environment' => 'test', 'csrf_token' => $_SESSION['csrf_token']];
        $saved = $this->post($this->app, '/admin/settings/payment', $form + ['action' => 'save', 'account' => 'fixture-account', 'token' => $token]);
        self::assertSame(200, $saved->getStatusCode(), strip_tags($this->body($saved)));
        self::assertStringNotContainsString($token, $this->body($saved));
        self::assertStringContainsString('value="fixture-account"', $this->body($saved));
        self::assertTrue($this->app->paymentSettings('testpg')->available('test'));
        self::assertFalse($this->app->paymentSettings()->available('test'));
        self::assertSame(422, $this->get($this->app, '/admin/settings/payment', ['provider' => '../unknown'])->getStatusCode());
        self::assertSame(422, $this->post($this->app, '/admin/settings/payment', array_replace($form, ['provider' => 'unknown', 'action' => 'save']))->getStatusCode());
    }

}
