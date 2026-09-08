<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>주문 관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '주문 관리', 'description' => '접수된 주문을 확인하고 준비·배송·취소를 처리합니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<div class="yc-admin">
<section class="card"><div class="card-body">
<form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/orders"><label>주문 상태 <select class="select select-bordered" name="status"><option value="">전체 상태</option><?php foreach ($statuses as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $status_filter === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?></select></label><label>주문 검색 <input class="input input-bordered" name="q" value="<?= $this->e($q) ?>" maxlength="100" placeholder="주문번호 또는 주문자"></label><button class="btn btn-primary" type="submit">검색</button></form>
<p class="muted">전체 <?= $list['total'] ?>건 · 주문 상태는 결제 완료를 의미하지 않습니다.</p>
<div class="overflow-x-auto"><table class="table yc-orders-table"><thead><tr><th>주문번호 / 접수일</th><th>주문자</th><th>주문 상태</th><th>주문 금액</th><th>상세</th></tr></thead><tbody>
<?php foreach ($list['items'] as $order): ?><tr><td data-label="주문번호"><a href="<?= $this->e($admin_url) ?>/orders/detail?id=<?= (int) $order['id'] ?>"><strong><?= $this->e($order['number']) ?></strong></a><br><small class="muted"><?= date('Y.m.d H:i', (int) $order['created_at']) ?></small></td><td data-label="주문자"><?= $this->e($order['buyer_name']) ?><br><small class="muted"><?= $order['user_id'] === null ? '비회원' : '회원' ?></small></td><td data-label="상태"><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span></td><td data-label="금액"><?= number_format((int) $order['total']) ?>원</td><td data-label="관리"><a class="btn btn-sm" href="<?= $this->e($admin_url) ?>/orders/detail?id=<?= (int) $order['id'] ?>">상세보기</a></td></tr><?php endforeach ?>
<?php if ($list['items'] === []): ?><tr><td colspan="5" class="yc-empty">조건에 맞는 주문이 없습니다.</td></tr><?php endif ?>
</tbody></table></div>
<?php $this->insert('_pager', ['page_url' => fn(int $p): string => $admin_url . '/orders?' . http_build_query(['status' => $status_filter, 'q' => $q, 'page' => $p])]) ?>
</div></section></div>
<?php $this->stop() ?>
