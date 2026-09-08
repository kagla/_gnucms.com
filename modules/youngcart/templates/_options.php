<?php $opts = $product['options']; $minimum = max(1, (int) $product['buy_min']); ?>
<form class="yc-purchase-form" method="post" action="<?= $this->e($url) ?>/cart/add" data-yc-purchase data-price="<?= (int) $product['price'] ?>">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
  <div class="yc-options" data-yc-options="<?= $this->e(json_encode($options_json, JSON_UNESCAPED_UNICODE)) ?>">
    <?php if ($opts['select'] !== []): ?><label class="yc-option-row"><span><?= $this->e(implode(' / ', $opts['select_groups'])) ?></span><select class="select select-bordered" name="option_id" required data-yc-option>
      <option value="">옵션을 선택해 주세요</option>
      <?php foreach ($opts['select'] as $option): $available = (int) $option['active'] === 1 && (int) $option['stock'] > 0; ?><option value="<?= (int) $option['id'] ?>" data-price="<?= (int) $option['price'] ?>" data-stock="<?= (int) $option['stock'] ?>"<?= !$available ? ' disabled' : '' ?>><?= $this->e(implode(' / ', array_filter([$option['value1'], $option['value2'], $option['value3']], static fn ($v) => $v !== ''))) ?><?= (int) $option['price'] !== 0 ? ' (' . ((int) $option['price'] > 0 ? '+' : '') . number_format((int) $option['price']) . '원)' : '' ?><?= !$available ? ' · 품절' : '' ?></option><?php endforeach ?>
    </select></label><?php else: ?><input type="hidden" name="option_id" value="0"><?php endif ?>
    <label class="yc-option-row"><span>수량</span><input class="input input-bordered yc-quantity" type="number" name="quantity" value="<?= $minimum ?>" min="<?= $minimum ?>" max="<?= min(9999, (int) $product['buy_max'] ?: 9999, $opts['select'] === [] ? (int) $product['stock'] : 9999) ?>" required data-yc-quantity></label>
    <?php if ($opts['extra'] !== []): ?><details class="yc-extras"><summary>추가 구성 선택 <span class="muted">선택사항</span></summary>
      <?php foreach ($opts['extra'] as $option): if ((int) $option['active'] !== 1) continue; ?><label class="yc-extra-row"><span><?= $this->e($option['value1'] . ' / ' . $option['value2']) ?><small><?= number_format((int) $option['price']) ?>원<?= (int) $option['stock'] < 1 ? ' · 품절' : '' ?></small></span><input class="input input-bordered yc-quantity" type="number" name="extras[<?= (int) $option['id'] ?>]" min="0" max="<?= min(9999, (int) $option['stock']) ?>" value="0" data-yc-extra-price="<?= (int) $option['price'] ?>" aria-label="<?= $this->e($option['value1'] . ' ' . $option['value2']) ?> 수량"<?= (int) $option['stock'] < 1 ? ' disabled' : '' ?>></label><?php endforeach ?>
    </details><?php endif ?>
    <p class="yc-total" aria-live="polite"><span>상품 금액 <small class="muted">배송비 별도</small></span><strong data-yc-total><?= number_format((int) $product['price'] * $minimum) ?>원</strong></p>
  </div>
  <div class="yc-buy-actions"><button class="yc-button yc-button-outline" type="submit" name="action" value="cart">장바구니 담기</button><button class="yc-button yc-button-primary" type="submit" name="action" value="buy">바로 주문하기</button></div>
  <p class="yc-purchase-help">다른 옵션은 장바구니에 추가로 담을 수 있어요.</p>
</form>
