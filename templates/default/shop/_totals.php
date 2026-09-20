<dl class="yc-totals">
  <div><dt>상품 금액</dt><dd><?= number_format((int) $quote['subtotal']) ?>원</dd></div>
  <div><dt>배송비 <small>(선불)</small></dt><dd><?= (int) $quote['shipping_fee'] === 0 ? '무료' : number_format((int) $quote['shipping_fee']) . '원' ?></dd></div>
  <?php if ((int) $quote['cod_fee'] > 0): ?><div><dt>착불 배송비 <small>(수령 시 별도)</small></dt><dd><?= number_format((int) $quote['cod_fee']) ?>원</dd></div><?php endif ?>
  <div class="yc-grand-total"><dt><?= $total_label ?? '주문 금액' ?></dt><dd><?= number_format((int) $quote['total']) ?><small>원</small></dd></div>
</dl>
