<?php $this->layout('layout') ?>
<?php $this->start('title') ?>주문 조회 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>modules/youngcart<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
<?php $this->insert('_header') ?>
<div class="yc-page-heading"><div><h1 class="yc-title">주문 조회</h1><p class="muted">주문부터 배송까지, 진행 상황을 확인하세요.</p></div></div>
<?php $this->insert('_feedback') ?>
<?php if ($user_id !== null): ?><section class="yc-panel"><h2>나의 주문 <small><?= $list['total'] ?>건</small></h2><?php if ($list['items'] === []): ?><p class="yc-empty">아직 주문 내역이 없습니다.</p><?php endif ?>
<?php foreach ($list['items'] as $order): ?><a class="yc-order-list-row" href="<?= $this->e($url) ?>/order?number=<?= $this->e($order['number']) ?>"><div><small class="muted"><?= date('Y.m.d', (int) $order['created_at']) ?></small><strong><?= $this->e($order['number']) ?></strong></div><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span><strong><?= number_format((int) $order['total']) ?>원</strong><?= $this->icon('chevron-right', 20) ?></a><?php endforeach ?>
<?php $this->insert('_pager', ['page_url' => fn(int $p): string => $url . '/orders?page=' . $p]) ?></section>
<?php else: ?><div class="yc-guest-banner"><div><strong>회원 주문은 로그인 후 확인해 주세요</strong><p>회원에게는 자신의 주문 목록이 표시됩니다.</p></div><a class="yc-button yc-button-small" href="<?= $this->e($base) ?>/login?url=<?= $this->e(rawurlencode($url . '/orders')) ?>">로그인</a></div><?php endif ?>
<section class="yc-panel yc-lookup"><span class="yc-kicker">GUEST ORDER</span><h2>비회원 주문 조회</h2><p class="muted">주문할 때 입력한 이메일과 비밀번호를 입력해 주세요.</p>
<form method="post" action="<?= $this->e($url) ?>/orders" class="yc-form-stack"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
<label class="yc-field"><span>주문번호</span><input class="input input-bordered" name="number" value="<?= $this->e($input['number'] ?? '') ?>" maxlength="32" placeholder="주문 완료 화면의 주문번호" required autocomplete="off"></label>
<label class="yc-field"><span>이메일</span><input class="input input-bordered" type="email" name="email" value="<?= $this->e($input['email'] ?? '') ?>" maxlength="191" required autocomplete="email"></label>
<label class="yc-field"><span>주문 조회 비밀번호</span><input class="input input-bordered" type="password" name="password" maxlength="72" required autocomplete="current-password"></label>
<button class="yc-button yc-button-primary" type="submit">주문 조회하기</button></form></section>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
