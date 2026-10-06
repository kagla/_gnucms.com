<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>주문 관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php
$weekdayNames = ['일', '월', '화', '수', '목', '금', '토'];
$weekdayDate = fn (int $timestamp): string => $this->date($timestamp) . ' (' . $weekdayNames[(int) $this->date($timestamp, 'w')] . ')';
$returnFields = ['return_status' => $status_filter, 'return_q' => $q, 'return_page' => (string) $page_number];
$failedId = $errors !== [] && is_scalar($input['id'] ?? null) ? (int) $input['id'] : 0;
$failedStatus = $errors !== [] && is_string($input['status'] ?? null) ? $input['status'] : '';
$failedAction = $errors !== [] && is_string($input['action'] ?? null) ? $input['action'] : '';
$failedNumber = '';
foreach ($list['items'] as $item) {
    if ((int) $item['id'] === $failedId) $failedNumber = (string) $item['number'];
}
if ($failedNumber === '' && $failedId > 0) $failedNumber = '#' . $failedId;
?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '주문 관리', 'description' => '접수된 주문을 확인하고 준비·배송·취소를 처리합니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<div class="yc-admin">
<nav class="tabs yc-status-filters" aria-label="주문 상태별 보기">
<?php foreach (['' => '전체 주문'] + $statuses as $key => $label): ?><a href="<?= $this->e($admin_url . '/orders?' . http_build_query(['status' => $key, 'q' => $q])) ?>"<?= $status_filter === $key ? ' aria-current="page"' : '' ?>><?= $this->e($label) ?></a><?php endforeach ?>
</nav>
<form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/orders"><input type="hidden" name="status" value="<?= $this->e($status_filter) ?>"><label class="yc-filter-query">주문 검색<input class="input input-bordered" type="search" name="q" value="<?= $this->e($q) ?>" maxlength="100" placeholder="주문번호·주문자·받는 분"></label><button class="btn btn-primary" type="submit"><?= $this->icon('search', 16) ?> 검색</button><?php if ($q !== ''): ?><a class="btn" href="<?= $this->e($admin_url . '/orders?' . http_build_query(['status' => $status_filter])) ?>">검색 초기화</a><?php endif ?></form>
<div class="yc-info-note"><?= $this->icon('info', 16) ?><span>결제 상태를 확인한 뒤 주문을 처리해 주세요. 환불과 상태 되돌리기는 주문 상세에서 처리합니다.</span></div>
<section class="yc-list-panel"><div class="yc-list-heading"><h2><?= $this->e($statuses[$status_filter] ?? '전체 주문') ?> <span><?= number_format($list['total']) ?></span></h2><p>최근 접수순</p></div>
<div class="overflow-x-auto"><table class="table yc-orders-table"><thead><tr><th>주문번호<br>주문일시</th><th class="yc-order-overview"><div class="yc-order-overview-header"><div class="yc-order-people"><span>주문자</span><span>받는 분</span></div><span>상품</span></div></th><th class="yc-order-amount">주문 금액</th><th>결제</th><th>주문 상태</th><th>상세 / 처리</th></tr></thead><tbody>
<?php foreach ($list['items'] as $order): ?>
<?php
$current = (string) $order['status'];
$method = (string) $order['payment_method'];
$next = match ($current) { 'paid' => 'confirmed', 'shipped' => 'completed', default => '' };
$canDeposit = $current === 'pending' && ($method === '' || $method === 'manual_transfer');
$canShip = $current === 'confirmed';
$canCancel = in_array($current, ['pending', 'paid', 'confirmed'], true)
    && (!in_array($method, \GnuCms\Shop\Commerce\Orders::PG_METHODS, true)
        || ($current !== 'pending' && (int) $order['paid_amount'] > 0 && (int) $order['refunded_amount'] >= (int) $order['paid_amount']));
