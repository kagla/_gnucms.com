<?php $remaining = (int) $order['paid_amount'] - (int) $order['refunded_amount']; ?>
<?php if ($pending_refunds !== []): ?>
<div class="alert alert-warning">결과를 확인하지 못한 환불 요청이 있습니다. 결제사 관리자 화면에서 취소 내역을 확인한 뒤 아래에서 정리해 주세요.</div>
<?php foreach ($pending_refunds as $key => $pending): ?>
<div class="yc-pending-refund"><p><strong><?= number_format((int) $pending['amount']) ?>원</strong> · <?= $this->e((string) $pending['reason']) ?> · 요청 <?= $this->e(date('Y-m-d H:i', (int) $pending['at'])) ?></p>
<form class="yc-form-stack" method="post" action="<?= $this->e($admin_url) ?>/orders/detail">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="refund-confirm"><input type="hidden" name="refund_key" value="<?= $this->e((string) $key) ?>">
<label class="yc-field"><span>결제사 취소 거래번호 <small>조회 화면의 취소 TID</small></span><input class="input input-bordered" name="reference" maxlength="100" required></label>
<button class="btn btn-outline btn-sm" type="submit">환불 대조</button></form>
<form method="post" action="<?= $this->e($admin_url) ?>/orders/detail">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="refund-unprocessed"><input type="hidden" name="refund_key" value="<?= $this->e((string) $key) ?>">
<button class="btn btn-ghost btn-sm" type="submit">결제사 미처리로 정리</button></form></div>
<?php endforeach ?>
<?php endif ?>
<?php if ((int) $order['paid_at'] > 0 && $remaining > 0 && (in_array($order['status'], ['paid', 'confirmed'], true) || ($order['status'] === 'cancelled' && !$is_pg))): ?>
<details class="yc-refund"><summary>환불</summary>
<form class="yc-form-stack" method="post" action="<?= $this->e($admin_url) ?>/orders/detail">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="refund"><input type="hidden" name="refund_key" value="<?= $this->e($refund_key) ?>">
<label class="yc-field"><span>환불 금액 <small>남은 금액 <?= number_format($remaining) ?>원</small></span><input class="input input-bordered" type="number" name="amount" min="1" max="<?= $remaining ?>" value="<?= $remaining ?>"<?= ($partial_refund ?? true) ? '' : ' readonly' ?> required></label>
<?php if (!($partial_refund ?? true)): ?><p class="yc-help"><?= ($escrow_payment ?? false) ? '에스크로 결제는 남은 금액의 전액 취소만 지원합니다.' : '이 결제사는 남은 금액의 전액 환불만 지원합니다.' ?></p><?php endif ?>
<label class="yc-field"><span>사유</span><input class="input input-bordered" name="reason" maxlength="200" required></label>
<?php if ($order['status'] !== 'cancelled'): ?><label class="label"><input class="checkbox checkbox-sm" type="checkbox" name="cancel_order" value="1" checked> 전액 환불이면 주문도 취소</label><?php endif ?>
<?php if ($is_pg): ?><p class="yc-help">결제사에 환불을 요청한 뒤 기록합니다. 같은 요청을 두 번 보내도 한 번만 처리됩니다.</p><?php else: ?><p class="yc-help">무통장입금·접수 전용 주문은 환불을 직접 보낸 뒤 여기 기록합니다.</p><?php endif ?>
<button class="btn btn-outline btn-sm" type="submit">환불 처리</button></form></details>
<?php endif ?>
