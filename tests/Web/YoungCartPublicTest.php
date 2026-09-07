<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Extension\Catalog;
use GnuCms\Extension\Manager;
use GnuCms\Extension\StateStore;
use GnuCms\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__, 2) . '/modules/youngcart/autoload.php';

final class YoungCartPublicTest extends WebTestCase
{
    private App $app;
    private string $root;

    private function setupModule(array $config): void
    {
        session_name(GNUCMS_ID . '_session');
        session_start(); $_SESSION = []; session_write_close();
        $this->root = sys_get_temp_dir() . '/gnucms-yc-web-' . bin2hex(random_bytes(8));
        $config['prefix'] = 'yw' . bin2hex(random_bytes(4)) . '_';
        $this->app = $this->makeApp($config, ['storage' => ['dir' => $this->root], 'uploads' => ['dir' => $this->root . '/uploads'],
            'app' => ['url' => 'https://shop.example.test']]);
        $manager = new Manager(new Catalog(dirname(__DIR__, 2)), new StateStore($this->root . '/extensions'));
        $manager->setEnabledMany(['modules/youngcart' => true]);
    }

    #[DataProvider('connectionProvider')]
    public function testNotInstalledShowsPreparingPageAndNoAliasPaths(array $config): void
    {
        $this->setupModule($config);
        $response = $this->get($this->app, '/shop');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('쇼핑몰을 준비 중입니다', $this->body($response));
        self::assertStringContainsString('쇼핑몰을 준비 중입니다', $this->body($this->get($this->app, '/shop/list', ['ca' => '10'])));
        self::assertSame(404, $this->get($this->app, '/modules/youngcart')->getStatusCode());
        self::assertSame(404, $this->get($this->app, '/modules/youngcart/')->getStatusCode());
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop'), '/admin/shop');
        self::assertSame(404, $this->get($this->app, '/shop/admin')->getStatusCode());
    }
}
