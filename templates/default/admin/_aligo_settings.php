<section class="card settings-card">
  <div class="card-body">
    <h2 class="card-title"><?= $this->icon('bell', 19) ?> 문자·알림톡 설정</h2>
    <p class="card-sub">카카오 알림톡과 문자(SMS·LMS)를 보낼 알리고(Aligo) 계정을 연결합니다. 알림톡과 문자는 알리고 안에서도 따로 신청하는 별개의 서비스라, 아래 칸을 어느 채널에 쓰이는지로 묶어 두었습니다.</p>
    <div><a class="btn btn-outline btn-sm" href="https://smartsms.aligo.in/" target="_blank" rel="noopener noreferrer" aria-label="알리고 사이트로 이동 (새 창)">알리고 사이트로 이동 <?= $this->icon('external', 14) ?></a></div>

    <div class="status-badges">
      <span class="badge <?= $status['configured'] ? 'badge-success badge-soft' : 'badge-ghost' ?>"><?= $status['configured'] ? '계정 연결됨' : '계정 연결 안 됨' ?></span>
      <span class="badge <?= $status['alimtalk_switch_on'] ? 'badge-success badge-soft' : 'badge-ghost' ?>" data-aligo-status="at">알림톡 발송 <?= $status['alimtalk_switch_on'] ? '켜짐' : '꺼짐' ?></span>
      <span class="badge <?= $status['sms_switch_on'] ? 'badge-success badge-soft' : 'badge-ghost' ?>" data-aligo-status="sms">문자 발송 <?= $status['sms_switch_on'] ? '켜짐' : '꺼짐' ?></span>
      <?php if ($status['test_mode']): ?><span class="badge badge-warning badge-soft">테스트 모드</span><?php endif ?>
      <span class="badge badge-info badge-soft" data-aligo-pending<?= $status['pending'] > 0 ? '' : ' hidden' ?>><?= $this->e($status['pending']) ?>건 대기 중</span>
    </div>

    <?php // 설정 저장·채널 켜기·끄기 결과는 페이지 위의 스위치 바로 앞에 보인다. ?>
    <div id="aligo-result" class="landing" role="status" aria-live="polite" data-channel-status-url="<?= $this->url('admin.aligo.status') ?>">
      <?php // 취소하지 못한 예약이 남았다는 안내에 초록 체크를 붙이면 안 된다. ?>
      <?php if ($notice !== null): ?><div class="alert <?= $notice['ok'] ? 'alert-success' : 'alert-warning' ?>"><span aria-hidden="true"><?= $this->icon($notice['ok'] ? 'check-circle' : 'warning', 18) ?></span><span><?= $this->e($notice['message']) ?></span></div><?php endif ?>
    </div>

    <div class="form-section">
      <h2 class="form-section-title">전체 채널 사용 설정</h2>
      <p class="card-sub">알림톡·문자 OFF·ON은 <a href="<?= $this->url('admin.settings.messaging') ?>">알림·발송 설정</a>에서 관리합니다. 이 화면은 알리고 계정과 발신번호·카카오채널을 연결하는 곳입니다. 사용자 ID나 API 키를 변경하면 두 채널이 함께 꺼지고 예약 발송 취소를 시도합니다.</p>
      <?php if (array_key_exists('channel', $errors) || array_key_exists('api_key', $errors)): ?>
        <p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['channel'] ?? $errors['api_key']) ?></p>
      <?php endif ?>
    </div>

    <?php
      // 발신프로필 조회는 설정 저장과 다른 주소로 보내는 별개의 폼이다. 폼은 폼 안에
      // 넣을 수 없으므로 알맹이 없는 폼을 여기 두고, 버튼은 아래 "알림톡" 칸 안에서
      // form= 로 이 폼을 가리킨다 — 그래야 발신프로필키와 그 칸을 채워 주는 버튼이
      // 한자리에 모인다. 버튼 위치는 바뀌어도 보내는 곳은 예전 그대로다.
    ?>
    <form method="post" action="<?= $this->url('admin.aligo.profiles') ?>#aligo-profiles" id="aligo-profile-lookup" hidden>
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
    </form>

    <form method="post" action="<?= $this->url('admin.aligo') ?>#aligo">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="senderkey" value="<?= $this->e($values['senderkey'] ?? '') ?>">
      <input type="hidden" name="channel_name" value="<?= $this->e($values['channel_name'] ?? '') ?>">

      <div class="form-section">
        <h2 class="form-section-title">공통 · 알리고 계정</h2>
        <p class="fieldset-label">알림톡과 문자(SMS·LMS)가 함께 쓰는 값입니다. 여기가 비면 두 채널 모두 보낼 수 없습니다.</p>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('user_id', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">알리고 사용자 ID</legend>
            <input class="input input-bordered input-block" type="text" name="user_id" value="<?= $this->e($values['user_id'] ?? '') ?>" maxlength="60" autocomplete="username" required>
            <?php if (array_key_exists('user_id', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['user_id']) ?></p><?php endif ?>
            <p class="fieldset-label">알리고 로그인 아이디입니다. 알림톡·문자 양쪽 인증에 함께 씁니다.</p>
          </fieldset>
          <fieldset class="fieldset<?php if (array_key_exists('api_key', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">API 키</legend>
            <label class="input input-bordered input-block">
              <input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?= ($values['api_key_set'] ?? false) ? '••••••••••••••••' : 'API 키 입력' ?>" maxlength="200">
              <button class="pw-toggle" type="button" data-aligo-key-toggle
                      data-key-url="<?= $this->url('admin.aligo.key') ?>"
                      data-csrf="<?= $this->e($csrf_token) ?>"
                      data-key-set="<?= ($values['api_key_set'] ?? false) ? '1' : '0' ?>"
                      aria-pressed="false" aria-label="API 키 표시" title="API 키 표시">
                <span class="pw-ico pw-ico-show" aria-hidden="true"><?= $this->icon('eye', 17) ?></span>
                <span class="pw-ico pw-ico-hide" aria-hidden="true"><?= $this->icon('eye-off', 17) ?></span>
              </button>
            </label>
            <?php // 눈 아이콘으로 저장된 키를 칸에 불러왔는지. 1 이면 빈 칸 저장이 삭제다. ?>
            <input type="hidden" name="api_key_loaded" value="0" id="aligo-api-key-loaded">
            <?php if (array_key_exists('api_key', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['api_key']) ?></p><?php endif ?>
            <p class="fieldset-label">알리고에서 발급받은 API Key 하나로 알림톡·문자 양쪽을 인증합니다. 알림톡용 키가 따로 있지 않습니다. 저장된 키는 페이지에 싣지 않고, 눈 아이콘을 누르면 불러와 보여줍니다. 불러온 뒤 칸을 비우고 저장하면 키가 지워지고, 불러오지 않은 빈 칸은 기존 키를 그대로 둡니다.</p>
          </fieldset>
        </div>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('sender', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">발신번호</legend>
            <input class="input input-bordered input-block" type="text" name="sender" value="<?= $this->e($values['sender'] ?? '') ?>" maxlength="20" required>
            <?php if (array_key_exists('sender', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['sender']) ?></p><?php endif ?>
            <p class="fieldset-label">알리고에 사전 등록한 발신번호와 같아야 합니다. 알림톡·문자 모두 이 번호로 나갑니다.</p>
          </fieldset>
          <fieldset class="fieldset">
            <legend class="fieldset-legend">테스트 모드</legend>
            <label class="label toggle-row">
              <input class="toggle" type="checkbox" name="test_mode" value="1"<?= ($values['test_mode'] ?? false) ? ' checked' : '' ?>>
              <span>알리고 API는 호출하되 실제로 발송하지 않습니다</span>
            </label>
            <p class="fieldset-label">알림톡·문자 모두에 적용됩니다. 켜 두면 과금도 실제 발송도 없고, 이력에는 테스트로 남습니다.</p>
          </fieldset>
        </div>
      </div>

      <div class="card-actions form-actions">
        <button class="btn btn-primary" type="submit">공통 설정 저장</button>
      </div>
    </form>

    <form method="post" action="<?= $this->url('admin.aligo') ?>#aligo">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="user_id" value="<?= $this->e($values['user_id'] ?? '') ?>">
      <input type="hidden" name="sender" value="<?= $this->e($values['sender'] ?? '') ?>">
      <input type="hidden" name="test_mode" value="<?= ($values['test_mode'] ?? false) ? '1' : '0' ?>">
      <div class="form-section">
        <h2 class="form-section-title">알림톡 (카카오)</h2>
        <p class="fieldset-label">카카오 알림톡에만 쓰는 값입니다. 문자 발송에는 영향을 주지 않습니다.</p>
        <fieldset class="fieldset<?php if (array_key_exists('senderkey', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">발신프로필키 (Senderkey)</legend>
          <label class="input input-bordered input-block">
            <input type="password" id="aligo-senderkey" name="senderkey" value="<?= $this->e($values['senderkey'] ?? '') ?>" maxlength="64" autocomplete="new-password">
            <button class="pw-toggle" type="button" data-mask-toggle="발신프로필키" aria-pressed="false" aria-label="발신프로필키 표시" title="발신프로필키 표시">
              <span class="pw-ico pw-ico-show" aria-hidden="true"><?= $this->icon('eye', 17) ?></span>
              <span class="pw-ico pw-ico-hide" aria-hidden="true"><?= $this->icon('eye-off', 17) ?></span>
            </button>
          </label>
          <?php if (array_key_exists('senderkey', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['senderkey']) ?></p><?php endif ?>
          <p class="fieldset-label">알리고가 <strong>Senderkey</strong>라고 부르는, 카카오채널 하나를 가리키는 값입니다. 아래 "채널 불러오기"로 고르거나 직접 입력하세요. 가려져 있으며 눈 아이콘을 누르면 보입니다. 비어 있으면 알림톡 템플릿을 불러올 수도 보낼 수도 없습니다.</p>
        </fieldset>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">카카오채널명 (선택)</legend>
          <input class="input input-bordered input-block" type="text" id="aligo-channel-name" name="channel_name" value="<?= $this->e($values['channel_name'] ?? '') ?>" maxlength="60" placeholder="예: 우리상점">
          <p class="fieldset-label">어느 채널을 연결했는지 이 화면에서 알아보기 위한 이름입니다. 알리고에도 카카오에도 보내지 않으므로 <strong>'@'는 붙여도 안 붙여도 됩니다</strong>. 아래 "채널 불러오기"에서 채널을 고르면 알리고에 등록된 발신프로필명이 그대로 채워지고, 비워 두어도 됩니다.</p>
        </fieldset>

        <div class="profile-lookup landing" id="aligo-profiles">
          <button class="btn btn-outline" type="submit" form="aligo-profile-lookup"><?= $this->icon('search', 15) ?> 채널 불러오기</button>
          <p class="fieldset-label">알리고에 등록된 카카오채널과 각 채널의 전체 템플릿을 불러옵니다. 채널을 고르고 템플릿의 검수 상태·본문·버튼을 확인할 수 있습니다.</p>
          <?php if ($error !== null && $error_at === 'profiles'): ?><div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($error) ?></span></div><?php endif ?>

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
                <span><?= $this->e($name) ?> · <?= $this->e(strlen($senderKey) > 12 ? substr($senderKey, 0, 4) . '…' . substr($senderKey, -4) : $senderKey) ?> <span class="badge badge-sm badge-soft">상태 <?= $this->e($profileStatus) ?></span></span>
              </label>
            <?php endforeach ?>
            <p class="fieldset-label">고르면 위 "발신프로필키"·"카카오채널명" 칸에 채워집니다. 저장하려면 "알림톡 설정 저장"을 눌러 주세요.</p>
          </fieldset>
          <?php endif ?>
          <?php if ($profiles_loaded ?? false): ?>
            <?php if ($profiles === []): ?>
              <p class="fieldset-label">알리고에 등록된 카카오채널이 없습니다.</p>
            <?php else: ?>
              <?php $this->insert('admin/_aligo_profile_templates', ['profiles' => $profiles]) ?>
            <?php endif ?>
          <?php endif ?>
        </div>
      </div>

      <div class="card-actions form-actions">
        <button class="btn btn-primary" type="submit">알림톡 설정 저장</button>
      </div>
    </form>

    <div class="form-section">
      <h2 class="form-section-title">문자 (SMS·LMS)</h2>
      <p class="fieldset-label">문자는 위 "공통 · 알리고 계정"의 값으로 보냅니다. 발신번호와 API 키를 저장한 뒤 <a href="<?= $this->url('admin.settings.messaging') ?>">알림·발송 설정</a>에서 문자를 ON으로 바꿔 주세요.</p>
    </div>

    <div class="form-section landing" id="aligo-verify">
      <h2 class="form-section-title">연결 확인</h2>
      <p class="fieldset-label">저장한 값으로 알림톡·문자 잔여 건수를 한 번에 조회합니다. 두 채널은 알리고 안에서도 따로 신청하므로 한쪽만 실패할 수 있습니다.</p>
      <form class="aligo-verify-form" method="post" action="<?= $this->url('admin.aligo.verify') ?>#aligo-verify">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <button class="btn btn-outline" type="submit"><?= $this->icon('shield', 15) ?> 연결 확인</button>
      </form>

      <?php if ($error !== null && $error_at !== 'profiles'): ?><div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($error) ?></span></div><?php endif ?>

      <?php if ($verified !== null): ?>
        <?php $allOk = $verified['alimtalk']['ok'] && $verified['sms']['ok']; ?>
        <div class="alert <?= $allOk ? 'alert-success' : 'alert-warning' ?> verify-result">
          <ul>
            <li>
              <?php if ($verified['alimtalk']['ok']): ?>
                알림톡 <?= number_format((int) $verified['alimtalk']['count']) ?>건 남았습니다
              <?php else: ?>
                <strong>알림톡 확인 실패</strong> — <?= $this->e($verified['alimtalk']['reason']) ?>
              <?php endif ?>
            </li>
            <li>
              <?php if ($verified['sms']['ok']): ?>
                SMS <?= number_format((int) $verified['sms']['sms_count']) ?>건 · LMS <?= number_format((int) $verified['sms']['lms_count']) ?>건 남았습니다
              <?php else: ?>
                <strong>문자 확인 실패</strong> — <?= $this->e($verified['sms']['reason']) ?>
              <?php endif ?>
            </li>
          </ul>
        </div>
      <?php endif ?>
    </div>

  </div>
</section>
