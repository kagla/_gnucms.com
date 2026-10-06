<?php $this->layout('layout') ?>
<?php $this->start('title') ?>이메일 알림 수신거부 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap"><section class="card auth-card"><div class="card-body">
  <h1 class="card-title">이메일 알림 수신거부</h1>
  <?php if ($unsubscribed): ?>
    <p>이메일 알림 수신이 꺼져 있습니다. 회원정보에서 언제든 다시 켤 수 있습니다.</p>
    <a class="btn btn-primary" href="<?= $this->url('account.edit') ?>#email-notifications">회원정보로 이동</a>
  <?php else: ?>
    <p>댓글·주문 등의 이메일 알림을 받지 않으려면 아래 버튼을 눌러 주세요.</p>
    <form method="post" action="<?= $this->url('notifications.email.unsubscribe') ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="token" value="<?= $this->e($token) ?>">
      <button class="btn btn-primary" type="submit">이메일 알림 수신거부</button>
    </form>
  <?php endif ?>
  <p class="fieldset-label">사이트 내 알림은 계속 받습니다. 직접 요청한 이메일 인증·비밀번호 재설정 메일도 받을 수 있습니다.</p>
</div></section></div>
<?php $this->stop() ?>
