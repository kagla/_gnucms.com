<?php $this->layout('layout') ?>
<?php $this->start('title') ?>상품 검색 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,follow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
  <?php $this->insert('_header') ?>
  <h1 class="yc-title">상품 검색</h1>
  <form class="yc-search-form" method="get" action="<?= $this->e($url) ?>/search">
    <input class="input input-bordered" type="search" name="q" value="<?= $this->e($q) ?>" maxlength="50" placeholder="검색어" aria-label="검색어">
    <input class="input input-bordered input-sm" type="number" name="min" value="<?= $min > 0 ? $min : '' ?>" min="0" placeholder="최저가" aria-label="최저 가격">
    <input class="input input-bordered input-sm" type="number" name="max" value="<?= $max > 0 ? $max : '' ?>" min="0" placeholder="최고가" aria-label="최고 가격">
    <input type="hidden" name="ca" value="<?= $this->e($ca) ?>">
    <button class="btn btn-sm" type="submit">검색</button>
  </form>
  <?php if ($q === ''): ?>
    <p class="muted">검색어를 입력해 주세요.</p>
  <?php else: ?>
    <?php if ($list['facets'] !== []): ?>
      <nav class="yc-facets" aria-label="분류별 결과">
        <a class="btn btn-sm<?= $ca === '' ? ' btn-active' : ' btn-outline' ?>" href="<?= $this->e($url) ?>/search?<?= $this->e(http_build_query(['q' => $q, 'min' => $min ?: '', 'max' => $max ?: ''])) ?>">전체</a>
        <?php foreach ($list['facets'] as $facet): ?><a class="btn btn-sm<?= $ca === $facet['slug'] ? ' btn-active' : ' btn-outline' ?>" href="<?= $this->e($url) ?>/search?<?= $this->e(http_build_query(['q' => $q, 'ca' => $facet['slug'], 'min' => $min ?: '', 'max' => $max ?: ''])) ?>"><?= $this->e($facet['name']) ?> (<?= $facet['count'] ?>)</a><?php endforeach ?>
      </nav>
    <?php endif ?>
    <div class="yc-toolbar"><span class="muted"><?= $list['total'] ?>개</span><?php $this->insert('_sort', ['action' => $url . '/search', 'hidden' => ['q' => $q, 'ca' => $ca, 'min' => $min ?: '', 'max' => $max ?: '']]) ?></div>
    <?php if ($list['items'] === []): ?><p class="muted">검색 결과가 없습니다.</p><?php else: ?>
      <?php $this->insert('_grid', ['items' => $list['items'], 'columns' => $list['columns'], 'size' => 'search']) ?>
      <?php $this->insert('_pager', ['page_url' => fn (int $p): string => $url . '/search?' . http_build_query(['q' => $q, 'ca' => $ca, 'min' => $min ?: '', 'max' => $max ?: '', 'sort' => $sort, 'dir' => $dir, 'page' => $p])]) ?>
    <?php endif ?>
  <?php endif ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
