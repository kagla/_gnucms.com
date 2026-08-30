<?php $this->layout('layout') ?>
<?php $this->start('title') ?>새 비밀번호 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card">
    <div class="card-body">
      <div class="auth-head">
        <span class="auth-mark auth-mark-soft" aria-hidden="true"><?= $this->icon('shield', 22) ?></span>
        <h1 class="card-title">새 비밀번호 설정</h1>
        <p class="card-sub">앞으로 사용할 비밀번호를 입력해 주세요.</p>
      </div>
      <form method="post" action="<?= $this->url('auth.reset') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <input type="hidden" name="token" value="<?= $this->e($token) ?>">
        <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">새 비밀번호 <span class="legend-hint">8자 이상</span></legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('lock', 16) ?></span>
            <input type="password" name="password" minlength="8" autocomplete="new-password" required>
          </label>
          <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('password_confirmation', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">비밀번호 확인</legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('lock', 16) ?></span>
            <input type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required>
          </label>
          <?php if (array_key_exists('password_confirmation', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password_confirmation']) ?></p><?php endif ?>
        </fieldset>
        <?php if (array_key_exists('token', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['token']) ?></p><?php endif ?>
        <button class="btn btn-primary btn-block btn-lg" type="submit">비밀번호 변경</button>
      </form>
    </div>
  </section>
</div>
<?php $this->stop() ?>
