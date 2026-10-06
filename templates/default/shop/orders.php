<?php $this->layout('layout') ?>
<?php $this->start('title') ?>주문 조회 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<?php
$weekdayNames = ['일', '월', '화', '수', '목', '금', '토'];
$weekdayDate = fn (int $timestamp): string => $this->date($timestamp) . ' (' . $weekdayNames[(int) $this->date($timestamp, 'w')] . ')';
?>
<div class="yc-shop">
<?php $this->insert('_header') ?>
<div class="yc-page-heading"><div><h1 class="yc-title">주문 조회</h1><p class="muted">주문부터 배송까지, 진행 상황을 확인하세요.</p></div></div>
<?php $this->insert('_feedback') ?>
<section class="yc-panel"><h2>나의 주문 <small><?= $list['total'] ?>건</small></h2>
<form class="yc-order-search" method="get" action="<?= $this->e($url) ?>/orders">
  <input class="input input-bordered" type="search" name="q" value="<?= $this->e($q) ?>" maxlength="100" placeholder="주문번호·상품명·이름·휴대폰" aria-label="주문번호, 상품명, 주문자명, 받는 분, 휴대폰번호 검색">
  <button class="yc-button yc-button-primary" type="submit">검색</button>
  <?php if ($q !== ''): ?><a class="yc-button" href="<?= $this->e($url) ?>/orders">전체 보기</a><?php endif ?>
</form>
<?php if ($list['items'] === []): ?><p class="yc-empty"><?= $q === '' ? '아직 주문 내역이 없습니다.' : '검색 결과가 없습니다.' ?></p><?php endif ?>
<?php foreach ($list['items'] as $order): $products = $order['product_summary'] ?? []; $remainingProducts = max(0, (int) ($order['product_count'] ?? count($products)) - 1); ?>
<a class="yc-order-list-row" href="<?= $this->e($url) ?>/order?ref=<?= $this->e($order_ref($order)) ?>">
  <div class="yc-order-list-meta">
    <div class="yc-order-list-primary"><strong class="yc-order-list-title"><?= $this->e($products[0] ?? '주문 상품') ?><?php if ($remainingProducts > 0): ?> <span>외 <?= $remainingProducts ?>개</span><?php endif ?></strong><strong class="yc-order-list-total"><?= number_format((int) $order['total']) ?>원</strong><?= $this->icon('chevron-right', 20) ?></div>
    <div class="yc-order-list-secondary"><span class="yc-order-list-number"><?= $this->e($order['number']) ?></span><span class="yc-order-list-date"><?= $this->e($weekdayDate((int) $order['created_at'])) ?></span><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span><span class="yc-order-list-method"><span>결제</span><strong><?= $this->e($method_labels[$order['payment_method']] ?? ($order['payment_method'] !== '' ? $order['payment_method'] : '미지정')) ?></strong></span><span class="yc-order-list-recipient"><span>받는 분</span><strong><?= $this->e($order['recipient']) ?></strong></span><?php if ($order['payment_environment'] === 'test'): ?><span class="yc-test-badge">테스트 결제</span><?php endif ?></div>
  </div>
</a>
<?php endforeach ?>
<?php $this->insert('_pager', ['page_url' => fn(int $p): string => $url . '/orders?' . http_build_query($q === '' ? ['page' => $p] : ['q' => $q, 'page' => $p])]) ?></section>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
