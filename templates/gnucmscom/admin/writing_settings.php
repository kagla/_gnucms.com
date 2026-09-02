<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>회원·글쓰기 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">회원·글쓰기</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'writing']) ?>
<section class="card settings-card"><div class="card-body">
  <h1 class="card-title"><?= $this->icon('edit', 19) ?> 회원·글쓰기 설정</h1><p class="card-sub">비회원 작성과 본문, 댓글 및 첨부 파일 제한을 정합니다.</p>
  <?php if (($query['saved'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>회원·글쓰기 설정을 저장했습니다.</span></div><?php endif ?>
  <form method="post" action="<?= $this->url('admin.settings.writing') ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
    <div class="form-section settings-write-rules">
      <fieldset class="fieldset toggle-list settings-write-toggle"><label class="label toggle-row"><input class="toggle toggle-primary" type="checkbox" name="guest_write_enabled" value="1"<?= ($values['guest_write_enabled'] ?? false) ? ' checked' : '' ?>><span><strong>비회원 글쓰기 허용</strong><small>게시판 쓰기 권한이 “누구나”여도 이 스위치가 꺼져 있으면 회원만 글을 쓸 수 있습니다.</small></span></label></fieldset>
      <div class="settings-write-rules-row">
        <fieldset class="fieldset"><legend class="fieldset-legend">본문 최소 글자수</legend><input class="input input-bordered input-block" type="number" name="post_min_chars" min="0" max="10000" value="<?= $this->e((string) ($values['post_min_chars'] ?? 0)) ?>" required><p class="fieldset-label">0 = 제한 없음. 태그와 공백을 뺀 글자 수입니다.</p></fieldset>
        <fieldset class="fieldset"><legend class="fieldset-legend">댓글 최소 글자수</legend><input class="input input-bordered input-block" type="number" name="comment_min_chars" min="0" max="1000" value="<?= $this->e((string) ($values['comment_min_chars'] ?? 0)) ?>" required><p class="fieldset-label">0 = 제한 없음.</p></fieldset>
      </div><div class="settings-write-rules-row">
        <fieldset class="fieldset"><legend class="fieldset-legend">글당 첨부 개수</legend><input class="input input-bordered input-block" type="number" name="attach_limit" min="0" max="999" value="<?= $this->e((string) ($values['attach_limit'] ?? 5)) ?>" required><p class="fieldset-label">0 = 무제한. 게시판별 파일 사용 설정도 켜야 합니다.</p></fieldset>
        <fieldset class="fieldset"><legend class="fieldset-legend">파일당 최대 용량 (MB)</legend><input class="input input-bordered input-block" type="number" name="attach_max_mb" min="1" max="1024" value="<?= $this->e((string) ($values['attach_max_mb'] ?? 5)) ?>" required><p class="fieldset-label"><?= (int) $server_max_mb === 0 ? '서버 PHP 한계가 없습니다.' : '서버 PHP 한계는 ' . $this->e((string) $server_max_mb) . ' MB입니다.' ?></p></fieldset>
      </div>
    </div><div class="card-actions form-actions"><a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a><button class="btn btn-primary" type="submit">설정 저장</button></div>
  </form>
</div></section>
<?php $this->stop() ?>
