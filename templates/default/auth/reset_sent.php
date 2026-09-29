<?php $this->layout('layout') ?>
<?php $this->start('title') ?>이메일을 확인해 주세요 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card auth-card-status">
    <div class="card-body">
      <span class="auth-mark auth-mark-soft" aria-hidden="true"><?= $this->icon('mail', 24) ?></span>
      <h1 class="card-title">이메일을 확인해 주세요</h1>
<?php if ($deliverable): ?>
      <p class="card-sub">가입된 계정이라면 비밀번호 재설정 링크를 보냈어요.</p>
<?php else: ?>
      <p class="card-sub">이 사이트는 지금 비밀번호 재설정 링크를 보낼 수 없습니다. 사이트 관리자에게 문의해 주세요.</p>
<?php endif ?>
      <p class="auth-switch"><a class="link link-hover" href="<?= $this->url('auth.login') ?>">로그인으로 돌아가기</a></p>
    </div>
  </section>
</div>
<?php $this->stop() ?>
