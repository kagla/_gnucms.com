<?php $itemGroups = \GnuCms\Shop\Commerce\ItemGroups::build($items); ?>
<?php $showTax = count(array_filter($items, static fn (array $item): bool => (int) ($item['tax_free'] ?? 0) === 1)) > 0; ?>
<section class="yc-panel"><h2>주문 상품 <small><?= $itemGroups['count'] ?>개 항목</small></h2>
  <?php foreach ($itemGroups['groups'] as $productId => $group): ?><div class="yc-order-product" data-yc-order-product="<?= (int) $productId ?>">
    <?php foreach ($group['items'] as $item): ?><?php $this->insert('_order_product_line', ['item' => $item, 'extra' => false, 'unit_prices' => $unit_prices ?? false, 'show_tax' => $showTax]) ?><?php endforeach ?>
    <?php if ($group['extras'] !== []): ?><div class="yc-order-extras" data-yc-order-extras><h3><?= count($group['items']) > 1 ? '이 상품의 공통 추가 구성' : '추가 구성' ?></h3>
      <?php foreach ($group['extras'] as $item): ?><?php $this->insert('_order_product_line', ['item' => $item, 'extra' => true, 'unit_prices' => $unit_prices ?? false, 'show_tax' => $showTax]) ?><?php endforeach ?>
    </div><?php endif ?>
  </div><?php endforeach ?>
</section>
