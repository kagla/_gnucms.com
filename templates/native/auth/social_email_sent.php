<?php $this->layout('layout') ?>
<?php $this->start('title') ?>확인 메일 전송 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card auth-card-status">
    <div class="card-body">
      <span class="auth-mark auth-mark-soft" aria-hidden="true"><?= $this->icon('mail', 24) ?></span>
      <h1 class="card-title">메일함을 확인해 주세요</h1>
      <p class="card-sub">보내드린 링크를 열면 소셜 계정 연결과 로그인이 완료됩니다. 링크는 30분 동안 유효해요.</p>

    </div>
  </section>
</div>
<?php $this->stop() ?>