$carrier = (string) ($order['carrier'] ?? '');
$trackingNumber = (string) ($order['tracking_number'] ?? '');
$showShipping = in_array($current, ['shipped', 'completed'], true) && ($carrier !== '' || $trackingNumber !== '');
$trackingUrl = $showShipping ? \GnuCms\Shop\Settings::trackingUrl($carrier, $trackingNumber) : null;
?>
<tr><td data-label="주문번호"><a href="<?= $this->e($admin_url) ?>/orders/detail?id=<?= (int) $order['id'] ?>"><strong><?= $this->e($order['number']) ?></strong></a><?php if ($order['payment_environment'] === 'test'): ?> <span class="yc-test-badge">테스트 결제</span><?php endif ?><br><small class="muted"><?= $this->e($weekdayDate((int) $order['created_at'])) ?></small></td>
<td class="yc-order-overview"><div class="yc-order-overview-content"><div class="yc-order-people"><span title="<?= $this->e($order['buyer_name']) ?>"><?= $this->e($order['buyer_name']) ?></span><span title="<?= $this->e($order['recipient']) ?>"><?= $this->e($order['recipient']) ?></span></div><div class="yc-order-product-summary"><?php if ($order['representative_product'] !== ''): ?><span class="yc-order-product-name" title="<?= $this->e($order['representative_product']) ?>"><?= $this->e($order['representative_product']) ?></span><?php if ((int) $order['product_count'] > 1): ?><span class="yc-order-product-more">외 <?= (int) $order['product_count'] - 1 ?>개</span><?php endif ?><?php else: ?><span>—</span><?php endif ?></div></div></td>
<td class="yc-order-amount" data-label="금액"><span><?= number_format((int) $order['total']) ?>원</span></td>
<td data-label="결제"><?= $method === '' ? '—' : $this->e($payment_methods[$method] ?? $method) . ((int) $order['paid_at'] > 0 ? ' · 완료' : ' · 대기') ?></td>
<td data-label="상태"><div class="yc-order-status-cell"><span class="yc-status" data-status="<?= $this->e($current) ?>"><?= $this->e($statuses[$current]) ?></span>
<?php if ($showShipping): ?><div class="yc-order-shipping"><?php if ($carrier !== ''): ?><span><?= $this->e($carrier) ?></span><?php endif ?><?php if ($trackingNumber !== ''): ?><?php if ($trackingUrl !== null): ?><a href="<?= $this->e($trackingUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= $this->e($carrier . ' 운송장 ' . $trackingNumber . ' 배송조회, 새 창') ?>" title="배송조회"><?= $this->e($trackingNumber) ?> <span aria-hidden="true">↗</span></a><?php else: ?><span><?= $this->e($trackingNumber) ?></span><?php endif ?><?php endif ?></div><?php endif ?>
</div></td>
<td data-label="상세 / 처리"><div class="yc-order-detail-cell"><a class="btn btn-sm" href="<?= $this->e($admin_url) ?>/orders/detail?id=<?= (int) $order['id'] ?>">상세보기</a><?php if ($next !== '' || $canDeposit || $canShip || $canCancel): ?><div class="yc-order-list-actions">
<?php if ($next !== ''): ?><form method="post" action="<?= $this->e($admin_url) ?>/orders">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="action" value="transition"><input type="hidden" name="from" value="<?= $this->e($current) ?>"><input type="hidden" name="status" value="<?= $this->e($next) ?>">
<?php foreach ($returnFields as $name => $value): ?><input type="hidden" name="<?= $name ?>" value="<?= $this->e($value) ?>"><?php endforeach ?>
<button class="btn btn-primary btn-sm" type="submit"<?= $next === 'completed' ? ' data-yc-confirm-complete="' . $this->e($order['number']) . '"' : '' ?>><?= $this->e($statuses[$next]) ?></button></form><?php endif ?>
<?php if ($canDeposit): ?><button class="btn btn-outline btn-sm yc-order-list-trigger" type="button" data-yc-quick-action="deposit" data-yc-order-id="<?= (int) $order['id'] ?>" data-yc-order-from="<?= $this->e($current) ?>" data-yc-order-number="<?= $this->e($order['number']) ?>">입금 확인</button><?php endif ?>
<?php if ($canShip): ?><button class="btn btn-primary btn-sm yc-order-list-trigger" type="button" data-yc-quick-action="shipped" data-yc-order-id="<?= (int) $order['id'] ?>" data-yc-order-from="<?= $this->e($current) ?>" data-yc-order-number="<?= $this->e($order['number']) ?>">배송 중</button><?php endif ?>
<?php if ($canCancel): ?><button class="btn btn-outline btn-sm yc-order-list-trigger" type="button" data-yc-quick-action="cancelled" data-yc-order-id="<?= (int) $order['id'] ?>" data-yc-order-from="<?= $this->e($current) ?>" data-yc-order-number="<?= $this->e($order['number']) ?>" data-yc-needs-return="<?= $current !== 'pending' && !in_array($method, \GnuCms\Shop\Commerce\Orders::PG_METHODS, true) && (int) $order['paid_amount'] > (int) $order['refunded_amount'] ? '1' : '0' ?>">주문 취소</button><?php endif ?>
</div><?php endif ?></div></td></tr>
<?php endforeach ?>
<?php if ($list['items'] === []): ?><tr><td colspan="6" class="yc-empty"><div class="yc-admin-empty">조건에 맞는 주문이 없습니다.</div></td></tr><?php endif ?>
</tbody></table></div>
</section>
<?php $this->insert('_pager', ['page_url' => fn(int $p): string => $admin_url . '/orders?' . http_build_query(['status' => $status_filter, 'q' => $q, 'page' => $p])]) ?>

