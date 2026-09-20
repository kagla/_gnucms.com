<?php $this->layout('layout') ?>
<?php $this->start('title') ?>쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><?php $this->stop() ?>
<?php $this->start('body') ?>
<section class="card"><div class="card-body">
  <h1 class="card-title">쇼핑몰을 준비 중입니다</h1>
  <p class="muted">상품이 등록되면 이곳에서 볼 수 있습니다.</p>
  <?php if ($admin): ?><p><a class="btn btn-sm" href="<?= $this->e($admin_url) ?>">쇼핑몰 데이터 설치</a></p><?php endif ?>
</div></section>
<?php $this->stop() ?>
