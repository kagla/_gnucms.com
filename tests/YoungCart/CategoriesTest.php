<?php

declare(strict_types=1);

namespace GnuCms\Tests\YoungCart;

use GnuCms\Error\DomainError;
use PHPUnit\Framework\Attributes\DataProvider;

final class CategoriesTest extends YoungCartTestCase
{
    #[DataProvider('connectionProvider')]
    public function testCodeSuggestionValidationAndTree(array $config): void
    {
        $this->setupShop($config);
        self::assertSame('10', $this->shop->categories->suggestCode(null));
        $top = $this->category('의류');
        self::assertSame('10', $top['code']); self::assertSame(1, (int) $top['depth']); self::assertNull($top['parent_id']);
        self::assertSame('20', $this->shop->categories->suggestCode(null));
        $second = $this->category('second');
        self::assertSame('1010', $this->shop->categories->suggestCode('10'));
        $child = $this->category('셔츠', '10');
        self::assertSame((int) $top['id'], (int) $child['parent_id']); self::assertSame(2, (int) $child['depth']);
        self::assertSame('1020', $this->shop->categories->suggestCode('10'));
        $this->shop->store->insert('yc_categories', ['code' => 'z0', 'parent_id' => null, 'depth' => 1, 'name' => '끝', 'sort_order' => 0, 'active' => 1,
            'no_coupon' => 0, 'head_html' => '', 'tail_html' => '', 'list_columns' => 3, 'list_rows' => 5, 'image_width' => 200, 'image_height' => 0, 'extra' => '[]', 'created_at' => 1, 'updated_at' => 1]);
        self::assertNull($this->shop->categories->suggestCode(null));
        foreach (['1', '101', '10-1', '9910', '가나', ''] as $bad) {
            try {
                $this->shop->categories->save(['code' => $bad, 'name' => 'x', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']);
                self::fail('코드 ' . $bad . '는 거절해야 한다');
            } catch (DomainError $e) {
                self::assertSame(422, $e->status(), $bad);
            }
        }
        $id = $this->shop->categories->save(['code' => '1A', 'name' => '소문자화', 'list_columns' => '3', 'list_rows' => '5', 'image_width' => '200', 'image_height' => '0']);
        self::assertSame('1a', $this->shop->categories->get($id)['code']);
        $deep = '10';
        foreach (['셔츠2', '3단', '4단', '5단'] as $name) { $deep = $this->category($name, $deep)['code']; }
        self::assertSame(10, strlen($deep));
        try { $this->shop->categories->suggestCode($deep); self::fail(); } catch (DomainError $e) { self::assertSame(422, $e->status()); }
        $tree = $this->shop->categories->tree();
        self::assertSame(['10', '1010', '1020', '102010', '10201010', '1020101010', '1a', '20', 'z0'], array_column($tree, 'code'));
        self::assertSame(0, (int) $tree[0]['product_count']);
        self::assertSame(['10', '1020', '102010'], array_column($this->shop->categories->path('102010'), 'code'));
        self::assertSame(['1010', '1020'], array_column($this->shop->categories->children('10', true), 'code'));
        self::assertStringStartsWith('의류 > ', $this->shop->categories->options()[(int) $child['id']]);
    }

    #[DataProvider('connectionProvider')]
    public function testUpdateApplyChildrenDeleteGuardsAndBulk(array $config): void
    {
        $this->setupShop($config);
        $top = $this->category('의류'); $child = $this->category('셔츠', '10'); $grand = $this->category('반팔', '1010');
        $this->shop->categories->save(['code' => 'ignored', 'name' => '의류(수정)', 'active' => '0', 'no_coupon' => '1', 'list_columns' => '4', 'list_rows' => '2',
            'image_width' => '150', 'image_height' => '150', 'head_html' => '<p>위</p><script>1</script>', 'sort_order' => '5', 'apply_children' => '1',
            'extra_label' => [1 => '라벨'], 'extra_value' => [1 => '값']], (int) $top['id']);
        $top = $this->shop->categories->get((int) $top['id']);
        self::assertSame('10', $top['code']); self::assertSame('의류(수정)', $top['name']); self::assertSame('<p>위</p>', $top['head_html']);
        self::assertSame('라벨', $top['extra'][0]['label']);
        $grand = $this->shop->categories->get((int) $grand['id']);
        self::assertSame(0, (int) $grand['active']); self::assertSame(4, (int) $grand['list_columns']); self::assertSame(150, (int) $grand['image_height']);
        self::assertSame('반팔', $grand['name']);
        try { $this->shop->categories->delete((int) $top['id']); self::fail(); } catch (DomainError $e) { self::assertStringContainsString('하위 분류', $e->details()['category']); }
        $this->product(['category_id' => (string) $grand['id']]);
        try { $this->shop->categories->delete((int) $grand['id']); self::fail(); } catch (DomainError $e) { self::assertStringContainsString('1개 상품', $e->details()['category']); }
        $leaf = $this->category('빈 분류', '1010');
        $this->shop->categories->delete((int) $leaf['id']);
        self::assertNull($this->shop->categories->byCode($leaf['code']));
        $this->shop->categories->bulk([(int) $child['id'] => ['name' => '셔츠(일괄)', 'sort_order' => '3', 'active' => '1', 'list_columns' => '2', 'list_rows' => '2', 'image_width' => '100', 'image_height' => '0']]);
        self::assertSame('셔츠(일괄)', $this->shop->categories->get((int) $child['id'])['name']);
        try {
            $this->shop->categories->bulk([(int) $child['id'] => ['name' => '', 'sort_order' => '3', 'active' => '1', 'list_columns' => '2', 'list_rows' => '2', 'image_width' => '100', 'image_height' => '0'],
                (int) $grand['id'] => ['name' => '유지', 'sort_order' => '0', 'active' => '1', 'list_columns' => '2', 'list_rows' => '2', 'image_width' => '100', 'image_height' => '0']]);
            self::fail();
        } catch (DomainError $e) {
            self::assertSame(422, $e->status());
        }
        self::assertSame('반팔', $this->shop->categories->get((int) $grand['id'])['name']);
        self::assertSame(3, $this->shop->categories->count());
    }
}
