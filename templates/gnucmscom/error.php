<?php $this->layout('layout') ?>
<?php $this->start('title') ?><?= $this->e($title) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<section class="card status-card">
  <div class="card-body">
    <span class="status-icon status-icon-error" aria-hidden="true"><?= $this->icon('warning', 28) ?></span>
    <h1 class="card-title"><?= $this->e($title) ?></h1>
    <p><?= $this->e($message) ?></p>
    <?php if (!empty($details)): ?>
      <ul class="list status-list">
        <?php foreach ($details as $field => $detail): ?>
          <li class="list-row"><strong><?= $this->e($field) ?></strong><span><?= $this->e($detail) ?></span></li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
    <div class="card-actions">
      <a class="btn btn-primary btn-lg" href="<?= $this->url('boards.index') ?>"><?= $this->icon('home', 16) ?> 홈으로 가기</a>
    </div>
  </div>
</section>
<?php $this->stop() ?>
