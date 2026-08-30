<?php $this->layout('layout') ?>
<?php $this->start('title') ?>이메일 확인 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card">
    <div class="card-body">
      <div class="auth-head">
        <span class="auth-mark auth-mark-soft" aria-hidden="true"><?= $this->icon('mail', 22) ?></span>
        <h1 class="card-title">이메일을 확인해 주세요</h1>
        <p class="card-sub"><?= $this->e($provider_label) ?> 계정을 안전하게 연결하려면 사용할 이메일 주소를 한 번 확인해야 해요.</p>
      </div>
      <form method="post" action="<?= $this->url('oauth.email') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <fieldset class="fieldset<?php if (array_key_exists('email', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">이메일</legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('mail', 16) ?></span>
            <input type="email" name="email" value="<?= $this->e($values['email'] ?? '') ?>" maxlength="191" autocomplete="email" required>
          </label>
          <?php if (array_key_exists('email', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['email']) ?></p><?php endif ?>
        </fieldset>
        <button class="btn btn-primary btn-block btn-lg" type="submit">확인 메일 보내기</button>
      </form>
    </div>
  </section>
</div>
<?php $this->stop() ?>
