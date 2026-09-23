<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>주문 상세 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '주문 상세', 'description' => $order['number'], 'actions' => [['url' => $admin_url . '/orders', 'label' => '주문 목록']]]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<div class="yc-order-banner"><div><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span><?php if ($order['payment_environment'] === 'test'): ?><span class="yc-test-badge">테스트 결제</span><?php endif ?><strong><?= $this->e($order['buyer_name']) ?> 님의 주문</strong></div><p>접수 <?= date('Y.m.d H:i', (int) $order['created_at']) ?></p></div>
<div class="yc-admin yc-commerce-grid"><div class="yc-checkout-sections"><?php $this->insert('_order_detail') ?>
<section class="yc-panel"><h2>처리 이력</h2><ol class="yc-order-timeline"><?php foreach ($order['history'] as $event): ?><li><div><strong><?= $this->e($statuses[$event['status']]) ?></strong><p class="muted"><?= $this->e($event['actor']) ?><?= $event['note'] !== '' ? ' · ' . $this->e($event['note']) : '' ?></p></div><time><?= date('Y.m.d H:i', (int) $event['created_at']) ?></time></li><?php endforeach ?></ol></section></div>
<aside class="yc-order-summary"><div class="yc-section-heading"><h2>주문 처리</h2><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span></div>
<?php $this->insert('_totals', ['quote' => $order]) ?>
<section class="yc-panel yc-payment-panel"><h2>결제</h2>
<?php if ($order['payment_method'] === ''): ?><p class="yc-help">온라인 결제 내역이 없는 주문입니다. 결제 안내와 확인은 별도로 진행해 주세요.</p>
<?php else: ?><dl class="yc-detail-list">
<?php if ($is_pg): ?><div><dt>결제사</dt><dd><?= $this->e($payment_provider_label) ?></dd></div><?php endif ?>
<div><dt>수단</dt><dd><?= $this->e($payment_methods[$order['payment_method']] ?? $order['payment_method']) ?></dd></div>
<div><dt>상태</dt><dd><?= (int) $order['paid_at'] > 0 ? '결제 완료 · ' . $this->e(date('Y-m-d H:i', (int) $order['paid_at'])) . ' · ' . number_format((int) $order['paid_amount']) . '원' : '결제 대기 · 기한 ' . $this->e(date('Y-m-d H:i', (int) $order['pay_by'])) ?></dd></div>
<?php if ((int) $order['refunded_amount'] > 0): ?><div><dt>환불</dt><dd><?= number_format((int) $order['refunded_amount']) ?>원</dd></div><?php endif ?>
<?php if ($order['payment_method'] === 'manual_transfer'): ?><div><dt>입금자명</dt><dd><?= $this->e(($order['payment']['depositor'] ?? '') !== '' ? $order['payment']['depositor'] : $order['buyer_name']) ?></dd></div><div><dt>안내 계좌</dt><dd><?= $this->e(($order['payment']['bank'] ?? '') . ' ' . ($order['payment']['account'] ?? '') . ' ' . ($order['payment']['holder'] ?? '')) ?></dd></div><?php endif ?>
<?php if (($order['payment']['tid'] ?? '') !== ''): ?><div><dt>거래번호</dt><dd><?= $this->e($order['payment']['tid']) ?></dd></div><?php endif ?>
</dl>
<?php endif ?>
<?php if ($order['payment']['needs_review'] ?? false): ?><p class="alert alert-warning">결제사 승인이 남아 있지만 주문은 결제 완료가 아닙니다. 결제사에서 환불한 뒤 처리 메모를 남겨 주세요. 거래번호 <?= $this->e((string) ($order['payment']['tid'] ?? '')) ?></p><?php endif ?>
<?php if ($order['status'] === 'pending' && ($order['payment_method'] === '' || $order['payment_method'] === 'manual_transfer')): ?><form method="post" action="<?= $this->e($admin_url) ?>/orders/detail"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="confirm-deposit"><button class="btn btn-primary btn-sm" type="submit">입금 확인</button></form><?php endif ?>
<?php if ($is_pg): ?><form method="post" action="<?= $this->e($admin_url) ?>/orders/detail"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="sync"><button class="btn btn-outline btn-sm" type="submit">결제 조회</button></form><?php endif ?>
<?php $this->insert('admin/_refund_form') ?>
</section>
<?php if ($next !== []): ?><form class="yc-form-stack" method="post" action="<?= $this->e($admin_url) ?>/orders/detail" data-yc-order-status-form>
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="from" value="<?= $this->e($order['status']) ?>">
<label class="yc-field"><span>변경할 상태</span><select class="select select-bordered" name="status" required><?php foreach ($next as $status): ?><option value="<?= $this->e($status) ?>"<?= ($input['status'] ?? '') === $status ? ' selected' : '' ?>><?= $this->e($statuses[$status]) ?></option><?php endforeach ?></select></label>
<?php if (in_array('shipped', $next, true)): ?><label class="yc-field"><span>택배사 <small>배송 중으로 변경할 때 필수</small></span><input class="input input-bordered" name="carrier" maxlength="100" value="<?= $this->e(is_string($input['carrier'] ?? null) ? $input['carrier'] : '') ?>"></label><label class="yc-field"><span>운송장 번호</span><input class="input input-bordered" name="tracking_number" maxlength="100" value="<?= $this->e(is_string($input['tracking_number'] ?? null) ? $input['tracking_number'] : '') ?>"></label><?php endif ?>
<?php if (in_array('cancelled', $next, true)): ?><div class="yc-form-stack" data-yc-admin-cancel-fields><?php $this->insert('_cancel_reason_fields', ['cancel_reason_required' => false]) ?></div><?php endif ?>
<label class="yc-field" data-yc-status-note><span>처리 메모 <small>관리자에게만 표시</small></span><textarea class="textarea textarea-bordered" name="note" maxlength="500" rows="3"><?= $this->e(is_string($input['note'] ?? null) ? $input['note'] : '') ?></textarea></label><p class="yc-help">주문 취소를 선택하면 모든 상품의 재고가 복원됩니다. 배송이 시작된 주문은 취소할 수 없습니다.</p><button class="btn btn-primary" type="submit">주문 상태 변경</button>
</form><?php endif ?>
<a class="yc-continue" href="<?= $this->e($admin_url) ?>/orders">주문 목록으로</a></aside></div>
<?php $this->stop() ?>
