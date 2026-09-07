<?php $this->layout('layout') ?>
<?php $this->start('title') ?><?= $this->e($type_labels[$type]) ?> · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>modules/youngcart<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
  <?php $this->insert('_header') ?>
  <h1 class="yc-title"><?= $this->e($type_labels[$type]) ?></h1>
  <div class="yc-toolbar"><span class="muted"><?= $list['total'] ?>개</span><?php $this->insert('_sort', ['action' => $url . '/type', 'hidden' => ['t' => $type]]) ?></div>
  <?php $this->insert('_grid', ['items' => $list['items'], 'columns' => $list['columns'], 'size' => 'type']) ?>
  <?php $this->insert('_pager', ['page_url' => fn (int $p): string => $url . '/type?' . http_build_query(['t' => $type, 'sort' => $sort, 'dir' => $dir, 'page' => $p])]) ?>
</div>
<?php $this->stop() ?>
