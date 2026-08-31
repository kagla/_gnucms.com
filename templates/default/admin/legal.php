<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>약관 관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>legal<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">약관</li></ul></div>
<div class="page-head">
  <div><h1>약관 관리</h1><p class="page-sub">약관마다 사용처를 정합니다. 회원가입 동의는 가입 화면에 붙고, 신청서·등록 동의는 그런 기능이 생기면 그 화면에 붙습니다. 안내만 하는 약관은 어디에도 붙지 않습니다.</p></div>
  <div class="page-head-actions page-head-actions-end">
    <a class="btn btn-primary" href="<?= $this->url('admin.terms.create') ?>"><?= $this->icon('plus', 16) ?> 약관 만들기</a>
  </div>
</div>
<?php if ($saved): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>저장했습니다.</span></div><?php endif ?>
<?php if ($created): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>약관을 만들었습니다.</span></div><?php endif ?>
<?php if ($deleted): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>약관을 휴지통으로 옮겼습니다.</span></div><?php endif ?>
<section class="card">
  <div class="card-body">
    <?php if ($pages === []): ?>
      <p class="cell-sub">아직 약관이 없습니다. 이용약관과 개인정보 처리방침 초안을 만들어 시작하세요.</p>
      <form method="post" action="<?= $this->url('admin.terms.setup') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <button class="btn btn-primary" type="submit">씨앗 약관 만들기</button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= $this->url('admin.terms.uses') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <div class="table-wrap">
          <table class="table table-zebra terms-table">
            <thead><tr><th>약관</th><th>공개 주소</th><th>사용처</th><th>필수</th><th>차례</th><th>동의</th><th>미동의</th><th class="right">관리</th></tr></thead>
            <tbody>
            <?php foreach ($pages as $page): ?>
              <tr>
                <td data-label="약관"><span class="cell-title"><?= $this->e($page['title']) ?></span>
                  <?php if ($page['status'] !== 'published'): ?><span class="badge badge-warning badge-soft badge-xs">초안</span><?php endif ?></td>
                <td data-label="공개 주소"><code class="kbd kbd-sm">/terms/<?= $this->e($page['slug']) ?></code></td>
                <td data-label="사용처">
                  <select class="select select-bordered select-sm" name="usage[<?= $this->e($page['id']) ?>]">
                    <option value="signup"<?= $page['usage'] === 'signup' ? ' selected' : '' ?>>회원가입 동의</option>
                    <option value="form"<?= $page['usage'] === 'form' ? ' selected' : '' ?>>신청서·등록 동의</option>
                    <option value="none"<?= $page['usage'] === 'none' ? ' selected' : '' ?>>안내만</option>
                  </select>
                  <?php if ($page['usage'] === 'form' && str_starts_with((string) ($page['uses'][0]['scope'] ?? ''), 'form:')): ?>
                    <div class="cell-sub"><code class="kbd kbd-sm"><?= $this->e(mb_substr((string) $page['uses'][0]['scope'], 5)) ?></code></div>
                  <?php endif ?>
                </td>
                <td data-label="필수"><input class="checkbox checkbox-sm" type="checkbox" name="required[<?= $this->e($page['id']) ?>]" value="1"<?= $page['usage_required'] ? ' checked' : '' ?>></td>
                <td data-label="차례"><input class="input input-bordered input-sm" type="number" name="sort_order[<?= $this->e($page['id']) ?>]" value="<?= $this->e($page['usage_order']) ?>" min="-9999" max="9999"></td>
                <td data-label="동의"><?= $this->e($page['counts']['agreed']) ?></td>
                <td data-label="미동의"><?= $this->e($page['counts']['declined']) ?></td>
                <td data-label="관리" class="right">
                  <div class="row-actions">
                    <a class="btn btn-outline btn-sm" href="<?= $this->url('admin.content.edit', ['id' => $page['id']]) ?>">수정</a>
                    <a class="btn btn-outline btn-sm" href="<?= $this->url('admin.terms.consents', ['id' => $page['id']]) ?>">동의 현황</a>
                  </div>
                </td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <p class="fieldset-label">공개 상태의 약관만 화면에 붙습니다. 초안은 사용처를 정해 두어도 나오지 않습니다.</p>
        <div class="card-actions form-actions">
          <button class="btn btn-primary" type="submit">변경사항 저장</button>
        </div>
      </form>
    <?php endif ?>
  </div>
</section>
<?php $this->stop() ?>
