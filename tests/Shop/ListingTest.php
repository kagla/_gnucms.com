<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use PHPUnit\Framework\Attributes\DataProvider;

final class ListingTest extends ShopTestCase
{
    #[DataProvider('connectionProvider')]
    public function testCategoryPrefixSlotsVisibilitySortAndPaging(array $config): void
    {
        $this->setupShop($config);
        $top = $this->category('의류'); $child = $this->category('셔츠', (int) $top['id']); $hidden = $this->category('숨김', (int) $top['id'], ['active' => '0']); $other = $this->category('잡화');
        $a = $this->product(['category_id' => (string) $child['id'], 'code' => 'A', 'name' => '가', 'price' => '300', 'sort_order' => '2']);
        $b = $this->product(['category_id' => (string) $other['id'], 'category2_id' => (string) $top['id'], 'code' => 'B', 'name' => '나', 'price' => '100', 'sort_order' => '1']);
        $c = $this->product(['category_id' => (string) $hidden['id'], 'code' => 'C', 'name' => '다', 'price' => '200']);
        $d = $this->product(['category_id' => (string) $child['id'], 'code' => 'D', 'name' => '라', 'price' => '50', 'active' => '0']);
        $e = $this->product(['category_id' => (string) $other['id'], 'code' => 'E', 'name' => '마', 'price' => '400', 'stock' => '0']);
        $list = $this->shop->listing->category($top, '', '', 1);
        self::assertSame(['B', 'A'], array_column($list['items'], 'code'));
        self::assertSame(15, $list['per_page']); self::assertSame(3, $list['columns']); self::assertSame(1, $list['total_pages']);
        self::assertSame(['A', 'B'], array_column($this->shop->listing->category($top, 'price', 'desc', 1)['items'], 'code'));
        self::assertSame(['B', 'A'], array_column($this->shop->listing->category($top, 'bogus', 'up', 1)['items'], 'code'));
        self::assertSame([], $this->shop->listing->category($top, '', '', 2)['items']);
        $small = $this->shop->categories->get((int) $other['id']);
        $this->shop->categories->save(['name' => '잡화', 'active' => '1', 'list_columns' => '1', 'list_rows' => '1', 'image_width' => '200', 'image_height' => '0'], (int) $other['id']);
        $small = $this->shop->categories->get((int) $other['id']);
        $page1 = $this->shop->listing->category($small, 'price', 'asc', 1); $page2 = $this->shop->listing->category($small, 'price', 'asc', 2);
        self::assertSame(['B'], array_column($page1['items'], 'code')); self::assertSame(['E'], array_column($page2['items'], 'code'));
        self::assertSame(2, $page1['total_pages']); self::assertSame(2, $page1['total']);
        self::assertTrue($page2['items'][0]['sold_out']); self::assertFalse($page1['items'][0]['sold_out']);
        self::assertNull($page1['items'][0]['image']);
    }

    #[DataProvider('connectionProvider')]
    public function testTypeSearchFacetsMainRelatedAndAdjacent(array $config): void
    {
        $this->setupShop($config);
        $top = $this->category('의류'); $other = $this->category('잡화');
        $a = $this->product(['category_id' => (string) $top['id'], 'code' => 'A', 'name' => '파란 셔츠', 'summary' => '여름 상품', 'price' => '300', 'is_hit' => '1', 'is_popular' => '1', 'sort_order' => '1']);
        $b = $this->product(['category_id' => (string) $top['id'], 'code' => 'B', 'name' => '빨간 셔츠', 'description' => '<p>겨울 상품</p>', 'price' => '100', 'is_hit' => '1', 'sort_order' => '2', 'relations' => (string) $a['id']]);
        $c = $this->product(['category_id' => (string) $other['id'], 'code' => 'C', 'name' => '가방', 'price' => '200', 'is_new' => '1', 'sort_order' => '3']);
        self::assertSame(['A', 'B'], array_column($this->shop->listing->type('hit', '', '', 1)['items'], 'code'));
        self::assertSame(['C'], array_column($this->shop->listing->type('new', '', '', 1)['items'], 'code'));
        self::assertSame(['A'], array_column($this->shop->listing->type('popular', '', '', 1)['items'], 'code'));
        $search = $this->shop->listing->search('셔츠 상품', null, 0, 0, '', '', 1);
        self::assertSame(['A', 'B'], array_column($search['items'], 'code'));
        self::assertSame(['셔츠', '상품'], $search['words']);
        self::assertSame([['slug' => '의류', 'name' => '의류', 'count' => 2]], $search['facets']);
        self::assertSame(['B'], array_column($this->shop->listing->search('셔츠', null, 0, 150, '', '', 1)['items'], 'code'));
        self::assertSame(['C'], array_column($this->shop->listing->search('가방', $other, 0, 0, '', '', 1)['items'], 'code'));
        self::assertSame([], $this->shop->listing->search('100%', null, 0, 0, '', '', 1)['items']);
        self::assertSame(['B', 'A'], array_column($this->shop->listing->search('셔츠', null, 0, 0, 'price', 'asc', 1)['items'], 'code'));
        $main = $this->shop->listing->main();
        self::assertSame(['hit', 'new', 'recommend', 'discount'], array_keys($main));
        self::assertSame(['A', 'B'], array_column($main['hit'], 'code')); self::assertSame([], $main['recommend']);
        self::assertSame(['A'], array_column($this->shop->listing->related((int) $b['id']), 'code'));
        self::assertSame(['B'], array_column($this->shop->listing->related((int) $a['id']), 'code'));
        $adjacent = $this->shop->listing->adjacent($this->shop->products->get((int) $b['id']));
        self::assertSame('A', $adjacent['prev']['code']); self::assertNull($adjacent['next']);
        $adjacent = $this->shop->listing->adjacent($this->shop->products->get((int) $a['id']));
        self::assertNull($adjacent['prev']); self::assertSame('B', $adjacent['next']['code']);
    }
}
