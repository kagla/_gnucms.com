<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Extension\Catalog;
use GnuCms\Extension\Manager;
use GnuCms\Extension\StateStore;
use GnuCms\Modules\YoungCart\Service;
use GnuCms\Tests\Support\WebTestCase;
use GnuCms\Tests\YoungCart\ImagesTest;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 2) . '/modules/youngcart/autoload.php';

final class YoungCartAdminTest extends WebTestCase
{
    private App $app;
    private Service $shop;
    private string $root;

    private function setupModule(array $config, bool $install = true): void
    {
        session_name(GNUCMS_ID . '_session');
        session_start(); $_SESSION = []; session_write_close();
        $this->root = sys_get_temp_dir() . '/gnucms-yc-admin-' . bin2hex(random_bytes(8));
        $config['prefix'] = 'ya' . bin2hex(random_bytes(4)) . '_';
        $this->app = $this->makeApp($config, ['storage' => ['dir' => $this->root], 'uploads' => ['dir' => $this->root . '/uploads'], 'app' => ['url' => 'https://shop.example.test']]);
        (new Manager(new Catalog(dirname(__DIR__, 2)), new StateStore($this->root . '/extensions')))->setEnabledMany(['modules/youngcart' => true]);
        $this->shop = new Service($this->app);
        if ($install) $this->shop->install();
    }

    private function signIn(bool $admin): int
    {
        $id = $this->app->users()->create(bin2hex(random_bytes(4)) . '@example.test', '', ($admin ? '운영자' : '회원') . bin2hex(random_bytes(3)), $admin);
        $this->get($this->app, '/login');
        session_start(); $_SESSION['user_id'] = $id; $_SESSION['session_epoch'] = 0; session_write_close();
        return (int) $id;
    }

    private function csrf(array $body = []): array { return $body + ['csrf_token' => $_SESSION['csrf_token']]; }

    #[DataProvider('connectionProvider')]
    public function testGuardsInstallAndSettings(array $config): void
    {
        $this->setupModule($config, false);
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop'), '/admin/shop');
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop/settings'), '/admin/shop/settings');
        $this->assertLoginRedirect($this->post($this->app, '/admin/shop', ['action' => 'install']));
        $this->signIn(false);
        self::assertSame(403, $this->get($this->app, '/admin/shop')->getStatusCode());
        $this->signIn(true);
        $dashboard = $this->body($this->get($this->app, '/admin/shop'));
        self::assertStringContainsString('데이터 설치', $dashboard);
        self::assertStringContainsString('설치되지 않았습니다', $dashboard);
        self::assertSame(403, $this->post($this->app, '/admin/shop', ['action' => 'install'])->getStatusCode());
        self::assertFalse($this->shop->ready());
        $response = $this->post($this->app, '/admin/shop', $this->csrf(['action' => 'install']));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/shop?installed=1', $response->getHeaderLine('Location'));
        self::assertTrue($this->shop->ready());
        $dashboard = $this->body($this->get($this->app, '/admin/shop', ['installed' => '1']));
        self::assertStringContainsString('설치했습니다', $dashboard);
        self::assertStringContainsString('상품 0', $dashboard);
        $settings = $this->body($this->get($this->app, '/admin/shop/settings'));
        self::assertStringContainsString('name="main_hit_use"', $settings);
        self::assertStringContainsString('name="category_columns" value="3"', $settings);
        $response = $this->post($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['category_columns' => '13'])));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('1~12', $this->body($response));
        self::assertSame(3, $this->shop->settings->all()['category']['columns']);
        $response = $this->post($this->app, '/admin/shop/settings', $this->csrf($this->settingsForm(['category_columns' => '4', 'show_tax' => '1'])));
        self::assertSame('/admin/shop/settings?saved=1', $response->getHeaderLine('Location'));
        self::assertSame(4, $this->shop->settings->all()['category']['columns']);
        self::assertTrue($this->shop->settings->all()['show_tax']);
    }

    private function settingsForm(array $overrides): array
    {
        $form = [];
        foreach (['hit', 'new', 'recommend', 'discount', 'popular'] as $type) {
            $form += ['main_' . $type . '_use' => '1', 'main_' . $type . '_columns' => '4', 'main_' . $type . '_rows' => '1', 'main_' . $type . '_image_width' => '200', 'main_' . $type . '_image_height' => '0'];
        }
        foreach (['category', 'type', 'search'] as $section) {
            $form += [$section . '_columns' => '3', $section . '_rows' => '5', $section . '_image_width' => '200', $section . '_image_height' => '0'];
        }
        return $overrides + $form + ['related_use' => '1', 'related_columns' => '4', 'related_image_width' => '100', 'related_image_height' => '0',
            'detail_image_width' => '400', 'detail_image_height' => '0', 'shipping_content' => '', 'exchange_content' => ''];
    }

    #[DataProvider('connectionProvider')]
    public function testNotInstalledAdminPagesRedirectToDashboard(array $config): void
    {
        $this->setupModule($config, false);
        $this->signIn(true);
        $response = $this->get($this->app, '/admin/shop/settings');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/shop?install=1', $response->getHeaderLine('Location'));
    }
}
