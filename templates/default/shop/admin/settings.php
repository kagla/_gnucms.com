<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>쇼핑몰 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '쇼핑몰 설정', 'description' => '배송 정책과 고객 안내, 쇼핑몰의 진열 방식을 설정하세요.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php $num = function (string $name, string $label, int $min, int $max) use ($values, $errors): void { ?>
  <fieldset class="fieldset<?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="yc-setting-<?= $this->e($name) ?>"><?= $this->e($label) ?></label></legend>
    <input class="input input-bordered input-sm" type="number" id="yc-setting-<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e((string) ($values[$name] ?? '')) ?>" min="<?= $min ?>" max="<?= $max ?>" required>
    <?php if (isset($errors[$name])): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?></fieldset>
<?php };
$catOptions = function (string $selected) use ($categories): void { ?><option value="">선택</option><?php foreach ($categories as $cid => $option): ?><option value="<?= $cid ?>" title="<?= $this->e($option['title']) ?>"<?= $selected === (string) $cid ? ' selected' : '' ?>><?= $this->e($option['text']) ?></option><?php endforeach ?><?php };
/* 메인 분류 블록 한 줄. $index 는 줄 번호이고, 틀(template)에서는 __i__ 다 — JS 가 넣을 때 현재 줄 수로 바꾼다. */
$categoryRow = function (string $index, array $row) use ($catOptions): void { $name = 'main_categories[' . $index . ']'; ?>
  <div class="yc-main-category-row" data-yc-main-category-item>
    <select class="select select-bordered select-sm" name="<?= $name ?>[id]" aria-label="메인에 놓을 분류"><?php $catOptions($row['id']) ?></select>
    <input class="input input-bordered input-sm" type="number" name="<?= $name ?>[columns]" value="<?= $this->e($row['columns']) ?>" min="1" max="12" aria-label="한 행 상품 수">
    <input class="input input-bordered input-sm" type="number" name="<?= $name ?>[rows]" value="<?= $this->e($row['rows']) ?>" min="1" max="50" aria-label="행 수">
    <button class="btn btn-xs" type="button" data-yc-remove-main-category aria-label="이 분류 블록 제거">제거</button>
  </div>
<?php };
$cell = static fn (array $row, string $key, string $default): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : $default;
$mainCategories = [];
foreach (is_array($values['main_categories'] ?? null) ? $values['main_categories'] : [] as $row) {
    if (is_array($row)) $mainCategories[] = ['id' => $cell($row, 'id', ''), 'columns' => $cell($row, 'columns', '4'), 'rows' => $cell($row, 'rows', '1')];
}
?>
<?php $this->insert('admin/_form_nav', ['sections' => ['settings-visible' => '공개', 'settings-banner' => '메인 배너', 'settings-shipping' => '배송·주문', 'settings-payment' => '결제', 'settings-notices' => '고객 안내', 'settings-main' => '메인 진열', 'settings-lists' => '목록 화면', 'settings-detail' => '상품 상세']]) ?>
<form class="yc-edit-form yc-shop-settings-form" method="post" enctype="multipart/form-data" action="<?= $this->e($admin_url) ?>/settings">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <section class="card" id="settings-visible"><div class="card-body"><h2 class="card-title">공개</h2>
    <input type="hidden" name="visible_form" value="1">
    <label class="label"><input class="checkbox checkbox-sm" type="checkbox" name="visible" value="1"<?= ($values['visible'] ?? '1') === '1' ? ' checked' : '' ?>> 쇼핑몰 공개</label>
    <p class="muted">끄면 상단 메뉴의 쇼핑몰 탭이 사라지고 쇼핑몰 화면은 "준비 중" 안내를 보입니다. 관리자 화면과 그 화면에 쓰이는 이미지, 이미 받은 주문의 영수증, 진행 중인 결제는 영향을 받지 않습니다.</p>
  </div></section>
  <?php $this->insert('admin/_banner_settings') ?>
  <section class="card" id="settings-shipping"><div class="card-body"><h2 class="card-title">배송비와 주문 안내</h2><p class="muted">상점 기본배송 상품은 선불·착불별로 묶어 한 번 계산합니다. 무료 기준 0원은 금액에 따른 무료배송을 적용하지 않습니다.</p>
    <div class="yc-fields"><?php $num('shipping_fee', '기본 배송비 (원)', 0, 9999999); $num('shipping_free_minimum', '무료배송 기준 금액 (원)', 0, 9999999); ?></div>
    <fieldset class="fieldset<?= isset($errors['shipping_default_carrier']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="yc-default-carrier">기본 택배사</label></legend>
      <select class="select select-bordered" id="yc-default-carrier" name="shipping_default_carrier"><option value="">기본값 없음</option><?php foreach ($carriers as $carrier => $label): ?><option value="<?= $this->e($carrier) ?>"<?= ($values['shipping_default_carrier'] ?? '') === $carrier ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?></select>
      <p class="muted">배송 처리할 때 미리 선택됩니다. 택배사 목록은 config/shop_carriers.json에서 바꿀 수 있고, 파일이 없으면 기본 목록을 사용합니다. 주문마다 다른 택배사를 고를 수 있습니다.</p>
      <?php if (isset($errors['shipping_default_carrier'])): ?><p class="validator-hint"><?= $this->e($errors['shipping_default_carrier']) ?></p><?php endif ?>
    </fieldset>
    <label class="fieldset"><span class="fieldset-legend">주문 접수 안내</span><textarea class="textarea textarea-bordered textarea-block" name="order_notice" rows="4" maxlength="2000" required><?= $this->e((string) ($values['order_notice'] ?? '')) ?></textarea><span class="muted">주문서와 주문 완료 화면에 표시됩니다. 카드 결제와 무통장입금은 아래 결제 항목에서 설정합니다.</span></label>
  </div></section>
  <section class="card" id="settings-payment"><div class="card-body"><h2 class="card-title">결제</h2><p class="muted">온라인 PG 결제는 에스크로 배송 절차가 필요 없는 신용카드만 사용합니다. 계좌이체·가상계좌·휴대폰 결제는 제공하지 않습니다. 무통장입금은 PG를 거치지 않고 아래 계좌를 안내하며 관리자가 입금을 확인합니다.</p>
    <fieldset class="fieldset"><legend class="fieldset-legend"><label for="payment-provider">온라인 결제사</label></legend><select class="select select-bordered" id="payment-provider" name="payment_provider"><?php foreach ($payment_providers as $id => $label): ?><option value="<?= $this->e($id) ?>"<?= ($values['payment_provider'] ?? 'inicis') === $id ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?></select><p class="muted">새 주문에 적용합니다. 기존 주문은 결제했던 PG로 조회·환불합니다.</p></fieldset>
    <fieldset class="fieldset"><legend class="fieldset-legend">결제 환경</legend>
      <?php foreach (['live' => '운영', 'test' => '테스트'] as $env => $label): ?><label class="label"><input class="radio radio-sm" type="radio" name="payment_environment" value="<?= $env ?>"<?= ($values['payment_environment'] ?? 'live') === $env ? ' checked' : '' ?>> <?= $label ?></label><?php endforeach ?>
      <span class="muted">선택한 환경과 신용카드 사용 여부가 새 주문에 적용됩니다.</span></fieldset>
    <?php foreach (['live' => '운영', 'test' => '테스트'] as $env => $envLabel): $environmentSettings = $inicis_environments[$env]; ?>
    <div data-yc-payment-panel data-provider="inicis" data-environment="<?= $env ?>"<?= ($values['payment_provider'] ?? 'inicis') === 'inicis' && ($values['payment_environment'] ?? 'live') === $env ? '' : ' hidden' ?>><div class="yc-settings-block"><h3>이니시스 <?= $envLabel ?> 연동<?php if ($env === 'live'): ?> <span class="badge badge-soft<?= $environmentSettings['configured'] ? ' badge-success' : '' ?>"><?= !$environmentSettings['configured'] ? '미설정' : '일반 카드 설정' ?></span><?php endif ?></h3>
      <p><a class="link" href="<?= $this->e($payment_manuals['inicis']) ?>" target="_blank" rel="noopener noreferrer">이니시스 공식 연동 매뉴얼 <?= $this->icon('external', 14) ?></a></p>
      <?php if ($env === 'test'): ?>
      <p>테스트 상점 아이디 (MID): <strong><?= $this->e(\GnuCms\Payment\ProviderConfig::TEST_MID) ?></strong></p>
      <p class="muted">하단의 설정 저장을 누르면 이니시스 공용 테스트 정보가 자동 적용됩니다.</p>
      <?php else: ?>
      <p class="muted">운영 MID와 발급받은 일반 카드 결제 인증키를 입력합니다. 하단의 설정 저장을 누르면 운영 연동 정보와 선택한 주문 환경이 함께 저장됩니다. MID가 같으면 키를 비워 두어도 저장된 값이 유지됩니다.</p>
      <div class="yc-fields">
        <?php foreach ($inicis_fields as $name => $field): if ($name === 'mode') continue; ?><fieldset class="fieldset"><legend class="fieldset-legend"><label for="yc-inicis-<?= $env ?>-<?= $this->e($name) ?>"><?= $this->e($field['label']) ?></label></legend>
          <?php if ($field['secret']): ?><label class="input input-bordered input-block">
            <input id="yc-inicis-<?= $env ?>-<?= $this->e($name) ?>" type="password" name="payment_credentials[inicis][<?= $env ?>][<?= $this->e($name) ?>]" value="" autocomplete="new-password" placeholder="<?= !empty($environmentSettings[$name . '_set']) ? str_repeat('*', (int) ($environmentSettings[$name . '_length'] ?? 0)) : ($environmentSettings['configured'] ? '저장된 키 없음 · 새 키 입력' : '') ?>">
            <button class="pw-toggle" type="button" data-yc-inicis-key-toggle data-secret-url="<?= $this->url('admin.settings.payment.secret') ?>" data-csrf="<?= $this->e($csrf_token) ?>" data-environment="<?= $env ?>" data-field="<?= $this->e($name) ?>" data-key-set="<?= !empty($environmentSettings[$name . '_set']) ? '1' : '0' ?>" aria-pressed="false" aria-label="<?= $this->e($field['label']) ?> 표시" title="<?= $this->e($field['label']) ?> 표시">
              <span class="pw-ico pw-ico-show" aria-hidden="true"><?= $this->icon('eye', 17) ?></span>
              <span class="pw-ico pw-ico-hide" aria-hidden="true"><?= $this->icon('eye-off', 17) ?></span>
            </button>
          </label><?php else: ?>
          <input class="input input-bordered input-sm" id="yc-inicis-<?= $env ?>-<?= $this->e($name) ?>" type="text" name="payment_credentials[inicis][<?= $env ?>][<?= $this->e($name) ?>]" value="<?= $this->e((string) ($environmentSettings[$name] ?? '')) ?>" autocomplete="off">
          <?php endif ?>
          <?php if ($name === 'client_ip'): ?><p class="muted">여러 서버에서 요청하면 각 서버의 config/config.php에 payment.inicis.client_ip를 지정합니다. 비워 두면 이 기본값을 사용합니다.</p><?php endif ?>
        </fieldset><?php endforeach ?>
      </div>
      <?php endif ?>
    </div></div>
    <?php endforeach ?>
    <?php foreach (['live' => '운영', 'test' => '테스트'] as $env => $envLabel): $environmentSettings = $kcp_legacy_environments[$env]; ?>
    <div data-yc-payment-panel data-provider="kcp_legacy" data-environment="<?= $env ?>"<?= ($values['payment_provider'] ?? 'inicis') === 'kcp_legacy' && ($values['payment_environment'] ?? 'live') === $env ? '' : ' hidden' ?>><div class="yc-settings-block"><h3>NHN KCP 기존 방식 (TCP/IP) · <?= $envLabel ?><?php if ($env === 'live'): ?> <span class="badge badge-soft<?= $environmentSettings['configured'] && $kcp_legacy_module_available ? ' badge-success' : '' ?>"><?= !$environmentSettings['configured'] ? '미등록' : (!$kcp_legacy_module_available ? '승인 모듈 미설치' : '카드 방식 등록') ?></span><?php endif ?></h3>
      <p><a class="link" href="<?= $this->e($payment_manuals['kcp_legacy']) ?>" target="_blank" rel="noopener noreferrer">KCP 기존 방식·전환 공식 안내 <?= $this->icon('external', 14) ?></a></p>
      <p class="muted">일반 카드 결제용 설정을 등록합니다.</p>
      <?php if ($kcp_legacy_module_available): ?><p class="muted">TCP/IP 승인 모듈이 설치되어 있습니다. 이 PG와 환경을 선택하고 신용카드 결제를 켜면 주문서에 표시됩니다.</p><?php else: ?><p class="muted">KCP에서 받은 pp_cli 실행 파일과 pub.key를 storage/payment/kcp_legacy/bin/에 설치해야 합니다. 모듈이 없는 동안 KCP 카드는 주문서에 표시되지 않습니다.</p><?php endif ?>
      <?php if ($env === 'test'): ?>
      <p class="muted">하단의 설정 저장을 누르면 일반결제용 공용 테스트 사이트 키가 자동 적용됩니다.</p>
      <?php else: ?>
      <p class="muted">일반 카드 결제용 운영 사이트 코드·사이트 키 한 쌍을 등록합니다. 사이트 코드가 같으면 사이트 키를 비워 두어도 저장된 값이 유지됩니다.</p>
      <div class="yc-fields">
        <?php foreach ($kcp_legacy_fields as $name => $field): if ($name === 'mode') continue; $fieldId = 'yc-kcp-legacy-' . $env . '-' . $name; $fieldName = 'payment_credentials[kcp_legacy][' . $env . '][' . $name . ']'; $fieldError = ($values['payment_provider'] ?? '') === 'kcp_legacy' && ($values['payment_environment'] ?? '') === $env && isset($errors[$name]); ?>
        <fieldset class="fieldset<?= $fieldError ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="<?= $this->e($fieldId) ?>"><?= $this->e($field['label']) ?></label></legend>
          <input class="input input-bordered input-block" id="<?= $this->e($fieldId) ?>" type="<?= $field['secret'] ? 'password' : 'text' ?>" name="<?= $this->e($fieldName) ?>" value="<?= $field['secret'] ? '' : $this->e((string) ($environmentSettings[$name] ?? '')) ?>" autocomplete="<?= $field['secret'] ? 'new-password' : 'off' ?>" placeholder="<?= $field['secret'] && !empty($environmentSettings[$name . '_set']) ? '저장된 값 유지 · 변경할 때만 입력' : '' ?>">
          <?php if ($fieldError): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?>
        </fieldset>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div></div>
    <?php endforeach ?>
    <?php foreach (['live' => '운영', 'test' => '테스트'] as $env => $envLabel): $environmentSettings = $kcp_environments[$env]; ?>
    <div data-yc-payment-panel data-provider="kcp" data-environment="<?= $env ?>"<?= ($values['payment_provider'] ?? 'inicis') === 'kcp' && ($values['payment_environment'] ?? 'live') === $env ? '' : ' hidden' ?>><div class="yc-settings-block"><h3>NHN KCP REST API 방식 · <?= $envLabel ?> <span class="badge badge-soft<?= $environmentSettings['configured'] ? ' badge-success' : '' ?>"><?= !$environmentSettings['configured'] ? '미설정' : '일반 카드 설정' ?></span></h3>
      <p><a class="link" href="<?= $this->e($payment_manuals['kcp']) ?>" target="_blank" rel="noopener noreferrer">KCP 표준결제 공식 매뉴얼 <?= $this->icon('external', 14) ?></a></p>
      <?php if ($env === 'test'): ?>
      <p class="muted">테스트 사이트 코드 T0000과 KCP가 공개한 인증서·개인키 파일, 테스트용 개인키 비밀번호가 설정 저장 시 자동 적용됩니다. PEM을 입력할 필요가 없습니다.</p>
      <?php else: ?>
      <p class="muted">운영 일반 카드 결제용 사이트 코드와 KCP에서 발급받은 서비스 인증서·개인키·발급 시 설정한 비밀번호를 입력합니다. 사이트 코드가 같으면 인증 정보를 비워 두어도 저장된 값이 유지됩니다.</p>
      <div class="yc-fields yc-kcp-fields">
        <?php foreach ($kcp_fields as $name => $field): if ($name === 'mode') continue; $fieldId = 'yc-kcp-' . $env . '-' . $name; $fieldName = 'payment_credentials[kcp][' . $env . '][' . $name . ']'; $fieldError = ($values['payment_provider'] ?? '') === 'kcp' && ($values['payment_environment'] ?? '') === $env && isset($errors[$name]); ?>
        <fieldset class="fieldset<?= $field['multiline'] ? ' yc-field-wide' : '' ?><?= $fieldError ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="<?= $this->e($fieldId) ?>"><?= $this->e($field['label']) ?></label></legend>
          <?php if ($field['multiline']): ?>
          <textarea class="textarea textarea-bordered input-block" id="<?= $this->e($fieldId) ?>" name="<?= $this->e($fieldName) ?>" rows="6" autocomplete="new-password" placeholder="<?= !empty($environmentSettings[$name . '_set']) ? '저장된 값 유지 · 변경할 때만 입력' : '' ?>"></textarea>
          <?php else: ?>
          <input class="input input-bordered input-block" id="<?= $this->e($fieldId) ?>" type="<?= $field['secret'] ? 'password' : 'text' ?>" name="<?= $this->e($fieldName) ?>" value="<?= $field['secret'] ? '' : $this->e((string) ($env === 'test' && $name === 'site_cd' ? 'T0000' : ($environmentSettings[$name] ?? ''))) ?>" autocomplete="<?= $field['secret'] ? 'new-password' : 'off' ?>"<?= $env === 'test' && $name === 'site_cd' ? ' readonly' : '' ?> placeholder="<?= $field['secret'] && !empty($environmentSettings[$name . '_set']) ? '저장된 값 유지 · 변경할 때만 입력' : '' ?>">
          <?php endif ?>
          <?php if ($fieldError): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?>
        </fieldset>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div></div>
    <?php endforeach ?>
    <?php foreach (['toss' => '토스페이먼츠', 'nicepay' => '나이스페이먼츠'] as $providerId => $providerLabel): foreach (['live' => '운영', 'test' => '테스트'] as $env => $envLabel): $environmentSettings = $providerId === 'toss' ? $toss_environments[$env] : $nicepay_environments[$env]; $providerFields = $providerId === 'toss' ? $toss_fields : $nicepay_fields; ?>
    <div data-yc-payment-panel data-provider="<?= $providerId ?>" data-environment="<?= $env ?>"<?= ($values['payment_provider'] ?? 'inicis') === $providerId && ($values['payment_environment'] ?? 'live') === $env ? '' : ' hidden' ?>><div class="yc-settings-block">
      <h3><?= $providerLabel ?> · <?= $envLabel ?> <span class="badge badge-soft<?= $environmentSettings['configured'] ? ' badge-success' : '' ?>"><?= !$environmentSettings['configured'] ? '미설정' : '일반 카드 설정' ?></span></h3>
      <p><a class="link" href="<?= $this->e($payment_manuals[$providerId]) ?>" target="_blank" rel="noopener noreferrer"><?= $this->e($providerLabel) ?> 공식 연동 매뉴얼 <?= $this->icon('external', 14) ?></a></p>
      <?php if ($env === 'test'): ?>
      <p class="muted">하단의 설정 저장을 누르면 <?= $providerId === 'toss' ? '토스페이먼츠 공식 SDK v1 샘플' : '나이스페이먼츠 공식 서버 승인 샌드박스 샘플' ?>의 공개 테스트 키가 자동 적용됩니다.</p>
      <?php else: ?>
      <p class="muted"><?= $envLabel ?> 일반 카드 결제용 클라이언트 키와 서버 시크릿 키를 입력합니다. <?= $providerId === 'nicepay' ? '나이스페이는 서버 승인 모델·Basic 인증 키가 필요합니다.' : '토스는 API 개별 연동 키가 필요합니다.' ?></p>
      <div class="yc-fields">
        <?php foreach ($providerFields as $name => $field): if ($name === 'mode') continue; $fieldId = 'yc-' . $providerId . '-' . $env . '-' . $name; $fieldName = 'payment_credentials[' . $providerId . '][' . $env . '][' . $name . ']'; $fieldError = ($values['payment_provider'] ?? '') === $providerId && ($values['payment_environment'] ?? '') === $env && isset($errors[$name]); ?>
        <fieldset class="fieldset<?= $fieldError ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="<?= $this->e($fieldId) ?>"><?= $this->e($field['label']) ?></label></legend>
          <input class="input input-bordered input-block" id="<?= $this->e($fieldId) ?>" type="<?= $field['secret'] ? 'password' : 'text' ?>" name="<?= $this->e($fieldName) ?>" value="<?= $field['secret'] ? '' : $this->e((string) ($environmentSettings[$name] ?? '')) ?>" autocomplete="<?= $field['secret'] ? 'new-password' : 'off' ?>" placeholder="<?= $field['secret'] && !empty($environmentSettings[$name . '_set']) ? '저장된 값 유지 · 변경할 때만 입력' : '' ?>">
          <?php if ($fieldError): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?>
        </fieldset><?php endforeach ?>
      </div>
      <?php endif ?>
    </div></div>
    <?php endforeach; endforeach ?>
    <h3 class="yc-settings-subtitle">온라인 결제 수단</h3><div class="yc-fields">
      <label class="label"><input type="hidden" name="payment_method_card" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="payment_method_card" value="1"<?= ($values['payment_method_card'] ?? '1') === '1' ? ' checked' : '' ?>> 신용카드</label>
    </div>
    <label class="label"><input class="checkbox checkbox-sm" type="checkbox" name="payment_manual_enabled" value="1"<?= ($values['payment_manual_enabled'] ?? '0') === '1' ? ' checked' : '' ?>> 무통장입금 사용</label>
    <div class="yc-fields">
      <?php foreach (['bank' => '은행', 'account' => '계좌번호', 'holder' => '예금주'] as $key => $label): $name = 'payment_manual_' . $key; ?>
      <fieldset class="fieldset<?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="yc-setting-<?= $name ?>"><?= $label ?></label></legend>
        <input class="input input-bordered input-sm" type="text" id="yc-setting-<?= $name ?>" name="<?= $name ?>" maxlength="50" value="<?= $this->e((string) ($values[$name] ?? '')) ?>">
        <?php if (isset($errors[$name])): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?></fieldset>
      <?php endforeach ?>
    </div>
    <div class="yc-fields"><?php $num('payment_deadline_card', '신용카드 결제 기한 (시간)', 1, 72); $num('payment_deadline_manual_transfer', '무통장 입금 기한 (시간)', 1, 720); ?></div>
    <p class="muted">기한이 지난 미결제 주문은 자동으로 취소되고 재고가 돌아갑니다.</p>
  </div></section>
  <section class="card" id="settings-notices"><div class="card-body"><h2 class="card-title">고객 안내문</h2><p class="muted">상품 상세의 배송정보·교환정보 탭에 표시됩니다.</p>
    <fieldset class="fieldset"><legend class="fieldset-legend">배송정보 탭</legend><textarea class="textarea textarea-bordered textarea-block" name="shipping_content" rows="6"><?= $this->e((string) ($values['shipping_content'] ?? '')) ?></textarea></fieldset>
    <fieldset class="fieldset"><legend class="fieldset-legend">교환정보 탭</legend><textarea class="textarea textarea-bordered textarea-block" name="exchange_content" rows="6"><?= $this->e((string) ($values['exchange_content'] ?? '')) ?></textarea></fieldset>
  </div></section>
  <section class="card" id="settings-main"><div class="card-body"><h2 class="card-title">메인 화면 블록</h2>
    <p class="muted">묶음은 규칙으로 자동으로 채웁니다. 기준을 "분류 선택"으로 바꾸면 그 분류(하위 포함)의 상품을 분류 정렬대로 보입니다. 이미지 높이를 0으로 설정하면 원본 비율을 유지합니다.</p>
    <h3 class="yc-settings-subtitle">자동 묶음</h3>
    <div class="yc-fields"><?php $num('auto_new_days', '신상품 기간(일)', 1, 365); $num('auto_best_days', '베스트 집계 기간(일)', 1, 365); ?></div>
    <p class="muted">신상품은 등록한 지 이 기간 안의 상품, 베스트는 이 기간의 판매량 순입니다. 인기상품은 누적 조회수, 할인상품은 시중가보다 싼 상품입니다.</p>
    <div class="yc-settings-blocks">
    <?php foreach ($types as $type => $label): $source = 'main_' . $type . '_source'; ?>
      <div class="yc-settings-block"><header><h3><?= $this->e($label) ?></h3>
        <label class="label cursor-pointer"><input type="hidden" name="main_<?= $type ?>_use" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="main_<?= $type ?>_use" value="1"<?= ($values['main_' . $type . '_use'] ?? '') === '1' ? ' checked' : '' ?>> 메인에 표시</label></header>
        <div class="yc-fields">
          <fieldset class="fieldset"><legend class="fieldset-legend"><label for="yc-setting-<?= $source ?>">기준</label></legend>
            <select class="select select-bordered select-sm" id="yc-setting-<?= $source ?>" name="<?= $source ?>"><?php foreach (['auto' => '자동 규칙', 'category' => '분류 선택'] as $key => $sourceLabel): ?><option value="<?= $key ?>"<?= ($values[$source] ?? 'auto') === $key ? ' selected' : '' ?>><?= $sourceLabel ?></option><?php endforeach ?></select></fieldset>
          <fieldset class="fieldset<?= isset($errors[$source . '_category_id']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="yc-setting-<?= $source ?>_category_id">기준 분류</label></legend>
            <select class="select select-bordered select-sm" id="yc-setting-<?= $source ?>_category_id" name="<?= $source ?>_category_id"><?php $catOptions((string) ($values[$source . '_category_id'] ?? '')) ?></select>
            <?php if (isset($errors[$source . '_category_id'])): ?><p class="validator-hint"><?= $this->e($errors[$source . '_category_id']) ?></p><?php endif ?></fieldset>
        </div>
        <div class="yc-fields"><?php $num('main_' . $type . '_columns', '한 행 상품 수', 1, 12); $num('main_' . $type . '_rows', '행 수', 1, 50); $num('main_' . $type . '_image_width', '이미지 너비', 0, 2000); $num('main_' . $type . '_image_height', '이미지 높이(0 = 비율)', 0, 2000); ?></div>
      </div>
    <?php endforeach ?>
    </div>
    <h3 class="yc-settings-subtitle">메인 분류 블록</h3>
    <p class="muted">고른 분류의 상품을 메인에 순서대로 놓습니다. 메뉴 숨김 분류도 고를 수 있어 기획전을 메인에 올릴 때 씁니다. 최대 <?= \GnuCms\Shop\Settings::MAX_MAIN_CATEGORIES ?>개.</p>
    <div class="yc-main-category-row yc-main-category-head" aria-hidden="true"><span>분류</span><span>한 행 상품 수</span><span>행 수</span><span></span></div>
    <div class="yc-main-category-rows<?= isset($errors['main_categories']) ? ' is-invalid' : '' ?>" data-yc-main-categories>
      <?php foreach ($mainCategories as $index => $row): ?><?php $categoryRow((string) $index, $row) ?><?php endforeach ?>
    </div>
    <template data-yc-main-category-row><?php $categoryRow('__i__', ['id' => '', 'columns' => '4', 'rows' => '1']) ?></template>
    <div class="yc-main-category-add"><button class="btn btn-xs" type="button" data-yc-add-main-category><?= $this->icon('plus', 14) ?> 블록 추가</button></div>
    <?php if (isset($errors['main_categories'])): ?><p class="validator-hint"><?= $this->e($errors['main_categories']) ?></p><?php endif ?>
  </div></section>
  <div id="settings-lists">
  <?php foreach (['category' => '분류 목록 기본값(새 분류에 적용)', 'type' => '묶음 목록', 'search' => '검색 결과'] as $section => $label): ?>
    <section class="card"><div class="card-body"><h2 class="card-title"><?= $label ?></h2>
      <div class="yc-fields"><?php $num($section . '_columns', '한 행 상품 수', 1, 12); $num($section . '_rows', '행 수', 1, 50); $num($section . '_image_width', '이미지 너비', 0, 2000); $num($section . '_image_height', '이미지 높이(0 = 비율)', 0, 2000); ?></div>
    </div></section>
  <?php endforeach ?>
  </div>
  <section class="card" id="settings-detail"><div class="card-body"><h2 class="card-title">관련상품과 상세</h2>
    <label class="label cursor-pointer"><input type="hidden" name="related_use" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="related_use" value="1"<?= ($values['related_use'] ?? '') === '1' ? ' checked' : '' ?>> 상세에 관련상품 표시</label>
    <div class="yc-fields"><?php $num('related_columns', '관련상품 한 행 수', 1, 12); $num('related_image_width', '관련상품 이미지 너비', 0, 2000); $num('related_image_height', '관련상품 이미지 높이', 0, 2000); $num('detail_image_width', '상세 대표 이미지 너비', 0, 2000); $num('detail_image_height', '상세 대표 이미지 높이', 0, 2000); ?></div>
    <label class="label cursor-pointer"><input type="hidden" name="show_tax" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="show_tax" value="1"<?= ($values['show_tax'] ?? '') === '1' ? ' checked' : '' ?>> 가격 옆에 부가세 포함 표시</label>
  </div></section>
  <?php $this->insert('admin/_save_bar', ['save_label' => '설정 저장']) ?>
</form>
<?php $this->stop() ?>
