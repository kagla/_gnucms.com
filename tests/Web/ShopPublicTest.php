<?php

declare(strict_types=1);

namespace GnuCms\Tests\Web;

use GnuCms\App;
use GnuCms\Shop\Service;
use GnuCms\Tests\Shop\ImagesTest;
use GnuCms\Tests\Support\WebTestCase;
use GnuCms\Web\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ShopPublicTest extends WebTestCase
{
    private App $app;
    private Service $shop;
    private string $root;

    private function setupShop(array $config): void
    {
        session_name(GNUCMS_ID . '_session');
        session_start(); $_SESSION = []; session_write_close();
        $this->root = sys_get_temp_dir() . '/gnucms-yc-web-' . bin2hex(random_bytes(8));
        $config['prefix'] = 'yw' . bin2hex(random_bytes(4)) . '_';
        $this->app = $this->makeApp($config, ['storage' => ['dir' => $this->root], 'uploads' => ['dir' => $this->root . '/uploads'],
            'app' => ['url' => 'https://shop.example.test']]);
        $this->shop = new Service($this->app);
    }

    private function seed(): array
    {
        $top = $this->shop->categories->get($this->shop->categories->save(['parent_id' => '', 'name' => '의류', 'active' => '1', 'list_columns' => '1', 'list_rows' => '1', 'image_width' => '200', 'image_height' => '0', 'head_html' => '<p>분류 안내</p>']));
        $child = $this->shop->categories->get($this->shop->categories->save(['parent_id' => (string) $top['id'], 'name' => '셔츠', 'active' => '1', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']));
        $ids = [];
        foreach ([['A', '파란 셔츠', '300', '1'], ['B', '빨간 셔츠', '100', '1'], ['C', '숨은 셔츠', '200', '0']] as [$code, $name, $price, $active]) {
            $ids[$code] = $this->shop->products->save(['code' => $code, 'name' => $name, 'category_id' => (string) $child['id'], 'price' => $price, 'list_price' => '500', 'stock' => $code === 'B' ? '0' : '3',
                'active' => $active, 'is_hit' => '1', 'summary' => '요약 ' . $code, 'description' => '<p>설명 ' . $code . '</p>', 'info_group' => 'wear', 'sort_order' => $code === 'A' ? '1' : '2',
                'option_group' => $code === 'A' ? [1 => '색상'] : [], 'options' => $code === 'A' ? [['value1' => '빨강', 'price' => '100', 'stock' => '2']] : [],
                'relations' => $code === 'B' ? (string) $ids['A'] : ''], $code === 'A' ? [ImagesTest::png(300, 300)] : []);
        }
        $this->app->db()->update('yc_categories', ['legacy_code' => '10'], 'id = :id', ['id' => (int) $top['id']]);
        return ['top' => $top, 'child' => $child, 'ids' => $ids];
    }

    /** 쇼핑몰은 모듈이 아니라 코어다: 확장 상태 파일 없이도 /shop 과 /admin/shop 이 열리고, 이름 붙은 라우트가 있다. */
    #[DataProvider('connectionProvider')]
    public function testShopRoutesAreCoreRoutes(array $config): void
    {
        $this->setupShop($config);
        self::assertSame(200, $this->get($this->app, '/shop')->getStatusCode());
        self::assertFileDoesNotExist($this->root . '/extensions/enabled.json');
        $parser = \GnuCms\Web\Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '')->getRouteCollector()->getRouteParser();
        self::assertSame('/shop', $parser->urlFor('shop.index'));
        self::assertSame('/admin/shop', $parser->urlFor('admin.shop'));
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop'), '/admin/shop');
    }

    /** 공개를 끄면 상단 탭이 사라지고 /shop 은 준비 중 안내, 관리자와 결제 콜백 경로는 그대로다. */
    #[DataProvider('connectionProvider')]
    public function testHiddenShopShowsTheClosedPageAndNoTab(array $config): void
    {
        $this->setupShop($config);
        self::assertStringContainsString('>쇼핑몰</a>', $this->body($this->get($this->app, '/')));
        $settings = $this->shop->settings->all();
        $settings['visible'] = false;
        $this->saveSettings($settings);
        self::assertStringNotContainsString('>쇼핑몰</a>', $this->body($this->get($this->app, '/')));
        $closed = $this->get($this->app, '/shop');
        self::assertSame(200, $closed->getStatusCode());
        self::assertStringContainsString('쇼핑몰을 준비 중입니다', $this->body($closed));
        self::assertSame('no-store', $closed->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('쇼핑몰을 준비 중입니다', $this->body($this->get($this->app, '/shop/cart')));
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop'), '/admin/shop');
    }

    /** 공개를 꺼도 관리자 화면이 끼워 넣는 상품 이미지는 관리자에게 그대로 나온다. 손님에게는 없는 주소다. */
    #[DataProvider('connectionProvider')]
    public function testHiddenShopStillServesProductImagesToAdmins(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seed();
        $file = $this->shop->products->get($seed['ids']['A'])['images'][0]['filename'];
        $query = ['p' => (string) $seed['ids']['A'], 'f' => $file, 's' => 'list'];
        self::assertSame(200, $this->get($this->app, '/shop/image', $query)->getStatusCode());
        $settings = $this->shop->settings->all();
        $settings['visible'] = false;
        $this->saveSettings($settings);
        self::assertSame(404, $this->get($this->app, '/shop/image', $query)->getStatusCode());

        $adminId = $this->app->users()->create('image-admin@example.test', '', '이미지 관리자', true);
        $this->get($this->app, '/login');
        session_start(); $_SESSION['user_id'] = $adminId; $_SESSION['session_epoch'] = 0; session_write_close();
        $image = $this->get($this->app, '/shop/image', $query);
        self::assertSame(200, $image->getStatusCode());
        self::assertSame('image/png', $image->getHeaderLine('Content-Type'));
        // 상품 편집 화면이 실제로 그 주소를 끼워 넣는다.
        self::assertStringContainsString('/shop/image?p=' . $seed['ids']['A'] . '&amp;f=' . $file,
            $this->body($this->get($this->app, '/admin/shop/products/edit', ['id' => (string) $seed['ids']['A']])));
        // 나머지 화면은 관리자에게도 준비 중이다.
        self::assertStringContainsString('쇼핑몰을 준비 중입니다', $this->body($this->get($this->app, '/shop')));
    }

    /** yc_settings 행이 없으면 만들고, 있으면 덮어쓴다. */
    private function saveSettings(array $settings): void
    {
        $db = $this->app->db();
        $payload = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($db->selectOne('SELECT id FROM ' . $db->table('yc_settings') . " WHERE id = 'settings'") === null) {
            $db->insert('yc_settings', ['id' => 'settings', 'payload' => $payload]);
        } else {
            $db->update('yc_settings', ['payload' => $payload], 'id = :id', ['id' => 'settings']);
        }
    }

    #[DataProvider('connectionProvider')]
    public function testBannerImagesVisibilityAndSubdirectory(array $config): void
    {
        $this->setupShop($config);
        $form = \GnuCms\Tests\Shop\HomeBannerTest::form(['banner_mode' => 'upload', 'banner_button_url' => '/shop/search', 'banner_image_url' => '/shop/type?t=new']);
        $banner = $this->shop->banner->saveSettings($form, ImagesTest::png(80, 80))['banner'];
        $response = $this->get($this->app, '/shop/banner-image', ['f' => $banner['image']]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(404, $this->get($this->app, '/shop/banner-image', ['f' => '../other.png'])->getStatusCode());
        self::assertSame(404, $this->get($this->app, '/shop/banner-image', ['f' => str_repeat('a', 32) . '.png'])->getStatusCode());
        $home = Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '/cms')
            ->handle((new ServerRequestFactory())->createServerRequest('GET', '/cms/shop'));
        self::assertStringContainsString('src="/cms/shop/banner-image?f=' . $banner['image'] . '"', $this->body($home));
        self::assertStringContainsString('href="/cms/shop/search"', $this->body($home));
        self::assertStringContainsString('href="/cms/shop/type?t=new"', $this->body($home));
        $this->shop->banner->saveSettings(['banner_mode' => 'auto'] + $form);
        self::assertSame(404, $this->get($this->app, '/shop/banner-image', ['f' => $banner['image']])->getStatusCode());
        $this->shop->banner->saveSettings(['banner_use' => '0', 'main_new_use' => '0', 'main_discount_use' => '0'] + $form);
        $hidden = $this->body($this->get($this->app, '/shop'));
        self::assertStringNotContainsString('class="yc-hero"', $hidden);
        self::assertStringNotContainsString('class="yc-promo-grid', $hidden);
        self::assertSame(404, $this->get($this->app, '/shop/banner-image', ['f' => $banner['image']])->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testRandomBannerRendersMatchingProductAndUncachedResponse(array $config): void
    {
        $this->setupShop($config);
        $this->seed();
        $this->shop->banner->saveSettings(\GnuCms\Tests\Shop\HomeBannerTest::form(['banner_mode' => 'random']));
        $response = $this->get($this->app, '/shop');
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        // A는 이미지가 있는 유일한 공개 진열 상품이다. B는 이미지 없음, C는 비공개다.
        self::assertStringContainsString('class="yc-hero-product" href="/shop/item?id=A"', $this->body($response));
        self::assertStringContainsString('class="yc-button yc-button-dark" href="/shop/item?id=A"', $this->body($response));
        self::assertStringNotContainsString('숨은 셔츠', $this->body($response));
    }

    #[DataProvider('connectionProvider')]
    public function testNoAliasPaths(array $config): void
    {
        $this->setupShop($config);
        self::assertSame(404, $this->get($this->app, '/shop/image', ['p' => '1', 'f' => 'x.png', 's' => 'list'])->getStatusCode());
        $this->assertLoginRedirect($this->get($this->app, '/admin/shop'), '/admin/shop');
        self::assertSame(404, $this->get($this->app, '/shop/admin')->getStatusCode());
    }

    #[DataProvider('connectionProvider')]
    public function testMainListTypeAndSearchPages(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seed();
        $home = $this->body($this->get($this->app, '/shop'));
        self::assertStringContainsString('히트상품', $home);
        self::assertStringContainsString('파란 셔츠', $home);
        self::assertStringNotContainsString('숨은 셔츠', $home);
        self::assertStringContainsString('href="/shop/item?id=A"', $home);
        self::assertStringContainsString('href="/shop/c/%EC%9D%98%EB%A5%98"', $home);
        self::assertStringContainsString('youngcart.css', $home);
        self::assertStringContainsString('data-yc-search-menu', $home);
        self::assertStringNotContainsString('class="yc-brand"', $home);
        $list = $this->body($this->get($this->app, '/shop/c/의류'));
        self::assertStringContainsString('<p>분류 안내</p>', $list);
        self::assertStringContainsString('href="/shop/c/%EC%85%94%EC%B8%A0"', $list);
        self::assertStringContainsString('300원', $list);
        self::assertStringContainsString('<del', $list);
        self::assertStringContainsString('page=2', $list);
        self::assertStringNotContainsString('빨간 셔츠', $list);
        $page2 = $this->body($this->get($this->app, '/shop/c/의류', ['page' => '2']));
        self::assertStringContainsString('빨간 셔츠', $page2);
        self::assertStringContainsString('SOLD OUT', $page2);
        self::assertStringContainsString('빨간 셔츠', $this->body($this->get($this->app, '/shop/c/의류', ['sort' => 'price', 'dir' => 'asc'])));
        self::assertStringContainsString('빨간 셔츠', $this->body($this->get($this->app, '/shop/c/의류', ['sortdir' => 'price_asc'])));
        self::assertStringNotContainsString('빨간 셔츠', $this->body($this->get($this->app, '/shop/c/의류', ['sortdir' => '_'])));
        self::assertSame(404, $this->get($this->app, '/shop/c/없는-분류')->getStatusCode());
        // 옛 주소는 새 주소로 넘긴다: 코드도, 슬러그도.
        foreach (['10', '의류'] as $ca) {
            $moved = $this->get($this->app, '/shop/list', ['ca' => $ca, 'sort' => 'price', 'dir' => 'asc']);
            self::assertSame(301, $moved->getStatusCode());
            self::assertSame('/shop/c/%EC%9D%98%EB%A5%98?sort=price&dir=asc', $moved->getHeaderLine('Location'));
        }
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
        $this->setupShop($config);
        $seed = $this->seed();
        $response = $this->get($this->app, '/shop/item', ['id' => 'A']);
        $item = $this->body($response);
        self::assertStringContainsString('파란 셔츠', $item);
        self::assertStringContainsString('<p>설명 A</p>', $item);
        self::assertStringContainsString('data-yc-options', $item);
        self::assertStringContainsString('&quot;빨강&quot;', $item);
        self::assertStringContainsString('상품페이지 참고', $item);
        self::assertStringContainsString('제품 소재', $item);
        self::assertStringNotContainsString('yc-product-edit', $item);
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
        $adminItem = $this->body($this->get($this->app, '/shop/item', ['id' => 'A']));
        self::assertStringContainsString('yc-product-edit', $adminItem);
        self::assertStringContainsString('href="/admin/shop/products/edit?id=' . $seed['ids']['A'] . '"', $adminItem);
        $subdirectoryItem = Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '/cms')
            ->handle((new ServerRequestFactory())->createServerRequest('GET', '/cms/shop/item?id=A'));
        self::assertStringContainsString('href="/cms/admin/shop/products/edit?id=' . $seed['ids']['A'] . '"', $this->body($subdirectoryItem));
        $preview = $this->body($this->get($this->app, '/shop/item', ['id' => 'C']));
        self::assertStringContainsString('숨은 셔츠', $preview);
        self::assertStringContainsString('미리보기', $preview);
        self::assertStringNotContainsString('yc-manage-link', $preview);
        self::assertStringContainsString('/admin/shop/products/edit?id=' . $seed['ids']['C'], $preview);
        $memberId = $this->app->users()->create('member@example.test', '', '일반회원');
        session_start(); $_SESSION['user_id'] = $memberId; $_SESSION['session_epoch'] = 0; session_write_close();
        $memberItem = $this->body($this->get($this->app, '/shop/item', ['id' => 'A']));
        self::assertStringNotContainsString('yc-product-edit', $memberItem);
    }

    #[DataProvider('connectionProvider')]
    public function testCategoryManagementShortcutAndSubdirectoryLinks(array $config): void
    {
        $this->setupShop($config);
        $seed = $this->seed();
        $response = Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '/cms')->handle((new ServerRequestFactory())->createServerRequest('GET', '/cms/shop/c/의류'));
        $body = $this->body($response);
        self::assertStringContainsString('href="/cms/shop/item?id=A"', $body);
        self::assertStringContainsString('/cms/shop/image?p=', $body);
        self::assertStringContainsString('action="/cms/shop/search"', $body);
        self::assertStringNotContainsString('yc-category-edit', $body);

        $adminId = $this->app->users()->create('category-admin@example.test', '', '분류 관리자', true);
        session_start(); $_SESSION['user_id'] = $adminId; $_SESSION['session_epoch'] = 0; session_write_close();
        foreach ([$seed['top'], $seed['child']] as $category) {
            $list = $this->body($this->get($this->app, '/shop/c/' . rawurlencode($category['slug'])));
            self::assertStringContainsString('yc-category-edit', $list);
            self::assertStringContainsString('href="/admin/shop/categories/edit?id=' . $category['id'] . '"', $list);
            self::assertSame(200, $this->get($this->app, '/admin/shop/categories/edit', ['id' => (string) $category['id']])->getStatusCode());
        }
        $subdirectoryList = Kernel::create($this->app, dirname(__DIR__, 2) . '/templates', '/cms')
            ->handle((new ServerRequestFactory())->createServerRequest('GET', '/cms/shop/c/셔츠'));
        self::assertStringContainsString('href="/cms/admin/shop/categories/edit?id=' . $seed['child']['id'] . '"', $this->body($subdirectoryList));

        $memberId = $this->app->users()->create('category-member@example.test', '', '일반회원');
        session_start(); $_SESSION['user_id'] = $memberId; $_SESSION['session_epoch'] = 0; session_write_close();
        $memberList = $this->body($this->get($this->app, '/shop/c/' . rawurlencode($seed['top']['slug'])));
        self::assertStringNotContainsString('yc-category-edit', $memberList);
    }
}
