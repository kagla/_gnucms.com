<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>쇼핑몰 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '쇼핑몰 설정', 'description' => '배송 정책과 고객 안내, 쇼핑몰의 진열 방식을 설정하세요.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php $num = function (string $name, string $label, int $min, int $max) use ($values, $errors): void { ?>
  <fieldset class="fieldset<?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="yc-setting-<?= $this->e($name) ?>"><?= $this->e($label) ?></label></legend>
    <input class="input input-bordered input-sm" type="number" id="yc-setting-<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e((string) ($values[$name] ?? '')) ?>" min="<?= $min ?>" max="<?= $max ?>" required>
    <?php if (isset($errors[$name])): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?></fieldset>
<?php }; ?>
<?php $this->insert('admin/_form_nav', ['sections' => ['settings-shipping' => '배송·주문', 'settings-notices' => '고객 안내', 'settings-main' => '메인 진열', 'settings-lists' => '목록 화면', 'settings-detail' => '상품 상세']]) ?>
<form class="yc-edit-form" method="post" action="<?= $this->e($admin_url) ?>/settings">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <section class="card" id="settings-shipping"><div class="card-body"><h2 class="card-title">배송비와 주문 안내</h2><p class="muted">상점 기본배송 상품은 선불·착불별로 묶어 한 번 계산합니다. 무료 기준 0원은 금액에 따른 무료배송을 적용하지 않습니다.</p>
    <div class="yc-fields"><?php $num('shipping_fee', '기본 배송비 (원)', 0, 9999999); $num('shipping_free_minimum', '무료배송 기준 금액 (원)', 0, 9999999); ?></div>
    <label class="fieldset"><span class="fieldset-legend">주문 접수 안내</span><textarea class="textarea textarea-bordered textarea-block" name="order_notice" rows="4" maxlength="2000" required><?= $this->e((string) ($values['order_notice'] ?? '')) ?></textarea><span class="muted">주문서와 주문 완료 화면에 표시됩니다. 온라인 결제는 별도 연동 단계입니다.</span></label>
  </div></section>
  <section class="card" id="settings-notices"><div class="card-body"><h2 class="card-title">고객 안내문</h2><p class="muted">상품 상세의 배송정보·교환정보 탭에 표시됩니다.</p>
    <fieldset class="fieldset"><legend class="fieldset-legend">배송정보 탭</legend><textarea class="textarea textarea-bordered textarea-block" name="shipping_content" rows="6"><?= $this->e((string) ($values['shipping_content'] ?? '')) ?></textarea></fieldset>
    <fieldset class="fieldset"><legend class="fieldset-legend">교환정보 탭</legend><textarea class="textarea textarea-bordered textarea-block" name="exchange_content" rows="6"><?= $this->e((string) ($values['exchange_content'] ?? '')) ?></textarea></fieldset>
  </div></section>
  <section class="card" id="settings-main"><div class="card-body"><h2 class="card-title">메인 화면 블록</h2><p class="muted">표시할 상품 유형과 진열 크기를 정하세요. 이미지 높이를 0으로 설정하면 원본 비율을 유지합니다.</p><div class="yc-settings-blocks">
    <?php foreach ($types as $type => $label): ?>
      <div class="yc-settings-block"><header><h3><?= $this->e($label) ?></h3>
        <label class="label cursor-pointer"><input type="hidden" name="main_<?= $type ?>_use" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="main_<?= $type ?>_use" value="1"<?= ($values['main_' . $type . '_use'] ?? '') === '1' ? ' checked' : '' ?>> 메인에 표시</label></header>
        <div class="yc-fields"><?php $num('main_' . $type . '_columns', '한 행 상품 수', 1, 12); $num('main_' . $type . '_rows', '행 수', 1, 50); $num('main_' . $type . '_image_width', '이미지 너비', 0, 2000); $num('main_' . $type . '_image_height', '이미지 높이(0 = 비율)', 0, 2000); ?></div>
      </div>
    <?php endforeach ?>
  </div></div></section>
  <div id="settings-lists">
  <?php foreach (['category' => '분류 목록 기본값(새 분류에 적용)', 'type' => '유형별 목록', 'search' => '검색 결과'] as $section => $label): ?>
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
