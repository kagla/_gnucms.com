<?php $this->layout('layout') ?>
<?php $this->start('title') ?>회원 탈퇴 완료 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap"><section class="card auth-card"><div class="card-body">
  <div class="auth-head"><span class="auth-mark auth-mark-soft" aria-hidden="true"><?= $this->icon('check-circle', 22) ?></span><h1 class="card-title">회원 탈퇴가 완료되었습니다</h1><p class="card-sub">이메일과 소셜 계정 연결을 해제하고 개인정보를 익명화했습니다.</p></div>
  <div class="alert alert-info alert-soft"><span><?= $this->icon('info', 18) ?></span><span>같은 이메일이나 소셜 계정으로 다시 가입할 수 있지만, 이전 글과 댓글은 새 계정에 연결되지 않습니다.</span></div>
  <a class="btn btn-primary btn-block" href="<?= $this->url('boards.index') ?>">홈으로 돌아가기</a>
</div></section></div>
<?php $this->stop() ?>
