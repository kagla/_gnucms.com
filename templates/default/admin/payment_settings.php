<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?><?= $this->e($label) ?> 결제 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">결제</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'payment']) ?>
<section class="card settings-card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('shield', 19) ?> <?= $this->e($label) ?> 결제 설정</h1>
    <p class="card-sub">결제사별 운영·테스트 인증 정보를 저장합니다. 이니시스 테스트 환경은 공용 테스트 정보를 사용합니다. 이니시스·KCP의 에스크로 현금 결제는 준비 중이며, 토스·나이스페이는 에스크로 계좌이체를 지원합니다. KCP 기존 방식은 자격정보 등록만 지원합니다.</p>
    <nav class="tabs tabs-border settings-tabs" aria-label="결제사">
      <?php foreach ($providers as $id => $name): ?><a class="tab<?= $provider === $id ? ' tab-active' : '' ?>" href="<?= $this->url('admin.settings.payment') ?>?provider=<?= $this->e($id) ?>&amp;environment=<?= $this->e($environment) ?>"<?= $provider === $id ? ' aria-current="page"' : '' ?>><?= $this->e($name) ?></a><?php endforeach ?>
    </nav>
    <nav class="tabs tabs-border settings-tabs" aria-label="결제 환경">
      <?php foreach (['test' => '테스트 환경', 'live' => '운영 환경'] as $env => $envLabel): ?><a class="tab<?= $environment === $env ? ' tab-active' : '' ?>" href="<?= $this->url('admin.settings.payment') ?>?provider=<?= $this->e($provider) ?>&amp;environment=<?= $env ?>"<?= $environment === $env ? ' aria-current="page"' : '' ?>><?= $this->e($envLabel) ?></a><?php endforeach ?>
    </nav>
    <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><span><?= $this->e($error) ?></span></div><?php endforeach ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success" role="status"><span><?= $this->e($notice) ?></span></div><?php endif ?>
    <?php if ($provider === 'kcp_legacy' && $environment === 'live'): ?><p class="muted">일반결제 또는 에스크로 결제 중 사용할 방식을 고르고, 그 방식의 운영 사이트 코드·사이트 키 한 쌍을 입력하세요. 에스크로는 계좌이체·가상계좌용입니다. 기존 TCP/IP 승인 모듈은 아직 연결되지 않아 등록 후에도 온라인 결제 수단이 주문서에 표시되지 않습니다.</p><?php endif ?>
    <form method="post" action="<?= $this->url('admin.settings.payment') ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="provider" value="<?= $this->e($provider) ?>"><input type="hidden" name="environment" value="<?= $this->e($environment) ?>">
      <?php if (in_array($provider, ['inicis', 'kcp_legacy', 'kcp', 'toss', 'nicepay'], true)): ?>
      <fieldset class="fieldset" data-payment-mode><legend class="fieldset-legend">결제 방식</legend>
        <div class="yc-payment-mode-options">
          <?php foreach (['general' => '일반결제', 'escrow' => '에스크로 결제'] as $mode => $modeLabel): ?><label class="label"><input class="radio radio-sm" type="radio" name="mode" value="<?= $mode ?>"<?= ($settings['mode'] ?: 'general') === $mode ? ' checked' : '' ?>> <?= $modeLabel ?></label><?php endforeach ?>
        </div>
        <?php if ($environment === 'test' && in_array($provider, ['inicis', 'kcp_legacy', 'kcp'], true)): ?><p>테스트 상점 아이디<?= $provider === 'inicis' ? ' (MID)' : '' ?>: <strong data-payment-test-id><?= $this->e($provider === 'inicis' ? \GnuCms\Payment\ProviderConfig::TEST_MID : (($settings['mode'] ?? '') === 'escrow' ? \GnuCms\Payment\KcpLegacyConfig::TEST_ESCROW_SITE_CD : \GnuCms\Payment\KcpLegacyConfig::TEST_SITE_CD)) ?></strong></p><?php endif ?>
      </fieldset>
      <?php if ($environment === 'test' && $provider === 'inicis'): ?><p class="muted">공용 테스트 인증 정보가 자동 적용됩니다.</p><?php endif ?>
      <?php if ($environment === 'test' && $provider === 'kcp_legacy'): ?><p class="muted">선택한 방식의 공용 테스트 사이트 키가 자동 적용됩니다. 기존 TCP/IP 승인 모듈은 아직 연결되지 않았습니다.</p><?php endif ?>
      <?php if ($provider === 'toss'): ?><p class="muted">토스 API 개별 연동 키를 입력합니다. 에스크로는 계좌이체에 적용되며 배송 정보는 토스 상점관리자에 등록합니다.</p><?php endif ?>
      <?php if ($provider === 'nicepay'): ?><p class="muted">나이스페이 서버 승인 모델의 Basic 인증 키를 입력합니다. 에스크로는 계좌이체에 적용되며 배송 정보는 나이스페이 상점관리자에 등록합니다.</p><?php endif ?>
      <?php endif ?>
      <?php if (!($environment === 'test' && in_array($provider, ['inicis', 'kcp_legacy'], true))): foreach ($fields as $name => $field): if ($name === 'mode') continue; ?>
      <fieldset class="fieldset<?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="payment-<?= $this->e($name) ?>"><?= $this->e($field['label']) ?></label></legend>
        <?php if (!empty($field['multiline'])): ?><textarea class="textarea textarea-bordered input-block" id="payment-<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" rows="6" autocomplete="new-password" placeholder="<?= $settings['configured'] ? '같은 결제 방식·상점이면 현재 값 유지' : '' ?>"></textarea><?php else: ?><input class="input input-bordered input-block" id="payment-<?= $this->e($name) ?>" type="<?= $field['secret'] ? 'password' : 'text' ?>" name="<?= $this->e($name) ?>" value="<?= $field['secret'] ? '' : $this->e((string) ($settings[$name] ?? '')) ?>" autocomplete="<?= $field['secret'] ? 'new-password' : 'off' ?>"<?= !$field['secret'] ? ' required' : '' ?><?= $environment === 'test' && $provider === 'kcp' && $name === 'site_cd' ? ' readonly' : '' ?> placeholder="<?= $field['secret'] && $settings['configured'] ? '같은 결제 방식·상점이면 현재 값 유지' : '' ?>"><?php endif ?>
        <?php if ($provider === 'inicis' && $name === 'client_ip'): ?><p class="muted">여러 서버에서 요청하면 각 서버의 config/config.php에 payment.inicis.client_ip를 지정합니다. 비워 두면 이 기본값을 사용합니다.</p><?php endif ?>
      </fieldset>
      <?php endforeach; endif ?>
      <div class="card-actions form-actions"><button class="btn btn-primary" name="action" value="save">설정 저장</button></div>
    </form>
  </div>
</section>
<p class="muted"><a class="link" href="<?= $this->e($manual) ?>" target="_blank" rel="noopener noreferrer">PG 공식 연동 문서 <?= $this->icon('external', 14) ?></a></p>
<script>
document.querySelectorAll('[data-payment-mode]').forEach(function(group){
  var form=group.closest('form'),id=group.querySelector('[data-payment-test-id]');
  var siteCode=form&&form.querySelector('input[name="site_cd"][readonly]');
  function sync(){
    var selected=group.querySelector('input[name="mode"]:checked');
    var code=selected&&selected.value==='escrow'?'T0007':'T0000';
    if(id&&form.querySelector('input[name="provider"]').value!=='inicis') id.textContent=code;
    if(siteCode) siteCode.value=code;
  }
  group.querySelectorAll('input[name="mode"]').forEach(function(input){input.addEventListener('change',sync);});
  sync();
});
</script>
<?php $this->stop() ?>
