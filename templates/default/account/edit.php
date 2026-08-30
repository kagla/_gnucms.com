<?php $this->layout('layout') ?>
<?php $this->start('title') ?>회원정보 수정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card">
    <div class="card-body">
      <div class="auth-head">
        <span class="auth-mark auth-mark-soft" aria-hidden="true"><?= $this->icon('user', 22) ?></span>
        <h1 class="card-title">회원정보 수정</h1>
        <p class="card-sub">표시 이름과 비밀번호를 바꿉니다.</p>
      </div>
      <?php if ($saved): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>저장했습니다.</span></div><?php endif ?>
      <form method="post" action="<?= $this->url('account.edit') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <fieldset class="fieldset">
          <legend class="fieldset-legend">이메일 <span class="legend-hint">로그인 아이디. 여기서는 바꿀 수 없습니다</span></legend>
          <input class="input input-bordered input-block" type="email" value="<?= $this->e($values['email']) ?>" readonly>
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('display_name', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">표시 이름</legend>
          <input class="input input-bordered input-block" type="text" name="display_name" value="<?= $this->e($values['display_name']) ?>" maxlength="100" required>
          <?php if (array_key_exists('display_name', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['display_name']) ?></p><?php endif ?>
        </fieldset>
        <div class="divider">비밀번호 바꾸기 <span class="legend-hint">비워 두면 그대로</span></div>
        <fieldset class="fieldset<?php if (array_key_exists('current_password', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">현재 비밀번호</legend>
          <input class="input input-bordered input-block" type="password" name="current_password" autocomplete="current-password">
          <?php if (array_key_exists('current_password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['current_password']) ?></p><?php endif ?>
        </fieldset>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">새 비밀번호</legend>
            <input class="input input-bordered input-block" type="password" name="password" minlength="8" autocomplete="new-password">
            <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
          </fieldset>
          <fieldset class="fieldset<?php if (array_key_exists('password_confirmation', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">새 비밀번호 확인</legend>
            <input class="input input-bordered input-block" type="password" name="password_confirmation" minlength="8" autocomplete="new-password">
            <?php if (array_key_exists('password_confirmation', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password_confirmation']) ?></p><?php endif ?>
          </fieldset>
        </div>
        <button class="btn btn-primary btn-block btn-lg" type="submit">저장</button>
      </form>
    </div>
  </section>
</div>
<?php $this->stop() ?>
