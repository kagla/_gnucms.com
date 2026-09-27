<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>주문 상세 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $weekdayNames = ['일', '월', '화', '수', '목', '금', '토']; $weekdayDate = fn (int $timestamp): string => $this->date($timestamp, 'y-m-d H:i:s') . ' (' . $weekdayNames[(int) $this->date($timestamp, 'w')] . ')'; ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '주문 상세', 'description' => $order['number'], 'actions' => [['url' => $admin_url . '/orders', 'label' => '주문 목록']]]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<div class="yc-order-banner"><div><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span><?php if ($order['payment_environment'] === 'test'): ?><span class="yc-test-badge">테스트 결제</span><?php endif ?><strong><?= $this->e($order['buyer_name']) ?> 님의 주문</strong></div><p>접수 <?= $this->e($weekdayDate((int) $order['created_at'])) ?></p></div>
<div class="yc-admin yc-commerce-grid"><div class="yc-checkout-sections"><?php $this->insert('_order_detail') ?>
<section class="yc-panel"><h2>처리 이력 <small>처리 메모는 관리자에게만 표시</small></h2>
<?php if ($undo_history_id !== null): ?><p class="yc-help">상태는 최신 처리 이력부터 순서대로 되돌릴 수 있습니다.</p><?php endif ?>
<ol class="yc-order-timeline yc-admin-order-timeline" id="yc-order-timeline">
<?php foreach ($timeline as $event): ?>
<?php if ($event['type'] === 'note'): ?><li class="yc-order-timeline-note" id="yc-order-note-<?= (int) $event['id'] ?>"><div class="yc-order-timeline-note-content">
  <div class="yc-order-timeline-note-heading"><strong>처리 메모</strong><time><?= $this->e($weekdayDate((int) $event['created_at'])) ?></time></div>
  <p data-yc-order-note-text><?= $this->e($event['note']) ?></p>
  <div class="yc-order-timeline-note-footer"><small class="muted"><?= $this->e($event['actor']) ?></small><div class="yc-order-timeline-actions"><button class="btn btn-sm" type="button" data-yc-edit-order-note="<?= (int) $event['id'] ?>">수정</button><form method="post" action="<?= $this->e($admin_url) ?>/orders/detail"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="delete-note"><input type="hidden" name="note_id" value="<?= (int) $event['id'] ?>"><button class="btn btn-sm" type="submit" aria-label="처리 메모 삭제">삭제</button></form></div></div>
