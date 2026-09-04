<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>회원 수정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>members<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.members') ?>">회원</a></li><li aria-current="page"><?= $this->e($values['email']) ?></li></ul></div>
<section class="card">
  <div class="card-body">
    <h1 class="card-title">회원 수정</h1>
    <p class="card-sub">로그인 이메일과 표시 이름, 프로필 이미지, 비밀번호, 이용 상태를 관리합니다. 관리자 자신의 비밀번호도 여기서 바꿉니다.</p>
    <?php if (($values['status'] ?? '') === 'withdrawn'): ?><div class="alert alert-info alert-soft"><span><?= $this->icon('info', 18) ?></span><span>탈퇴 처리된 회원입니다. 익명화된 정보와 보안 이력만 조회할 수 있습니다.</span></div><?php endif ?>
    <?php if (array_key_exists('member', $errors)): ?><div class="alert alert-error"><span><?= $this->icon('warning', 18) ?></span><span><?= $this->e($errors['member']) ?></span></div><?php endif ?>
    <?php if ($member_identities !== []): ?>
      <div class="alert alert-info alert-soft member-social-summary">
        <span aria-hidden="true"><?= $this->icon('users', 18) ?></span>
        <div><strong>소셜 로그인 회원</strong><p><?php foreach ($member_identities as $i => $identity): ?><?= $i > 0 ? ', ' : '' ?><?= $this->e($identity['label']) ?> 계정<?php endforeach ?>이 연결되어 있습니다.</p></div>
      </div>
    <?php endif ?>
    <form method="post" action="<?= $this->url('admin.members.edit', ['id' => $values['id']]) ?>" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <fieldset class="fieldset<?php if (array_key_exists('email', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">이메일</legend>
        <input class="input input-bordered input-block" type="email" name="email" value="<?= $this->e($values['email'] ?? '') ?>" maxlength="191" autocomplete="off" required>
        <?php if (array_key_exists('email', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['email']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?php if (array_key_exists('display_name', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">표시 이름 <span class="legend-hint">한글·영문·숫자만 · 한글 2자 또는 영문 4자 이상</span></legend>
        <input class="input input-bordered input-block" type="text" name="display_name" minlength="2" pattern="[가-힣A-Za-z0-9]+" title="한글·영문·숫자만, 공백 없이" value="<?= $this->e($values['display_name'] ?? '') ?>" maxlength="100" required>
        <?php if (array_key_exists('display_name', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['display_name']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?= array_key_exists('profile_image', $errors) ? ' is-invalid' : '' ?>">
        <legend class="fieldset-legend">프로필 이미지 <span class="legend-hint">JPG, PNG, WebP · 2MB 이하</span></legend>
        <?php if (!empty($values['avatar_file'])): ?><div class="profile-image-preview"><img src="<?= $this->url('avatar.show', ['file' => $values['avatar_file']]) ?>" alt="<?= $this->e($values['display_name']) ?> 프로필 이미지"><label><input class="checkbox checkbox-sm" type="checkbox" name="remove_profile_image" value="1"> 현재 이미지 삭제</label></div><?php endif ?>
        <input class="file-input file-input-bordered input-block" type="file" name="profile_image" accept="image/jpeg,image/png,image/webp">
        <p class="fieldset-label">새 이미지를 선택하면 현재 이미지를 교체합니다.</p>
        <?php if (array_key_exists('profile_image', $errors)): ?><p class="validator-hint"><?= $this->e($errors['profile_image']) ?></p><?php endif ?>
      </fieldset>
      <div class="grid-2">
        <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">새 비밀번호 <span class="legend-hint">비워 두면 그대로</span></legend>
          <input class="input input-bordered input-block" type="password" name="password" minlength="8" autocomplete="new-password">
          <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('password_confirmation', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">새 비밀번호 확인</legend>
          <input class="input input-bordered input-block" type="password" name="password_confirmation" minlength="8" autocomplete="new-password">
          <?php if (array_key_exists('password_confirmation', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password_confirmation']) ?></p><?php endif ?>
        </fieldset>
      </div>
      <fieldset class="fieldset<?php if (array_key_exists('status', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">이용 상태</legend>
        <select class="select select-bordered select-block" name="status">
          <option value="active"<?= $this->def($values['status'] ?? null, 'active') === 'active' ? ' selected' : '' ?>>활성</option>
          <option value="blocked"<?= $this->def($values['status'] ?? null, 'active') === 'blocked' ? ' selected' : '' ?>>차단</option>
          <?php if (($values['status'] ?? '') === 'withdrawn'): ?><option value="withdrawn" selected>탈퇴</option><?php endif ?>
        </select>
        <?php if (array_key_exists('status', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['status']) ?></p><?php endif ?>
      </fieldset>
      <ul class="list fact-list">
        <li class="list-row"><span>로그인 방식</span><strong class="member-login-methods">
          <?php if (($values['password_hash'] ?? null) !== null): ?><span class="badge badge-outline">이메일·비밀번호</span><?php endif ?>
          <?php foreach ($member_identities as $identity): ?><span class="badge badge-outline social-provider-badge social-<?= $this->e($identity['provider']) ?>"><?= $this->e($identity['label']) ?> 소셜 로그인</span><?php endforeach ?>
          <?php if (($values['password_hash'] ?? null) === null && $member_identities === []): ?><span class="badge badge-warning">로그인 수단 없음</span><?php endif ?>
        </strong></li>
        <li class="list-row"><span>권한</span><strong><?= $values['is_admin'] ? '소유자' : '일반 회원' ?></strong></li>
        <li class="list-row"><span>이메일 인증</span><strong><?= $values['email_verified'] ? '완료' : '대기' ?></strong></li>
        <li class="list-row"><span>가입일</span><strong><?= $this->date($values['created_at'], 'Y.m.d H:i') ?></strong></li>
      </ul>
      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.members') ?>">취소</a>
        <?php if (($values['status'] ?? '') !== 'withdrawn'): ?><button class="btn btn-primary" type="submit">변경사항 저장</button><?php endif ?>
      </div>
    </form>
  </div>
</section>
<?php $this->insert('admin/_member_consents') ?>
<?php $this->insert('admin/_member_login_events') ?>
<?php $this->stop() ?>
