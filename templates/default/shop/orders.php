<?php $this->layout('layout') ?>
<?php $this->start('title') ?>주문 조회 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<?php $weekdayNames = ['일', '월', '화', '수', '목', '금', '토']; $weekdayDate = fn (int $timestamp, string $format): string => $this->date($timestamp, $format) . ' (' . $weekdayNames[(int) $this->date($timestamp, 'w')] . ')'; ?>
<div class="yc-shop">
<?php $this->insert('_header') ?>
<div class="yc-page-heading"><div><h1 class="yc-title">주문 조회</h1><p class="muted">주문부터 배송까지, 진행 상황을 확인하세요.</p></div></div>
<?php $this->insert('_feedback') ?>
<section class="yc-panel"><h2>나의 주문 <small><?= $list['total'] ?>건</small></h2><?php if ($list['items'] === []): ?><p class="yc-empty">아직 주문 내역이 없습니다.</p><?php endif ?>
<?php foreach ($list['items'] as $order): ?><a class="yc-order-list-row" href="<?= $this->e($url) ?>/order?ref=<?= $this->e($order_ref($order)) ?>"><div class="yc-order-list-meta"><div><small class="muted"><?= $this->e($weekdayDate((int) $order['created_at'], 'Y.m.d H:i:s')) ?></small><strong><?= $this->e($order['number']) ?></strong><?php if ($order['payment_environment'] === 'test'): ?><span class="yc-test-badge">테스트 결제</span><?php endif ?></div><?php if (($order['product_summary'] ?? []) !== []): ?><ul class="yc-order-list-products"><?php foreach (array_slice($order['product_summary'], 0, 2) as $product): ?><li><?= $this->e($product) ?></li><?php endforeach ?><?php if (($order['product_count'] ?? 0) > 2): ?><li>외 <?= (int) $order['product_count'] - 2 ?>개 상품</li><?php endif ?></ul><?php endif ?></div><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span><strong><?= number_format((int) $order['total']) ?>원</strong><?= $this->icon('chevron-right', 20) ?></a><?php endforeach ?>
<?php $this->insert('_pager', ['page_url' => fn(int $p): string => $url . '/orders?page=' . $p]) ?></section>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
