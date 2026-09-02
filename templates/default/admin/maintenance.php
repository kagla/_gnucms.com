<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>시스템·유지보수 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">시스템·유지보수</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'maintenance']) ?>
<section class="card settings-card"><div class="card-body">
  <h1 class="card-title"><?= $this->icon('shield', 19) ?> 시스템·유지보수</h1><p class="card-sub">일반 설정이 아닌 데이터베이스 갱신 상태, 백업과 파일 정리 도구입니다.</p>
  <h2 class="form-section-title">데이터베이스 상태</h2><dl class="schema-facts"><div><dt>판 번호</dt><dd><?= $this->e($schema['version']) ?> <small class="schema-stamp"><?= $this->e($schema['stamp']) ?></small></dd></div><div><dt>마지막으로 옮긴 시각</dt><dd><?= $schema['upgraded_at'] !== null ? $this->e($schema['upgraded_at']) . ' UTC' : '설치 이후 없음' ?></dd></div><div><dt>마지막 백업</dt><dd><?= $schema['backup'] !== null ? $this->e(basename($schema['backup'])) : '없음' ?></dd></div></dl>
  <?php if (!$schema['can_backup']): ?><p class="schema-note">MySQL/PostgreSQL은 앱이 백업하지 못합니다. mysqldump·pg_dump 같은 DB 도구로 백업하세요.</p><?php elseif ($schema['backups'] === []): ?><p class="schema-note">아직 백업이 없습니다. 판이 바뀔 때 <code>storage/backups/</code>에 최근 <?= $this->e((string) $schema['keep']) ?>개까지 남깁니다.</p><?php else: ?><div class="overflow-x-auto"><table class="table table-sm schema-backups"><thead><tr><th>백업 파일</th><th>크기</th><th>만든 시각</th></tr></thead><tbody><?php foreach ($schema['backups'] as $backup): ?><tr><td><code><?= $this->e($backup['name']) ?></code></td><td><?= $this->e(number_format($backup['size'] / 1024, 1)) ?> KB</td><td><?= $this->e(gmdate('Y-m-d H:i', $backup['mtime'])) ?> UTC</td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
  <div class="form-section"><h2 class="form-section-title">업로드 파일 정리</h2>
    <?php if (($query['gc'] ?? '') !== ''): ?><?php if ((int) $query['gc'] === 0): ?><div class="alert alert-info"><span><?= $this->icon('info', 18) ?></span><span>정리할 파일이 없습니다.</span></div><?php else: ?><div class="alert alert-success"><span><?= $this->icon('check-circle', 18) ?></span><span>버려진 파일 <?= $this->e((string) (int) $query['gc']) ?>개를 정리했습니다.</span></div><?php endif ?><?php endif ?>
    <form method="post" action="<?= $this->url('admin.uploads.gc') ?>" class="schema-gc"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><button class="btn btn-sm" type="submit">버려진 파일 정리</button><span class="schema-note">글에 붙지 못하고 하루 넘게 남은 업로드를 지웁니다.</span></form>
  </div>
</div></section>
<?php $this->stop() ?>
