<?php $this->layout('layout') ?>
<?php $this->start('title') ?>쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
  <?php $this->insert('_header') ?>
  <?php
  $captions = ['hit' => ['지금 많이 찾는 상품', '둘러볼수록 마음에 드는 발견'], 'new' => ['새롭게 만나는 상품', '일상에 새로운 선택을 더해 보세요'],
      'recommend' => ['함께 둘러보면 좋은 상품', '고르는 즐거움, 추천상품에서 시작하세요'], 'discount' => ['가격까지 마음에 드는 선택', '할인 중인 상품을 한곳에서 살펴보세요'], 'popular' => ['관심을 모으는 상품', '인기상품을 한눈에 비교해 보세요']];
  ?>
  <?php $hasSide = $settings['main']['new']['use'] || $settings['main']['discount']['use']; ?>
  <?php if ($settings['banner']['use'] || $hasSide): ?>
  <section class="yc-promo-grid<?= !$settings['banner']['use'] || !$hasSide ? ' yc-promo-single' : '' ?>" aria-label="쇼핑 둘러보기">
    <?php $this->insert('_banner') ?>
    <?php if ($hasSide): ?>
    <div class="yc-promo-side">
      <?php if ($settings['main']['new']['use']): ?><a class="yc-promo-tile" href="<?= $this->e($url) ?>/type?t=new"><span class="yc-eyebrow">JUST ARRIVED</span><h2>새로운 상품,<br>먼저 만나보세요.</h2><span>신상품 둘러보기 <?= $this->icon('arrow-right', 18) ?></span></a><?php endif ?>
      <?php if ($settings['main']['discount']['use']): ?><a class="yc-promo-tile yc-promo-sale" href="<?= $this->e($url) ?>/type?t=discount"><span class="yc-eyebrow">SMART CHOICE</span><h2>합리적인 가격으로<br>기분 좋은 선택.</h2><span>할인상품 둘러보기 <?= $this->icon('arrow-right', 18) ?></span></a><?php endif ?>
    </div>
    <?php endif ?>
  </section>
  <?php endif ?>
  <?php if ($menu !== []): ?><section class="yc-category-section" aria-labelledby="yc-categories-title"><div class="yc-section-heading"><h2 id="yc-categories-title">무엇을 찾으세요?</h2><span class="muted">카테고리별로 편하게 둘러보세요</span></div>
    <nav class="yc-category-shortcuts" aria-label="인기 분류 바로가기"><?php foreach ($menu as $index => $category): ?><a href="<?= $this->e($url) ?>/c/<?= $this->e(rawurlencode($category['slug'])) ?>"><span class="yc-category-icon" data-tone="<?= $index % 4 ?>" aria-hidden="true"><?= $this->icon(['grid', 'gift', 'home', 'star'][$index % 4], 26) ?></span><strong><?= $this->e($category['name']) ?></strong></a><?php endforeach ?></nav>
  </section><?php endif ?>
  <?php foreach ($blocks as $type => $items): ?>
    <section class="yc-block">
      <div class="yc-section-heading"><div><p class="yc-kicker"><?= $this->e($captions[$type][0]) ?></p><h2 class="yc-block-title"><?= $this->e($type_labels[$type]) ?></h2><p class="yc-block-description"><?= $this->e($captions[$type][1]) ?></p></div><a class="yc-more" href="<?= $this->e($url) ?>/type?t=<?= $this->e($type) ?>">전체보기 <?= $this->icon('chevron-right', 17) ?></a></div>
      <?php $this->insert('_grid', ['items' => $items, 'columns' => $settings['main'][$type]['columns'], 'size' => 'main']) ?>
    </section>
  <?php endforeach ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
