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

    #[DataProvider('connectionProvider')]
    public function testCategoryScreens(array $config): void
    {
        $this->setupModule($config);
        $this->signIn(true);
        $form = $this->body($this->get($this->app, '/admin/shop/categories/new'));
        self::assertStringContainsString('name="code" value="10"', $form);
        $response = $this->post($this->app, '/admin/shop/categories/new', $this->csrf(['code' => '10', 'name' => '의류', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        self::assertSame(303, $response->getStatusCode());
        $top = $this->shop->categories->byCode('10');
        self::assertSame('/admin/shop/categories/edit?id=' . $top['id'] . '&saved=1', $response->getHeaderLine('Location'));
        $response = $this->post($this->app, '/admin/shop/categories/new', $this->csrf(['code' => '10', 'name' => '중복', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('이미 사용 중인 분류 코드', $this->body($response));
        self::assertStringContainsString('value="중복"', $this->body($response));
        self::assertStringContainsString('name="code" value="1010"', $this->body($this->get($this->app, '/admin/shop/categories/new', ['parent' => '10'])));
        $this->post($this->app, '/admin/shop/categories/new', $this->csrf(['code' => '1010', 'name' => '셔츠', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $list = $this->body($this->get($this->app, '/admin/shop/categories'));
        self::assertStringContainsString('의류', $list); self::assertStringContainsString('셔츠', $list);
        self::assertStringContainsString('name="rows[' . $top['id'] . '][name]"', $list);
        $edit = $this->body($this->get($this->app, '/admin/shop/categories/edit', ['id' => (string) $top['id']]));
        self::assertStringContainsString('value="의류"', $edit); self::assertStringContainsString('apply_children', $edit);
        $response = $this->post($this->app, '/admin/shop/categories/edit', $this->csrf(['id' => (string) $top['id'], 'name' => '의류(수정)', 'active' => '0', 'list_columns' => '4', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0', 'apply_children' => '1']));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(0, (int) $this->shop->categories->byCode('1010')['active']);
        $child = $this->shop->categories->byCode('1010');
        $response = $this->post($this->app, '/admin/shop/categories', $this->csrf(['action' => 'bulk', 'rows' => [$child['id'] => ['name' => '셔츠(일괄)', 'sort_order' => '1', 'active' => '1', 'list_columns' => '2', 'list_rows' => '2', 'image_width' => '100', 'image_height' => '0']]]));
        self::assertSame('/admin/shop/categories?saved=1', $response->getHeaderLine('Location'));
        self::assertSame('셔츠(일괄)', $this->shop->categories->byCode('1010')['name']);
        $response = $this->post($this->app, '/admin/shop/categories', $this->csrf(['action' => 'delete', 'id' => (string) $top['id']]));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('하위 분류가 있어', $this->body($response));
        $response = $this->post($this->app, '/admin/shop/categories', $this->csrf(['action' => 'delete', 'id' => (string) $child['id']]));
        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->shop->categories->byCode('1010'));
        self::assertSame(404, $this->get($this->app, '/admin/shop/categories/edit', ['id' => '999'])->getStatusCode());
        self::assertSame(403, $this->post($this->app, '/admin/shop/categories', ['action' => 'delete', 'id' => (string) $top['id']])->getStatusCode());
    }

    /** 분류명에 홑따옴표와 스크립트가 섞여 있어도 삭제 확인창은 고정 문구여야 한다(인라인 JS 삽입 방지). */
    #[DataProvider('connectionProvider')]
    public function testCategoryListDeleteConfirmIsStatic(array $config): void
    {
        $this->setupModule($config);
        $this->signIn(true);
        $this->post($this->app, '/admin/shop/categories/new', $this->csrf(['code' => '10', 'name' => "잡화'); alert(1);//", 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $list = $this->body($this->get($this->app, '/admin/shop/categories'));
        self::assertStringContainsString("confirm('이 분류를 삭제할까요?')", $list);
        self::assertStringNotContainsString("confirm('잡화", $list);
        self::assertStringContainsString('&#039;', $list);
    }

    private function seedProducts(): array
    {
        $top = $this->shop->categories->get($this->shop->categories->save(['code' => '10', 'name' => '의류', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $other = $this->shop->categories->get($this->shop->categories->save(['code' => '20', 'name' => '잡화', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $a = $this->shop->products->save(['code' => 'A1', 'name' => '파란 셔츠', 'category_id' => (string) $top['id'], 'price' => '300', 'stock' => '3', 'active' => '1', 'stock_alert' => '5',
            'option_group' => [1 => '색상'], 'options' => [['value1' => '빨강', 'price' => '0', 'stock' => '1', 'stock_alert' => '2', 'active' => '1']]], []);
        $b = $this->shop->products->save(['code' => 'B1', 'name' => '가방', 'category_id' => (string) $other['id'], 'price' => '100', 'stock' => '0', 'active' => '1'], []);
        return ['top' => $top, 'other' => $other, 'a' => $a, 'b' => $b];
    }

    #[DataProvider('connectionProvider')]
    public function testProductListBulkCopyTypesStockAndSearch(array $config): void
    {
        $this->setupModule($config);
        $seed = $this->seedProducts();
        $this->signIn(true);
        $list = $this->body($this->get($this->app, '/admin/shop/products'));
        self::assertStringContainsString('파란 셔츠', $list); self::assertStringContainsString('가방', $list);
        self::assertStringContainsString('name="rows[' . $seed['a'] . '][price]"', $list);
        self::assertStringNotContainsString('가방', $this->body($this->get($this->app, '/admin/shop/products', ['q' => '셔츠'])));
        self::assertStringNotContainsString('파란 셔츠', $this->body($this->get($this->app, '/admin/shop/products', ['ca' => '20'])));
        $response = $this->post($this->app, '/admin/shop/products', $this->csrf(['action' => 'bulk', 'rows' => [$seed['b'] => ['category_id' => (string) $seed['top']['id'], 'name' => '가방(일괄)', 'list_price' => '0', 'price' => '150', 'stock' => '2', 'active' => '1', 'sold_out' => '0', 'sort_order' => '1']]]));
        self::assertSame('/admin/shop/products?saved=1', $response->getHeaderLine('Location'));
        self::assertSame('가방(일괄)', $this->shop->products->find($seed['b'])['name']);
        $response = $this->post($this->app, '/admin/shop/products', $this->csrf(['action' => 'bulk', 'rows' => [$seed['b'] => ['category_id' => '999', 'name' => 'x', 'price' => '1']]]));
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('분류를 찾을 수 없습니다', $this->body($response));
        $response = $this->post($this->app, '/admin/shop/products/copy', $this->csrf(['id' => (string) $seed['a'], 'code' => 'A2']));
        $copy = $this->shop->products->byCode('A2');
        self::assertNotNull($copy);
        self::assertSame('/admin/shop/products/edit?id=' . $copy['id'] . '&saved=1', $response->getHeaderLine('Location'));
        self::assertCount(1, $copy['options']['select']);
        self::assertSame(422, $this->post($this->app, '/admin/shop/products/copy', $this->csrf(['id' => (string) $seed['a'], 'code' => 'A2']))->getStatusCode());
        $types = $this->body($this->get($this->app, '/admin/shop/products/types'));
        self::assertStringContainsString('name="rows[' . $seed['a'] . '][is_hit]"', $types);
        $this->post($this->app, '/admin/shop/products/types', $this->csrf(['rows' => [$seed['a'] => ['is_hit' => '1', 'is_new' => '1']]]));
        self::assertSame(1, (int) $this->shop->products->find($seed['a'])['is_new']);
        $stock = $this->body($this->get($this->app, '/admin/shop/products/stock'));
        self::assertStringContainsString('name="rows[' . $seed['a'] . '][stock]"', $stock);
        $this->post($this->app, '/admin/shop/products/stock', $this->csrf(['rows' => [$seed['a'] => ['stock' => '9', 'stock_alert' => '1', 'active' => '1', 'sold_out' => '0', 'restock_notify' => '1']]]));
        self::assertSame(9, (int) $this->shop->products->find($seed['a'])['stock']);
        $optionId = (int) $this->shop->products->get($seed['a'])['options']['select'][0]['id'];
        $optionStock = $this->body($this->get($this->app, '/admin/shop/products/option-stock'));
        self::assertStringContainsString('name="rows[' . $optionId . '][stock]"', $optionStock);
        self::assertStringContainsString('빨강', $optionStock);
        $this->post($this->app, '/admin/shop/products/option-stock', $this->csrf(['rows' => [$optionId => ['stock' => '4', 'stock_alert' => '0', 'active' => '1']]]));
        self::assertSame(4, (int) $this->shop->products->get($seed['a'])['options']['select'][0]['stock']);
        $json = $this->get($this->app, '/admin/shop/products/search', ['q' => '가방', 'exclude' => (string) $seed['a']]);
        self::assertSame('application/json; charset=utf-8', $json->getHeaderLine('Content-Type'));
        $decoded = json_decode($this->body($json), true);
        self::assertSame('B1', $decoded['items'][0]['code']); self::assertSame('의류', $decoded['items'][0]['category_name']);
        self::assertSame([], json_decode($this->body($this->get($this->app, '/admin/shop/products/search', ['q' => '셔츠', 'exclude' => (string) $seed['a'], 'ca' => '20'])), true)['items']);
        $response = $this->post($this->app, '/admin/shop/products', $this->csrf(['action' => 'delete', 'ids' => [(string) $copy['id']]]));
        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->shop->products->find((int) $copy['id']));
        session_start(); $_SESSION = []; session_write_close();
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop/products/search', ['q' => 'x']), '/admin/shop/products/search?q=x');
    }
}
