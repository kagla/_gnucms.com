<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림톡·문자 · 발송 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>messages<?php $this->stop() ?>
<?php $this->start('body') ?>
<?php
// 라디오·체크박스는 재렌더링(미리보기·검증 실패) 때 방금 입력한 값을 그대로 유지한다.
$channel = (string) ($values['channel'] ?? 'sms');
$failoverChecked = ($values['failover'] ?? '') === '1';
$selectedTplCode = (string) ($values['tpl_code'] ?? '');
?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">알림톡·문자</li></ul></div>
<?php $this->insert('admin/message/_tabs', ['active' => 'send']) ?>
<section class="card settings-card">
  <div class="card-body">
    <div class="page-head">
      <div><h1 class="card-title"><?= $this->icon('mail', 19) ?> 발송</h1><p class="card-sub">누구에게 무엇이 나갈지 "미리보기"로 먼저 확인한 뒤에만 "발송"을 누르세요. 미승인 템플릿·빈 변수·꺼진 채널은 발송 단계에서 거절됩니다.</p></div>
    </div>

    <div class="status-badges">
      <span class="badge <?= $status['alimtalk_enabled'] ? 'badge-success badge-soft' : 'badge-ghost' ?>">알림톡 발송 <?= $status['alimtalk_enabled'] ? '켜짐' : '꺼짐' ?></span>
      <span class="badge <?= $status['sms_enabled'] ? 'badge-success badge-soft' : 'badge-ghost' ?>">문자 발송 <?= $status['sms_enabled'] ? '켜짐' : '꺼짐' ?></span>
      <?php if ($status['test_mode']): ?><span class="badge badge-warning badge-soft">테스트 모드</span><?php endif ?>
    </div>

    <?php if ($notice !== null): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span><?= $this->e($notice) ?></span></div><?php endif ?>
    <?php if ($error !== null): ?><div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($error) ?></span></div><?php endif ?>

    <form method="get" action="<?= $this->url('admin.messages.send') ?>" class="member-search-form">
      <fieldset class="fieldset">
        <legend class="fieldset-legend">회원 검색</legend>
        <div class="row-actions">
          <input class="input input-bordered" type="search" name="q" value="<?= $this->e($search_query) ?>" placeholder="이름 또는 이메일" maxlength="100">
          <button class="btn btn-outline btn-sm" type="submit"><?= $this->icon('search', 15) ?> 검색</button>
        </div>
      </fieldset>
    </form>
    <?php if ($search_query !== ''): ?>
      <?php if ($search_results === []): ?>
        <p class="card-sub">"<?= $this->e($search_query) ?>" 검색 결과가 없습니다.</p>
      <?php else: ?>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">검색 결과 — 보낼 폼 아래 "받는 사람"에 체크하세요</legend>
          <?php foreach ($search_results as $m): ?>
            <label class="label toggle-row">
              <input type="checkbox" form="send-form" name="members[]" value="<?= $this->e($m['id']) ?>">
              <span><?= $this->e($m['display_name']) ?> (<?= $this->e($m['email']) ?>) · <?= $m['phone_display'] !== null ? $this->e($m['phone_display']) : '번호 없음' ?></span>
            </label>
          <?php endforeach ?>
        </fieldset>
      <?php endif ?>
    <?php endif ?>

    <form method="post" action="<?= $this->url('admin.messages.send.preview') ?>" id="send-form">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">

      <div class="form-section">
        <h2 class="form-section-title">채널</h2>
        <div class="grid-2">
          <label class="label toggle-row"><input type="radio" name="channel" value="at" data-channel-radio<?= $channel === 'at' ? ' checked' : '' ?>> 알림톡</label>
          <label class="label toggle-row"><input type="radio" name="channel" value="sms" data-channel-radio<?= $channel === 'sms' ? ' checked' : '' ?>> 문자(SMS·LMS)</label>
        </div>
      </div>

      <div class="form-section" data-channel-only="at">
        <h2 class="form-section-title">알림톡 템플릿</h2>
        <fieldset class="fieldset<?php if (array_key_exists('tpl_code', $field_errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">템플릿</legend>
          <select class="select select-bordered" name="tpl_code">
            <option value="">템플릿을 선택하세요</option>
            <?php foreach ($templates as $tpl): ?>
              <option value="<?= $this->e($tpl['tpl_code']) ?>"<?= $selectedTplCode === (string) $tpl['tpl_code'] ? ' selected' : '' ?>><?= $this->e($tpl['name']) ?> (<?= $this->e($tpl['tpl_code']) ?>)</option>
            <?php endforeach ?>
          </select>
          <?php if ($templates === []): ?><p class="fieldset-label">사용 중인 승인 템플릿이 없습니다. 템플릿 탭에서 먼저 켜 주세요.</p><?php endif ?>
          <?php if (array_key_exists('tpl_code', $field_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($field_errors['tpl_code']) ?></p><?php endif ?>
        </fieldset>
        <label class="label toggle-row">
          <input type="checkbox" name="failover" value="1"<?= $failoverChecked ? ' checked' : '' ?>>
          <span>알림톡이 실패하면 문자로 대체발송</span>
        </label>
      </div>

      <div class="form-section" data-channel-only="sms">
        <h2 class="form-section-title">문자 본문</h2>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">제목 (LMS 로 분류될 때만 쓰인다, 선택)</legend>
          <input class="input input-bordered input-block" type="text" name="title" value="<?= $this->e($values['title'] ?? '') ?>" maxlength="44">
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('body', $field_errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">본문</legend>
          <textarea class="textarea textarea-bordered textarea-block" name="body" rows="5" maxlength="2000"><?= $this->e($values['body'] ?? '') ?></textarea>
          <?php if ($preview !== null && $preview['bytes'] !== null): ?>
            <p class="fieldset-label">현재 <?= $this->e($preview['bytes']) ?>바이트 · <?= $preview['classify'] === 'lms' ? 'LMS' : 'SMS' ?></p>
          <?php endif ?>
          <?php if (array_key_exists('body', $field_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($field_errors['body']) ?></p><?php endif ?>
        </fieldset>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">변수</h2>
        <?php if ($variable_names === []): ?>
          <p class="card-sub">본문에 #{ } 로 표시한 변수가 없습니다.</p>
        <?php else: ?>
          <div class="grid-2">
          <?php foreach ($variable_names as $name): ?>
            <fieldset class="fieldset">
              <legend class="fieldset-legend">#{<?= $this->e($name) ?>}</legend>
              <input class="input input-bordered input-block" type="text" name="var_<?= $this->e($name) ?>" value="<?= $this->e($values['var_' . $name] ?? '') ?>">
            </fieldset>
          <?php endforeach ?>
          </div>
          <?php if (array_key_exists('vars', $field_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($field_errors['vars']) ?></p><?php endif ?>
        <?php endif ?>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">받는 사람</h2>
        <?php if ($selected_members !== []): ?>
          <fieldset class="fieldset">
            <legend class="fieldset-legend">선택된 회원 (<?= $this->e(count($selected_members)) ?>명)</legend>
            <?php foreach ($selected_members as $m): ?>
              <label class="label toggle-row">
                <input type="checkbox" name="members[]" value="<?= $this->e($m['id']) ?>" checked>
                <span><?= $this->e($m['display_name']) ?> · <?= $m['phone_display'] !== null ? $this->e($m['phone_display']) : '번호 없음' ?></span>
              </label>
            <?php endforeach ?>
          </fieldset>
        <?php endif ?>
        <fieldset class="fieldset<?php if (array_key_exists('recipients', $field_errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">번호 붙여넣기 (줄마다 하나씩, 콤마로도 구분 가능)</legend>
          <textarea class="textarea textarea-bordered textarea-block" name="numbers" rows="4" placeholder="010-1234-5678"><?= $this->e($values['numbers'] ?? '') ?></textarea>
          <?php if (array_key_exists('recipients', $field_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($field_errors['recipients']) ?></p><?php endif ?>
        </fieldset>
      </div>

      <?php if ($preview !== null): ?>
        <div class="form-section">
          <h2 class="form-section-title">미리보기</h2>
          <div class="alert alert-info">
            <p>받는 사람 <strong><?= $this->e($preview['count']) ?>명</strong><?php if ($preview['skipped'] > 0): ?> · 선택한 회원 중 <?= $this->e($preview['skipped']) ?>명은 번호가 없어 제외됩니다<?php endif ?><?php if ($preview['withdrawn'] > 0): ?> · 선택한 회원 중 <?= $this->e($preview['withdrawn']) ?>명은 탈퇴한 회원이라 제외됩니다<?php endif ?></p>
            <?php if ($preview['sample'] !== null): ?>
              <p class="card-sub">첫 번째 수신자에게 나갈 본문<?php if ($preview['bytes'] !== null): ?> · <?= $this->e($preview['bytes']) ?>바이트 · <?= $preview['classify'] === 'lms' ? 'LMS' : 'SMS' ?><?php endif ?></p>
              <pre class="tpl-detail-content"><?= $this->e($preview['sample']) ?></pre>
            <?php else: ?>
              <p class="card-sub">받는 사람이 없어 미리 볼 본문이 없습니다.</p>
            <?php endif ?>
          </div>
        </div>
      <?php endif ?>

      <div class="card-actions form-actions">
        <button class="btn btn-outline" type="submit"><?= $this->icon('eye', 15) ?> 미리보기</button>
        <?php if ($preview !== null): ?>
          <button class="btn btn-primary" type="submit" formaction="<?= $this->url('admin.messages.send.dispatch') ?>" formnovalidate><?= $this->icon('mail', 15) ?> 발송</button>
        <?php else: ?>
          <p class="card-sub">먼저 "미리보기"로 받는 사람·본문·제외 인원을 확인한 뒤에만 발송할 수 있습니다.</p>
        <?php endif ?>
      </div>
    </form>
  </div>
</section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script>
(function () {
  var radios = document.querySelectorAll('[data-channel-radio]');
  var sections = document.querySelectorAll('[data-channel-only]');
  if (!radios.length || !sections.length) { return; }
  function apply() {
    var picked = document.querySelector('[data-channel-radio]:checked');
    var channel = picked ? picked.value : 'sms';
    sections.forEach(function (section) {
      section.hidden = section.getAttribute('data-channel-only') !== channel;
    });
  }
  radios.forEach(function (radio) { radio.addEventListener('change', apply); });
  apply();
})();
</script>
<?php $this->stop() ?>
