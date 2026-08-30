<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>회원 수정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>members<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.members') ?>">회원</a></li><li aria-current="page"><?= $this->e($values['email']) ?></li></ul></div>
<section class="card">
  <div class="card-body">
    <h1 class="card-title">회원 수정</h1>
    <p class="card-sub">로그인 이메일과 화면에 표시할 이름, 이용 상태를 관리합니다.</p>
    <form method="post" action="<?= $this->url('admin.members.edit', ['id' => $values['id']]) ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <fieldset class="fieldset<?php if (array_key_exists('email', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">이메일</legend>
        <input class="input input-bordered input-block" type="email" name="email" value="<?= $this->e($values['email'] ?? '') ?>" maxlength="191" autocomplete="off" required>
        <?php if (array_key_exists('email', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['email']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?php if (array_key_exists('display_name', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">표시 이름</legend>
        <input class="input input-bordered input-block" type="text" name="display_name" value="<?= $this->e($values['display_name'] ?? '') ?>" maxlength="100" required>
        <?php if (array_key_exists('display_name', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['display_name']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?php if (array_key_exists('status', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">이용 상태</legend>
        <select class="select select-bordered select-block" name="status">
          <option value="active"<?= ($values['status'] ?? 'active') === 'active' ? ' selected' : '' ?>>활성</option>
          <option value="blocked"<?= ($values['status'] ?? 'active') === 'blocked' ? ' selected' : '' ?>>차단</option>
        </select>
        <?php if (array_key_exists('status', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['status']) ?></p><?php endif ?>
      </fieldset>
      <ul class="list fact-list">
        <li class="list-row"><span>권한</span><strong><?= $values['is_admin'] ? '소유자' : '일반 회원' ?></strong></li>
        <li class="list-row"><span>이메일 인증</span><strong><?= $values['email_verified'] ? '완료' : '대기' ?></strong></li>
        <li class="list-row"><span>가입일</span><strong><?= $this->date($values['created_at'], 'Y.m.d H:i') ?></strong></li>
      </ul>
      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.members') ?>">취소</a>
        <button class="btn btn-primary" type="submit">변경사항 저장</button>
      </div>
    </form>
  </div>
</section>
<?php $this->insert('admin/_member_consents') ?>
<?php $this->stop() ?>
