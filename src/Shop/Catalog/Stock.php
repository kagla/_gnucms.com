<?php

declare(strict_types=1);

namespace GnuCms\Shop\Catalog;

/**
 * 재고 규칙은 한 곳이다: 주문 줄에 옵션(선택·추가) 행이 있으면 재고는 그 행에, 없으면 상품 행에 있다.
 * 선택옵션 조합이 있는 상품의 상품 재고 칸은 쓰이지 않는다 — 장바구니 검사, 주문 차감·복원, 품절 판정,
 * 부족 알림, 재고 화면이 모두 이 규칙을 따른다. 나중에 "기본 조합" 방식으로 옮기더라도 이 안만 바꾸면 된다.
 */
final class Stock
{
    /** @return array{table: string, id: int, stock: int} 이 줄의 재고가 있는 표와 행, 지금 재고 */
    public static function cell(array $product, ?array $option): array
    {
        return $option === null
            ? ['table' => 'yc_products', 'id' => (int) $product['id'], 'stock' => (int) $product['stock']]
            : ['table' => 'yc_options', 'id' => (int) $option['id'], 'stock' => (int) $option['stock']];
    }

    /** @return array{table: string, id: int} 주문 항목 행(option_id 가 비면 상품)의 재고 칸 */
    public static function cellOfItem(array $item): array
    {
        $optionId = (int) ($item['option_id'] ?? 0);
        return $optionId === 0 ? ['table' => 'yc_products', 'id' => (int) $item['product_id']] : ['table' => 'yc_options', 'id' => $optionId];
    }

    /** 수동 품절이거나, 조합이 있으면 사용 중인 조합의 재고가 모두 0, 없으면 상품 재고가 0. */
    public static function soldOut(array $product, array $selectRows): bool
    {
        if ((int) ($product['sold_out'] ?? 0) === 1) return true;
        if ($selectRows === []) return (int) ($product['stock'] ?? 0) <= 0;
        foreach ($selectRows as $row) {
            if ((int) ($row['active'] ?? 1) === 1 && (int) $row['stock'] > 0) return false;
        }
        return true;
    }

    /** SQL 조각: 별칭 $alias 의 상품에 선택옵션 조합이 없다. */
    public static function withoutOptionsWhere(string $optionsTable, string $alias): string
    {
        return 'NOT EXISTS (SELECT 1 FROM ' . $optionsTable . ' so WHERE so.product_id = ' . $alias . ".id AND so.kind = 'select')";
    }
}
