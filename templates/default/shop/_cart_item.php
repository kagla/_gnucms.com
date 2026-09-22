<article class="yc-cart-item<?= $extra ? ' yc-cart-extra' : '' ?>" data-yc-cart-line="<?= $this->e($item['key']) ?>">
  <?php if (!$extra): ?><a class="yc-cart-image" href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($item['code'])) ?>"><?php if ($item['image'] !== null): ?><img src="<?= $this->e($img($item['product_id'], $item['image'], 'thumb')) ?>" alt="<?= $this->e($item['name']) ?>"><?php else: ?><span class="yc-noimage"><?= $this->icon('gift', 25) ?></span><?php endif ?></a><?php endif ?>
  <div class="yc-cart-item-info">
    <?php if ($extra): ?><h4><?= $this->e($item['label']) ?></h4>
    <?php else: ?><a href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($item['code'])) ?>"><h2><?= $this->e($item['name']) ?></h2></a><?php if ($item['label'] !== ''): ?><p class="muted"><?= $this->e($item['label']) ?></p><?php endif ?><?php endif ?>
    <?php if ($item['error'] !== ''): ?><p class="yc-inline-error"><?= $this->e($item['error']) ?></p><?php endif ?>
    <div class="yc-cart-item-bottom">
      <div class="yc-option-quantity-controls" data-yc-cart-quantity>
        <button type="button" data-yc-cart-minus hidden aria-label="<?= $this->e($item['name'] . ' ' . $item['label']) ?> 수량 줄이기">−</button>
        <input class="input input-bordered" type="number" name="quantities[<?= $this->e($item['key']) ?>]" value="<?= $item['quantity'] ?>" min="<?= $extra ? 0 : 1 ?>" max="9999" required aria-label="<?= $this->e($item['name'] . ' ' . $item['label']) ?> 수량">
        <button type="button" data-yc-cart-plus hidden aria-label="<?= $this->e($item['name'] . ' ' . $item['label']) ?> 수량 늘리기">+</button>
      </div>
      <strong><?= number_format($item['total']) ?>원</strong>
    </div>
  </div>
  <button class="yc-remove" type="submit" name="remove" value="<?= $this->e($item['key']) ?>" formnovalidate aria-label="<?= $this->e($item['name'] . ' ' . $item['label']) ?> 삭제"><?= $this->icon('close', 19) ?></button>
</article>
