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
use GnuCms\Web\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once dirname(__DIR__, 2) . '/modules/youngcart/autoload.php';

final class YoungCartPublicTest extends WebTestCase
{
    private App $app;
    private Service $shop;
    private string $root;

    private function setupModule(array $config, bool $install = true): void
    {
        session_name(GNUCMS_ID . '_session');
        session_start(); $_SESSION = []; session_write_close();
        $this->root = sys_get_temp_dir() . '/gnucms-yc-web-' . bin2hex(random_bytes(8));
        $config['prefix'] = 'yw' . bin2hex(random_bytes(4)) . '_';
        $this->app = $this->makeApp($config, ['storage' => ['dir' => $this->root], 'uploads' => ['dir' => $this->root . '/uploads'],
            'app' => ['url' => 'https://shop.example.test']]);
        $manager = new Manager(new Catalog(dirname(__DIR__, 2)), new StateStore($this->root . '/extensions'));
        $manager->setEnabledMany(['modules/youngcart' => true]);
        $this->shop = new Service($this->app);
        if ($install) $this->shop->install();
    }

    private function seed(): array
    {
        $top = $this->shop->categories->get($this->shop->categories->save(['code' => '10', 'name' => '의류', 'active' => '1', 'list_columns' => '1', 'list_rows' => '1', 'image_width' => '200', 'image_height' => '0', 'head_html' => '<p>분류 안내</p>']));
        $child = $this->shop->categories->get($this->shop->categories->save(['code' => '1010', 'name' => '셔츠', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $ids = [];
        foreach ([['A', '파란 셔츠', '300', '1'], ['B', '빨간 셔츠', '100', '1'], ['C', '숨은 셔츠', '200', '0']] as [$code, $name, $price, $active]) {
            $ids[$code] = $this->shop->products->save(['code' => $code, 'name' => $name, 'category_id' => (string) $child['id'], 'price' => $price, 'list_price' => '500', 'stock' => $code === 'B' ? '0' : '3',
                'active' => $active, 'is_hit' => '1', 'summary' => '요약 ' . $code, 'description' => '<p>설명 ' . $code . '</p>', 'info_group' => 'wear', 'sort_order' => $code === 'A' ? '1' : '2',
                'option_group' => $code === 'A' ? [1 => '색상'] : [], 'options' => $code === 'A' ? [['value1' => '빨강', 'price' => '100', 'stock' => '2']] : [],
                'relations' => $code === 'B' ? (string) $ids['A'] : ''], $code === 'A' ? [ImagesTest::png(300, 300)] : []);
        }
        return ['top' => $top, 'child' => $child, 'ids' => $ids];
    }

    #[DataProvider('connectionProvider')]
    public function testNotInstalledShowsPreparingPageAndNoAliasPaths(array $config): void
    {
        $this->setupModule($config, false);
        $response = $this->get($this->app, '/shop');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('쇼핑몰을 준비 중입니다', $this->body($response));
        self::assertStringContainsString('쇼핑몰을 준비 중입니다', $this->body($this->get($this->app, '/shop/list', ['ca' => '10'])));
        self::assertSame(404, $this->get($this->app, '/shop/image', ['p' => '1', 'f' => 'x.png', 's' => 'list'])->getStatusCode());
        self::assertSame(404, $this->get($this->app, '/modules/youngcart')->getStatusCode());
        self::assertSame(404, $this->get($this->app, '/modules/youngcart/')->getStatusCode());
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop'), '/admin/shop');
        self::assertSame(404, $this->get($this->app, '/shop/admin')->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testMainListTypeAndSearchPages(array $config): void
    {
        $this->setupModule($config);
        $seed = $this->seed();
        $home = $this->body($this->get($this->app, '/shop'));
        self::assertStringContainsString('히트상품', $home);
        self::assertStringContainsString('파란 셔츠', $home);
        self::assertStringNotContainsString('숨은 셔츠', $home);
        self::assertStringContainsString('href="/shop/item?id=A"', $home);
        self::assertStringContainsString('href="/shop/list?ca=10"', $home);
        self::assertStringContainsString('youngcart.css', $home);
        self::assertStringContainsString('data-yc-search-menu', $home);
        self::assertStringNotContainsString('class="yc-brand"', $home);
        $list = $this->body($this->get($this->app, '/shop/list', ['ca' => '10']));
        self::assertStringContainsString('<p>분류 안내</p>', $list);
        self::assertStringContainsString('href="/shop/list?ca=1010"', $list);
        self::assertStringContainsString('300원', $list);
        self::assertStringContainsString('<del', $list);
        self::assertStringContainsString('page=2', $list);
        self::assertStringNotContainsString('빨간 셔츠', $list);
        $page2 = $this->body($this->get($this->app, '/shop/list', ['ca' => '10', 'page' => '2']));
        self::assertStringContainsString('빨간 셔츠', $page2);
        self::assertStringContainsString('SOLD OUT', $page2);
        self::assertStringContainsString('빨간 셔츠', $this->body($this->get($this->app, '/shop/list', ['ca' => '10', 'sort' => 'price', 'dir' => 'asc'])));
        self::assertStringContainsString('빨간 셔츠', $this->body($this->get($this->app, '/shop/list', ['ca' => '10', 'sortdir' => 'price_asc'])));
        self::assertStringNotContainsString('빨간 셔츠', $this->body($this->get($this->app, '/shop/list', ['ca' => '10', 'sortdir' => '_'])));
        self::assertSame(404, $this->get($this->app, '/shop/list', ['ca' => '99'])->getStatusCode());
        self::assertSame(404, $this->get($this->app, '/shop/list')->getStatusCode());
        $type = $this->body($this->get($this->app, '/shop/type', ['t' => 'hit']));
        self::assertStringContainsString('히트상품', $type);
        self::assertStringContainsString('빨간 셔츠', $type);
        self::assertSame(404, $this->get($this->app, '/shop/type', ['t' => 'nope'])->getStatusCode());
        $search = $this->body($this->get($this->app, '/shop/search', ['q' => '셔츠']));
        self::assertStringContainsString('2개', $search);
        self::assertStringContainsString('의류', $search);
        self::assertStringContainsString('value="셔츠"', $search);
        self::assertStringContainsString('검색어를 입력', $this->body($this->get($this->app, '/shop/search')));
        $noHit = $this->body($this->get($this->app, '/shop/search', ['q' => '<script>']));
        self::assertStringContainsString('검색 결과가 없습니다', $noHit);
        self::assertStringContainsString('value="&lt;script&gt;"', $noHit);
        self::assertStringNotContainsString('q=<script>', $noHit);
    }

    #[DataProvider('connectionProvider')]
    public function testItemPageImageAndAdminPreview(array $config): void
    {
        $this->setupModule($config);
        $seed = $this->seed();
        $response = $this->get($this->app, '/shop/item', ['id' => 'A']);
        $item = $this->body($response);
        self::assertStringContainsString('파란 셔츠', $item);
        self::assertStringContainsString('<p>설명 A</p>', $item);
        self::assertStringContainsString('data-yc-options', $item);
        self::assertStringContainsString('&quot;빨강&quot;', $item);
        self::assertStringContainsString('상품페이지 참고', $item);
        self::assertStringContainsString('제품 소재', $item);
        self::assertStringContainsString('/shop/image?p=' . $seed['ids']['A'] . '&amp;f=', $item);
        self::assertStringContainsString('href="/shop/item?id=B"', $item);
        self::assertStringContainsString('yc_hit_' . $seed['ids']['A'] . '=1', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(1, (int) $this->shop->products->find($seed['ids']['A'])['hit']);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/shop/item?id=A')->withCookieParams(['yc_hit_' . $seed['ids']['A'] => '1']);
        Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '')->handle($request);
        self::assertSame(1, (int) $this->shop->products->find($seed['ids']['A'])['hit']);
        self::assertStringContainsString('파란 셔츠', $this->body($this->get($this->app, '/shop/item', ['slug' => '파란-셔츠'])));
        $b = $this->body($this->get($this->app, '/shop/item', ['id' => 'B']));
        self::assertStringContainsString('품절', $b);
        self::assertStringContainsString('관련상품', $b);
        self::assertSame(404, $this->get($this->app, '/shop/item', ['id' => 'C'])->getStatusCode());
        self::assertSame(404, $this->get($this->app, '/shop/item', ['id' => 'ZZ'])->getStatusCode());
        $file = $this->shop->products->get($seed['ids']['A'])['images'][0]['filename'];
        $image = $this->get($this->app, '/shop/image', ['p' => (string) $seed['ids']['A'], 'f' => $file, 's' => 'list']);
        self::assertSame('image/png', $image->getHeaderLine('Content-Type'));
        self::assertSame(404, $this->get($this->app, '/shop/image', ['p' => (string) $seed['ids']['A'], 'f' => 'nope.png', 's' => 'list'])->getStatusCode());
        $adminId = $this->app->users()->create('owner@example.test', '', '관리자', true);
        $this->get($this->app, '/login');
        session_start(); $_SESSION['user_id'] = $adminId; $_SESSION['session_epoch'] = 0; session_write_close();
        $preview = $this->body($this->get($this->app, '/shop/item', ['id' => 'C']));
        self::assertStringContainsString('숨은 셔츠', $preview);
        self::assertStringContainsString('미리보기', $preview);
        self::assertStringNotContainsString('yc-manage-link', $preview);
        self::assertStringContainsString('/admin/shop/products/edit?id=' . $seed['ids']['C'], $preview);
    }

    #[DataProvider('connectionProvider')]
    public function testSubdirectoryInstallationLinks(array $config): void
    {
        $this->setupModule($config);
        $this->seed();
        $response = Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '/cms')->handle((new ServerRequestFactory())->createServerRequest('GET', '/cms/shop/list?ca=10'));
        $body = $this->body($response);
        self::assertStringContainsString('href="/cms/shop/item?id=A"', $body);
        self::assertStringContainsString('/cms/shop/image?p=', $body);
        self::assertStringContainsString('action="/cms/shop/search"', $body);
    }
}
