<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>주문 관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '주문 관리', 'description' => '접수된 주문을 확인하고 준비·배송·취소를 처리합니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<div class="yc-admin">
<nav class="tabs yc-status-filters" aria-label="주문 상태별 보기">
<?php foreach (['' => '전체 주문'] + $statuses as $key => $label): ?><a href="<?= $this->e($admin_url . '/orders?' . http_build_query(['status' => $key, 'q' => $q])) ?>"<?= $status_filter === $key ? ' aria-current="page"' : '' ?>><?= $this->e($label) ?></a><?php endforeach ?>
</nav>
<form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/orders"><input type="hidden" name="status" value="<?= $this->e($status_filter) ?>"><label class="yc-filter-query">주문 검색<input class="input input-bordered" type="search" name="q" value="<?= $this->e($q) ?>" maxlength="100" placeholder="주문번호 또는 주문자 이름"></label><button class="btn btn-primary" type="submit"><?= $this->icon('search', 16) ?> 검색</button><?php if ($q !== ''): ?><a class="btn" href="<?= $this->e($admin_url . '/orders?' . http_build_query(['status' => $status_filter])) ?>">검색 초기화</a><?php endif ?></form>
<div class="yc-info-note"><?= $this->icon('info', 16) ?><span>결제 완료 상태의 주문부터 상품 준비와 배송을 진행해 주세요. 무통장입금은 주문 상세에서 입금을 확인합니다.</span></div>
<section class="yc-list-panel"><div class="yc-list-heading"><h2><?= $this->e($statuses[$status_filter] ?? '전체 주문') ?> <span><?= number_format($list['total']) ?></span></h2><p>최근 접수순</p></div>
<div class="overflow-x-auto"><table class="table yc-orders-table"><thead><tr><th>주문번호 / 접수일</th><th>주문자</th><th>주문 상태</th><th>결제</th><th>주문 금액</th><th>상세</th></tr></thead><tbody>
<?php foreach ($list['items'] as $order): ?><tr><td data-label="주문번호"><a href="<?= $this->e($admin_url) ?>/orders/detail?id=<?= (int) $order['id'] ?>"><strong><?= $this->e($order['number']) ?></strong></a><br><small class="muted"><?= date('Y.m.d H:i', (int) $order['created_at']) ?></small></td><td data-label="주문자"><?= $this->e($order['buyer_name']) ?><br><small class="muted"><?= $order['user_id'] === null ? '비회원' : '회원' ?></small></td><td data-label="상태"><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span></td><td data-label="결제"><?= $order['payment_method'] === '' ? '—' : $this->e($payment_methods[$order['payment_method']] ?? $order['payment_method']) . ((int) $order['paid_at'] > 0 ? ' · 완료' : ' · 대기') ?></td><td data-label="금액"><?= number_format((int) $order['total']) ?>원</td><td data-label="관리"><a class="btn btn-sm" href="<?= $this->e($admin_url) ?>/orders/detail?id=<?= (int) $order['id'] ?>">상세보기</a></td></tr><?php endforeach ?>
<?php if ($list['items'] === []): ?><tr><td colspan="6" class="yc-empty"><div class="yc-admin-empty">조건에 맞는 주문이 없습니다.</div></td></tr><?php endif ?>
</tbody></table></div>
</section>
<?php $this->insert('_pager', ['page_url' => fn(int $p): string => $admin_url . '/orders?' . http_build_query(['status' => $status_filter, 'q' => $q, 'page' => $p])]) ?>
</div>
<?php $this->stop() ?>
