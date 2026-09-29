<?php $opts = $product['options']; $minimum = max(1, (int) $product['buy_min']); ?>
<form class="yc-purchase-form" method="post" action="<?= $this->e($url) ?>/cart/add" data-yc-purchase data-price="<?= (int) $product['price'] ?>" data-buy-max="<?= (int) $product['buy_max'] ?>">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
  <div class="yc-options" data-yc-options="<?= $this->e(json_encode($options_json, JSON_UNESCAPED_UNICODE)) ?>">
    <?php if ($opts['select'] !== []): ?>
    <div class="yc-option-stages" data-yc-option-stages hidden>
      <p class="yc-option-guide" id="yc-option-guide"><?php if (count($opts['select_groups']) > 1): ?>위에서부터 옵션을 선택해 주세요.<?php else: ?>옵션을 선택해 주세요.<?php endif ?></p>
      <?php foreach ($opts['select_groups'] as $index => $group): ?>
      <label class="yc-option-stage" data-yc-option-stage>
        <span><?= $this->e($group) ?></span>
        <select class="select select-bordered" name="option_step[<?= $index + 1 ?>]" data-yc-option-step="<?= $index ?>" aria-label="<?= $this->e($group) ?>" aria-describedby="yc-option-guide" required disabled><option value=""><?= $this->e($group) ?> 선택</option></select>
      </label>
      <?php endforeach ?>
      <p class="yc-option-feedback" role="status" aria-live="polite" aria-atomic="true" data-yc-option-feedback></p>
      <div class="yc-option-selection" data-yc-option-selection hidden><strong data-yc-selection-name></strong><div><span data-yc-selection-stock></span><span data-yc-selection-price></span></div></div>
      <div class="yc-selected-options" data-yc-selections hidden aria-label="선택한 옵션"></div>
      <template data-yc-selection-template>
        <div class="yc-selected-option" data-yc-selected-option>
          <div class="yc-selected-option-heading"><strong data-yc-line-name></strong><button class="yc-text-button" type="button" data-yc-line-remove>삭제</button></div>
          <p data-yc-line-meta></p>
          <div class="yc-selected-option-bottom"><div class="yc-option-quantity-controls"><button type="button" data-yc-line-minus>−</button><input class="input input-bordered" type="number" min="1" value="1" required data-yc-line-quantity><button type="button" data-yc-line-plus>+</button></div><strong data-yc-line-total></strong></div>
        </div>
      </template>
    </div>
    <label class="yc-option-row" data-yc-option-fallback><span><?= $this->e(implode(' / ', $opts['select_groups'])) ?></span><select class="select select-bordered" name="option_id" required data-yc-option>
      <option value="">옵션을 선택해 주세요</option>
      <?php foreach ($opts['select'] as $option): $available = (int) $option['active'] === 1 && (int) $option['stock'] > 0; ?><option value="<?= (int) $option['id'] ?>" data-price="<?= (int) $option['price'] ?>" data-stock="<?= $available ? (int) $option['stock'] : 0 ?>"<?= !$available ? ' disabled' : '' ?>><?= $this->e(implode(' / ', array_filter([$option['value1'], $option['value2'], $option['value3']], static fn ($v) => $v !== ''))) ?><?= (int) $option['price'] !== 0 ? ' (' . ((int) $option['price'] > 0 ? '+' : '') . number_format((int) $option['price']) . '원)' : '' ?><?= $available ? ' · 재고 ' . number_format((int) $option['stock']) . '개' : ' · 품절' ?></option><?php endforeach ?>
    </select></label><?php else: ?><input type="hidden" name="option_id" value="0"><?php endif ?>
    <div class="yc-option-row" data-yc-single-quantity><label for="yc-product-quantity">수량</label><div class="yc-option-quantity-controls" data-yc-quantity-controls>
      <button type="button" data-yc-quantity-minus aria-label="상품 수량 줄이기" hidden>−</button>
      <input class="input input-bordered yc-quantity" type="number" id="yc-product-quantity" name="quantity" value="<?= $minimum ?>" min="<?= $minimum ?>" max="<?= min(9999, (int) $product['buy_max'] ?: 9999, $opts['select'] === [] ? (int) $product['stock'] : 9999) ?>" required data-yc-quantity data-yc-stock="<?= $opts['select'] === [] ? (int) $product['stock'] : 9999 ?>">
      <button type="button" data-yc-quantity-plus aria-label="상품 수량 늘리기" hidden>+</button>
    </div></div>
    <?php if ($opts['extra'] !== []): ?><details class="yc-extras"><summary>추가 구성 선택 <span class="muted">선택사항</span></summary>
      <?php foreach ($opts['extra'] as $option):
        if ((int) $option['active'] !== 1) continue;
        $extraName = implode(' / ', array_filter([$option['value1'], $option['value2']], static fn ($value) => $value !== ''));
        $extraId = 'yc-extra-quantity-' . (int) $option['id'];
        $extraSoldOut = (int) $option['stock'] < 1;
      ?>
      <div class="yc-extra-row">
        <label for="<?= $extraId ?>"><?= $this->e($extraName) ?><small><?= number_format((int) $option['price']) ?>원<?= $extraSoldOut ? ' · 품절' : '' ?></small></label>
        <div class="yc-option-quantity-controls" data-yc-quantity-controls>
          <button type="button" data-yc-quantity-minus aria-label="<?= $this->e($extraName) ?> 수량 줄이기" hidden>−</button>
          <input class="input input-bordered" type="number" id="<?= $extraId ?>" name="extras[<?= (int) $option['id'] ?>]" min="0" max="<?= min(9999, (int) $option['stock']) ?>" value="0" inputmode="numeric" data-yc-extra-price="<?= (int) $option['price'] ?>" data-yc-stock="<?= (int) $option['stock'] ?>" aria-label="<?= $this->e($extraName) ?> 수량"<?= $extraSoldOut ? ' disabled' : '' ?>>
          <button type="button" data-yc-quantity-plus aria-label="<?= $this->e($extraName) ?> 수량 늘리기" hidden>+</button>
        </div>
      </div>
      <?php endforeach ?>
    </details><?php endif ?>
    <p class="yc-total" aria-live="polite"><span>상품 금액 <small class="muted">배송비 별도</small></span><strong data-yc-total><?= number_format((int) $product['price'] * $minimum) ?>원</strong></p>
  </div>
  <div class="yc-feedback yc-feedback-error yc-purchase-feedback" role="alert" tabindex="-1" data-yc-purchase-feedback hidden><div><strong>구매 내용을 다시 확인해 주세요</strong><ul data-yc-purchase-errors></ul><a href="<?= $this->e($url) ?>/cart">장바구니 확인</a></div></div>
  <div class="yc-buy-actions"><button class="yc-button yc-button-outline" type="submit" name="action" value="cart">장바구니 담기</button><button class="yc-button yc-button-primary" type="submit" name="action" value="buy">바로 주문하기</button></div>
  <p class="yc-purchase-help" data-yc-purchase-help>다른 옵션은 장바구니에 추가로 담을 수 있어요.</p>
</form>
