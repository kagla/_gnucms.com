<?php $this->layout('layout') ?>
<?php $this->start('title') ?>회원가입 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card">
    <div class="card-body">
      <div class="auth-head">
        <h1 class="card-title"><?= $this->e($site['site_name']) ?> 회원가입</h1>
        <p class="card-sub">1분이면 가입할 수 있어요.</p>
      </div>
      <?php if ($site['social_registration_enabled']): ?>
        <?php $this->insert('auth/_social', ['show_email_divider' => (bool) $site['registration_enabled']]) ?>
      <?php endif ?>
      <?php if ($site['registration_enabled']): ?>
      <form method="post" action="<?= $this->url('auth.register') ?>" enctype="multipart/form-data">
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
          <legend class="fieldset-legend">비밀번호 <span class="legend-hint"><?= $this->e((string) $password_min) ?>자 이상</span></legend>
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
        <fieldset class="fieldset<?= array_key_exists('profile_image', $errors) ? ' is-invalid' : '' ?>">
          <legend class="fieldset-legend">프로필 이미지 <span class="legend-hint">선택 · JPG, PNG, WebP · 2MB 이하</span></legend>
          <input class="file-input file-input-bordered input-block" type="file" name="profile_image" accept="image/jpeg,image/png,image/webp">
          <?php if (array_key_exists('profile_image', $errors)): ?><p class="validator-hint"><?= $this->e($errors['profile_image']) ?></p><?php endif ?>
        </fieldset>
        <?php $this->insert('auth/_consents') ?>
        <?php $this->insert('_turnstile', ['action' => 'register', 'errors' => $errors]) ?>
        <button class="btn btn-primary btn-block btn-lg" type="submit">가입하기</button>
      </form>
      <?php endif ?>
      <p class="auth-switch">이미 계정이 있으신가요? <a class="link" href="<?= $this->url('auth.login') ?>">로그인</a></p>
    </div>
  </section>
</div>
<?php $this->insert('auth/_pw_toggle_script') ?>
<?php if ($turnstile_enabled): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif ?>
<?php $this->stop() ?>
