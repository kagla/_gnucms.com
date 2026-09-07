<?php $this->layout('layout') ?>
<?php $this->start('title') ?>쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>modules/youngcart<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
  <?php $this->insert('_header') ?>
  <?php foreach ($blocks as $type => $items): ?>
    <section class="yc-block">
      <h2 class="yc-block-title"><a href="<?= $this->e($url) ?>/type?t=<?= $this->e($type) ?>"><?= $this->e($type_labels[$type]) ?></a></h2>
      <?php $this->insert('_grid', ['items' => $items, 'columns' => $settings['main'][$type]['columns'], 'size' => 'main']) ?>
    </section>
  <?php endforeach ?>
</div>
<?php $this->stop() ?>
