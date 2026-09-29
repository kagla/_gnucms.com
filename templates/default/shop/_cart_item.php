<article class="yc-cart-item<?= $extra ? ' yc-cart-extra' : '' ?>" data-yc-cart-line="<?= $this->e($item['key']) ?>">
  <div class="yc-cart-item-info">
    <h3><?= $this->e($item['label'] !== '' ? $item['label'] : '기본 상품') ?></h3>
    <?php if ($item['error'] !== ''): ?><p class="yc-inline-error" data-yc-cart-error role="alert"><?= $this->e($item['error']) ?></p><?php endif ?>
    <div class="yc-cart-item-bottom">
      <div class="yc-option-quantity-controls" data-yc-cart-quantity>
        <button type="button" data-yc-cart-minus hidden aria-label="<?= $this->e($item['name'] . ' ' . $item['label']) ?> 수량 줄이기">−</button>
        <input class="input input-bordered" type="number" name="quantities[<?= $this->e($item['key']) ?>]" value="<?= $item['quantity'] ?>" min="1" max="<?= max(1, (int) $item['available']) ?>" required aria-invalid="<?= $item['error'] !== '' ? 'true' : 'false' ?>" aria-label="<?= $this->e($item['name'] . ' ' . $item['label']) ?> 수량">
        <button type="button" data-yc-cart-plus hidden aria-label="<?= $this->e($item['name'] . ' ' . $item['label']) ?> 수량 늘리기">+</button>
      </div>
      <strong data-yc-cart-line-total><?= number_format($item['total']) ?>원</strong>
    </div>
  </div>
  <button class="yc-remove" type="submit" name="remove" value="<?= $this->e($item['key']) ?>" formnovalidate aria-label="<?= $this->e($item['name'] . ' ' . $item['label']) ?> 삭제" data-yc-cart-remove><?= $this->icon('close', 19) ?></button>
</article>
