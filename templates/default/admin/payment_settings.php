<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?><?= $this->e($label) ?> 결제 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">결제</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'payment']) ?>
<section class="card settings-card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('shield', 19) ?> <?= $this->e($label) ?> 결제 설정</h1>
    <p class="card-sub">상점 코드와 환경별 인증 정보를 저장합니다. 쇼핑몰에서 운영 또는 테스트를 선택하면 해당 환경의 결제 설정이 주문서에 적용됩니다. 인증 정보는 암호화해서 저장합니다.</p>
    <nav class="tabs tabs-border settings-tabs" aria-label="결제사">
      <?php foreach ($providers as $id => $name): ?><a class="tab<?= $provider === $id ? ' tab-active' : '' ?>" href="<?= $this->url('admin.settings.payment') ?>?provider=<?= $this->e($id) ?>&amp;environment=<?= $this->e($environment) ?>"<?= $provider === $id ? ' aria-current="page"' : '' ?>><?= $this->e($name) ?></a><?php endforeach ?>
    </nav>
    <nav class="tabs tabs-border settings-tabs" aria-label="결제 환경">
      <?php foreach (['test' => '테스트 환경', 'live' => '운영 환경'] as $env => $envLabel): ?><a class="tab<?= $environment === $env ? ' tab-active' : '' ?>" href="<?= $this->url('admin.settings.payment') ?>?provider=<?= $this->e($provider) ?>&amp;environment=<?= $env ?>"<?= $environment === $env ? ' aria-current="page"' : '' ?>><?= $this->e($envLabel) ?></a><?php endforeach ?>
    </nav>
    <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><span><?= $this->e($error) ?></span></div><?php endforeach ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success" role="status"><span><?= $this->e($notice) ?></span></div><?php endif ?>
    <form method="post" action="<?= $this->url('admin.settings.payment') ?>" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="provider" value="<?= $this->e($provider) ?>"><input type="hidden" name="environment" value="<?= $this->e($environment) ?>">
      <?php foreach ($fields as $name => $field): ?>
      <fieldset class="fieldset<?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="payment-<?= $this->e($name) ?>"><?= $this->e($field['label']) ?></label></legend>
        <?php if (!empty($field['multiline'])): ?><textarea class="textarea textarea-bordered input-block" id="payment-<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" rows="6" autocomplete="new-password" placeholder="<?= $settings['configured'] ? '같은 상점에서 비워두면 현재 값 유지' : '' ?>"></textarea><?php else: ?><input class="input input-bordered input-block" id="payment-<?= $this->e($name) ?>" type="<?= $field['secret'] ? 'password' : 'text' ?>" name="<?= $this->e($name) ?>" value="<?= $field['secret'] ? '' : $this->e($settings[$name] ?? '') ?>" autocomplete="<?= $field['secret'] ? 'new-password' : 'off' ?>"<?= !$field['secret'] ? ' required' : '' ?> placeholder="<?= $field['secret'] && $settings['configured'] ? '같은 상점에서 비워두면 현재 값 유지' : '' ?>"><?php endif ?>
        <?php if ($provider === 'inicis' && $name === 'client_ip'): ?><p class="muted">여러 서버에서 요청하면 각 서버의 config/config.php에 payment.inicis.client_ip를 지정합니다. 비워 두면 이 기본값을 사용합니다.</p><?php endif ?>
      </fieldset>
      <?php endforeach ?>
      <div class="card-actions form-actions"><button class="btn btn-primary" name="action" value="save">설정 저장</button></div>
    </form>
  </div>
</section>
<p class="muted"><a class="link" href="<?= $this->e($manual) ?>" target="_blank" rel="noopener noreferrer">PG 공식 연동 문서 <?= $this->icon('external', 14) ?></a></p>
<?php $this->stop() ?>
