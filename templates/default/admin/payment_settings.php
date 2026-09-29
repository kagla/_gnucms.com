<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?><?= $this->e($label) ?> 결제 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">결제</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'payment']) ?>
<section class="card settings-card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('shield', 19) ?> <?= $this->e($label) ?> 결제 설정</h1>
    <p class="card-sub">PG별 운영·테스트 인증 정보를 저장합니다. 쇼핑몰의 온라인 PG 결제는 일반 신용카드만 사용하며 에스크로 결제와 계좌이체·가상계좌·휴대폰 결제는 제공하지 않습니다.</p>
    <nav class="tabs tabs-border settings-tabs" aria-label="결제사">
      <?php foreach ($providers as $id => $name): ?><a class="tab<?= $provider === $id ? ' tab-active' : '' ?>" href="<?= $this->url('admin.settings.payment') ?>?provider=<?= $this->e($id) ?>&amp;environment=<?= $this->e($environment) ?>"<?= $provider === $id ? ' aria-current="page"' : '' ?>><?= $this->e($name) ?></a><?php endforeach ?>
    </nav>
    <nav class="tabs tabs-border settings-tabs" aria-label="결제 환경">
      <?php foreach (['test' => '테스트 환경', 'live' => '운영 환경'] as $env => $envLabel): ?><a class="tab<?= $environment === $env ? ' tab-active' : '' ?>" href="<?= $this->url('admin.settings.payment') ?>?provider=<?= $this->e($provider) ?>&amp;environment=<?= $env ?>"<?= $environment === $env ? ' aria-current="page"' : '' ?>><?= $this->e($envLabel) ?></a><?php endforeach ?>
    </nav>
    <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><span><?= $this->e($error) ?></span></div><?php endforeach ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success" role="status"><span><?= $this->e($notice) ?></span></div><?php endif ?>
    <?php if ($provider === 'kcp_legacy' && $environment === 'live'): ?><p class="muted">일반 카드 결제용 운영 사이트 코드·사이트 키 한 쌍을 입력하세요.</p><?php endif ?>
    <form method="post" action="<?= $this->url('admin.settings.payment') ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="provider" value="<?= $this->e($provider) ?>"><input type="hidden" name="environment" value="<?= $this->e($environment) ?>">
      <?php if ($environment === 'test' && $provider === 'inicis'): ?><p class="muted">이니시스 공용 일반결제 테스트 인증 정보가 자동 적용됩니다.</p><?php endif ?>
      <?php if ($environment === 'test' && $provider === 'kcp_legacy'): ?><p class="muted">일반결제용 공용 테스트 사이트 키가 자동 적용됩니다.</p><?php endif ?>
      <?php if ($environment === 'test' && $provider === 'kcp'): ?><p class="muted">KCP가 공개한 테스트 인증서·개인키 파일과 테스트용 개인키 비밀번호가 자동 적용됩니다. PEM을 입력할 필요가 없습니다.</p><?php endif ?>
      <?php if ($provider === 'kcp_legacy'): ?><p class="muted"><?= $kcp_legacy_module_available ? 'TCP/IP 승인 모듈이 설치되어 있습니다. 사이트 결제 설정에서 이 환경과 신용카드 결제를 선택하면 주문서에 표시됩니다.' : 'TCP/IP 결제창과 서버 승인을 사용하려면 KCP pp_cli 실행 파일과 pub.key를 storage/payment/kcp_legacy/bin/에 설치해야 합니다. 현재 모듈이 없어 주문서에는 표시되지 않습니다.' ?></p><?php endif ?>
      <?php if ($provider === 'toss' && $environment === 'test'): ?><p class="muted">토스페이먼츠 공식 SDK v1 샘플의 공개 테스트 키가 자동 적용됩니다. 키를 입력할 필요가 없습니다.</p><?php endif ?>
      <?php if ($provider === 'toss' && $environment === 'live'): ?><p class="muted">상점에 발급된 토스 API 개별 연동 키를 입력합니다. 일반 신용카드 결제만 사용합니다.</p><?php endif ?>
      <?php if ($provider === 'nicepay' && $environment === 'test'): ?><p class="muted">나이스페이먼츠 공식 서버 승인 샌드박스 샘플의 공개 테스트 키가 자동 적용됩니다. 키를 입력할 필요가 없습니다.</p><?php endif ?>
      <?php if ($provider === 'nicepay' && $environment === 'live'): ?><p class="muted">나이스페이 서버 승인 모델의 Basic 인증 키를 입력합니다. 일반 신용카드 결제만 사용합니다.</p><?php endif ?>
      <?php if (!($environment === 'test' && in_array($provider, ['inicis', 'kcp_legacy', 'kcp', 'toss', 'nicepay'], true))): foreach ($fields as $name => $field): if ($name === 'mode') continue; ?>
      <fieldset class="fieldset<?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="payment-<?= $this->e($name) ?>"><?= $this->e($field['label']) ?></label></legend>
        <?php if (!empty($field['multiline'])): ?><textarea class="textarea textarea-bordered input-block" id="payment-<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" rows="6" autocomplete="new-password" placeholder="<?= $settings['configured'] ? '현재 카드 결제 설정을 유지하려면 비워 두세요' : '' ?>"></textarea><?php else: ?><input class="input input-bordered input-block" id="payment-<?= $this->e($name) ?>" type="<?= $field['secret'] ? 'password' : 'text' ?>" name="<?= $this->e($name) ?>" value="<?= $field['secret'] ? '' : $this->e((string) ($provider === 'kcp' && $environment === 'test' && $name === 'site_cd' ? 'T0000' : ($settings[$name] ?? ''))) ?>" autocomplete="<?= $field['secret'] ? 'new-password' : 'off' ?>"<?= !$field['secret'] ? ' required' : '' ?><?= $environment === 'test' && $provider === 'kcp' && $name === 'site_cd' ? ' readonly' : '' ?> placeholder="<?= $field['secret'] && $settings['configured'] ? '현재 카드 결제 설정을 유지하려면 비워 두세요' : '' ?>"><?php endif ?>
        <?php if ($provider === 'inicis' && $name === 'client_ip'): ?><p class="muted">여러 서버에서 요청하면 각 서버의 config/config.php에 payment.inicis.client_ip를 지정합니다. 비워 두면 이 기본값을 사용합니다.</p><?php endif ?>
      </fieldset>
      <?php endforeach; endif ?>
      <div class="card-actions form-actions"><button class="btn btn-primary" name="action" value="save">설정 저장</button></div>
    </form>
  </div>
</section>
<p class="muted"><a class="link" href="<?= $this->e($manual) ?>" target="_blank" rel="noopener noreferrer">PG 공식 연동 문서 <?= $this->icon('external', 14) ?></a></p>
<?php $this->stop() ?>
