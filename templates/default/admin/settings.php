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
            <?php foreach ($available_themes as $theme_name): ?><option value="<?= $this->e($theme_name) ?>"<?= $this->def($values['theme'] ?? null, $active_theme) === $theme_name ? ' selected' : '' ?>><?= $this->e($theme_name === 'default' ? 'default (기본)' : $theme_name) ?></option><?php endforeach ?>
          </select>
          <?php if (array_key_exists('theme', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['theme']) ?></p><?php endif ?>
          <p class="fieldset-label">템플릿은 화면 전부를 가집니다. 정적 파일만 없으면 default 의 것을 씁니다.</p>
        </fieldset>
        <fieldset class="fieldset toggle-list">
          <label class="label toggle-row">
            <input class="toggle toggle-primary" type="checkbox" name="registration_enabled" value="1"<?= ($values['registration_enabled'] ?? false) ? ' checked' : '' ?>>
            <span><strong>새 회원가입 허용</strong><small>끄면 가입 화면이 닫힙니다.</small></span>
          </label>
        </fieldset>
      </div>
      <div class="form-section">
        <h2 class="form-section-title">파일 첨부</h2>
        <fieldset class="fieldset<?php if (array_key_exists('attach_max_mb', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">파일당 최대 용량 (MB)</legend>
          <input class="input input-bordered input-block" type="number" name="attach_max_mb" min="1" max="1024" value="<?= $this->e((string) ($values['attach_max_mb'] ?? 5)) ?>" required>
          <?php if (array_key_exists('attach_max_mb', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['attach_max_mb']) ?></p><?php endif ?>
          <?php if ((int) $server_max_mb === 0): ?>
          <p class="fieldset-label">서버 PHP 한계가 없습니다.</p>
          <?php else: ?>
          <p class="fieldset-label">서버 PHP 한계는 <?= $this->e((string) $server_max_mb) ?> MB 입니다. 그보다 크게 적어도 거기까지만 받습니다.</p>
          <?php endif ?>
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('attach_limit', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">글당 첨부 개수</legend>
          <input class="input input-bordered input-block" type="number" name="attach_limit" min="0" max="999" value="<?= $this->e((string) ($values['attach_limit'] ?? 5)) ?>" required>
          <?php if (array_key_exists('attach_limit', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['attach_limit']) ?></p><?php endif ?>
          <p class="fieldset-label">0 = 무제한. 파일 첨부는 게시판 설정에서 게시판마다 켭니다.</p>
        </fieldset>
      </div>
      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a>
        <button class="btn btn-primary" type="submit">설정 저장</button>
      </div>
    </form>
  </div>
</section>
<section class="card schema-card">
  <div class="card-body">
    <h2 class="card-title"><?= $this->icon('shield', 18) ?> 데이터 구조</h2>
    <p class="card-sub">코드를 새로 올리면 첫 요청에서 스스로 새 판으로 옮깁니다. SQLite 는 옮기기 전에 백업합니다.</p>
    <dl class="schema-facts">
      <div><dt>판 번호</dt><dd><?= $this->e($schema['version']) ?> <small class="schema-stamp"><?= $this->e($schema['stamp']) ?></small></dd></div>
      <div><dt>마지막으로 옮긴 시각</dt><dd><?= $schema['upgraded_at'] !== null ? $this->e($schema['upgraded_at']) . ' UTC' : '설치 이후 없음' ?></dd></div>
      <div><dt>마지막 백업</dt><dd><?= $schema['backup'] !== null ? $this->e(basename($schema['backup'])) : '없음' ?></dd></div>
    </dl>
    <?php if (!$schema['can_backup']): ?>
      <p class="schema-note">MySQL/PostgreSQL 은 앱이 백업하지 못합니다. mysqldump·pg_dump 같은 DB 도구로 백업하세요.</p>
    <?php elseif ($schema['backups'] === []): ?>
      <p class="schema-note">아직 백업이 없습니다. 판이 바뀔 때 <code>storage/backups/</code> 에 최근 <?= $this->e((string) $schema['keep']) ?>개까지 남깁니다.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="table table-sm schema-backups">
          <thead><tr><th>백업 파일</th><th>크기</th><th>만든 시각</th></tr></thead>
          <tbody>
          <?php foreach ($schema['backups'] as $backup): ?>
            <tr>
              <td><code><?= $this->e($backup['name']) ?></code></td>
              <td><?= $this->e(number_format($backup['size'] / 1024, 1)) ?> KB</td>
              <td><?= $this->e(gmdate('Y-m-d H:i', $backup['mtime'])) ?> UTC</td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <p class="schema-note">되돌리려면 사이트를 잠시 멈추고 <code>storage/board.sqlite</code> 를 백업 파일로 바꿉니다.</p>
    <?php endif ?>
    <?php if (($query['gc'] ?? '') !== ''): ?>
      <?php if ((int) $query['gc'] === 0): ?>
        <div class="alert alert-info"><span aria-hidden="true"><?= $this->icon('info', 18) ?></span><span>정리할 파일이 없습니다.</span></div>
      <?php else: ?>
        <div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>버려진 파일 <?= $this->e((string) (int) $query['gc']) ?>개를 정리했습니다.</span></div>
      <?php endif ?>
    <?php endif ?>
    <form method="post" action="<?= $this->url('admin.uploads.gc') ?>" class="schema-gc">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <button class="btn btn-sm" type="submit">버려진 파일 정리</button>
      <span class="schema-note">글에 붙지 못하고 하루 넘게 남은 업로드를 지웁니다.</span>
    </form>
  </div>
</section>
<?php $this->stop() ?>
