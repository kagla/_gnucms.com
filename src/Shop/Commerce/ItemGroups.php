<?php

declare(strict_types=1);

namespace GnuCms\Shop\Commerce;

/** 선택옵션은 구매 항목으로, 추가옵션은 같은 상품의 부속 구성으로 표시한다. */
final class ItemGroups
{
    public static function build(array $items): array
    {
        $groups = []; $count = 0;
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $groups[$id] ??= ['items' => [], 'extras' => []];
            if ($item['kind'] === 'extra') $groups[$id]['extras'][] = $item;
            else { $groups[$id]['items'][] = $item; $count++; }
        }
        return ['groups' => $groups, 'count' => $count];
    }
}
