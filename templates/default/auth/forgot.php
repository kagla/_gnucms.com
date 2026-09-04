<?php $this->layout('layout') ?>
<?php $this->start('title') ?>비밀번호 찾기 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card">
    <div class="card-body">
      <div class="auth-head">
        <span class="auth-mark auth-mark-soft" aria-hidden="true"><?= $this->icon('mail', 22) ?></span>
        <h1 class="card-title">비밀번호 찾기</h1>
        <p class="card-sub">가입한 이메일로 재설정 링크를 보내드려요.</p>
      </div>
      <form method="post" action="<?= $this->url('auth.forgot') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <fieldset class="fieldset">
          <legend class="fieldset-legend">이메일</legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('mail', 16) ?></span>
            <input type="email" name="email" value="<?= $this->e($values['email'] ?? '') ?>" autocomplete="email" placeholder="you@example.com" required>
          </label>
        </fieldset>
        <?php $this->insert('_turnstile', ['action' => 'password_reset', 'errors' => $errors]) ?>
        <button class="btn btn-primary btn-block btn-lg" type="submit">재설정 링크 받기</button>
      </form>
      <p class="auth-switch"><a class="link link-hover" href="<?= $this->url('auth.login') ?>">로그인으로 돌아가기</a></p>
    </div>
  </section>
</div>
<?php if ($turnstile_enabled): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif ?>
<?php $this->stop() ?>
