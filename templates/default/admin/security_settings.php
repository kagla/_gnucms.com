<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>보안 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">보안</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'security']) ?>
<section class="card settings-card"><div class="card-body">
  <h1 class="card-title"><?= $this->icon('shield', 19) ?> 자동 등록 방지</h1>
  <p class="card-sub">Cloudflare Turnstile 키를 입력하면 가입·복구·비회원 작성과 반복 로그인 시도를 보호합니다.</p>
  <?php if (($query['saved'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>Turnstile 설정을 저장했습니다.</span></div><?php endif ?>
  <form method="post" action="<?= $this->url('admin.settings.security') ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
    <div class="form-section">
      <fieldset class="fieldset toggle-list"><label class="label toggle-row"><input class="toggle toggle-primary" type="checkbox" name="enabled" value="1"<?= $values['enabled'] ? ' checked' : '' ?>><span><strong>Cloudflare Turnstile 사용</strong><small>Site Key, Secret Key, 호스트명이 모두 설정된 경우에만 켜세요.</small></span></label></fieldset>
      <div class="oauth-provider-tools"><a class="btn btn-outline btn-sm" href="<?= $this->e($values['console_url']) ?>" target="_blank" rel="noopener noreferrer">Cloudflare에서 키 발급·관리 <span aria-hidden="true">↗</span></a></div>
      <div class="grid-2">
        <fieldset class="fieldset<?= array_key_exists('site_key', $errors) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">Site Key</legend><input class="input input-bordered input-block" type="text" name="site_key" value="<?= $this->e($values['site_key']) ?>" maxlength="500" autocomplete="off"><?php if (array_key_exists('site_key', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['site_key']) ?></p><?php endif ?><p class="fieldset-label">브라우저 위젯에 공개되는 키입니다.</p></fieldset>
        <fieldset class="fieldset<?= array_key_exists('secret_key', $errors) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">Secret Key</legend><label class="input input-bordered input-block"><input type="password" name="secret_key" value="" maxlength="1000" autocomplete="new-password" placeholder="<?= $values['secret_key_set'] ? '••••••••••••••••' : 'Secret Key 입력' ?>"><button class="pw-toggle" type="button" data-turnstile-secret-toggle data-secret-url="<?= $this->url('admin.settings.security.secret') ?>" data-csrf="<?= $this->e($csrf_token) ?>" data-secret-set="<?= $values['secret_key_set'] ? '1' : '0' ?>" aria-pressed="false" aria-label="Secret Key 표시" title="Secret Key 표시"><span class="pw-ico pw-ico-show" aria-hidden="true"><?= $this->icon('eye', 17) ?></span><span class="pw-ico pw-ico-hide" aria-hidden="true"><?= $this->icon('eye-off', 17) ?></span></button></label><?php if (array_key_exists('secret_key', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['secret_key']) ?></p><?php endif ?><p class="fieldset-label"><?php if ($values['secret_key_set']): ?>비밀키가 암호화되어 저장되어 있습니다. 눈 버튼으로 확인하거나, 변경할 때 새 값을 입력하세요.<?php else: ?>비밀키는 암호화해 저장합니다.<?php endif ?></p><?php if ($values['secret_key_set']): ?><label class="label"><input class="checkbox checkbox-sm" type="checkbox" name="secret_key_clear" value="1"> 저장된 Secret Key 삭제</label><?php endif ?></fieldset>
      </div>
      <fieldset class="fieldset<?= array_key_exists('hostname', $errors) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">호스트명</legend><input class="input input-bordered input-block" type="text" name="hostname" value="<?= $this->e($values['hostname']) ?>" maxlength="253" placeholder="example.com" autocomplete="off"><?php if (array_key_exists('hostname', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['hostname']) ?></p><?php endif ?><p class="fieldset-label"><code>https://</code>와 경로를 빼고 Cloudflare 위젯에 등록한 도메인만 입력하세요. 서버 검증 결과의 호스트명과 정확히 비교합니다.</p></fieldset>
      <div class="alert alert-info alert-soft"><span><?= $this->icon('info', 16) ?></span><span>일반 로그인에는 처음부터 위젯을 표시하지 않습니다. 같은 계정 또는 IP에서 3회 실패한 뒤 확인을 요구하며, 확인 가능한 상태에서는 로그인 시도를 최대 10회까지 허용합니다.</span></div>
    </div>
    <div class="card-actions form-actions"><a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a><button class="btn btn-primary" type="submit">설정 저장</button></div>
  </form>
</div></section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script>
(function(){
  var btn=document.querySelector('[data-turnstile-secret-toggle]');if(!btn){return}
  var box=btn.closest('label'),field=box?box.querySelector('input'):null;if(!field){return}
  var revealedStored=false,loading=false;
  function state(show){
    var label=show?'Secret Key 숨기기':'Secret Key 표시';
    field.type=show?'text':'password';btn.setAttribute('aria-pressed',show?'true':'false');
    btn.setAttribute('aria-label',label);btn.title=label;
  }
  field.addEventListener('input',function(){revealedStored=false});
  btn.addEventListener('click',async function(){
    if(field.type==='text'){
      state(false);if(revealedStored){field.value='';revealedStored=false}field.focus();return;
    }
    if(field.value!==''||btn.dataset.secretSet!=='1'){state(true);field.focus();return}
    if(loading){return}loading=true;btn.disabled=true;
    try{
      var body=new URLSearchParams({csrf_token:btn.dataset.csrf});
      var res=await fetch(btn.dataset.secretUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
      if(!res.ok){throw new Error('request failed')}
      var data=await res.json();field.value=typeof data.secret==='string'?data.secret:'';
      revealedStored=true;state(true);field.focus();field.setSelectionRange(field.value.length,field.value.length);
    }catch(e){window.alert('Secret Key를 불러오지 못했습니다. 다시 로그인한 뒤 시도해 주세요.')}
    finally{loading=false;btn.disabled=false}
  });
})();
</script>
<?php $this->stop() ?>
