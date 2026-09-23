<dl class="yc-totals">
  <div><dt>상품 금액</dt><dd<?= isset($cart_preview) && $cart_preview ? ' data-yc-cart-total="subtotal"' : '' ?>><?= number_format((int) $quote['subtotal']) ?>원</dd></div>
  <div><dt>배송비 <small>(선불)</small></dt><dd<?= isset($cart_preview) && $cart_preview ? ' data-yc-cart-total="shipping"' : '' ?>><?= (int) $quote['shipping_fee'] === 0 ? '무료' : number_format((int) $quote['shipping_fee']) . '원' ?></dd></div>
  <?php if ((int) $quote['cod_fee'] > 0 || (isset($cart_preview) && $cart_preview)): ?><div><dt>착불 배송비 <small>(수령 시 별도)</small></dt><dd<?= isset($cart_preview) && $cart_preview ? ' data-yc-cart-total="cod"' : '' ?>><?= (int) $quote['cod_fee'] === 0 ? '0원' : number_format((int) $quote['cod_fee']) . '원' ?></dd></div><?php endif ?>
  <div class="yc-grand-total"><dt><?= $total_label ?? '주문 금액' ?></dt><dd<?= isset($cart_preview) && $cart_preview ? ' data-yc-cart-total="total"' : '' ?>><?= number_format((int) $quote['total']) ?><small>원</small></dd></div>
</dl>
