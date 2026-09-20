<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림톡·문자 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">알림톡·문자</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'aligo']) ?>
<section class="card settings-card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('bell', 19) ?> 알림톡·문자 설정</h1>
    <p class="card-sub">카카오 알림톡과 문자(SMS·LMS)를 보낼 알리고(Aligo) 계정을 연결합니다. 알림톡과 문자는 알리고 안에서도 따로 신청하는 별개의 서비스라, 아래 칸을 어느 채널에 쓰이는지로 묶어 두었습니다.</p>

    <div class="status-badges">
      <span class="badge <?= $status['configured'] ? 'badge-success badge-soft' : 'badge-ghost' ?>"><?= $status['configured'] ? '계정 연결됨' : '계정 연결 안 됨' ?></span>
      <span class="badge <?= $status['alimtalk_enabled'] ? 'badge-success badge-soft' : 'badge-ghost' ?>">알림톡 발송 <?= $status['alimtalk_enabled'] ? '켜짐' : '꺼짐' ?></span>
      <span class="badge <?= $status['sms_enabled'] ? 'badge-success badge-soft' : 'badge-ghost' ?>">문자 발송 <?= $status['sms_enabled'] ? '켜짐' : '꺼짐' ?></span>
      <?php if ($status['test_mode']): ?><span class="badge badge-warning badge-soft">테스트 모드</span><?php endif ?>
      <?php if ($status['pending'] > 0): ?><span class="badge badge-info badge-soft"><?= $this->e($status['pending']) ?>건 대기 중</span><?php endif ?>
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

    <form method="post" action="<?= $this->url('admin.aligo') ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">

      <div class="form-section">
        <h2 class="form-section-title">공통 · 알리고 계정</h2>
        <p class="fieldset-label">알림톡과 문자(SMS·LMS)가 함께 쓰는 값입니다. 여기가 비면 두 채널 모두 보낼 수 없습니다.</p>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('user_id', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">알리고 사용자 ID</legend>
            <input class="input input-bordered input-block" type="text" name="user_id" value="<?= $this->e($values['user_id'] ?? '') ?>" maxlength="60" required>
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
          <p class="fieldset-label">알리고에 등록된 카카오채널 목록을 불러와 발신프로필키를 고를 수 있습니다.</p>
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
            <p class="fieldset-label">고르면 위 "발신프로필키"·"카카오채널명" 칸에 채워집니다. 저장하려면 "설정 저장"을 다시 눌러 주세요.</p>
          </fieldset>
          <?php endif ?>
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">문자 (SMS·LMS)</h2>
        <p class="fieldset-label">문자는 위 "공통 · 알리고 계정"의 값만으로 나갑니다 — 여기서 따로 입력할 값은 없습니다. 발신번호와 API 키가 곧 문자 설정입니다. 실제로 보내려면 아래 "채널별 발송 허용"에서 문자 발송을 켜 주세요.</p>
      </div>

      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a>
        <button class="btn btn-primary" type="submit">설정 저장</button>
      </div>
    </form>

    <?php // 저장·켜기·끄기·연결 확인은 여기(#aligo-result)로 착지한다 — 폼을 보내면 새로
      // 고침이 일어나는데, 맨 위에 착지하면 방금 누른 결과를 보려고 다시 내려가야 했다.
      // 안내와 오류는 착지 지점 뒤에 그려야 보이므로 맨 위가 아니라 여기 그린다. 채널
      // 불러오기의 오류만은 그 버튼 곁(#aligo-profiles)에 그린다. ?>
    <div id="aligo-result" class="landing">
    <?php // 취소하지 못한 예약이 남았다는 안내에 초록 체크를 붙이면 안 된다 — 'ok' 가 그것을 가른다. ?>
    <?php if ($notice !== null): ?><div class="alert <?= $notice['ok'] ? 'alert-success' : 'alert-warning' ?>"><span aria-hidden="true"><?= $this->icon($notice['ok'] ? 'check-circle' : 'warning', 18) ?></span><span><?= $this->e($notice['message']) ?></span></div><?php endif ?>
    <?php if ($error !== null && $error_at !== 'profiles'): ?><div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($error) ?></span></div><?php endif ?>
    </div>

    <div class="form-section">
      <h2 class="form-section-title">연결 확인</h2>
      <p class="fieldset-label">저장한 값으로 알림톡·문자 잔여 건수를 한 번에 조회합니다. 두 채널은 알리고 안에서도 따로 신청하므로 한쪽만 실패할 수 있습니다.</p>
      <form method="post" action="<?= $this->url('admin.aligo.verify') ?>#aligo-result">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <button class="btn btn-outline" type="submit"><?= $this->icon('shield', 15) ?> 연결 확인</button>
      </form>

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

    <div class="form-section">
      <h2 class="form-section-title">채널별 발송 허용</h2>
      <?php // 이 두 스위치를 타는 것은 발송 화면만이 아니다. 코어 알림(비밀번호 재설정·
        // 새 댓글 등)도 같은 스위치를 지나므로, 여기서 끄면 설정 → 알림에서 켜 둔 칸도
        // 함께 멈춘다. 알림 설정 화면은 이 화면으로 링크를 걸어 그 사실을 말하는데
        // 반대쪽은 말하지 않아, 관리자가 이 화면만 보고 끄면 무엇이 함께 멈추는지 알 수
        // 없었다. 계정을 바꾸면 두 스위치가 함께 꺼진다는 사실도 여기 적는다 — 그 길로도
        // 예약 취소가 함께 일어난다(AligoService::saveSettings()). ?>
      <p class="card-sub">계정을 저장하기 전에는 켤 수 없습니다. <a href="<?= $this->url('admin.settings.notifications') ?>">설정 → 알림</a>에서 켜 둔 코어 알림(비밀번호 재설정·새 댓글 등)도 이 두 스위치를 함께 타므로, 여기서 끄면 그 알림의 알림톡·문자도 멈춥니다. 채널을 끄면 이미 걸린 예약도 함께 취소를 시도하며, 위에서 사용자 ID 나 API 키를 바꿔 저장해도 두 스위치가 함께 꺼지면서 같은 취소가 일어납니다.</p>
      <?php // 줄마다 켜기·끄기가 붙은 버튼 한 쌍이고, 저장된 상태의 버튼이 채운 색으로 강조된다
        // (켜기는 초록, 끄기는 진회색 — 색만 봐도 어느 쪽인지 안다).
        // 강조된 버튼은 이미 그 상태라 제출하지 않고(눌린 상태), 반대쪽 버튼만 제출한다. 버튼
        // 하나만 있으면 "끄기"가 지금 꺼져 있다는 뜻인지 끌 수 있다는 뜻인지 읽히지 않았다. ?>
      <div class="channel-switches">
        <?php foreach ([['sms', '문자(SMS·LMS) 발송', $status['sms_enabled']], ['at', '알림톡 발송', $status['alimtalk_enabled']]] as [$channel, $label, $on]): ?>
        <div class="toggle-row channel-switch">
          <span class="channel-switch-name"><?= $label ?></span>
          <form method="post" action="<?= $this->url('admin.aligo.toggle') ?>#aligo-result">
            <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
            <input type="hidden" name="channel" value="<?= $channel ?>">
            <?php // join 안에는 버튼 둘만 둔다 — daisyUI 는 첫째·마지막 자식에만 바깥 모서리를
              // 둥글게 하므로, hidden input 이 앞에 오면 왼쪽 버튼이 각진 채 남는다. ?>
            <span class="join">
            <?php foreach ([['enable', '켜기', $on], ['disable', '끄기', !$on]] as [$action, $caption, $current]): ?>
            <?php if ($current): ?>
            <button class="btn btn-sm join-item <?= $action === 'enable' ? 'btn-success' : 'btn-neutral' ?>" type="button" aria-pressed="true"><?= $caption ?></button>
            <?php else: ?>
            <button class="btn btn-sm join-item btn-outline" type="submit" name="action" value="<?= $action ?>" aria-pressed="false"<?= ($action === 'enable' && !$status['configured']) ? ' disabled' : '' ?>><?= $caption ?></button>
            <?php endif ?>
            <?php endforeach ?>
            </span>
          </form>
        </div>
        <?php endforeach ?>
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
<script>
(function(){
  // 가린 칸 보기·숨기기. 값은 칸에 그대로 있으므로 입력상자의 종류만 바꾼다.
  document.querySelectorAll('[data-mask-toggle]').forEach(function(btn){
    var box=btn.closest('label'),field=box?box.querySelector('input'):null;if(!field){return}
    var name=btn.dataset.maskToggle;
    btn.addEventListener('click',function(){
      var show=field.type!=='text',label=name+(show?' 숨기기':' 표시');
      field.type=show?'text':'password';btn.setAttribute('aria-pressed',show?'true':'false');
      btn.setAttribute('aria-label',label);btn.title=label;field.focus();
    });
  });
})();
</script>
<script>
(function(){
  // 저장된 API 키 보기. 페이지에는 키가 없고(소스 보기에 나오면 안 된다), 눈 버튼을 눌렀을
  // 때만 POST 로 받아 채운다. 받아 온 뒤에는 api_key_loaded 를 1 로 두어 그 상태에서 칸을
  // 비우고 저장하면 삭제가 된다. 손대지 않은 채 다시 가리면 값을 비우고 표시도 0 으로
  // 되돌린다 — 그 빈 칸은 "기존 키 유지"라서 저장해도 바뀌지 않고, 화면에 원본이 남지도 않는다.
  var btn=document.querySelector('[data-aligo-key-toggle]');if(!btn){return}
  var box=btn.closest('label'),field=box?box.querySelector('input'):null;
  var loaded=document.getElementById('aligo-api-key-loaded');if(!field||!loaded){return}
  var revealedStored=false,loading=false;
  function state(show){
    var label=show?'API 키 숨기기':'API 키 표시';
    field.type=show?'text':'password';btn.setAttribute('aria-pressed',show?'true':'false');
    btn.setAttribute('aria-label',label);btn.title=label;
  }
  field.addEventListener('input',function(){revealedStored=false});
  btn.addEventListener('click',async function(){
    if(field.type==='text'){
      state(false);if(revealedStored){field.value='';loaded.value='0';revealedStored=false}field.focus();return;
    }
    if(field.value!==''||btn.dataset.keySet!=='1'){state(true);field.focus();return}
    if(loading){return}loading=true;btn.disabled=true;
    try{
      var body=new URLSearchParams({csrf_token:btn.dataset.csrf});
      var res=await fetch(btn.dataset.keyUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
      if(!res.ok){throw new Error('request failed')}
      var data=await res.json();field.value=typeof data.api_key==='string'?data.api_key:'';
      loaded.value='1';revealedStored=true;state(true);field.focus();field.setSelectionRange(field.value.length,field.value.length);
    }catch(e){window.alert('API 키를 불러오지 못했습니다. 다시 로그인한 뒤 시도해 주세요.')}
    finally{loading=false;btn.disabled=false}
  });
})();
</script>
<?php $this->stop() ?>
