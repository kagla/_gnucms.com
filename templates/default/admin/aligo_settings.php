<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림톡·문자 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">알림톡·문자</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'aligo']) ?>
<section class="card settings-card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('bell', 19) ?> 알림톡·문자 설정</h1>
    <p class="card-sub">카카오 알림톡과 문자(SMS·LMS)를 보낼 알리고(Aligo) 계정을 연결합니다.</p>

    <div class="status-badges">
      <span class="badge <?= $status['configured'] ? 'badge-success badge-soft' : 'badge-ghost' ?>"><?= $status['configured'] ? '계정 연결됨' : '계정 연결 안 됨' ?></span>
      <span class="badge <?= $status['alimtalk_enabled'] ? 'badge-success badge-soft' : 'badge-ghost' ?>">알림톡 발송 <?= $status['alimtalk_enabled'] ? '켜짐' : '꺼짐' ?></span>
      <span class="badge <?= $status['sms_enabled'] ? 'badge-success badge-soft' : 'badge-ghost' ?>">문자 발송 <?= $status['sms_enabled'] ? '켜짐' : '꺼짐' ?></span>
      <?php if ($status['test_mode']): ?><span class="badge badge-warning badge-soft">테스트 모드</span><?php endif ?>
      <?php if ($status['pending'] > 0): ?><span class="badge badge-info badge-soft"><?= $this->e($status['pending']) ?>건 대기 중</span><?php endif ?>
    </div>

    <?php // 취소하지 못한 예약이 남았다는 안내에 초록 체크를 붙이면 안 된다 — 'ok' 가 그것을 가른다. ?>
    <?php if ($notice !== null): ?><div class="alert <?= $notice['ok'] ? 'alert-success' : 'alert-warning' ?>"><span aria-hidden="true"><?= $this->icon($notice['ok'] ? 'check-circle' : 'warning', 18) ?></span><span><?= $this->e($notice['message']) ?></span></div><?php endif ?>
    <?php if ($error !== null): ?><div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($error) ?></span></div><?php endif ?>

    <form method="post" action="<?= $this->url('admin.aligo') ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">

      <div class="form-section">
        <h2 class="form-section-title">계정</h2>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('user_id', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">알리고 사용자 ID</legend>
            <input class="input input-bordered input-block" type="text" name="user_id" value="<?= $this->e($values['user_id'] ?? '') ?>" maxlength="60" required>
            <?php if (array_key_exists('user_id', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['user_id']) ?></p><?php endif ?>
          </fieldset>
          <fieldset class="fieldset<?php if (array_key_exists('api_key', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">API 키</legend>
            <input class="input input-bordered input-block" type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?= ($values['api_key_set'] ?? false) ? '저장됨' : 'API 키 입력' ?>" maxlength="200">
            <?php if ($values['api_key_set'] ?? false): ?>
              <label class="label toggle-row fieldset-label"><input type="checkbox" name="api_key_delete" value="1"> 저장된 API 키 삭제</label>
            <?php endif ?>
            <?php if (array_key_exists('api_key', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['api_key']) ?></p><?php endif ?>
            <p class="fieldset-label">보안상 저장된 키는 다시 보여주지 않습니다. 바꿀 때만 새 값을 입력하세요.</p>
          </fieldset>
        </div>
        <div class="grid-2">
          <fieldset class="fieldset">
            <legend class="fieldset-legend">알림톡 전용 API 키 (선택)</legend>
            <input class="input input-bordered input-block" type="password" name="alimtalk_api_key" value="" autocomplete="new-password" placeholder="<?= ($values['alimtalk_api_key_set'] ?? false) ? '저장됨' : '비워두면 위 API 키를 함께 씁니다' ?>" maxlength="200">
            <?php if ($values['alimtalk_api_key_set'] ?? false): ?>
              <label class="label toggle-row fieldset-label"><input type="checkbox" name="alimtalk_api_key_delete" value="1"> 저장된 알림톡 키 삭제</label>
            <?php endif ?>
            <p class="fieldset-label">알림톡만 별도 키를 쓰는 계정일 때만 입력하세요. 비워두면 API 키를 그대로 씁니다.</p>
          </fieldset>
          <fieldset class="fieldset">
            <legend class="fieldset-legend">테스트 모드</legend>
            <label class="label toggle-row">
              <input class="toggle" type="checkbox" name="test_mode" value="1"<?= ($values['test_mode'] ?? false) ? ' checked' : '' ?>>
              <span>알리고 API는 호출하되 실제로 발송하지 않습니다</span>
            </label>
          </fieldset>
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">발신 정보</h2>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('sender', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">발신번호</legend>
            <input class="input input-bordered input-block" type="text" name="sender" value="<?= $this->e($values['sender'] ?? '') ?>" maxlength="20" required>
            <?php if (array_key_exists('sender', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['sender']) ?></p><?php endif ?>
            <p class="fieldset-label">알리고에 사전 등록된 발신번호와 같아야 합니다.</p>
          </fieldset>
          <fieldset class="fieldset<?php if (array_key_exists('senderkey', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">발신프로필키 (카카오채널)</legend>
            <input class="input input-bordered input-block" type="text" id="aligo-senderkey" name="senderkey" value="<?= $this->e($values['senderkey'] ?? '') ?>" maxlength="64">
            <?php if (array_key_exists('senderkey', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['senderkey']) ?></p><?php endif ?>
            <p class="fieldset-label">아래 "채널 불러오기"로 목록에서 고르거나 직접 입력하세요.</p>
          </fieldset>
        </div>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">채널명 (선택)</legend>
          <input class="input input-bordered input-block" type="text" id="aligo-channel-name" name="channel_name" value="<?= $this->e($values['channel_name'] ?? '') ?>" maxlength="60" placeholder="예: @상점">
        </fieldset>
      </div>

      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a>
        <button class="btn btn-primary" type="submit">설정 저장</button>
      </div>
    </form>

    <div class="form-section">
      <h2 class="form-section-title">발신프로필 조회</h2>
      <form method="post" action="<?= $this->url('admin.aligo.profiles') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <button class="btn btn-outline" type="submit"><?= $this->icon('search', 15) ?> 채널 불러오기</button>
      </form>

      <?php if ($profiles !== []): ?>
      <fieldset class="fieldset profile-list">
        <legend class="fieldset-legend">알리고에 등록된 카카오채널</legend>
        <?php foreach ($profiles as $profile): ?>
          <?php
            $senderKey = (string) ($profile['senderKey'] ?? '');
            $name = (string) ($profile['name'] ?? '');
            $profileStatus = (string) ($profile['status'] ?? '');
          ?>
          <label class="label toggle-row">
            <input type="radio" name="profile_pick" value="<?= $this->e($senderKey) ?>" data-senderkey="<?= $this->e($senderKey) ?>" data-name="<?= $this->e($name) ?>"<?= ((string) ($values['senderkey'] ?? '')) === $senderKey ? ' checked' : '' ?>>
            <span><?= $this->e($name) ?> · <?= $this->e($senderKey) ?> <span class="badge badge-sm badge-soft">상태 <?= $this->e($profileStatus) ?></span></span>
          </label>
        <?php endforeach ?>
        <p class="fieldset-label">고르면 위 "발신프로필키"·"채널명" 칸에 채워집니다. 저장하려면 "설정 저장"을 다시 눌러 주세요.</p>
      </fieldset>
      <?php endif ?>
    </div>

    <div class="form-section">
      <h2 class="form-section-title">연결 확인</h2>
      <form method="post" action="<?= $this->url('admin.aligo.verify') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <button class="btn btn-outline" type="submit"><?= $this->icon('shield', 15) ?> 연결 확인</button>
      </form>

      <?php if ($verified !== null): ?>
        <?php $allOk = $verified['alimtalk']['ok'] && $verified['sms']['ok']; ?>
        <div class="alert <?= $allOk ? 'alert-success' : 'alert-warning' ?> verify-result">
          <ul>
            <li>
              <?php if ($verified['alimtalk']['ok']): ?>
                알림톡 <?= $this->e($verified['alimtalk']['count']) ?>건 남았습니다
              <?php else: ?>
                <strong>알림톡 확인 실패</strong> — <?= $this->e($verified['alimtalk']['reason']) ?>
              <?php endif ?>
            </li>
            <li>
              <?php if ($verified['sms']['ok']): ?>
                SMS <?= $this->e($verified['sms']['sms_count']) ?>건 · LMS <?= $this->e($verified['sms']['lms_count']) ?>건 남았습니다
              <?php else: ?>
                <strong>문자 확인 실패</strong> — <?= $this->e($verified['sms']['reason']) ?>
              <?php endif ?>
            </li>
          </ul>
        </div>
      <?php endif ?>
    </div>

    <div class="form-section">
      <h2 class="form-section-title">채널별 발송 허용</h2>
      <p class="card-sub">계정을 저장하기 전에는 켤 수 없습니다.</p>
      <div class="toggle-list">
        <div class="toggle-row-line">
          <span>알림톡 발송</span>
          <form method="post" action="<?= $this->url('admin.aligo.toggle') ?>">
            <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
            <input type="hidden" name="channel" value="at">
            <input type="hidden" name="action" value="<?= $status['alimtalk_enabled'] ? 'disable' : 'enable' ?>">
            <button class="btn btn-sm <?= $status['alimtalk_enabled'] ? 'btn-outline' : 'btn-primary' ?>" type="submit"<?= (!$status['configured'] && !$status['alimtalk_enabled']) ? ' disabled' : '' ?>><?= $status['alimtalk_enabled'] ? '끄기' : '켜기' ?></button>
          </form>
        </div>
        <div class="toggle-row-line">
          <span>문자(SMS·LMS) 발송</span>
          <form method="post" action="<?= $this->url('admin.aligo.toggle') ?>">
            <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
            <input type="hidden" name="channel" value="sms">
            <input type="hidden" name="action" value="<?= $status['sms_enabled'] ? 'disable' : 'enable' ?>">
            <button class="btn btn-sm <?= $status['sms_enabled'] ? 'btn-outline' : 'btn-primary' ?>" type="submit"<?= (!$status['configured'] && !$status['sms_enabled']) ? ' disabled' : '' ?>><?= $status['sms_enabled'] ? '끄기' : '켜기' ?></button>
          </form>
        </div>
      </div>
      <?php if (array_key_exists('channel', $errors) || array_key_exists('api_key', $errors)): ?>
        <p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['channel'] ?? $errors['api_key']) ?></p>
      <?php endif ?>
    </div>
  </div>
</section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script>
(function(){
  var radios=document.querySelectorAll('[data-senderkey]');
  var senderkey=document.getElementById('aligo-senderkey'),channelName=document.getElementById('aligo-channel-name');
  if(!radios.length||!senderkey){return}
  radios.forEach(function(radio){
    radio.addEventListener('change',function(){
      senderkey.value=radio.dataset.senderkey||'';
      if(channelName){channelName.value=radio.dataset.name||''}
    });
  });
})();
</script>
<?php $this->stop() ?>
