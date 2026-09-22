<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Error\DomainError;
use GnuCms\Shop\Catalog\Options;
use GnuCms\Shop\Catalog\Stock;
use PHPUnit\Framework\Attributes\DataProvider;

final class OptionsTest extends ShopTestCase
{
    public function testCombineDraftAndValidation(): void
    {
        self::assertSame([['빨강', 'S', ''], ['빨강', 'M', ''], ['파랑', 'S', ''], ['파랑', 'M', '']], Options::combine([['빨강', '파랑'], ['S', 'M']]));
        self::assertSame([['빨강', '', '']], Options::combine([['빨강']]));
        self::assertSame([], Options::combine([]));
        $draft = Options::draft(['option_group' => [1 => '색상', 2 => '크기'], 'option_values' => [1 => '빨강,파랑', 2 => 'S']],
            [['value1' => '빨강', 'value2' => 'S', 'value3' => '', 'price' => 500, 'stock' => 3, 'stock_alert' => 1, 'active' => 0]]);
        self::assertSame(['색상', '크기'], $draft['groups']);
        self::assertSame(['value1' => '빨강', 'value2' => 'S', 'value3' => '', 'price' => 500, 'stock' => 3, 'stock_alert' => 1, 'active' => 0], $draft['rows'][0]);
        self::assertSame(['value1' => '파랑', 'value2' => 'S', 'value3' => '', 'price' => 0, 'stock' => 9999, 'stock_alert' => 100, 'active' => 1], $draft['rows'][1]);
        try { Options::draft(['option_group' => [1 => '색상'], 'option_values' => [1 => implode(',', range(1, 21))]], []); self::fail(); } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        try { Options::draft(['option_group' => [1 => '', 2 => '크기'], 'option_values' => [1 => '', 2 => 'S']], []); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('option_group', $e->details()); }
        $rows = Options::rows(['0' => ['value1' => '빨강', 'value2' => 'S', 'price' => '500', 'stock' => '3', 'stock_alert' => '1', 'active' => '1'], 'x' => 'junk']);
        self::assertCount(1, $rows);
        $normalized = (new Options($this->stubStore()))->validate(10000, ['색상', '크기'], $rows, [['value1' => '포장', 'value2' => '선물포장', 'price' => '2000', 'stock' => '10', 'stock_alert' => '0', 'active' => '1']]);
        self::assertSame(['색상', '크기'], $normalized['select_groups']);
        self::assertSame(['포장'], $normalized['extra_groups']);
        self::assertSame(2000, $normalized['extra'][0]['price']);
        try { (new Options($this->stubStore()))->validate(10000, ['색상'], [['value1' => '빨강', 'price' => '-10001']], []); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('options', $e->details()); }
        try { (new Options($this->stubStore()))->validate(10000, ['색상'], [['value1' => '빨강'], ['value1' => '빨강']], []); self::fail(); } catch (DomainError $e) { self::assertStringContainsString('중복', $e->details()['options']); }
        try { (new Options($this->stubStore()))->validate(10000, [], [], [['value1' => '포장', 'value2' => '선물', 'price' => '-1']]); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('extras', $e->details()); }
        try { (new Options($this->stubStore()))->validate(10000, ['색상'], [['value1' => '<b>']], []); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('options', $e->details()); }
        $many = array_map(static fn (int $i): array => ['value1' => 'v' . $i], range(1, 21));
        try { (new Options($this->stubStore()))->validate(10000, ['색상'], $many, []); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('options', $e->details()); }
        $normalized = (new Options($this->stubStore()))->validate(10000, ['색상'], Options::rows([['value1' => '빨강']]), Options::rows([['value1' => '포장', 'value2' => '리본']]));
        self::assertSame(1, $normalized['select'][0]['active']);
        self::assertSame(1, $normalized['extra'][0]['active']);
    }

    private function stubStore(): \GnuCms\Shop\Store
    {
        return new \GnuCms\Shop\Store(\GnuCms\Db\Connection::create(['dsn' => 'sqlite::memory:', 'username' => null, 'password' => null]));
    }

