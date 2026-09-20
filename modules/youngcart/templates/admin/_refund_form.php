<?php $remaining = (int) $order['paid_amount'] - (int) $order['refunded_amount']; ?>
<?php if ((int) $order['paid_at'] > 0 && $remaining > 0 && in_array($order['status'], ['paid', 'confirmed'], true)): ?>
<details class="yc-refund"><summary>환불</summary>
<form class="yc-form-stack" method="post" action="<?= $this->e($admin_url) ?>/orders/detail">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="refund"><input type="hidden" name="refund_key" value="<?= $this->e($refund_key) ?>">
<label class="yc-field"><span>환불 금액 <small>남은 금액 <?= number_format($remaining) ?>원</small></span><input class="input input-bordered" type="number" name="amount" min="1" max="<?= $remaining ?>" value="<?= $remaining ?>" required></label>
<label class="yc-field"><span>사유</span><input class="input input-bordered" name="reason" maxlength="200" required></label>
<label class="label"><input class="checkbox checkbox-sm" type="checkbox" name="cancel_order" value="1" checked> 전액 환불이면 주문도 취소</label>
<?php if ($is_pg): ?><p class="yc-help">결제사에 환불을 요청한 뒤 기록합니다. 같은 요청을 두 번 보내도 한 번만 처리됩니다.</p><?php else: ?><p class="yc-help">무통장입금은 환불을 계좌로 직접 보낸 뒤 여기 기록합니다.</p><?php endif ?>
<button class="btn btn-outline btn-sm" type="submit">환불 처리</button></form></details>
<?php endif ?>
