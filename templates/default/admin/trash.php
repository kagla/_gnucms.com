<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>내용 휴지통 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>content<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.content') ?>">내용</a></li><li aria-current="page">휴지통</li></ul></div>
<div class="page-head">
  <div><h1>내용 휴지통</h1><p class="page-sub">삭제한 내용은 공개되지 않으며 언제든 복원할 수 있습니다.</p></div>
  <div class="page-head-actions"><a class="btn btn-outline" href="<?= $this->url('admin.content') ?>"><?= $this->icon('arrow-left', 15) ?> 내용 관리로</a></div>
</div>
<?php if (($query['restored'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>내용을 초안 상태로 복원했습니다.</span></div><?php endif ?>
<?php if (($query['deleted'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>내용과 연결된 이미지를 완전히 삭제했습니다.</span></div><?php endif ?>
<section class="card">
  <div class="table-wrap">
    <table class="table table-zebra">
      <thead><tr><th>제목</th><th>주소</th><th>삭제일</th><th class="right">관리</th></tr></thead>
      <tbody>
      <?php if ($pages === []): ?>
        <tr class="table-empty"><td colspan="4">휴지통이 비어 있습니다.</td></tr>
      <?php else: foreach ($pages as $page): ?>
        <tr>
          <td data-label="제목"><span class="cell-title"><?= $this->e($page['title']) ?></span></td>
          <td data-label="주소"><code class="kbd kbd-sm">/content/<?= $this->e($page['slug']) ?></code></td>
          <td data-label="삭제일"><?= $this->date($page['deleted_at'], 'Y.m.d H:i') ?></td>
          <td data-label="관리" class="right">
            <div class="row-actions">
              <form method="post" action="<?= $this->url('admin.content.restore', ['id' => $page['id']]) ?>">
                <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
                <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('restore', 14) ?> 복원</button>
              </form>
              <form method="post" action="<?= $this->url('admin.content.permanent_delete', ['id' => $page['id']]) ?>" onsubmit="return confirm('내용과 연결된 이미지를 모두 완전히 삭제할까요? 이 작업은 되돌릴 수 없습니다.')">
                <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
                <button class="btn btn-error btn-outline btn-sm" type="submit"><?= $this->icon('trash', 14) ?> 완전 삭제</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif ?>
      </tbody>
    </table>
  </div>
</section>
<?php $this->stop() ?>
