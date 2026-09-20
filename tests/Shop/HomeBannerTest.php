<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use GnuCms\Shop\HomeBanner;
use GnuCms\Shop\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Slim\Psr7\UploadedFile;

final class HomeBannerTest extends ShopTestCase
{
    /** 기존 테마의 설정 폼: 배너 필드는 없다. */
    public static function form(array $overrides = []): array
    {
        $flat = [];
        foreach (Settings::defaults()['main'] as $type => $block) foreach ($block as $key => $value) $flat['main_' . $type . '_' . $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        foreach (['category', 'type', 'search', 'related', 'detail'] as $section) foreach (Settings::defaults()[$section] as $key => $value) $flat[$section . '_' . $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        return $overrides + $flat;
    }

    #[DataProvider('connectionProvider')]
    public function testDefaultsRoundTripAndLegacyFormPreservation(array $config): void
    {
        $this->setupShop($config);
        self::assertSame(HomeBanner::defaults(), $this->shop->settings->all()['banner']);
        $saved = $this->shop->banner->saveSettings(self::form(['banner_use' => '1', 'banner_eyebrow' => '가을', 'banner_title' => "새로운 계절\n새로운 상품",
            'banner_description' => '<script>alert(1)</script>', 'banner_button_label' => '신상품', 'banner_button_url' => '/shop/type?t=new', 'banner_mode' => 'auto']));
        self::assertSame("새로운 계절\n새로운 상품", $saved['banner']['title']);
        self::assertSame($saved['banner'], $this->shop->banner->saveSettings(self::form())['banner']);
        self::assertFalse($this->shop->banner->saveSettings(self::form(['banner_use' => '0']))['banner']['use']);
        $this->shop->store->db->update('yc_settings', ['payload' => '{"main":{"hit":{"columns":2}}}'], 'id = :id', ['id' => 'settings']);
        self::assertSame(HomeBanner::defaults(), $this->shop->settings->all()['banner']);
        self::assertSame(2, $this->shop->settings->all()['main']['hit']['columns']);
    }

    #[DataProvider('connectionProvider')]
    public function testInvalidInputsNeverChangeSavedSettings(array $config): void
    {
        $this->setupShop($config);
        $before = $this->shop->settings->all();
        $invalid = [['banner_mode', 'wrong'], ['banner_mode', []], ['banner_use', []], ['banner_title', []], ['banner_title', str_repeat('가', 201)],
            ['banner_product_id', 'not-an-id'], ['banner_description', "bad\0value"]];
        foreach (['javascript:alert(1)', 'data:text/html,hello', '//evil.test', '/\\evil.test', '/%2fevil.test', '/%5cevil.test', "/x\ny", 'https://good.test/%0aevil', 'relative/path'] as $url) {
            $invalid[] = ['banner_button_url', $url]; $invalid[] = ['banner_image_url', $url];
        }
        foreach ($invalid as [$key, $value]) {
            try { $this->shop->banner->saveSettings(self::form([$key => $value])); self::fail($key . ' must be rejected'); }
            catch (DomainError $e) { self::assertSame(422, $e->status()); self::assertArrayHasKey($key, $e->details()); }
        }
        foreach ([['banner_mode' => 'upload'], ['banner_mode' => 'product', 'banner_product_id' => '9999'], ['banner_title' => '']] as $input) {
            try { $this->shop->banner->saveSettings(self::form($input)); self::fail('Incomplete banner accepted'); }
            catch (DomainError $e) { self::assertSame(422, $e->status()); }
        }
        self::assertSame($before, $this->shop->settings->all());
    }

    #[DataProvider('connectionProvider')]
    public function testUploadReplacementRollbackAndRemoval(array $config): void
    {
        $this->setupShop($config);
        $saved = $this->shop->banner->saveSettings(self::form(['banner_mode' => 'upload']), ImagesTest::png(100, 100));
        $file = $saved['banner']['image'];
        $dir = $this->shop->images->directory(HomeBanner::IMAGE_OWNER);
        self::assertFileExists($dir . '/' . $file);
        self::assertSame($file, $this->shop->banner->saveSettings(self::form(['banner_image' => '../../forged.png']))['banner']['image']);
        try {
            $this->shop->banner->saveSettings(self::form(['category_columns' => '99', 'banner_image_delete' => '1']), ImagesTest::png(80, 80));
            self::fail('Invalid settings accepted');
        } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        self::assertSame([$dir . '/' . $file], glob($dir . '/*'));
        self::assertSame($file, $this->shop->settings->all()['banner']['image']);
        $bad = new UploadedFile((new StreamFactory())->createStream('<script>bad</script>'), 'fake.png', 'image/png', 20, UPLOAD_ERR_OK);
        foreach ([$bad, [ImagesTest::png(10, 10)]] as $upload) {
            try { $this->shop->banner->saveSettings(self::form(), $upload); self::fail('Invalid upload accepted'); }
            catch (DomainError $e) { self::assertArrayHasKey('banner_image', $e->details()); }
        }
        self::assertFileExists($dir . '/' . $file);
        $new = $this->shop->banner->saveSettings(self::form(), ImagesTest::png(120, 120))['banner']['image'];
        self::assertNotSame($file, $new);
        self::assertFileDoesNotExist($dir . '/' . $file);
        self::assertFileExists($dir . '/' . $new);
        self::assertSame('image/png', $this->shop->banner->imageResponse($new, false, new Response())->getHeaderLine('Content-Type'));
        $removed = $this->shop->banner->saveSettings(self::form(['banner_image_delete' => '1']));
        self::assertSame('', $removed['banner']['image']);
        self::assertSame('auto', $removed['banner']['mode']);
        self::assertFileDoesNotExist($dir . '/' . $new);
    }

    #[DataProvider('connectionProvider')]
    public function testProductSelectionAndBasePathLinks(array $config): void
    {
        $this->setupShop($config);
        $automatic = $this->product(['is_hit' => '1']);
        $selected = $this->product(['name' => '선택 상품']);
        $filename = $this->shop->images->save((int) $selected['id'], ImagesTest::png(80, 80));
        $this->shop->store->insert('yc_product_images', ['product_id' => $selected['id'], 'filename' => $filename, 'sort_order' => 0]);
        $view = $this->shop->banner->view(HomeBanner::defaults(), $this->shop->listing->main(), [], '/cms/store', '/cms');
        self::assertSame('/cms/store/type?t=hit', $view['button_url']);
        self::assertSame([$selected['id']], array_column($this->shop->banner->choices(), 'id'));
        $input = self::form(['banner_mode' => 'product', 'banner_product_id' => (string) $selected['id']]);
        $banner = $this->shop->banner->saveSettings($input)['banner'];
        $view = $this->shop->banner->view($banner, $this->shop->listing->main(), [], '/cms/store', '/cms');
        self::assertSame('선택 상품', $view['caption']);
        self::assertSame('/cms/store/item?id=' . $selected['code'], $view['image_url']);
        self::assertSame($view['image_url'], $view['button_url']);
        self::assertStringStartsWith('/cms/store/image?', $view['image']);
        foreach (['/sale' => '/cms/sale', '/shop/search?q=wireless%20earbuds' => '/cms/shop/search?q=wireless%20earbuds', 'https://example.test/sale?a=1&b=2' => 'https://example.test/sale?a=1&b=2', '#items' => '#items'] as $url => $expected) {
            $banner = $this->shop->banner->saveSettings(['banner_button_url' => $url] + $input)['banner'];
            self::assertSame($expected, $this->shop->banner->view($banner, [], [], '/cms/store', '/cms')['button_url']);
        }
        $this->shop->store->update('yc_categories', (int) $selected['category_id'], ['active' => 0]);
        self::assertNull($this->shop->banner->view($banner, [], [], '/cms/store', '/cms')['image']);
        self::assertSame([], $this->shop->banner->choices());
        try { $this->shop->banner->saveSettings($input); self::fail('Hidden category accepted'); }
        catch (DomainError $e) { self::assertArrayHasKey('banner_product_id', $e->details()); }
        self::assertSame($banner, $this->shop->banner->saveSettings(self::form())['banner']);
        try { $this->shop->banner->saveSettings(['banner_product_id' => (string) $automatic['id']] + $input); self::fail('Product without image accepted'); }
        catch (DomainError $e) { self::assertArrayHasKey('banner_product_id', $e->details()); }
    }

    #[DataProvider('connectionProvider')]
    public function testRandomBannerUsesOnlyVisibleMainProductsWithImages(array $config): void
    {
        $this->setupShop($config);
        $category = $this->category();
        $this->product(['name' => '이미지 없는 첫 상품', 'category_id' => $category['id'], 'is_hit' => '1', 'sort_order' => '-10']);
        $first = $this->product(['name' => '첫 후보', 'category_id' => $category['id'], 'is_hit' => '1', 'is_new' => '1']);
        $second = $this->product(['name' => '둘째 후보', 'category_id' => $category['id'], 'is_new' => '1']);
        $hidden = $this->product(['name' => '비공개 상품', 'category_id' => $category['id'], 'is_hit' => '1', 'active' => '0']);
        $disabledType = $this->product(['name' => '진열하지 않는 유형', 'category_id' => $category['id'], 'is_popular' => '1']);
        $hiddenCategory = $this->category('비공개 분류', null, ['active' => '0']);
        $hiddenCategoryProduct = $this->product(['name' => '비공개 분류 상품', 'category_id' => $hiddenCategory['id'], 'is_hit' => '1']);
        $expected = [];
        foreach ([$first, $second, $hidden, $disabledType, $hiddenCategoryProduct] as $product) {
            $file = $this->shop->images->save((int) $product['id'], ImagesTest::png(20, 20));
            $this->shop->store->insert('yc_product_images', ['product_id' => $product['id'], 'filename' => $file, 'sort_order' => 0]);
            if (in_array($product['id'], [$first['id'], $second['id']], true)) $expected[$product['name']] = ['code' => $product['code'], 'id' => $product['id'], 'image' => $file];
        }
        $banner = $this->shop->banner->saveSettings(self::form(['banner_mode' => 'random']))['banner'];
        self::assertSame('random', $banner['mode']);
        self::assertSame($banner, $this->shop->banner->saveSettings(self::form())['banner']);
        $blocks = $this->shop->listing->main();
        for ($i = 0; $i < 12; $i++) {
            $view = $this->shop->banner->view($banner, $blocks, [], '/cms/store', '/cms');
            self::assertArrayHasKey($view['caption'], $expected);
            $chosen = $expected[$view['caption']];
            self::assertSame('/cms/store/item?id=' . $chosen['code'], $view['image_url']);
            self::assertSame($view['image_url'], $view['button_url']);
            self::assertSame($view['caption'], $view['alt']);
            self::assertSame('/cms/store/image?p=' . $chosen['id'] . '&f=' . $chosen['image'] . '&s=detail', $view['image']);
        }
        $banner['button_url'] = '/campaign';
        self::assertSame('/cms/campaign', $this->shop->banner->view($banner, $blocks, [], '/cms/store', '/cms')['button_url']);
        $banner['button_url'] = '';
        $this->shop->store->update('yc_products', (int) $second['id'], ['active' => 0]);
        self::assertSame($first['name'], $this->shop->banner->view($banner, $this->shop->listing->main(), [], '/cms/store', '/cms')['caption']);
        $this->shop->store->update('yc_products', (int) $first['id'], ['active' => 0]);
        $empty = $this->shop->banner->view($banner, $this->shop->listing->main(), [], '/cms/store', '/cms');
        self::assertNull($empty['image']);
        self::assertSame('', $empty['image_url']);
        self::assertSame('', $empty['caption']);
        self::assertSame('/cms/store/type?t=hit', $empty['button_url']);
        self::assertSame('/cms/store/list?ca=' . $category['code'], $this->shop->banner->view($banner, [], [$category], '/cms/store', '/cms')['button_url']);
        self::assertSame('', $this->shop->banner->view($banner, [], [], '/cms/store', '/cms')['button_url']);
    }
}
