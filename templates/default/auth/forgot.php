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
        <fieldset class="fieldset<?php if (array_key_exists('email', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">이메일</legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('mail', 16) ?></span>
            <input type="email" name="email" value="<?= $this->e($values['email'] ?? '') ?>" autocomplete="email" placeholder="you@example.com" required>
          </label>
          <?php // 이 칸에 붙는 문구는 "얼마나 자주 눌렀는가"만 말한다. 가입된 주소든 아니든
                // 똑같이 세고 똑같이 나오므로 계정의 존재를 흘리지 않는다(AccountService
                // ::requestPasswordReset). 로그인 화면과 같은 모양의 같은 자리다. ?>
          <?php if (array_key_exists('email', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['email']) ?></p><?php endif ?>
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
