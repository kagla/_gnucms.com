<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>관리자 비밀번호 변경 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>security<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">계정 보안</li></ul></div>
<section class="card card-narrow">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('shield', 19) ?> 비밀번호 변경</h1>
    <p class="card-sub">새 비밀번호를 저장하면 모든 기기에서 다시 로그인해야 합니다.</p>
    <form method="post" action="<?= $this->url('admin.password') ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <fieldset class="fieldset<?php if (array_key_exists('current_password', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">현재 비밀번호</legend>
        <input class="input input-bordered input-block" type="password" name="current_password" autocomplete="current-password" required>
        <?php if (array_key_exists('current_password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['current_password']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">새 비밀번호 <span class="legend-hint">8자 이상</span></legend>
        <input class="input input-bordered input-block" type="password" name="password" minlength="8" autocomplete="new-password" required>
        <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?php if (array_key_exists('password_confirmation', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">새 비밀번호 확인</legend>
        <input class="input input-bordered input-block" type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required>
        <?php if (array_key_exists('password_confirmation', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password_confirmation']) ?></p><?php endif ?>
      </fieldset>
      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a>
        <button class="btn btn-primary" type="submit">비밀번호 변경</button>
      </div>
    </form>
  </div>
</section>
<?php $this->stop() ?>