<dialog class="yc-order-note-dialog yc-quick-order-dialog" data-yc-quick-dialog="deposit" aria-labelledby="yc-quick-deposit-title"<?= $failedAction === 'confirm-deposit' ? ' data-yc-open-on-error' : '' ?>>
<form method="post" action="<?= $this->e($admin_url) ?>/orders">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= $failedAction === 'confirm-deposit' ? $failedId : 0 ?>" data-yc-quick-id><input type="hidden" name="action" value="confirm-deposit">
<?php foreach ($returnFields as $name => $value): ?><input type="hidden" name="<?= $name ?>" value="<?= $this->e($value) ?>"><?php endforeach ?>
<h3 id="yc-quick-deposit-title">입금 확인</h3><p class="yc-help" data-yc-quick-label><?= $this->e($failedNumber) ?></p>
<?php if ($failedAction === 'confirm-deposit'): ?><div class="alert alert-error" role="alert" data-yc-quick-errors><?php foreach ($errors as $message): ?><p><?= $this->e($message) ?></p><?php endforeach ?></div><?php endif ?>
<p>실제 입금 내역과 주문 금액을 확인했습니까? 확인하면 주문이 결제 완료로 바뀝니다.</p>
<div class="yc-order-note-dialog-actions"><button class="btn" type="button" data-yc-close-quick-dialog>닫기</button><button class="btn btn-primary" type="submit">입금 확인</button></div>
</form></dialog>

