<?php $name = $item['name'] ?? $item['product_name']; $label = $item['label'] ?? $item['option_label']; ?>
<div class="yc-order-line"><div>
  <strong><?= $this->e($extra ? $label : $name) ?></strong><?php if ($show_tax ?? false): ?> <small class="yc-tax-badge" data-tax-free="<?= (int) ($item['tax_free'] ?? 0) ?>"><?= (int) ($item['tax_free'] ?? 0) === 1 ? '면세' : '과세' ?></small><?php endif ?>
  <?php if (!$extra && $label !== ''): ?><p class="muted"><?= $this->e($label) ?></p><?php endif ?>
  <small class="muted"><?php if ($unit_prices): ?><?= number_format((int) $item['unit_price']) ?>원 · <?php endif ?><?= (int) $item['quantity'] ?>개</small>
</div><strong><?= number_format((int) $item['total']) ?>원</strong></div>
