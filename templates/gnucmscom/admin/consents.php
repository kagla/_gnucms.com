<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?><?= $this->e($page['title']) ?> 동의 현황 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>legal<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.terms') ?>">약관</a></li><li aria-current="page"><?= $this->e($page['title']) ?></li></ul></div>
<section class="card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->e($page['title']) ?> 동의 현황</h1>
    <p class="card-sub">동의 <?= $this->e($counts['agreed']) ?>건 · 동의 안 함 <?= $this->e($counts['declined']) ?>건. 보여 준 항목은 동의하지 않았어도 남습니다.</p>
    <?php if ($rows === []): ?>
      <p class="cell-sub">아직 기록이 없습니다.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table-zebra">
          <thead><tr><th>대상</th><th>자리</th><th>동의</th><th>시각</th><th>그때 본 판</th><th>증적</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td data-label="대상">
                <?php if ($row['subject_type'] === 'user'): ?>
                  <span class="cell-title"><?= $this->e($this->def($row['user_email'] ?? null, '지워진 회원')) ?></span>
                <?php else: ?>
                  <span class="cell-title">제출 #<?= $this->e($row['subject_id']) ?></span>
                <?php endif ?>
              </td>
              <td data-label="자리"><code class="kbd kbd-sm"><?= $this->e($row['scope']) ?></code></td>
              <td data-label="동의"><span class="badge badge-sm badge-soft <?= $row['agreed'] ? 'badge-success' : 'badge-ghost' ?>"><?= $row['agreed'] ? '동의' : '안 함' ?></span></td>
              <td data-label="시각"><?= $this->date($row['agreed_at'], 'Y.m.d H:i') ?></td>
              <td data-label="그때 본 판"><?= $this->date($row['content_updated_at'], 'Y.m.d H:i') ?>
                <?php if ($page['updated_at'] > $row['content_updated_at']): ?><span class="badge badge-warning badge-soft badge-xs">그 뒤 바뀜</span><?php endif ?></td>
              <td data-label="증적"><span class="cell-sub"><?= $this->e($this->def($row['agreed_ip'] ?? null, '-')) ?></span></td>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
  </div>
</section>
<?php $this->stop() ?>
