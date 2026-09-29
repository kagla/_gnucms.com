<?php

declare(strict_types=1);

namespace GnuCms\Tests\Shop;

use GnuCms\Shop\Catalog\Stock;
use PHPUnit\Framework\TestCase;

/** 재고 규칙은 한 곳이다: 옵션(선택·추가) 행이 있으면 그 행, 없으면 상품 행. */
final class StockTest extends TestCase
{
    public function testCellPicksTheOptionRowWhenThereIsOneAndTheProductRowOtherwise(): void
    {
        $product = ['id' => 7, 'stock' => 3];
        self::assertSame(['table' => 'yc_products', 'id' => 7, 'stock' => 3], Stock::cell($product, null));
        self::assertSame(['table' => 'yc_options', 'id' => 21, 'stock' => 5], Stock::cell($product, ['id' => 21, 'stock' => 5, 'kind' => 'select']));
        self::assertSame(['table' => 'yc_options', 'id' => 22, 'stock' => 0], Stock::cell($product, ['id' => '22', 'stock' => '0', 'kind' => 'extra']));
    }

    public function testCellOfItemFollowsTheOrderLine(): void
    {
        self::assertSame(['table' => 'yc_products', 'id' => 7], Stock::cellOfItem(['product_id' => 7, 'option_id' => null]));
        self::assertSame(['table' => 'yc_products', 'id' => 7], Stock::cellOfItem(['product_id' => '7', 'option_id' => 0]));
        self::assertSame(['table' => 'yc_options', 'id' => 21], Stock::cellOfItem(['product_id' => 7, 'option_id' => '21']));
    }

    public function testSoldOutFollowsTheSameRule(): void
    {
        $product = ['sold_out' => 0, 'stock' => 0];
        self::assertTrue(Stock::soldOut(['sold_out' => 1, 'stock' => 9], [['stock' => 5, 'active' => 1]]), '수동 품절이 우선');
        self::assertFalse(Stock::soldOut($product, [['stock' => 5, 'active' => 1]]), '조합 하나라도 팔 수 있으면 품절 아님');
        self::assertTrue(Stock::soldOut($product, [['stock' => 0, 'active' => 1], ['stock' => 5, 'active' => 0]]), '사용 끈 조합의 재고는 세지 않음');
        self::assertTrue(Stock::soldOut($product, []), '조합이 없으면 상품 재고');
        self::assertFalse(Stock::soldOut(['sold_out' => 0, 'stock' => 1], []));
    }
}
