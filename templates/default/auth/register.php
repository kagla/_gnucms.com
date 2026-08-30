<?php $this->layout('layout') ?>
<?php $this->start('title') ?>회원가입 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card">
    <div class="card-body">
      <div class="auth-head">
        <span class="auth-mark" aria-hidden="true"><?= $this->icon('sparkle', 22) ?></span>
        <h1 class="card-title"><?= $this->e($site['site_name']) ?> 시작하기</h1>
        <p class="card-sub">1분이면 가입할 수 있어요.</p>
      </div>
      <form method="post" action="<?= $this->url('auth.register') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <fieldset class="fieldset<?php if (array_key_exists('email', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">이메일</legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('mail', 16) ?></span>
            <input type="email" name="email" value="<?= $this->e($values['email'] ?? '') ?>" maxlength="191" autocomplete="email" placeholder="you@example.com" required>
          </label>
          <?php if (array_key_exists('email', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['email']) ?></p><?php endif ?>
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">비밀번호 <span class="legend-hint">8자 이상</span></legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('lock', 16) ?></span>
            <input type="password" name="password" minlength="8" autocomplete="new-password" required>
            <?php $this->insert('auth/_pw_toggle') ?>
          </label>
          <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('password_confirmation', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">비밀번호 확인</legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('lock', 16) ?></span>
            <input type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required>
            <?php $this->insert('auth/_pw_toggle') ?>
          </label>
          <?php if (array_key_exists('password_confirmation', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password_confirmation']) ?></p><?php endif ?>
        </fieldset>
        <?php $this->insert('auth/_consents') ?>
        <button class="btn btn-primary btn-block btn-lg" type="submit">가입하기</button>
      </form>
      <?php $this->insert('auth/_social') ?>
      <p class="auth-switch">이미 계정이 있으신가요? <a class="link" href="<?= $this->url('auth.login') ?>">로그인</a></p>
    </div>
  </section>
</div>
<?php $this->insert('auth/_pw_toggle_script') ?>
<?php $this->stop() ?>
