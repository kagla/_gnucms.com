<?php $this->layout('layout') ?>
<?php $this->start('title') ?>상태 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<section class="card status-card">
  <div class="card-body">
    <span class="status-icon status-icon-success" aria-hidden="true"><?= $this->icon('check-circle', 28) ?></span>
    <h1 class="card-title">모든 시스템이 정상입니다</h1>
    <p><span class="status status-success" aria-hidden="true"></span> 데이터베이스에 <strong><?= $this->e($dialect) ?></strong>로 연결되어 있습니다.</p>
    <p class="status-note">이 화면은 연결 상태만 확인하며 테이블 유무는 검사하지 않습니다.</p>
  </div>
</section>
<?php $this->stop() ?>
