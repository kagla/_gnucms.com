<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProductsTest extends ShopTestCase
{
    private function fullInput(int $category, array $overrides = []): array
    {
        return $overrides + ['code' => 'SHIRT-01', 'name' => '<b>여름 셔츠</b>', 'category_id' => (string) $category, 'maker' => '메이커', 'origin' => '한국', 'brand' => '브랜드', 'model' => 'M-1',
            'summary' => '<p>요약</p><script>x</script>', 'description' => '<h2>설명</h2><p>본문 내용</p>', 'list_price' => '15000', 'price' => '10000',
            'point_type' => '1', 'point' => '5', 'supply_point' => '100', 'tax_free' => '0', 'seller_email' => 'seller@example.test', 'active' => '1', 'no_coupon' => '0',
            'sold_out' => '0', 'stock' => '3', 'stock_alert' => '1', 'restock_notify' => '1', 'buy_min' => '1', 'buy_max' => '5', 'phone_inquiry' => '0',
            'shipping_type' => '2', 'shipping_method' => '0', 'shipping_fee' => '3000', 'shipping_free_minimum' => '50000', 'shipping_per_qty' => '0',
            'head_html' => '<p>위</p>', 'tail_html' => '<p>아래</p>', 'info_group' => 'wear', 'info' => [0 => '면 100%'], 'memo' => '메모', 'is_hit' => '1', 'is_new' => '1', 'sort_order' => '2',
            'extra_label' => [1 => '라벨'], 'extra_value' => [1 => '값'],
            'option_group' => [1 => '색상', 2 => '크기'], 'options' => [
                ['value1' => '빨강', 'value2' => 'S', 'price' => '0', 'stock' => '2', 'stock_alert' => '1', 'active' => '1'],
                ['value1' => '빨강', 'value2' => 'M', 'price' => '500', 'stock' => '0', 'stock_alert' => '1', 'active' => '1']],
            'extras' => [['value1' => '포장', 'value2' => '선물포장', 'price' => '2000', 'stock' => '10', 'stock_alert' => '0', 'active' => '1']]];
    }

    #[DataProvider('connectionProvider')]
    public function testSaveStoresEverythingAndGuardsEdits(array $config): void
    {
        $this->setupShop($config);
        $top = $this->category('의류'); $child = $this->category('셔츠', '10'); $other = $this->category('잡화');
        $related = $this->product(['category_id' => (string) $other['id'], 'code' => 'REL-1', 'name' => '관련상품']);
        $id = $this->shop->products->save($this->fullInput((int) $child['id'], ['category2_id' => (string) $other['id'], 'relations' => $related['id'] . ',']), []);
        $product = $this->shop->products->get($id);
        self::assertSame('SHIRT-01', $product['code']); self::assertSame('여름 셔츠', $product['name']); self::assertSame('여름-셔츠', $product['slug']);
        self::assertSame('<p>요약</p>', $product['summary']); self::assertSame('설명 본문 내용', $product['description_text']);
        self::assertSame((int) $child['id'], (int) $product['category_id']);
        self::assertSame([1 => (int) $child['id'], 2 => (int) $other['id']], array_map(static fn ($c) => (int) $c['id'], $product['categories']));
        self::assertSame(['색상', '크기'], $product['options']['select_groups']); self::assertCount(2, $product['options']['select']); self::assertCount(1, $product['options']['extra']);
        self::assertSame([(int) $related['id']], array_map(static fn ($r) => (int) $r['id'], $product['relations']));
        self::assertSame('면 100%', $product['info'][0]); self::assertSame('상품페이지 참고', $product['info'][1]);
        self::assertSame('라벨', $product['extra'][0]['label']);
        self::assertSame(1, (int) $product['is_hit']); self::assertSame(0, (int) $product['is_popular']);
        self::assertFalse($product['sold_out_computed']);
        self::assertSame(3, (int) $this->shop->store->selectOne('SELECT SUM(delta) AS d FROM ' . $this->shop->store->table('yc_stock_log') . ' WHERE product_id = ? AND option_id IS NULL', [$id])['d']);
        self::assertSame($id, (int) $this->shop->products->byCode('SHIRT-01')['id']);
        self::assertSame($id, (int) $this->shop->products->bySlug('여름-셔츠')['id']);
        $second = $this->shop->products->save($this->fullInput((int) $child['id'], ['code' => 'SHIRT-02']), []);
        self::assertSame('여름-셔츠-2', $this->shop->products->get($second)['slug']);
        $edit = $this->fullInput((int) $child['id'], ['code' => 'IGNORED', 'version' => (string) $product['version'], 'name' => '가을 셔츠', 'stock' => '1',
            'options' => [['value1' => '빨강', 'value2' => 'S', 'price' => '100', 'stock' => '9', 'stock_alert' => '1', 'active' => '1']], 'extras' => []]);
        self::assertSame($id, $this->shop->products->save($edit, [], $id));
        $product = $this->shop->products->get($id);
        self::assertSame('SHIRT-01', $product['code']); self::assertSame('가을-셔츠', $product['slug']); self::assertSame(1, (int) $product['stock']);
        self::assertCount(1, $product['options']['select']); self::assertSame(9, (int) $product['options']['select'][0]['stock']); self::assertSame([], $product['options']['extra']);
        self::assertSame(1, (int) $product['version']);
        try { $this->shop->products->save($edit, [], $id); self::fail('오래된 version은 거절해야 한다'); } catch (DomainError $e) { self::assertArrayHasKey('version', $e->details()); }
        self::assertSame('가을 셔츠', $this->shop->products->get($id)['name']);
    }

    #[DataProvider('connectionProvider')]
    public function testValidationErrors(array $config): void
    {
        $this->setupShop($config);
        $category = $this->category();
        $cases = [
            'code' => ['code' => 'bad code'], 'category_id' => ['category_id' => '999'], 'price' => ['price' => '-1'], 'point' => ['point_type' => '1', 'point' => '100'],
            'shipping_free_minimum' => ['shipping_type' => '2', 'shipping_free_minimum' => '0'], 'shipping_per_qty' => ['shipping_type' => '4', 'shipping_per_qty' => '0'],
            'buy_max' => ['buy_min' => '5', 'buy_max' => '2'], 'seller_email' => ['seller_email' => 'not-mail'], 'relations' => ['relations' => '999'],
            'name' => ['name' => ''], 'category2_id' => ['category2_id' => (string) $category['id']], 'info_group' => ['info_group' => 'nope'],
        ];
        foreach ($cases as $field => $override) {
            try {
                $this->shop->products->save($this->fullInput((int) $category['id'], $override + ['options' => [], 'extras' => [], 'option_group' => []]), []);
                self::fail($field . ' 오류를 거절해야 한다');
            } catch (DomainError $e) {
                self::assertSame(422, $e->status(), $field);
                self::assertArrayHasKey($field, $e->details(), $field . ': ' . json_encode($e->details(), JSON_UNESCAPED_UNICODE));
            }
        }
        $this->shop->products->save($this->fullInput((int) $category['id'], ['options' => [], 'extras' => [], 'option_group' => []]), []);
        try { $this->shop->products->save($this->fullInput((int) $category['id'], ['options' => [], 'extras' => [], 'option_group' => []]), []); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('code', $e->details()); }
        self::assertSame(0, (int) $this->shop->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->shop->store->table('yc_products') . " WHERE code = 'bad code'")['c']);
    }

    #[DataProvider('connectionProvider')]
    public function testImagesCopyDeleteBulkTypesStockAndApply(array $config): void
    {
        $this->setupShop($config);
        $category = $this->category(); $other = $this->category('잡화');
        $base = $this->fullInput((int) $category['id'], ['options' => [], 'extras' => [], 'option_group' => []]);
        $id = $this->shop->products->save($base, [ImagesTest::png(300, 300), ImagesTest::png(400, 200)]);
        $product = $this->shop->products->get($id);
        self::assertCount(2, $product['images']);
        [$first, $second] = $product['images'];
        $this->shop->products->save($base + ['version' => (string) $product['version'], 'image_order' => $second['id'] . ',' . $first['id']], [], $id);
        self::assertSame([(int) $second['id'], (int) $first['id']], array_map(static fn ($i) => (int) $i['id'], $this->shop->products->get($id)['images']));
        $this->shop->products->save($base + ['version' => '1', 'image_delete' => [(string) $second['id']]], [ImagesTest::png(100, 100)], $id);
        $images = $this->shop->products->get($id)['images'];
        self::assertSame([(int) $first['id']], [(int) $images[0]['id']]); self::assertCount(2, $images);
        self::assertFileDoesNotExist($this->shop->images->directory($id) . '/' . $second['filename']);
        try { $this->shop->products->save($base + ['version' => '2'], array_fill(0, 9, ImagesTest::png(50, 50)), $id); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('images', $e->details()); }
        self::assertCount(2, $this->shop->products->get($id)['images']);
        self::assertCount(2, glob($this->shop->images->directory($id) . '/*'));

        $copyId = $this->shop->products->copy($id, 'COPY-1', 'tester');
        $copy = $this->shop->products->get($copyId);
        self::assertSame('COPY-1', $copy['code']); self::assertSame(0, (int) $copy['hit']); self::assertCount(2, $copy['images']);
        self::assertNotSame($images[0]['filename'], $copy['images'][0]['filename']);
        self::assertSame((int) $category['id'], (int) $copy['categories'][1]['id']);
        try { $this->shop->products->copy($id, 'COPY-1', 'tester'); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('code', $e->details()); }

        $this->shop->products->bulk([$copyId => ['original_stock' => (string) $this->shop->products->get($copyId)['stock'], 'category_id' => (string) $other['id'], 'name' => '일괄', 'list_price' => '0', 'price' => '900', 'stock' => '7', 'active' => '0', 'sold_out' => '1', 'sort_order' => '9']], 'tester');
        $copy = $this->shop->products->get($copyId);
        self::assertSame('일괄', $copy['name']); self::assertSame((int) $other['id'], (int) $copy['categories'][1]['id']); self::assertSame(7, (int) $copy['stock']);
        self::assertSame(4, (int) $this->shop->store->selectOne('SELECT delta FROM ' . $this->shop->store->table('yc_stock_log') . ' WHERE product_id = ? ORDER BY id DESC LIMIT 1', [$copyId])['delta']);
        $this->shop->products->setTypes([$copyId => ['is_hit' => '0', 'is_popular' => '1']]);
        $copy = $this->shop->products->get($copyId);
        self::assertSame(0, (int) $copy['is_hit']); self::assertSame(1, (int) $copy['is_popular']);
        $this->shop->products->updateStock([$copyId => ['original_stock' => (string) $this->shop->products->get($copyId)['stock'], 'stock' => '0', 'stock_alert' => '2', 'active' => '1', 'sold_out' => '0', 'restock_notify' => '0']], 'tester');
        self::assertSame(0, (int) $this->shop->products->get($copyId)['stock']);
        try {
            $this->shop->products->updateStock([$copyId => ['original_stock' => (string) $this->shop->products->get($copyId)['stock'], 'stock' => '-1', 'stock_alert' => '0', 'active' => '1', 'sold_out' => '0', 'restock_notify' => '0'], $id => ['original_stock' => (string) $this->shop->products->get($id)['stock'], 'stock' => '99', 'stock_alert' => '0', 'active' => '1', 'sold_out' => '0', 'restock_notify' => '0']], 'tester');
            self::fail();
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
            self::assertArrayHasKey('row_' . $copyId, $e->details());
        }
        self::assertSame(0, (int) $this->shop->products->find($copyId)['stock']);
        self::assertNotSame(99, (int) $this->shop->products->find($id)['stock']);
        $list = $this->shop->products->stockList('', 1, 20);
        self::assertSame($copyId, (int) $list['items'][0]['id']);
        self::assertSame(1, count($this->shop->products->lowStock()['products']));

        $this->shop->products->save($base + ['version' => '2', 'is_discount' => '1', 'apply_scope' => 'category', 'apply_fields' => ['types']], [], $id);
        self::assertSame(0, (int) $this->shop->products->get($copyId)['is_discount']);
        $this->shop->products->save($base + ['version' => '3', 'is_discount' => '1', 'apply_scope' => 'all', 'apply_fields' => ['types', 'shipping']], [], $id);
        $copy = $this->shop->products->get($copyId);
        self::assertSame(1, (int) $copy['is_discount']); self::assertSame(2, (int) $copy['shipping_type']); self::assertSame('일괄', $copy['name']);

        $stats = $this->shop->products->stats();
        self::assertSame(['products' => 2, 'active' => 2, 'sold_out' => 0, 'categories' => 2], $stats);
        self::assertSame([$copyId], array_map(static fn ($r) => (int) $r['id'], $this->shop->products->search('일괄', '', $id)));
        $this->shop->products->delete($copyId);
        self::assertNull($this->shop->products->find($copyId));
        self::assertDirectoryDoesNotExist($this->shop->images->directory($copyId));
        self::assertSame(0, (int) $this->shop->store->selectOne('SELECT COUNT(*) AS c FROM ' . $this->shop->store->table('yc_product_categories') . ' WHERE product_id = ?', [$copyId])['c']);
        $this->shop->products->bulkDelete([$id]);
        self::assertSame(0, $this->shop->products->stats()['products']);
    }

    #[DataProvider('connectionProvider')]
    public function testAdminListFiltersAndSorts(array $config): void
    {
        $this->setupShop($config);
        $top = $this->category('의류'); $child = $this->category('셔츠', '10'); $other = $this->category('잡화');
        $a = $this->product(['category_id' => (string) $child['id'], 'code' => 'A1', 'name' => '파란 셔츠', 'price' => '300', 'maker' => '메이커A']);
        $b = $this->product(['category_id' => (string) $other['id'], 'code' => 'B1', 'name' => '가방', 'price' => '100']);
        $c = $this->product(['category_id' => (string) $top['id'], 'code' => 'C1', 'name' => '빨간 셔츠', 'price' => '200']);
        $all = $this->shop->products->list([], 1);
        self::assertSame(['C1', 'B1', 'A1'], array_column($all['items'], 'code'));
        self::assertSame('셔츠', $all['items'][2]['category_name']);
        self::assertSame(['C1', 'A1'], array_column($this->shop->products->list(['q' => '셔츠'], 1)['items'], 'code'));
        self::assertSame(['A1'], array_column($this->shop->products->list(['q' => '메이커A', 'field' => 'maker'], 1)['items'], 'code'));
        self::assertSame(['C1', 'A1'], array_column($this->shop->products->list(['ca' => '10'], 1)['items'], 'code'));
        self::assertSame(['B1', 'C1', 'A1'], array_column($this->shop->products->list(['sort' => 'price', 'dir' => 'asc'], 1)['items'], 'code'));
        self::assertSame(['C1', 'B1', 'A1'], array_column($this->shop->products->list(['sort' => 'nope', 'dir' => 'sideways'], 1)['items'], 'code'));
        $page = $this->shop->products->list([], 2, 2);
        self::assertSame(['A1'], array_column($page['items'], 'code')); self::assertSame(2, $page['total_pages']); self::assertSame(3, $page['total']);
    }

    /** 상세 설명의 편집기 사진은 products/<id> 폴더에 둔다. 첫 저장 전 tmp 폴더의 사진은 저장하면서 옮기고, 본문에서 빠지면 지우며, 상품을 지우면 폴더째 없앤다. */
    #[DataProvider('connectionProvider')]
    public function testDescriptionImagesLiveInTheProductFolder(array $config): void
    {
        $this->setupShop($config);
        $category = $this->category();
        $tmp = 'tmp/' . str_repeat('ab', 16);
        $tmpDir = $this->root . '/editor/' . $tmp;
        mkdir($tmpDir, 0700, true);
        $used = str_repeat('1', 32) . '.png';
        file_put_contents($tmpDir . '/' . $used, 'x');
        $id = $this->shop->products->save($this->fullInput((int) $category['id'], ['image_key' => $tmp, 'description' => '<p><img src="/media/editor/' . $tmp . '/' . $used . '" alt=""></p>']), []);
        $dir = $this->root . '/editor/products/' . $id;
        self::assertStringContainsString('/media/editor/products/' . $id . '/' . $used, $this->shop->products->get($id)['description']);
        self::assertFileExists($dir . '/' . $used); self::assertDirectoryDoesNotExist($tmpDir);
        $version = (string) $this->shop->products->get($id)['version'];
        $this->shop->products->save($this->fullInput((int) $category['id'], ['image_key' => 'tmp/' . str_repeat('cd', 16), 'description' => '<p>없음</p>', 'version' => $version]), [], $id);
        self::assertDirectoryDoesNotExist($dir);
        mkdir($dir, 0700, true); file_put_contents($dir . '/' . $used, 'x');
        $this->shop->products->delete($id);
        self::assertDirectoryDoesNotExist($dir);
    }
}
