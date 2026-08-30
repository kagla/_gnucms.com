<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>사이트 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">사이트 설정</li></ul></div>
<section class="card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('cog', 19) ?> 사이트 설정</h1>
    <p class="card-sub">방문자가 가장 먼저 보는 이름과 소개를 정합니다.</p>
    <?php if (($query['saved'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>사이트 설정을 저장했습니다.</span></div><?php endif ?>
    <form method="post" action="<?= $this->url('admin.settings') ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <div class="form-section">
        <h2 class="form-section-title">사이트 정보</h2>
        <fieldset class="fieldset<?php if (array_key_exists('site_name', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">사이트 이름</legend>
          <input class="input input-bordered input-block" type="text" name="site_name" value="<?= $this->e($values['site_name'] ?? '') ?>" maxlength="50" required>
          <?php if (array_key_exists('site_name', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['site_name']) ?></p><?php endif ?>
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('site_tagline', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">짧은 소개</legend>
          <input class="input input-bordered input-block" type="text" name="site_tagline" value="<?= $this->e($values['site_tagline'] ?? '') ?>" maxlength="120" required>
          <?php if (array_key_exists('site_tagline', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['site_tagline']) ?></p><?php endif ?>
        </fieldset>
      </div>
      <div class="form-section">
        <h2 class="form-section-title">홈 화면</h2>
        <fieldset class="fieldset<?php if (array_key_exists('home_title', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">홈 제목</legend>
          <input class="input input-bordered input-block" type="text" name="home_title" value="<?= $this->e($values['home_title'] ?? '') ?>" maxlength="120" required>
          <?php if (array_key_exists('home_title', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['home_title']) ?></p><?php endif ?>
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('home_intro', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">홈 소개</legend>
          <textarea class="textarea textarea-bordered textarea-block" name="home_intro" rows="4" maxlength="500" required><?= $this->e($values['home_intro'] ?? '') ?></textarea>
          <?php if (array_key_exists('home_intro', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['home_intro']) ?></p><?php endif ?>
        </fieldset>
      </div>
      <div class="form-section">
        <h2 class="form-section-title">표시와 가입</h2>
        <fieldset class="fieldset<?php if (array_key_exists('theme', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">템플릿</legend>
          <select class="select select-bordered select-block" name="theme" required>
            <?php foreach ($available_themes as $theme_name): ?><option value="<?= $this->e($theme_name) ?>"<?= ($values['theme'] ?? $active_theme) === $theme_name ? ' selected' : '' ?>><?= $this->e($theme_name === 'default' ? 'default (기본)' : $theme_name) ?></option><?php endforeach ?>
          </select>
          <?php if (array_key_exists('theme', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['theme']) ?></p><?php endif ?>
          <p class="fieldset-label">선택한 템플릿에 없는 화면과 파일은 default 템플릿을 사용합니다.</p>
        </fieldset>
        <fieldset class="fieldset toggle-list">
          <label class="label toggle-row">
            <input class="toggle toggle-primary" type="checkbox" name="registration_enabled" value="1"<?= ($values['registration_enabled'] ?? false) ? ' checked' : '' ?>>
            <span><strong>새 회원가입 허용</strong><small>끄면 가입 화면이 닫힙니다.</small></span>
          </label>
        </fieldset>
      </div>
      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a>
        <button class="btn btn-primary" type="submit">설정 저장</button>
      </div>
    </form>
  </div>
</section>
<?php $this->stop() ?>