    #[DataProvider('connectionProvider')]
    public function testReplaceKeepsStockSoldOutAndPageJson(array $config): void
    {
        $this->setupShop($config);
        $productId = $this->shop->store->insert('yc_products', $this->minimalProductRow('P1'));
        $options = $this->shop->options;
        $first = $options->validate(10000, ['색상'], [['value1' => '빨강', 'price' => '0', 'stock' => '5', 'stock_alert' => '1', 'active' => '1'], ['value1' => '파랑', 'price' => '1000', 'stock' => '0', 'stock_alert' => '1', 'active' => '1']],
            [['value1' => '포장', 'value2' => '선물포장', 'price' => '2000', 'stock' => '10', 'stock_alert' => '0', 'active' => '1']]);
        $this->shop->store->transaction(fn () => $options->replace($productId, $first, 'tester'));
        $loaded = $options->load($productId);
        self::assertSame(['색상'], $loaded['select_groups']); self::assertCount(2, $loaded['select']); self::assertSame(['포장'], $loaded['extra_groups']);
        $redId = (int) $loaded['select'][0]['id'];
        self::assertSame(5, (int) $this->shop->store->selectOne('SELECT SUM(delta) AS d FROM ' . $this->shop->store->table('yc_stock_log') . ' WHERE option_id = ?', [$redId])['d']);
        $second = $options->validate(10000, ['색상'], [['value1' => '빨강', 'price' => '100', 'stock' => '7', 'stock_alert' => '1', 'active' => '1'], ['value1' => '노랑', 'price' => '0', 'stock' => '1', 'stock_alert' => '0', 'active' => '1']], []);
        $this->shop->store->transaction(fn () => $options->replace($productId, $second, 'tester'));
        $loaded = $options->load($productId);
        self::assertSame($redId, (int) $loaded['select'][0]['id']);
        self::assertSame(100, (int) $loaded['select'][0]['price']); self::assertSame(7, (int) $loaded['select'][0]['stock']);
        self::assertSame(['빨강', '노랑'], array_column($loaded['select'], 'value1'));
        self::assertSame([], $loaded['extra']);
        self::assertSame(7, (int) $this->shop->store->selectOne('SELECT SUM(delta) AS d FROM ' . $this->shop->store->table('yc_stock_log') . ' WHERE option_id = ?', [$redId])['d']);
        $product = ['sold_out' => 0, 'stock' => 0];
        self::assertFalse(Stock::soldOut($product, $loaded['select']));
        self::assertTrue(Stock::soldOut(['sold_out' => 1, 'stock' => 9], $loaded['select']));
        self::assertTrue(Stock::soldOut($product, [['stock' => 0, 'active' => 1], ['stock' => 5, 'active' => 0]]));
        self::assertTrue(Stock::soldOut($product, []));
        self::assertFalse(Stock::soldOut(['sold_out' => 0, 'stock' => 1], []));
        $json = Options::pageJson(['price' => 10000], $loaded);
        self::assertSame(['색상'], $json['select']['groups']);
        self::assertSame(['id' => $redId, 'v' => ['빨강'], 'price' => 100, 'stock' => 7], $json['select']['items'][0]);
        self::assertSame([], $json['extra']['groups']);
        $unavailable = $loaded;
        $unavailable['select'][0]['value1'] = '0';
        $unavailable['select'][0]['active'] = 0;
        $json = Options::pageJson(['price' => 10000], $unavailable);
        self::assertSame(['id' => $redId, 'v' => ['0'], 'price' => 100, 'stock' => 0], $json['select']['items'][0]);
        self::assertSame((int) $loaded['select'][1]['id'], $json['select']['items'][1]['id']);
        $list = $options->stockList('', 1, 20);
        self::assertSame(2, $list['total']);
        $options->updateStock([$redId => ['original_stock' => (string) $this->shop->store->get('yc_options', $redId)['stock'], 'stock' => '2', 'stock_alert' => '3', 'active' => '0']], 'tester');
        self::assertSame(2, (int) $options->load($productId)['select'][0]['stock']);
        self::assertSame(-5, (int) $this->shop->store->selectOne('SELECT delta FROM ' . $this->shop->store->table('yc_stock_log') . ' WHERE option_id = ? ORDER BY id DESC LIMIT 1', [$redId])['delta']);
        try { $options->updateStock([$redId => ['original_stock' => (string) $this->shop->store->get('yc_options', $redId)['stock'], 'stock' => 'x']], 'tester'); self::fail(); } catch (DomainError $e) { self::assertArrayHasKey('row_' . $redId, $e->details()); }
        self::assertSame(2, (int) $options->load($productId)['select'][0]['stock']);
    }

    private function minimalProductRow(string $code): array
    {
        return ['code' => $code, 'slug' => $code, 'category_id' => 1, 'name' => $code, 'summary' => '', 'description' => '', 'description_text' => '', 'price' => 10000,
            'head_html' => '', 'tail_html' => '', 'info_values' => '', 'memo' => '', 'extra' => '[]', 'created_at' => 1, 'updated_at' => 1];
    }
}
