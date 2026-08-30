<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>내용 관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>content<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">내용</li></ul></div>
<div class="page-head">
  <div><h1>내용 관리</h1><p class="page-sub">소개·이용안내 같은 사이트 내용을 작성합니다. 공개와 메뉴 표시만 고르면 됩니다.</p></div>
  <div class="page-head-actions">
    <a class="btn btn-outline" href="<?= $this->url('admin.content.trash') ?>"><?= $this->icon('trash', 15) ?> 휴지통<?php if ($trash_count > 0): ?> <span class="badge badge-ghost badge-sm"><?= $this->e($trash_count) ?></span><?php endif ?></a>
    <a class="btn btn-primary" href="<?= $this->url('admin.content.create') ?>"><?= $this->icon('plus', 16) ?> 내용 만들기</a>
  </div>
</div>
<?php if (($query['saved'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>내용을 저장했습니다.</span></div><?php endif ?>
<?php if (($query['deleted'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>내용을 휴지통으로 옮겼습니다.</span></div><?php endif ?>
<section class="card">
  <div class="table-wrap">
    <table class="table table-zebra">
      <thead><tr><th>제목</th><th>주소</th><th>상태</th><th>메뉴</th><th class="right">관리</th></tr></thead>
      <tbody>
      <?php if ($pages === []): ?>
        <tr class="table-empty"><td colspan="5">아직 등록된 내용이 없습니다.</td></tr>
      <?php else: foreach ($pages as $page): ?>
        <tr>
          <td data-label="제목">
            <?php if ($page['status'] === 'published'): ?><a class="cell-title link link-hover" href="<?= $this->url('content.show', ['slug' => $page['slug']]) ?>"><?= $this->e($page['title']) ?></a>
            <?php else: ?><span class="cell-title"><?= $this->e($page['title']) ?></span><?php endif ?>
          </td>
          <td data-label="주소"><code class="kbd kbd-sm">/content/<?= $this->e($page['slug']) ?></code></td>
          <td data-label="상태"><span class="badge badge-sm badge-soft <?= $page['status'] === 'published' ? 'badge-success' : 'badge-ghost' ?>"><?= $page['status'] === 'published' ? '공개' : '초안' ?></span></td>
          <td data-label="메뉴"><?= $page['show_in_menu'] ? '표시' : '숨김' ?></td>
          <td data-label="관리" class="right">
            <div class="row-actions">
              <a class="btn btn-outline btn-sm" href="<?= $this->url('admin.content.preview', ['id' => $page['id']]) ?>" target="_blank" rel="noopener">미리보기</a>
              <a class="btn btn-outline btn-sm" href="<?= $this->url('admin.content.edit', ['id' => $page['id']]) ?>">수정</a>
            </div>
          </td>
        </tr>
      <?php endforeach; endif ?>
      </tbody>
    </table>
  </div>
</section>
<?php $this->stop() ?>
