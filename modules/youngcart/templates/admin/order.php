<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>주문 상세 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '주문 상세', 'description' => $order['number'], 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<div class="yc-admin yc-commerce-grid"><div class="yc-checkout-sections"><?php $this->insert('_order_detail') ?>
<section class="yc-panel"><h2>처리 이력</h2><ol class="yc-order-timeline"><?php foreach ($order['history'] as $event): ?><li><div><strong><?= $this->e($statuses[$event['status']]) ?></strong><p class="muted"><?= $this->e($event['actor']) ?><?= $event['note'] !== '' ? ' · ' . $this->e($event['note']) : '' ?></p></div><time><?= date('Y.m.d H:i', (int) $event['created_at']) ?></time></li><?php endforeach ?></ol></section></div>
<aside class="yc-order-summary"><div class="yc-section-heading"><h2>주문 처리</h2><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span></div>
<?php $this->insert('_totals', ['quote' => $order]) ?><p class="yc-help">온라인 결제 내역이 없는 주문입니다. 결제 안내와 확인은 별도로 진행해 주세요.</p>
<?php if ($next !== []): ?><form class="yc-form-stack" method="post" action="<?= $this->e($admin_url) ?>/orders/detail">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="from" value="<?= $this->e($order['status']) ?>">
<label class="yc-field"><span>변경할 상태</span><select class="select select-bordered" name="status" required><?php foreach ($next as $status): ?><option value="<?= $this->e($status) ?>"><?= $this->e($statuses[$status]) ?></option><?php endforeach ?></select></label>
<?php if (in_array('shipped', $next, true)): ?><label class="yc-field"><span>택배사 <small>배송 중으로 변경할 때 필수</small></span><input class="input input-bordered" name="carrier" maxlength="100" value="<?= $this->e(is_string($input['carrier'] ?? null) ? $input['carrier'] : '') ?>"></label><label class="yc-field"><span>운송장 번호</span><input class="input input-bordered" name="tracking_number" maxlength="100" value="<?= $this->e(is_string($input['tracking_number'] ?? null) ? $input['tracking_number'] : '') ?>"></label><?php endif ?>
<label class="yc-field"><span>처리 메모 <small>관리자에게만 표시</small></span><textarea class="textarea textarea-bordered" name="note" maxlength="500" rows="3"><?= $this->e(is_string($input['note'] ?? null) ? $input['note'] : '') ?></textarea></label><p class="yc-help">주문 취소를 선택하면 모든 상품의 재고가 복원됩니다. 배송이 시작된 주문은 취소할 수 없습니다.</p><button class="btn btn-primary" type="submit">주문 상태 변경</button>
</form><?php endif ?>
<a class="yc-continue" href="<?= $this->e($admin_url) ?>/orders">주문 목록으로</a></aside></div>
<?php $this->stop() ?>
