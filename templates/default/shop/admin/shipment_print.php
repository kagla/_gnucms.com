<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>피킹·포장 인쇄 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '피킹·포장 명세', 'description' => count($orders) . '건의 배송 준비 주문입니다.', 'actions' => []]) ?>
<div class="row-actions yc-print-actions"><button class="btn btn-primary" type="button" data-yc-print-page>인쇄</button></div>
<div class="yc-admin yc-shipment-print">
<?php foreach ($orders as $order): ?><article class="yc-packing-sheet">
  <header><div><strong><?= $this->e($order['number']) ?></strong><small><?= $this->date((int) $order['created_at'], 'Y.m.d H:i') ?></small></div><div class="yc-pack-check">포장 확인 □</div></header>
  <div class="yc-pack-address"><strong><?= $this->e($order['recipient']) ?></strong> · <?= $this->e($order['recipient_phone']) ?><br>[<?= $this->e($order['postcode']) ?>] <?= $this->e($order['address'] . ' ' . $order['address_detail']) ?><?php if ($order['delivery_note'] !== ''): ?><br><small>요청: <?= $this->e($order['delivery_note']) ?></small><?php endif ?></div>
  <table class="table"><thead><tr><th>확인</th><th>상품코드</th><th>상품 / 옵션</th><th>수량</th></tr></thead><tbody><?php foreach ($order['items'] as $item): ?><tr><td>□</td><td><?= $this->e($item['product_code']) ?></td><td><?= $this->e($item['product_name']) ?><?php if ($item['option_label'] !== ''): ?><br><small><?= $this->e($item['option_label']) ?></small><?php endif ?></td><td><?= number_format((int) $item['quantity']) ?></td></tr><?php endforeach ?></tbody></table>
</article><?php endforeach ?>
</div>
<?php $this->stop() ?>
