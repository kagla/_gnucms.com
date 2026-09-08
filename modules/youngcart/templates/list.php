<?php $this->layout('layout') ?>
<?php $this->start('title') ?><?= $this->e($category['name']) ?> · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>modules/youngcart<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
  <?php $this->insert('_header') ?>
  <?php $this->insert('_breadcrumb') ?>
  <h1 class="yc-title"><?= $this->e($category['name']) ?></h1>
  <?php if ($category['head_html'] !== ''): ?><div class="yc-html"><?= $this->html($category['head_html']) ?></div><?php endif ?>
  <?php if ($children !== []): ?><nav class="yc-children" aria-label="하위 분류"><?php foreach ($children as $child): ?><a class="btn btn-sm btn-outline" href="<?= $this->e($url) ?>/list?ca=<?= $this->e($child['code']) ?>"><?= $this->e($child['name']) ?></a><?php endforeach ?></nav><?php endif ?>
  <div class="yc-toolbar"><span class="muted"><?= $list['total'] ?>개</span><?php $this->insert('_sort', ['action' => $url . '/list', 'hidden' => ['ca' => $category['code']]]) ?></div>
  <?php $this->insert('_grid', ['items' => $list['items'], 'columns' => $list['columns'], 'size' => 'list']) ?>
  <?php $this->insert('_pager', ['page_url' => fn (int $p): string => $url . '/list?' . http_build_query(['ca' => $category['code'], 'sort' => $sort, 'dir' => $dir, 'page' => $p])]) ?>
  <?php if ($category['tail_html'] !== ''): ?><div class="yc-html"><?= $this->html($category['tail_html']) ?></div><?php endif ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
