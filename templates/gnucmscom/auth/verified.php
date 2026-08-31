<?php $this->layout('layout') ?>
<?php $this->start('title') ?>이메일 인증 완료 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card auth-card-status">
    <div class="card-body">
      <span class="auth-mark auth-mark-ok" aria-hidden="true"><?= $this->icon('check-circle', 24) ?></span>
      <h1 class="card-title">인증이 완료됐어요</h1>
      <p class="card-sub">이제 로그인하고 커뮤니티에 참여할 수 있어요.</p>
      <div class="card-actions"><a class="btn btn-primary btn-block btn-lg" href="<?= $this->url('auth.login') ?>">로그인하기</a></div>
    </div>
  </section>
</div>
<?php $this->stop() ?>
