<?php $this->layout('layout') ?>
<?php $this->start('title') ?>장바구니 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
<?php $this->insert('_header'); $itemGroups = \GnuCms\Shop\Commerce\ItemGroups::build($quote['items']); ?>
<div class="yc-page-heading"><h1 class="yc-title">장바구니 <span><?= $itemGroups['count'] ?></span></h1><?php $this->insert('_steps') ?></div>
<?php $this->insert('_feedback') ?>
<?php if ($quote['items'] === []): ?><section class="yc-empty-state"><span class="yc-empty-icon"><?= $this->icon('gift', 36) ?></span><h2>장바구니가 비어 있어요</h2><p>마음에 드는 상품을 담고 한 번에 주문해 보세요.</p><a class="yc-button yc-button-primary" href="<?= $this->e($url) ?>">쇼핑하러 가기</a></section>
<?php else: ?><div class="yc-commerce-grid">
<form method="post" action="<?= $this->e($url) ?>/cart" class="yc-cart-items">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <div class="yc-cart-toolbar"><strong>담은 상품 <?= $itemGroups['count'] ?>개 항목</strong><button class="yc-button yc-button-small" type="submit">수량 변경 저장</button></div>
  <?php foreach ($itemGroups['groups'] as $productId => $group): ?>
  <section class="yc-cart-product" data-yc-cart-product="<?= (int) $productId ?>" aria-label="<?= $this->e(($group['items'][0] ?? $group['extras'][0])['name']) ?>">
    <?php foreach ($group['items'] as $item): ?><?php $this->insert('_cart_item', ['item' => $item, 'extra' => false]) ?><?php endforeach ?>
    <?php if ($group['extras'] !== []): ?><div class="yc-cart-extras" data-yc-cart-extras><h3><?= count($group['items']) > 1 ? '이 상품의 공통 추가 구성' : '추가 구성' ?></h3>
      <?php foreach ($group['extras'] as $item): ?><?php $this->insert('_cart_item', ['item' => $item, 'extra' => true]) ?><?php endforeach ?>
    </div><?php endif ?>
  </section>
  <?php endforeach ?>
  <p class="yc-help">수량 변경 후 저장해 주세요. 장바구니에 담은 상품은 주문 접수 전까지 재고가 확보되지 않습니다.</p>
</form>
<aside class="yc-order-summary"><h2>주문 예상 금액</h2><?php $this->insert('_totals', ['total_label' => '예상 주문 금액']) ?><p class="yc-help">배송 방식은 주문서에서 확인할 수 있습니다.</p>
<?php if ($quote['errors'] === []): ?><a class="yc-button yc-button-primary yc-button-block" href="<?= $this->e($url) ?>/checkout">주문서 작성 <?= $this->icon('arrow-right', 18) ?></a><?php else: ?><p class="yc-inline-error">상품과 수량을 확인한 뒤 주문해 주세요.</p><?php endif ?>
<a class="yc-continue" href="<?= $this->e($url) ?>">계속 쇼핑하기</a></aside>
</div><?php endif ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
