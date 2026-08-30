<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?><?= $create ? ($legal ? '약관 만들기' : '내용 만들기') : ($legal ? $this->e($values['title']) . ' 수정' : '내용 수정') ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>content<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url($legal ? 'admin.terms' : 'admin.content') ?>"><?= $legal ? '약관' : '내용' ?></a></li><li aria-current="page"><?= $create ? ($legal ? '새 약관' : '새 내용') : $this->e($values['title']) ?></li></ul></div>
<section class="card">
  <div class="card-body">
    <h1 class="card-title"><?= $create ? ($legal ? '약관 만들기' : '내용 만들기') : ($legal ? $this->e($values['title']) . ' 수정' : '내용 수정') ?></h1>
    <p class="card-sub"><?= $legal ? '약관 내용을 작성하고 어디에 쓸지 정하세요. 공개된 약관은 사이트 하단에 나옵니다.' : '내용을 작성하고 공개 여부와 메뉴 표시만 정하세요.' ?></p>
    <form method="post" action="<?= $create ? $this->url($legal ? 'admin.terms.create' : 'admin.content.create') : $this->url('admin.content.edit', ['id' => $page_id]) ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="image_key" value="<?= $this->e($values['image_key']) ?>">
      <input type="hidden" name="uploaded_images" value="<?= $this->e($values['uploaded_images'] ?? '') ?>" data-uploaded-images>
      <fieldset class="fieldset<?php if (array_key_exists('title', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">제목</legend>
        <input class="input input-bordered input-block" type="text" name="title" value="<?= $this->e($values['title'] ?? '') ?>" maxlength="200" required>
        <?php if (array_key_exists('title', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['title']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?php if (array_key_exists('slug', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">주소 <span class="legend-hint"><?= ($legal && !$create) ? '동의 기록 연결을 위해 고정됩니다.' : '예: about' ?></span></legend>
        <input class="input input-bordered input-block" type="text" name="slug" value="<?= $this->e($values['slug'] ?? '') ?>" maxlength="100" pattern="[a-z0-9][a-z0-9_-]*"<?= ($legal && !$create) ? ' readonly' : '' ?> required>
        <?php if (array_key_exists('slug', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['slug']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?php if (array_key_exists('content', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">내용</legend>
        <textarea class="textarea textarea-bordered textarea-block" id="content-editor" name="content" rows="14" data-cms-editor><?= $this->e($values['content'] ?? '') ?></textarea>
        <p class="fieldset-label">사진 올리기에서 여러 장을 선택하거나 편집 영역으로 끌어놓을 수 있습니다.</p>
        <?php if (array_key_exists('content', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['content']) ?></p><?php endif ?>
      </fieldset>
      <div class="grid-2">
        <fieldset class="fieldset">
          <legend class="fieldset-legend">상태</legend>
          <select class="select select-bordered select-block" name="status">
            <option value="draft"<?= $this->def($values['status'] ?? null, 'draft') === 'draft' ? ' selected' : '' ?>>초안</option>
            <option value="published"<?= $this->def($values['status'] ?? null, 'draft') === 'published' ? ' selected' : '' ?>>공개</option>
          </select>
        </fieldset>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">메뉴 순서</legend>
          <input class="input input-bordered input-block" type="number" name="sort_order" value="<?= $this->e($values['sort_order'] ?? 0) ?>" min="-9999" max="9999" inputmode="numeric">
        </fieldset>
      </div>
      <?php if ($legal): ?>
      <?php // 사용처는 약관에만 있다. 회원가입 동의로 정하면 가입 화면에 필수·선택으로 붙는다. ?>
      <div class="grid-2">
        <fieldset class="fieldset">
          <legend class="fieldset-legend">사용처</legend>
          <select class="select select-bordered select-block" name="consent_usage">
            <option value="signup"<?= $this->def($values['consent_usage'] ?? null, 'none') === 'signup' ? ' selected' : '' ?>>회원가입 동의</option>
            <option value="form"<?= $this->def($values['consent_usage'] ?? null, 'none') === 'form' ? ' selected' : '' ?>>신청서·등록 동의</option>
            <option value="none"<?= $this->def($values['consent_usage'] ?? null, 'none') === 'none' ? ' selected' : '' ?>>안내만</option>
          </select>
          <?php // 안내는 처음 한두 번만 필요하다. 접어 두고 필요할 때만 편다. ?>
          <details class="usage-help">
            <summary>사용처가 각각 어디에 쓰이나요?</summary>
            <ul class="usage-guide">
              <li><strong>회원가입 동의</strong> — 회원가입 화면에 동의 체크박스로 나옵니다. 아래 토글로 필수·선택을 정합니다.</li>
              <li><strong>신청서·등록 동의</strong> — 신청서 같은 제출 기능이 생기면 그 화면에 붙습니다. 아직 쓰는 곳이 없으니 미리 정해 두는 용도이고, 아래 자리 이름으로 어느 폼의 약관인지 가릅니다.</li>
              <li><strong>안내만</strong> — 어디에도 붙지 않습니다. 청소년 보호정책처럼 하단 목록에서 읽기만 하는 약관입니다.</li>
            </ul>
          </details>
          <p class="fieldset-label">공개 상태여야 화면에 붙습니다.</p>
        </fieldset>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">동의 차례 <span class="legend-hint">작을수록 위</span></legend>
          <input class="input input-bordered input-block" type="number" name="consent_order" value="<?= $this->e($values['consent_order'] ?? 0) ?>" min="-9999" max="9999" inputmode="numeric">
        </fieldset>
      </div>
      <?php // 자리 이름은 신청서·등록 동의에만 뜻이 있다. 다른 사용처에서는 숨긴다. ?>
      <fieldset class="fieldset" data-scope-key<?= $this->def($values['consent_usage'] ?? null, 'none') !== 'form' ? ' hidden' : '' ?>>
        <legend class="fieldset-legend">신청서 자리 이름 <span class="legend-hint">비우면 공용 자리</span></legend>
        <input class="input input-bordered input-block" type="text" name="consent_scope_key" value="<?= $this->e($values['consent_scope_key'] ?? '') ?>" maxlength="35" pattern="[a-z0-9][a-z0-9_-]*" placeholder="예: event-2026, rental">
        <p class="fieldset-label">나중에 신청서가 여럿일 때 어느 폼의 약관인지 이 이름으로 가릅니다.</p>
      </fieldset>
      <script>
      (function () {
        var usage = document.querySelector('select[name="consent_usage"]');
        var key = document.querySelector('[data-scope-key]');
        if (!usage || !key) { return; }
        usage.addEventListener('change', function () { key.hidden = usage.value !== 'form'; });
      })();
      </script>
      <?php endif ?>
      <fieldset class="fieldset toggle-list">
        <label class="label toggle-row">
          <input class="toggle toggle-primary" type="checkbox" name="show_in_menu" value="1"<?= ($values['show_in_menu'] ?? false) ? ' checked' : '' ?>>
          <span><strong><?= $legal ? '하단에 표시' : '상단 메뉴에 표시' ?></strong><?php if ($legal): ?><small>사이트 하단의 약관 목록에 나옵니다. 끄면 주소로만 열 수 있습니다.</small><?php endif ?></span>
        </label>
        <?php if ($legal): ?>
        <label class="label toggle-row">
          <input class="toggle toggle-primary" type="checkbox" name="consent_required" value="1"<?= $this->def($values['consent_required'] ?? null, 1) ? ' checked' : '' ?>>
          <span><strong>가입할 때 반드시 동의</strong><small>풀면 선택 동의가 됩니다. 안 해도 가입은 됩니다.</small></span>
        </label>
        <?php endif ?>
      </fieldset>
      <div class="card-actions form-actions">
        <?php if (!$create): ?><a class="btn btn-outline" href="<?= $this->url('admin.content.preview', ['id' => $page_id]) ?>" target="_blank" rel="noopener"><?= $this->icon('eye', 15) ?> 미리보기</a><?php endif ?>
        <a class="btn btn-ghost" href="<?= $this->url($legal ? 'admin.terms' : 'admin.content') ?>">취소</a>
        <button class="btn btn-primary" type="submit"><?= $create ? ($legal ? '약관 만들기' : '내용 만들기') : '변경사항 저장' ?></button>
      </div>
    </form>
  </div>
  <?php if (!$create): ?>
    <form class="danger-zone" method="post" action="<?= $this->url('admin.content.delete', ['id' => $page_id]) ?>" onsubmit="return confirm('이 내용을 휴지통으로 옮길까요?')">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <div><strong><?= $this->icon('trash', 15) ?> 휴지통으로 이동</strong><p>공개가 중단되며 휴지통에서 다시 복원할 수 있습니다.</p></div>
      <button class="btn btn-error btn-outline" type="submit">휴지통으로 이동</button>
    </form>
  <?php endif ?>
</section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><?php $this->insert('admin/_editor') ?><?php $this->stop() ?>
