<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>회원 관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>members<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">회원</li></ul></div>
<div class="page-head">
  <div><h1>회원 관리</h1><p class="page-sub">회원 정보를 수정하거나 필요한 경우 이용을 차단합니다. 소유자 권한은 변경되지 않습니다.</p></div>
</div>
<?php if ($saved): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>회원 정보를 저장했습니다.</span></div><?php endif ?>
<?php if ($mail_failed): ?><div class="alert alert-warning"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span>비밀번호는 바뀌었지만 변경 알림 메일은 보내지 못했습니다. 메일 설정을 확인하세요.</span></div><?php endif ?>
<form class="inline-search" method="get" action="<?= $this->url('admin.members') ?>" role="search">
  <label class="input input-bordered">
    <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 16) ?></span>
    <input type="search" name="q" value="<?= $this->e($query) ?>" placeholder="이메일 또는 표시 이름 검색" aria-label="회원 검색" data-search-input>
  </label>
  <button class="btn btn-primary" type="submit">검색</button>
</form>
<section class="card">
  <div class="table-wrap">
    <table class="table table-zebra">
      <thead><tr><th>회원</th><th>가입일</th><th>상태</th><th class="right">관리</th></tr></thead>
      <tbody>
      <?php if ($members === []): ?>
        <tr class="table-empty"><td colspan="4">조건에 맞는 회원이 없습니다.</td></tr>
      <?php else: foreach ($members as $member): ?>
        <tr>
          <td data-label="회원">
            <div class="cell-user">
              <span class="avatar avatar-placeholder avatar-sm">
                <span class="avatar-inner" data-tone="<?= $this->e(mb_strlen((string) $member['display_name']) % 6) ?>" aria-hidden="true"><span><?= $this->e(mb_strtoupper(mb_substr((string) $member['display_name'], 0, 1))) ?></span></span>
              </span>
              <div>
                <div class="cell-title"><?= $this->e($member['display_name']) ?><?php if ($member['is_admin']): ?> <span class="badge badge-primary badge-soft badge-xs">소유자</span><?php endif ?></div>
                <div class="cell-sub"><?= $this->e($member['email']) ?></div>
              </div>
            </div>
          </td>
          <td data-label="가입일"><?= $this->date($member['created_at'], 'Y.m.d') ?></td>
          <td data-label="상태"><span class="badge badge-sm <?= $member['status'] === 'active' ? 'badge-success' : 'badge-error' ?> badge-soft"><?= $member['status'] === 'active' ? '활성' : '차단' ?></span></td>
          <td data-label="관리" class="right">
            <div class="row-actions">
              <a class="btn btn-outline btn-sm" href="<?= $this->url('admin.members.edit', ['id' => $member['id']]) ?>">수정</a>
              <form method="post" action="<?= $this->url('admin.members.status', ['id' => $member['id']]) ?>">
                <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
                <button class="btn btn-outline btn-sm" type="submit"><?= $member['status'] === 'active' ? '차단' : '해제' ?></button>
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