</div></li>
<?php else: ?><li class="yc-order-timeline-status" id="yc-order-history-<?= (int) $event['id'] ?>"><div><div class="yc-order-timeline-status-heading"><strong><?= $this->e($statuses[$event['status']]) ?></strong><?php if ($event['can_undo']): ?><button class="btn btn-outline btn-xs" type="button" data-yc-undo-history="<?= (int) $event['id'] ?>" data-yc-undo-from="<?= $this->e($event['status']) ?>" data-yc-undo-label="<?= $this->e($statuses[$event['status']] . ' → ' . $statuses[$previous]) ?>">이 상태 되돌리기</button><?php endif ?></div><p class="muted"><?= $this->e($event['actor']) ?><?= $event['note'] !== '' ? ' · ' . $this->e($event['note']) : '' ?></p></div><span class="yc-order-timeline-status-meta"><time><?= $this->e($weekdayDate((int) $event['created_at'])) ?></time><button class="btn btn-ghost yc-order-timeline-add-button" type="button" data-yc-add-order-note="<?= (int) $event['id'] ?>" data-yc-add-note-label="<?= $this->e($event['add_note_label']) ?>" aria-label="<?= $this->e($event['add_note_label']) ?>" title="<?= $this->e($event['add_note_label']) ?>"><svg aria-hidden="true" viewBox="0 0 16 16" fill="none"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></button></span></li><?php endif ?>
<?php endforeach ?>
</ol>
<dialog class="yc-order-note-dialog" data-yc-add-order-note-dialog aria-labelledby="yc-add-order-note-dialog-title"<?= $errors !== [] && ($input['action'] ?? '') === 'add-note' ? ' data-yc-open-on-error' : '' ?>>
<form method="post" action="<?= $this->e($admin_url) ?>/orders/detail">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="add-note"><input type="hidden" name="after_history_id" value="<?= $this->e(is_string($input['after_history_id'] ?? null) ? $input['after_history_id'] : '') ?>" data-yc-add-note-after>
<h3 id="yc-add-order-note-dialog-title">처리 메모 추가</h3><p class="muted" data-yc-add-note-position>선택한 처리 이력 뒤에 추가합니다.</p>
<label class="yc-field" for="yc-add-order-note-text"><span>메모 내용</span></label>
<input class="input input-bordered" id="yc-add-order-note-text" type="text" name="note" value="<?= $this->e(($input['action'] ?? '') === 'add-note' && is_string($input['note'] ?? null) ? $input['note'] : '') ?>" maxlength="500" required data-yc-add-note-text>
<?php if (isset($errors['after_history_id']) && ($input['action'] ?? '') === 'add-note'): ?><p class="yc-inline-error"><?= $this->e($errors['after_history_id']) ?></p><?php endif ?>
<?php if (isset($errors['note']) && ($input['action'] ?? '') === 'add-note'): ?><p class="yc-inline-error"><?= $this->e($errors['note']) ?></p><?php endif ?>
<div class="yc-order-note-dialog-actions"><button class="btn" type="button" data-yc-close-add-order-note>취소</button><button class="btn btn-primary" type="submit">추가</button></div>
</form>
</dialog>
<dialog class="yc-order-note-dialog" data-yc-order-note-dialog aria-labelledby="yc-order-note-dialog-title"<?= $errors !== [] && ($input['action'] ?? '') === 'edit-note' ? ' data-yc-open-on-error' : '' ?>>
<form method="post" action="<?= $this->e($admin_url) ?>/orders/detail">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="edit-note"><input type="hidden" name="note_id" value="<?= (int) ($input['note_id'] ?? 0) ?>" data-yc-edit-note-id>
<h3 id="yc-order-note-dialog-title">처리 메모 수정</h3>
<label class="yc-field" for="yc-edit-order-note-text"><span>메모 내용</span></label>
<input class="input input-bordered" id="yc-edit-order-note-text" type="text" name="note" value="<?= $this->e(($input['action'] ?? '') === 'edit-note' && is_string($input['note'] ?? null) ? $input['note'] : '') ?>" maxlength="500" required data-yc-edit-note-text>
<?php if (isset($errors['note']) && ($input['action'] ?? '') === 'edit-note'): ?><p class="yc-inline-error"><?= $this->e($errors['note']) ?></p><?php endif ?>
<div class="yc-order-note-dialog-actions"><button class="btn" type="button" data-yc-close-order-note>취소</button><button class="btn btn-primary" type="submit">저장</button></div>
</form>
</dialog>
<?php if ($previous !== null): ?><dialog class="yc-order-note-dialog" data-yc-undo-status-dialog aria-labelledby="yc-undo-status-dialog-title"<?= $errors !== [] && ($input['action'] ?? '') === 'undo-status' ? ' data-yc-open-on-error' : '' ?>>
<form method="post" action="<?= $this->e($admin_url) ?>/orders/detail">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="undo-status"><input type="hidden" name="from" value="<?= $this->e(($input['action'] ?? '') === 'undo-status' && is_string($input['from'] ?? null) ? $input['from'] : $order['status']) ?>" data-yc-undo-from><input type="hidden" name="history_id" value="<?= $this->e(($input['action'] ?? '') === 'undo-status' && is_string($input['history_id'] ?? null) ? $input['history_id'] : (string) $undo_history_id) ?>" data-yc-undo-history-id>
<h3 id="yc-undo-status-dialog-title">처리 상태 되돌리기</h3><p class="muted" data-yc-undo-status-label><?= $this->e($statuses[$order['status']] . ' → ' . $statuses[$previous]) ?></p>
<p class="yc-help">처리 이력에 사유가 남습니다.<?php if ($order['status'] === 'completed'): ?> 판매수량도 되돌리며 실제 배송은 취소되지 않습니다.<?php elseif ($order['status'] === 'shipped'): ?> 택배사와 운송장 번호를 지웁니다. 실제 발송은 취소되지 않습니다.<?php elseif ($order['status'] === 'paid'): ?> 입금 확인 기록만 되돌리며 실제 입금은 반환되지 않습니다.<?php endif ?></p>
<label class="yc-field" for="yc-undo-status-reason"><span>되돌리는 사유</span></label>
<input class="input input-bordered" id="yc-undo-status-reason" name="reason" maxlength="400" value="<?= $this->e(($input['action'] ?? '') === 'undo-status' && is_string($input['reason'] ?? null) ? $input['reason'] : '') ?>" required data-yc-undo-reason>
<?php if (isset($errors['reason']) && ($input['action'] ?? '') === 'undo-status'): ?><p class="yc-inline-error"><?= $this->e($errors['reason']) ?></p><?php endif ?>
<div class="yc-order-note-dialog-actions"><button class="btn" type="button" data-yc-close-undo-status>취소</button><button class="btn btn-primary" type="submit">상태 되돌리기</button></div>
</form>
</dialog><?php endif ?></section></div>
<aside class="yc-order-summary"><div class="yc-section-heading"><h2>주문 처리</h2><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span></div>
<?php $this->insert('_totals', ['quote' => $order]) ?>
<section class="yc-panel yc-payment-panel"><h2>결제</h2>
<?php if ($order['payment_method'] === ''): ?><p class="yc-help">온라인 결제 내역이 없는 주문입니다. 결제 안내와 확인은 별도로 진행해 주세요.</p>
<?php else: ?><dl class="yc-detail-list">
<?php if ($is_pg): ?><div><dt>결제사</dt><dd><?= $this->e($payment_provider_label) ?></dd></div><?php endif ?>
<div><dt>수단</dt><dd><?= $this->e($payment_methods[$order['payment_method']] ?? $order['payment_method']) ?></dd></div>
<div><dt>상태</dt><dd><?= (int) $order['paid_at'] > 0 ? '결제 완료 · ' . $this->e($weekdayDate((int) $order['paid_at'])) . ' · ' . number_format((int) $order['paid_amount']) . '원' : '결제 대기' ?></dd></div>
<?php if ((int) $order['paid_at'] === 0 && (int) $order['pay_by'] > 0): ?><div><dt>결제 기한</dt><dd><?= $this->e($weekdayDate((int) $order['pay_by'])) ?></dd></div><?php endif ?>
<?php if (is_array($order['payment']['virtual_account'] ?? null)): $account = $order['payment']['virtual_account']; ?><div><dt>가상계좌</dt><dd><?= $this->e(($account['bank'] ?? '') . ' ' . ($account['account'] ?? '')) ?> · 예금주 <?= $this->e($account['holder'] ?? '') ?></dd></div><?php endif ?>
<?php if ((int) $order['refunded_amount'] > 0): ?><div><dt>환불</dt><dd><?= number_format((int) $order['refunded_amount']) ?>원</dd></div><?php endif ?>
<?php if ($order['payment_method'] === 'manual_transfer'): ?>
<div><dt>입금자명</dt><dd><?= $this->e(($order['payment']['depositor'] ?? '') !== '' ? $order['payment']['depositor'] : $order['buyer_name']) ?></dd></div>
<div><dt>안내 계좌</dt><dd>
  <div><?= $this->e($order['payment']['bank'] ?? '') ?></div>
  <div><?= $this->e($order['payment']['account'] ?? '') ?></div>
  <div><?= $this->e($order['payment']['holder'] ?? '') ?></div>
