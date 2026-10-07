<?php if (in_array($order['status'], ['shipped', 'completed'], true)): ?>
<section class="yc-panel"><details class="yc-cancel"<?= ($return_attempted ?? false) ? ' open' : '' ?>><summary>주문 전체 반품 요청</summary>
<p>반품 사유를 선택해 주세요. 수거 일정은 상점에서 안내하며, 상품 확인 뒤 환불을 처리합니다.</p>
<form class="yc-form-stack" method="post" action="<?= $this->e($url) ?>/order/return"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="number" value="<?= $this->e($order['number']) ?>">
<?php $this->insert('_return_reason_fields') ?><button class="yc-button yc-button-outline" type="submit">반품 요청</button></form></details></section>
<?php elseif ($order['status'] === 'returning'): ?>
<section class="yc-panel"><h2>반품 요청</h2><p><?= $this->e($order['payment']['return']['reason'] ?? '') ?></p><p class="muted">상점에서 수거를 안내하고 상품 확인 뒤 환불을 처리합니다.</p>
<form method="post" action="<?= $this->e($url) ?>/order/return"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="number" value="<?= $this->e($order['number']) ?>"><input type="hidden" name="return_action" value="withdraw"><button class="yc-button yc-button-outline" type="submit">반품 요청 취소</button></form></section>
<?php elseif ($order['status'] === 'returned'): ?>
<section class="yc-panel"><h2>반품 완료</h2><p>반품 처리가 완료되었습니다. 환불 내역은 주문의 결제 정보에서 확인할 수 있습니다.</p></section>
<?php endif ?>
