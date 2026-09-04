<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>시스템·유지보수 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">시스템·유지보수</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'maintenance']) ?>
<section class="card settings-card"><div class="card-body">
  <h1 class="card-title"><?= $this->icon('shield', 19) ?> 시스템·유지보수</h1><p class="card-sub">일반 설정이 아닌 데이터베이스 갱신 상태, 백업과 파일 정리 도구입니다.</p>
  <?php if (!empty($backup_error)): ?><div class="alert alert-error"><span><?= $this->icon('info', 18) ?></span><span><?= $this->e($backup_error) ?></span></div><?php endif ?>
  <?php if (($query['backup_created'] ?? '') !== ''): ?><div class="alert alert-success"><span><?= $this->icon('check-circle', 18) ?></span><span><code><?= $this->e((string) $query['backup_created']) ?></code> 전체 백업을 만들고 검증했습니다.</span></div><?php endif ?>
  <?php if (($query['backup_uploaded'] ?? '') !== ''): ?><div class="alert alert-success"><span><?= $this->icon('check-circle', 18) ?></span><span><code><?= $this->e((string) $query['backup_uploaded']) ?></code> 백업을 업로드하고 검증했습니다.</span></div><?php endif ?>
  <?php if (($query['backup_verified'] ?? '') !== ''): ?><div class="alert alert-success"><span><?= $this->icon('check-circle', 18) ?></span><span><code><?= $this->e((string) $query['backup_verified']) ?></code>의 형식과 체크섬, DB 무결성이 올바릅니다.</span></div><?php endif ?>
  <?php if (($query['backup_restored'] ?? '') !== ''): ?><div class="alert alert-success"><span><?= $this->icon('check-circle', 18) ?></span><span><code><?= $this->e((string) $query['backup_restored']) ?></code>을 복원했습니다. 복원 직전 상태는 <code><?= $this->e((string) ($query['safety_backup'] ?? '')) ?></code>에 보관했습니다.</span></div><?php endif ?>
  <?php if (($query['backup_deleted'] ?? '') !== ''): ?><div class="alert alert-success"><span><?= $this->icon('check-circle', 18) ?></span><span><code><?= $this->e((string) $query['backup_deleted']) ?></code> 백업을 삭제했습니다.</span></div><?php endif ?>
  <?php if (($query['schema_backup_deleted'] ?? '') !== ''): ?><div class="alert alert-success"><span><?= $this->icon('check-circle', 18) ?></span><span><code><?= $this->e((string) $query['schema_backup_deleted']) ?></code> 자동 DB 백업을 삭제했습니다.</span></div><?php endif ?>
  <h2 class="form-section-title">데이터베이스 상태</h2><dl class="schema-facts"><div><dt>판 번호</dt><dd><?= $this->e($schema['version']) ?> <small class="schema-stamp"><?= $this->e($schema['stamp']) ?></small></dd></div><div><dt>마지막으로 옮긴 시각</dt><dd><?= $schema['upgraded_at'] !== null ? $this->date($schema['upgraded_at'], 'Y-m-d H:i:s') . ' ' . $this->e((string) $site['timezone']) : '설치 이후 없음' ?></dd></div><div><dt>마지막 백업</dt><dd><?= $schema['backup'] !== null ? $this->e(basename($schema['backup'])) : '없음' ?></dd></div></dl>
  <?php if (!$schema['can_backup']): ?><p class="schema-note">스키마 갱신 직전 자동 DB 백업은 SQLite에서만 만듭니다. 아래 전체 백업은 사용 가능한 네이티브 DB 도구를 이용합니다.</p><?php elseif ($schema['backups'] === []): ?><p class="schema-note">아직 자동 DB 백업이 없습니다. 판이 바뀔 때 <code>storage/backups/</code>에 최근 <?= $this->e((string) $schema['keep']) ?>개까지 남깁니다.</p><?php else: ?><div class="overflow-x-auto"><table class="table table-sm schema-backups"><thead><tr><th>자동 DB 백업</th><th>크기</th><th>만든 시각 (<?= $this->e((string) $site['timezone']) ?>)</th><th>관리</th></tr></thead><tbody><?php foreach ($schema['backups'] as $item): ?><tr><td><code><?= $this->e($item['name']) ?></code></td><td><?= $this->e(number_format($item['size'] / 1024, 1)) ?> KB</td><td><?= $this->date((int) $item['mtime'], 'Y-m-d H:i:s') ?></td><td><form method="post" action="<?= $this->url('admin.schema-backups.delete', ['name' => $item['name']]) ?>" onsubmit="return confirm('이 자동 DB 백업을 삭제할까요? 삭제한 파일은 복구할 수 없습니다.')"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><button class="btn btn-xs btn-error btn-outline" type="submit">삭제</button></form></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>

  <div class="form-section backup-section"><h2 class="form-section-title">전체 수동 백업</h2>
    <p class="schema-note">DB, 첨부 파일, 에디터 이미지, 프로필 이미지와 복원에 필요한 설정을 하나의 압축 파일로 보관합니다. 파일명과 화면 시각은 현재 사이트 시간대(<code><?= $this->e((string) $site['timezone']) ?></code>)를 사용합니다. 옮길 서버 환경에 맞춰 ZIP 또는 TAR를 선택하세요. 설정에는 DB 비밀번호와 암호화 키가 포함될 수 있으므로 내려받은 파일도 비공개로 보관하세요.</p>
    <?php if ($backup['can_create']): ?>
      <div class="schema-gc backup-create-row"><div class="backup-create-actions">
        <?php if (in_array('zip', $backup['available_formats'], true)): ?><form method="post" action="<?= $this->url('admin.backups.create') ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="format" value="zip"><button class="btn btn-primary btn-sm" type="submit">ZIP 백업 만들기</button></form><?php endif ?>
        <?php if (in_array('tar', $backup['available_formats'], true)): ?><form method="post" action="<?= $this->url('admin.backups.create') ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="format" value="tar"><button class="btn btn-sm" type="submit">TAR 백업 만들기</button></form><?php endif ?>
      </div><span class="schema-note">큰 사이트나 웹 실행 시간이 짧은 서버에서는 <code>php bin/backup.php create --format=zip|tar</code>를 사용하세요.</span></div>
    <?php else: ?>
      <div class="alert alert-warning"><span><?= $this->icon('info', 18) ?></span><span><?= $this->e((string) $backup['unavailable_reason']) ?></span></div>
    <?php endif ?>

    <div class="backup-import"><h3>내려받은 백업 가져오기</h3>
      <form method="post" action="<?= $this->url('admin.backups.upload') ?>?csrf_token=<?= rawurlencode($csrf_token) ?>" enctype="multipart/form-data" class="backup-import-form"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><label class="fieldset"><span class="fieldset-legend">GNUCMS ZIP 또는 TAR 백업 파일</span><input class="file-input file-input-bordered" type="file" name="backup_file" accept=".zip,.tar,application/zip,application/x-tar" required></label><button class="btn btn-sm" type="submit">업로드하고 검증</button></form>
      <p class="schema-note"><?php if ((int) $backup_upload_max_mb > 0): ?>현재 서버의 웹 업로드 한도는 약 <?= $this->e((string) $backup_upload_max_mb) ?>MB입니다. <?php endif ?>더 큰 파일은 서버의 안전한 폴더에 올린 뒤 CLI에서 <code>verify</code>와 <code>restore</code>를 실행하세요. 파일명이 바뀌어도 내부 정보로 판별하며 기존 백업은 덮어쓰지 않습니다.</p>
    </div>

    <?php if ($backup['archives'] === []): ?>
      <p class="schema-note">아직 전체 수동 백업이 없습니다.</p>
    <?php else: ?>
      <div class="overflow-x-auto"><table class="table table-sm manual-backups"><thead><tr><th>백업 파일</th><th>DB</th><th>크기</th><th>검증</th><th>작업</th></tr></thead><tbody>
      <?php foreach ($backup['archives'] as $item): ?><tr<?= ($query['backup_uploaded'] ?? '') === $item['name'] ? ' id="backup-uploaded" class="is-uploaded-backup"' : '' ?>>
        <td><code><?= $this->e($item['name']) ?></code><?php if (($query['backup_uploaded'] ?? '') === $item['name']): ?> <span class="badge badge-sm badge-primary backup-uploaded-badge">방금 업로드</span><?php endif ?><br><small><?= $item['created_at'] !== null ? $this->date($item['created_at'], 'Y-m-d H:i:s') : $this->e((string) ($item['error'] ?? '')) ?></small></td>
        <td><?= $this->e((string) ($item['driver'] ?? '-')) ?></td>
        <td><?= $this->e(number_format($item['size'] / 1048576, 2)) ?> MB</td>
        <td><?= $item['verified_at'] !== null ? $this->date($item['verified_at'], 'Y-m-d H:i:s') : '확인 필요' ?></td>
        <td><div class="backup-actions-row"><div class="backup-safe-actions">
          <a class="btn btn-xs" href="<?= $this->url('admin.backups.download', ['name' => $item['name']]) ?>">내려받기</a>
          <form method="post" action="<?= $this->url('admin.backups.verify', ['name' => $item['name']]) ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><button class="btn btn-xs" type="submit">검증</button></form>
        </div><div class="backup-danger-actions">
          <?php if ($backup['can_restore'] && ($item['driver'] ?? null) === 'sqlite' && !isset($item['error'])): ?><details><summary class="btn btn-xs btn-warning btn-outline">복원</summary><form method="post" action="<?= $this->url('admin.backups.restore', ['name' => $item['name']]) ?>" class="backup-restore-form"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><label><span>현재 DB와 파일을 이 백업으로 바꿉니다.<br>계속하려면 아래 칸에 <strong>복원</strong>을 입력하세요.</span><input class="input input-xs" type="text" name="confirmation" placeholder="복원" aria-label="복원 확인 문구" autocomplete="off" spellcheck="false" required></label><button class="btn btn-warning btn-xs" type="submit">확인하고 복원</button></form></details><?php endif ?>
          <form method="post" action="<?= $this->url('admin.backups.delete', ['name' => $item['name']]) ?>" onsubmit="return confirm('이 전체 백업을 삭제할까요? 삭제한 파일은 복구할 수 없습니다.')"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><button class="btn btn-xs btn-error btn-outline" type="submit">삭제</button></form>
        </div></div>
        </td>
      </tr><?php endforeach ?>
      </tbody></table></div>
    <?php endif ?>

    <?php if ($backup['driver'] !== 'sqlite'): ?><div class="backup-instructions"><h3>DB 복원 절차</h3><p class="schema-note">원격 DB는 권한·버전·실행 시간 차이 때문에 웹에서 자동 복원하지 않습니다. 전체 백업을 내려받고 쓰기를 중지한 뒤 아래 절차를 서버 콘솔에서 실행하세요.</p><ol><?php foreach ($backup['instructions'] as $instruction): ?><li><code><?= $this->e($instruction) ?></code></li><?php endforeach ?></ol></div><?php endif ?>
  </div>

  <div class="form-section"><h2 class="form-section-title">업로드 파일 정리</h2>
    <?php if (($query['gc'] ?? '') !== ''): ?><?php if ((int) $query['gc'] === 0): ?><div class="alert alert-info"><span><?= $this->icon('info', 18) ?></span><span>정리할 파일이 없습니다.</span></div><?php else: ?><div class="alert alert-success"><span><?= $this->icon('check-circle', 18) ?></span><span>버려진 파일 <?= $this->e((string) (int) $query['gc']) ?>개를 정리했습니다.</span></div><?php endif ?><?php endif ?>
    <p class="schema-note">글에 붙지 못한 채 24시간 넘게 남은 첨부 파일과 그 축소본만 표시합니다. 글에서 사용 중이거나 방금 올린 파일은 제외합니다.</p>
    <?php if ($garbage['items'] === []): ?>
      <p class="schema-note upload-garbage-empty">현재 정리할 업로드 파일이 없습니다.</p>
    <?php else: ?>
      <p class="upload-garbage-summary">삭제 예정 <strong><?= $this->e((string) $garbage['files']) ?>개</strong> · <?= $this->e(number_format($garbage['bytes'] / 1024, 1)) ?> KB</p>
      <div class="overflow-x-auto"><table class="table table-sm upload-garbage-list"><thead><tr><th>저장 경로</th><th>파일 수</th><th>용량</th><th>마지막 변경 (<?= $this->e((string) $site['timezone']) ?>)</th></tr></thead><tbody>
      <?php foreach ($garbage['items'] as $item): ?><tr><td><code><?= $this->e($item['relative_path']) ?></code></td><td><?= $this->e((string) $item['file_count']) ?>개<?= $item['file_count'] > 1 ? ' (축소본 포함)' : '' ?></td><td><?= $this->e(number_format($item['size'] / 1024, 1)) ?> KB</td><td><?= $this->date((int) $item['mtime'], 'Y-m-d H:i:s') ?></td></tr><?php endforeach ?>
      </tbody></table></div>
      <form method="post" action="<?= $this->url('admin.uploads.gc') ?>" class="schema-gc" onsubmit="return confirm('위 목록의 파일을 삭제할까요? 실행 시 대상을 다시 확인하며 삭제한 파일은 복구할 수 없습니다.')"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><button class="btn btn-sm btn-error btn-outline" type="submit">정리 대상 <?= $this->e((string) $garbage['files']) ?>개 삭제</button><span class="schema-note">실행 직전에 대상을 다시 확인한 후 삭제합니다.</span></form>
    <?php endif ?>
  </div>
</div></section>
<?php $this->stop() ?>