</dd></div>
<?php endif ?>
<?php if (($order['payment']['tid'] ?? '') !== ''): ?><div><dt>거래번호</dt><dd><?= $this->e($order['payment']['tid']) ?></dd></div><?php endif ?>
</dl>
<?php endif ?>
<?php if ($order['payment']['needs_review'] ?? false): ?><p class="alert alert-warning">결제사 승인이 남아 있지만 주문은 결제 완료가 아닙니다. 결제사에서 환불한 뒤 처리 메모를 남겨 주세요. 거래번호 <?= $this->e((string) ($order['payment']['tid'] ?? '')) ?></p><?php endif ?>
<?php if ($order['status'] === 'cancelled' && !$is_pg && (int) $order['paid_amount'] > (int) $order['refunded_amount']): ?><p class="alert alert-warning">취소된 주문의 입금액 반환 기록이 남아 있습니다. 실제 송금 반환 후 아래 환불에서 처리해 주세요.</p><?php endif ?>
<?php if ($order['status'] === 'pending' && ($order['payment_method'] === '' || $order['payment_method'] === 'manual_transfer')): ?><form method="post" action="<?= $this->e($admin_url) ?>/orders/detail"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="confirm-deposit"><button class="btn btn-primary btn-sm" type="submit">입금 확인</button></form><?php endif ?>
<?php if ($escrow_payment): ?><p class="yc-help">에스크로 계좌이체입니다. 발송 후 <?= $this->e($payment_provider_label) ?> 상점관리자에 배송 정보를 등록해 주세요.</p><?php endif ?>
<?php if ($is_pg): ?><?php if (!in_array($order['payment_provider'], ['toss', 'nicepay'], true) && in_array($order['payment_method'], ['virtual_account', 'mobile'], true) && (int) $order['paid_at'] > 0): ?><p class="yc-help">이 결제 수단의 환불은 이니시스 관리자에서 처리한 뒤 결제 조회로 결과를 반영합니다.</p><?php endif ?><form method="post" action="<?= $this->e($admin_url) ?>/orders/detail"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="sync"><button class="btn btn-outline btn-sm" type="submit">결제 조회</button></form><?php endif ?>
<?php $this->insert('admin/_refund_form') ?>
</section>
<?php if ($next !== []): ?><form class="yc-form-stack" method="post" action="<?= $this->e($admin_url) ?>/orders/detail" data-yc-order-status-form>
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="from" value="<?= $this->e($order['status']) ?>">
<label class="yc-field"><span>변경할 상태</span><select class="select select-bordered" name="status" required><?php foreach ($next as $status): ?><option value="<?= $this->e($status) ?>"<?= ($input['status'] ?? '') === $status ? ' selected' : '' ?>><?= $this->e($statuses[$status]) ?></option><?php endforeach ?></select></label>
<?php if (in_array('shipped', $next, true)): ?><div class="yc-form-stack" data-yc-shipping-fields>
<label class="yc-field"><span>택배사 <small>배송 중으로 변경할 때 필수</small></span><select class="select select-bordered" name="carrier" data-yc-carrier-select data-yc-other-value="<?= $this->e(\GnuCms\Shop\Settings::OTHER_CARRIER) ?>"><option value="">택배사 선택</option><?php foreach ($carriers as $carrier => $label): ?><option value="<?= $this->e($carrier) ?>"<?= $carrier_choice === $carrier ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?><option value="<?= $this->e(\GnuCms\Shop\Settings::OTHER_CARRIER) ?>"<?= $carrier_choice === \GnuCms\Shop\Settings::OTHER_CARRIER ? ' selected' : '' ?>>기타 · 직접 입력</option></select></label>
<label class="yc-field" data-yc-carrier-other<?= $carrier_choice === \GnuCms\Shop\Settings::OTHER_CARRIER ? '' : ' hidden' ?>><span>기타 택배사명</span><input class="input input-bordered" name="carrier_other" maxlength="100" value="<?= $this->e($carrier_other) ?>"<?= $carrier_choice === \GnuCms\Shop\Settings::OTHER_CARRIER ? ' required' : '' ?>></label>
<label class="yc-field"><span>운송장 번호</span><input class="input input-bordered" name="tracking_number" maxlength="100" value="<?= $this->e(is_string($input['tracking_number'] ?? null) ? $input['tracking_number'] : '') ?>"></label>
</div><?php endif ?>
<?php if (in_array('cancelled', $next, true)): ?><div class="yc-form-stack" data-yc-admin-cancel-fields><?php $this->insert('_cancel_reason_fields', ['cancel_reason_required' => false]) ?></div><?php endif ?>
<p class="yc-help">주문 취소를 선택하면 모든 상품의 재고가 복원됩니다. 배송이 시작된 주문은 취소할 수 없습니다.</p><button class="btn btn-primary" type="submit">주문 상태 변경</button>
</form><?php endif ?>
<a class="yc-continue" href="<?= $this->e($admin_url) ?>/orders">주문 목록으로</a></aside></div>
<?php $this->stop() ?>
