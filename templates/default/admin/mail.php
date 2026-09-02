<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>메일 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">메일</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'mail']) ?>
<section class="card settings-card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('mail', 19) ?> 메일 설정</h1>
    <p class="card-sub">회원 인증과 비밀번호 재설정 메일을 보낼 계정을 연결합니다.</p>
    <?php if (($query['saved'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>SMTP 설정을 저장했습니다.</span></div><?php endif ?>
    <?php if (($query['tested'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>발신 주소로 테스트 메일을 보냈습니다.</span></div><?php endif ?>
    <?php if ($test_error): ?><div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($test_error) ?></span></div><?php endif ?>

    <form method="post" action="<?= $this->url('admin.mail') ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <fieldset class="fieldset toggle-list">
        <label class="label toggle-row">
          <input class="toggle toggle-primary" type="checkbox" name="enabled" value="1"<?= ($values['enabled'] ?? false) ? ' checked' : '' ?>>
          <span><strong>SMTP로 메일 보내기</strong></span>
        </label>
      </fieldset>

      <div class="form-section">
        <h2 class="form-section-title">서버</h2>
        <div class="grid-2">
          <fieldset class="fieldset">
            <legend class="fieldset-legend">메일 서비스</legend>
            <select class="select select-bordered select-block" name="provider" data-mail-provider>
              <option value="gmail"<?= $this->def($values['provider'] ?? null, 'gmail') === 'gmail' ? ' selected' : '' ?>>Gmail</option>
              <option value="naver"<?= ($values['provider'] ?? '') === 'naver' ? ' selected' : '' ?>>네이버 메일</option>
              <option value="daum"<?= ($values['provider'] ?? '') === 'daum' ? ' selected' : '' ?>>다음 메일</option>
              <option value="custom"<?= ($values['provider'] ?? '') === 'custom' ? ' selected' : '' ?>>직접 설정</option>
            </select>
          </fieldset>
        </div>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('host', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">SMTP 서버</legend>
            <input class="input input-bordered input-block" type="text" name="host" value="<?= $this->e($this->def($values['host'] ?? null, 'smtp.gmail.com')) ?>" maxlength="253" data-mail-host required>
            <?php if (array_key_exists('host', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['host']) ?></p><?php endif ?>
          </fieldset>
          <fieldset class="fieldset">
            <legend class="fieldset-legend">포트</legend>
            <input class="input input-bordered input-block" type="number" name="port" value="<?= $this->e($this->def($values['port'] ?? null, 465)) ?>" min="1" max="65535" data-mail-port required>
          </fieldset>
        </div>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">보안 연결</legend>
          <select class="select select-bordered select-block" name="encryption" data-mail-encryption>
            <option value="ssl"<?= $this->def($values['encryption'] ?? null, 'ssl') === 'ssl' ? ' selected' : '' ?>>SSL/TLS</option>
            <option value="tls"<?= ($values['encryption'] ?? '') === 'tls' ? ' selected' : '' ?>>STARTTLS</option>
          </select>
        </fieldset>
        <div class="alert alert-info alert-soft" data-mail-help></div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">계정</h2>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('username', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">SMTP 사용자 이름</legend>
            <input class="input input-bordered input-block" type="text" name="username" value="<?= $this->e($values['username'] ?? '') ?>" maxlength="254" autocomplete="username" required>
            <?php if (array_key_exists('username', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['username']) ?></p><?php endif ?>
          </fieldset>
          <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">앱 비밀번호</legend>
            <label class="input input-bordered input-block">
              <input type="password" name="password" value="" autocomplete="new-password" placeholder="<?= ($values['password_set'] ?? false) ? '••••••••••••••••' : '앱 비밀번호 입력' ?>">
              <button class="pw-toggle" type="button" data-mail-password-toggle
                      data-password-url="<?= $this->url('admin.mail.password') ?>"
                      data-csrf="<?= $this->e($csrf_token) ?>"
                      data-password-set="<?= ($values['password_set'] ?? false) ? '1' : '0' ?>"
                      aria-pressed="false" aria-label="앱 비밀번호 표시" title="앱 비밀번호 표시">
                <span class="pw-ico pw-ico-show" aria-hidden="true"><?= $this->icon('eye', 17) ?></span>
                <span class="pw-ico pw-ico-hide" aria-hidden="true"><?= $this->icon('eye-off', 17) ?></span>
              </button>
            </label>
            <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
            <p class="fieldset-label"><?= ($values['password_set'] ?? false) ? '앱 비밀번호가 저장되어 있습니다. 보안상 기존 값은 표시하지 않으며, 변경할 때만 새 값을 입력하세요.' : '일반 로그인 비밀번호가 아니라 메일 서비스에서 발급한 앱 비밀번호를 사용하세요.' ?></p>
          </fieldset>
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">발신 정보</h2>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('from_email', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">발신 이메일</legend>
            <input class="input input-bordered input-block" type="email" name="from_email" value="<?= $this->e($values['from_email'] ?? '') ?>" maxlength="254" required>
            <?php if (array_key_exists('from_email', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['from_email']) ?></p><?php endif ?>
          </fieldset>
          <fieldset class="fieldset">
            <legend class="fieldset-legend">발신 이름</legend>
            <input class="input input-bordered input-block" type="text" name="from_name" value="<?= $this->e($this->def($values['from_name'] ?? null, GNUCMS)) ?>" maxlength="100" required>
          </fieldset>
        </div>
      </div>

      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a>
        <button class="btn btn-primary" type="submit">설정 저장</button>
      </div>
    </form>

    <?php if (($values['password_set'] ?? false) && ($values['enabled'] ?? false)): ?>
      <form class="test-mail" method="post" action="<?= $this->url('admin.mail.test') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <button class="btn btn-outline" type="submit"><?= $this->icon('mail', 15) ?> 테스트 메일 보내기</button>
      </form>
    <?php endif ?>
  </div>
</section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script>
(function(){
  var p=document.querySelector('[data-mail-provider]');if(!p){return}
  var host=document.querySelector('[data-mail-host]'),port=document.querySelector('[data-mail-port]'),enc=document.querySelector('[data-mail-encryption]'),help=document.querySelector('[data-mail-help]');
  var presets={
    gmail:{host:'smtp.gmail.com',port:'465',encryption:'ssl',help:'Google 계정의 2단계 인증을 켠 뒤 16자리 앱 비밀번호를 발급하세요.'},
    naver:{host:'smtp.naver.com',port:'587',encryption:'tls',help:'네이버 메일에서 IMAP/SMTP 사용을 켜고 애플리케이션 비밀번호를 발급하세요.'},
    daum:{host:'smtp.daum.net',port:'465',encryption:'ssl',help:'다음 계정에서 앱 비밀번호를 발급해 사용하세요.'}
  };
  function sync(change){
    var s=presets[p.value],custom=!s;
    host.readOnly=!custom;port.readOnly=!custom;enc.disabled=!custom;
    if(change&&s){host.value=s.host;port.value=s.port;enc.value=s.encryption}
    help.textContent=s?s.help:'SMTP 제공업체가 안내한 서버, 포트와 보안 연결을 입력하세요.';
  }
  p.addEventListener('change',function(){sync(true)});
  sync(false);
})();
</script>
<script>
(function(){
  var btn=document.querySelector('[data-mail-password-toggle]');if(!btn){return}
  var box=btn.closest('label'),field=box?box.querySelector('input'):null;if(!field){return}
  var revealedStored=false,loading=false;
  function state(show){
    var label=show?'앱 비밀번호 숨기기':'앱 비밀번호 표시';
    field.type=show?'text':'password';btn.setAttribute('aria-pressed',show?'true':'false');
    btn.setAttribute('aria-label',label);btn.title=label;
  }
  field.addEventListener('input',function(){revealedStored=false});
  btn.addEventListener('click',async function(){
    if(field.type==='text'){
      state(false);if(revealedStored){field.value='';revealedStored=false}field.focus();return;
    }
    if(field.value!==''||btn.dataset.passwordSet!=='1'){state(true);field.focus();return}
    if(loading){return}loading=true;btn.disabled=true;
    try{
      var body=new URLSearchParams({csrf_token:btn.dataset.csrf});
      var res=await fetch(btn.dataset.passwordUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
      if(!res.ok){throw new Error('request failed')}
      var data=await res.json();field.value=typeof data.password==='string'?data.password:'';
      revealedStored=true;state(true);field.focus();field.setSelectionRange(field.value.length,field.value.length);
    }catch(e){window.alert('앱 비밀번호를 불러오지 못했습니다. 다시 로그인한 뒤 시도해 주세요.')}
    finally{loading=false;btn.disabled=false}
  });
})();
</script>
<?php $this->stop() ?>
