<?php $this->layout('layout') ?>
<?php $this->start('title') ?>장바구니 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page yc-cart-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
<?php $this->insert('_header'); $itemGroups = \GnuCms\Shop\Commerce\ItemGroups::build($quote['items']); ?>
<div class="yc-page-heading"><h1 class="yc-title">장바구니 <span><?= count($itemGroups['groups']) ?></span></h1><?php $this->insert('_steps') ?></div>
<?php $this->insert('_feedback') ?>
<?php if ($quote['items'] === []): ?><section class="yc-empty-state"><span class="yc-empty-icon"><?= $this->icon('gift', 36) ?></span><h2>장바구니가 비어 있어요</h2><p>마음에 드는 상품을 담고 한 번에 주문해 보세요.</p><a class="yc-button yc-button-primary" href="<?= $this->e($url) ?>">쇼핑하러 가기</a></section>
<?php else: ?><form method="post" action="<?= $this->e($url) ?>/cart" class="yc-cart-form" id="yc-cart-form" data-yc-cart-form>
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <div class="yc-cart-select-toolbar">
    <label class="yc-cart-checkbox yc-cart-select-all"><input type="checkbox" checked data-yc-cart-select-all><span>전체 선택</span></label>
    <button class="yc-button yc-button-small" type="submit" name="cart_action" value="delete_selected" data-yc-cart-delete-selected>× 선택 삭제</button>
    <noscript><button class="yc-button yc-button-small" type="submit" name="cart_action" value="update">수량 변경 저장</button></noscript>
  </div>
  <p class="yc-inline-error" data-yc-cart-save-status role="status" aria-live="polite" hidden></p>
<div class="yc-commerce-grid">
<div class="yc-cart-items">
  <?php foreach ($itemGroups['groups'] as $productId => $group): ?>
  <?php $productItem = $group['items'][0] ?? $group['extras'][0]; ?>
  <section class="yc-cart-product" data-yc-cart-product="<?= (int) $productId ?>" aria-label="<?= $this->e($productItem['name']) ?>">
    <header class="yc-cart-product-heading">
      <label class="yc-cart-checkbox yc-cart-product-check"><input type="checkbox" name="selected_products[]" value="<?= (int) $productId ?>" checked data-yc-cart-select><span class="sr-only"><?= $this->e($productItem['name']) ?> 선택</span></label>
      <a class="yc-cart-image" href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($productItem['code'])) ?>"><?php if ($productItem['image'] !== null): ?><img src="<?= $this->e($img($productItem['product_id'], $productItem['image'], 'thumb')) ?>" alt="<?= $this->e($productItem['name']) ?>"><?php else: ?><span class="yc-noimage"><?= $this->icon('gift', 25) ?></span><?php endif ?></a>
      <div class="yc-cart-product-info"><a href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($productItem['code'])) ?>"><h2><?= $this->e($productItem['name']) ?></h2></a><span><?= count($group['items']) ?>개 선택 구성</span></div>
    </header>
    <div class="yc-cart-options" aria-label="선택옵션">
      <?php foreach ($group['items'] as $item): ?><?php $this->insert('_cart_item', ['item' => $item, 'extra' => false]) ?><?php endforeach ?>
    </div>
    <?php if ($group['extras'] !== []): ?><div class="yc-cart-extras" data-yc-cart-extras><h3>추가옵션</h3>
      <?php foreach ($group['extras'] as $item): ?><?php $this->insert('_cart_item', ['item' => $item, 'extra' => true]) ?><?php endforeach ?>
    </div><?php endif ?>
  </section>
  <?php endforeach ?>
</div>
<aside class="yc-order-summary"><h2>주문 예상 금액</h2><?php $this->insert('_totals', ['total_label' => '예상 주문 금액', 'cart_preview' => true]) ?><p class="yc-help">배송 방식은 주문서에서 확인할 수 있습니다.</p>
<button class="yc-button yc-button-primary yc-button-block" type="submit" name="cart_action" value="checkout_selected" data-yc-cart-checkout data-yc-cart-valid="<?= $quote['errors'] === [] ? 'true' : 'false' ?>"<?= $quote['errors'] !== [] ? ' disabled' : '' ?>>주문하기 <span data-yc-cart-selected-count><?= count($itemGroups['groups']) ?></span> <?= $this->icon('arrow-right', 18) ?></button>
<a class="yc-continue" href="<?= $this->e($url) ?>">계속 쇼핑하기</a></aside>
</div><p class="yc-help yc-cart-inventory-note">장바구니에 담은 상품은 주문 접수 전까지 재고가 확보되지 않습니다.</p></form><?php endif ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
