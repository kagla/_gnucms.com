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
<form class="yc-edit-form" method="post" enctype="multipart/form-data" action="<?= $this->e($admin_url) ?>/settings">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <section class="card" id="settings-visible"><div class="card-body"><h2 class="card-title">공개</h2>
    <input type="hidden" name="visible_form" value="1">
    <label class="label"><input class="checkbox checkbox-sm" type="checkbox" name="visible" value="1"<?= ($values['visible'] ?? '1') === '1' ? ' checked' : '' ?>> 쇼핑몰 공개</label>
    <p class="muted">끄면 상단 메뉴의 쇼핑몰 탭이 사라지고 쇼핑몰 화면은 "준비 중" 안내를 보입니다. 관리자 화면과 그 화면에 쓰이는 이미지, 이미 받은 주문의 영수증, 진행 중인 결제는 영향을 받지 않습니다.</p>
  </div></section>
  <?php $this->insert('admin/_banner_settings') ?>
  <section class="card" id="settings-shipping"><div class="card-body"><h2 class="card-title">배송비와 주문 안내</h2><p class="muted">상점 기본배송 상품은 선불·착불별로 묶어 한 번 계산합니다. 무료 기준 0원은 금액에 따른 무료배송을 적용하지 않습니다.</p>
    <div class="yc-fields"><?php $num('shipping_fee', '기본 배송비 (원)', 0, 9999999); $num('shipping_free_minimum', '무료배송 기준 금액 (원)', 0, 9999999); ?></div>
    <label class="fieldset"><span class="fieldset-legend">주문 접수 안내</span><textarea class="textarea textarea-bordered textarea-block" name="order_notice" rows="4" maxlength="2000" required><?= $this->e((string) ($values['order_notice'] ?? '')) ?></textarea><span class="muted">주문서와 주문 완료 화면에 표시됩니다. 카드 결제와 무통장입금은 아래 결제 항목에서 설정합니다.</span></label>
  </div></section>
  <section class="card" id="settings-payment"><div class="card-body"><h2 class="card-title">결제</h2><p class="muted">카드 결제는 설정 → 결제에서 저장하고 실행을 허용한 이니시스 환경을 씁니다. 무통장입금은 아래 계좌를 안내하고 관리자가 입금을 확인합니다.</p>
    <fieldset class="fieldset"><legend class="fieldset-legend">결제 환경</legend>
      <?php foreach (['live' => '운영', 'test' => '테스트'] as $env => $label): ?><label class="label"><input class="radio radio-sm" type="radio" name="payment_environment" value="<?= $env ?>"<?= ($values['payment_environment'] ?? 'live') === $env ? ' checked' : '' ?>> <?= $label ?></label><?php endforeach ?>
      <span class="muted">주문서의 카드 결제는 이 환경이 결제 설정에서 허용돼 있을 때만 보입니다.</span></fieldset>
    <label class="label"><input class="checkbox checkbox-sm" type="checkbox" name="payment_manual_enabled" value="1"<?= ($values['payment_manual_enabled'] ?? '0') === '1' ? ' checked' : '' ?>> 무통장입금 사용</label>
    <div class="yc-fields">
      <?php foreach (['bank' => '은행', 'account' => '계좌번호', 'holder' => '예금주'] as $key => $label): $name = 'payment_manual_' . $key; ?>
      <fieldset class="fieldset<?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="yc-setting-<?= $name ?>"><?= $label ?></label></legend>
        <input class="input input-bordered input-sm" type="text" id="yc-setting-<?= $name ?>" name="<?= $name ?>" maxlength="50" value="<?= $this->e((string) ($values[$name] ?? '')) ?>">
        <?php if (isset($errors[$name])): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?></fieldset>
      <?php endforeach ?>
    </div>
    <div class="yc-fields"><?php $num('payment_deadline_card', '카드 결제 기한 (시간)', 1, 72); $num('payment_deadline_manual_transfer', '무통장 입금 기한 (시간)', 1, 720); $num('payment_deadline_virtual_account', '가상계좌 입금 기한 (시간)', 1, 720); ?></div>
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