<dialog class="yc-order-note-dialog yc-quick-order-dialog" data-yc-quick-dialog="shipped" aria-labelledby="yc-quick-ship-title"<?= $failedAction === 'transition' && $failedStatus === 'shipped' ? ' data-yc-open-on-error' : '' ?>>
<form method="post" action="<?= $this->e($admin_url) ?>/orders">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= $failedStatus === 'shipped' ? $failedId : 0 ?>" data-yc-quick-id><input type="hidden" name="action" value="transition"><input type="hidden" name="from" value="<?= $this->e($failedStatus === 'shipped' && is_string($input['from'] ?? null) ? $input['from'] : 'confirmed') ?>" data-yc-quick-from><input type="hidden" name="status" value="shipped">
<?php foreach ($returnFields as $name => $value): ?><input type="hidden" name="<?= $name ?>" value="<?= $this->e($value) ?>"><?php endforeach ?>
<h3 id="yc-quick-ship-title">배송 중으로 변경</h3><p class="yc-help" data-yc-quick-label><?= $this->e($failedNumber) ?></p>
<?php if ($failedAction === 'transition' && $failedStatus === 'shipped'): ?><div class="alert alert-error" role="alert" data-yc-quick-errors><?php foreach ($errors as $message): ?><p><?= $this->e($message) ?></p><?php endforeach ?></div><?php endif ?>
<label class="yc-field"><span>택배사</span><select class="select select-bordered" name="carrier" required data-yc-carrier-select data-yc-other-value="<?= $this->e(\GnuCms\Shop\Settings::OTHER_CARRIER) ?>" data-yc-default-carrier="<?= $this->e($default_carrier) ?>"><option value="">택배사 선택</option><?php foreach ($carriers as $carrier => $label): ?><option value="<?= $this->e($carrier) ?>"<?= ($failedStatus === 'shipped' ? ($input['carrier'] ?? '') : $default_carrier) === $carrier ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?><option value="<?= $this->e(\GnuCms\Shop\Settings::OTHER_CARRIER) ?>"<?= ($failedStatus === 'shipped' ? ($input['carrier'] ?? '') : $default_carrier) === \GnuCms\Shop\Settings::OTHER_CARRIER ? ' selected' : '' ?>>기타 · 직접 입력</option></select></label>
<label class="yc-field" data-yc-carrier-other><span>기타 택배사명</span><input class="input input-bordered" name="carrier_other" maxlength="100" value="<?= $this->e($failedStatus === 'shipped' && is_string($input['carrier_other'] ?? null) ? $input['carrier_other'] : '') ?>"></label>
<label class="yc-field"><span>운송장 번호</span><input class="input input-bordered" name="tracking_number" maxlength="100" value="<?= $this->e($failedStatus === 'shipped' && is_string($input['tracking_number'] ?? null) ? $input['tracking_number'] : '') ?>" required></label>
<div class="yc-order-note-dialog-actions"><button class="btn" type="button" data-yc-close-quick-dialog>닫기</button><button class="btn btn-primary" type="submit">배송 중으로 변경</button></div>
</form></dialog>

<dialog class="yc-order-note-dialog yc-quick-order-dialog" data-yc-quick-dialog="cancelled" aria-labelledby="yc-quick-cancel-title"<?= $failedAction === 'transition' && $failedStatus === 'cancelled' ? ' data-yc-open-on-error' : '' ?>>
<form method="post" action="<?= $this->e($admin_url) ?>/orders">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= $failedStatus === 'cancelled' ? $failedId : 0 ?>" data-yc-quick-id><input type="hidden" name="action" value="transition"><input type="hidden" name="from" value="<?= $this->e($failedStatus === 'cancelled' && is_string($input['from'] ?? null) ? $input['from'] : '') ?>" data-yc-quick-from><input type="hidden" name="status" value="cancelled">
<?php foreach ($returnFields as $name => $value): ?><input type="hidden" name="<?= $name ?>" value="<?= $this->e($value) ?>"><?php endforeach ?>
<h3 id="yc-quick-cancel-title">주문 취소</h3><p class="yc-help" data-yc-quick-label><?= $this->e($failedNumber) ?></p>
<?php if ($failedAction === 'transition' && $failedStatus === 'cancelled'): ?><div class="alert alert-error" role="alert" data-yc-quick-errors><?php foreach ($errors as $message): ?><p><?= $this->e($message) ?></p><?php endforeach ?></div><?php endif ?>
<p class="yc-help">취소하면 상품 재고가 복원됩니다.</p><p class="alert alert-warning" data-yc-return-warning hidden>입금된 금액은 자동으로 반환되지 않습니다. 실제 반환과 환불 기록은 별도로 처리해 주세요.</p>
<?php $this->insert('_cancel_reason_fields', ['cancel_reason_required' => true]) ?>
<div class="yc-order-note-dialog-actions"><button class="btn" type="button" data-yc-close-quick-dialog>닫기</button><button class="btn btn-primary" type="submit">주문 취소</button></div>
</form></dialog>
</div>
<?php $this->stop() ?>
