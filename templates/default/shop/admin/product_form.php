<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?><?= $copy_source !== null ? '상품 복사' : ($id === null ? '상품 등록' : '상품 수정') ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => $copy_source !== null ? '상품 복사' : ($id === null ? '상품 등록' : '상품 수정'), 'description' => $copy_source !== null ? $copy_source['code'] . '에서 복사' : ($id === null ? '상품 정보와 판매 조건을 입력해 새 상품을 등록하세요.' : $product['name'] . ' · ' . $product['code']), 'actions' => array_merge([['url' => $admin_url . '/products', 'label' => '상품 목록']], $id === null ? [] : [['url' => $admin_url . '/products/settings-copy?source=' . (int) $product['id'], 'label' => '설정 복사'], ['url' => $public_url . '/item?id=' . rawurlencode((string) $product['code']), 'label' => '상품 보기']])]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php if ($copy_source !== null): ?><div class="alert alert-info">아직 새 상품이 생성되지 않았습니다. 복사된 내용을 확인하고 <strong>새 상품 저장</strong>을 눌러야 등록됩니다.</div><?php endif ?>
<?php
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
$field = function (string $name, string $label, string $type = 'text', array $attrs = []) use ($v, $errors): void {
    $appearance = match ($name) { 'price' => ' yc-price-sale', 'list_price' => ' yc-price-list', default => '' };
    $extra = ''; foreach ($attrs as $k => $val) $extra .= ' ' . $k . '="' . $this->e((string) $val) . '"'; ?>
  <fieldset class="fieldset<?= $appearance ?><?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><label for="yc-field-<?= $name ?>"><?= $this->e($label) ?></label></legend>
    <input class="input input-bordered input-sm input-block" type="<?= $type ?>" id="yc-field-<?= $name ?>" name="<?= $name ?>" value="<?= $this->e($v($name)) ?>"<?= $extra ?>>
    <?php if (isset($errors[$name])): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?></fieldset>
<?php };
$check = function (string $name, string $label) use ($v): void {
$appearance = match ($name) { 'active' => ' yc-status-sale', 'sold_out' => ' yc-status-soldout', 'phone_inquiry' => ' yc-status-phone', 'tax_free' => ' yc-status-tax', default => '' }; ?>
  <label class="label cursor-pointer<?= $appearance ?>"><input type="hidden" name="<?= $name ?>" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="<?= $name ?>" value="1"<?= $v($name) === '1' ? ' checked' : '' ?>> <?= $this->e($label) ?></label>
<?php };
$catOptions = function (string $selected) use ($categories, $public_url): void { ?><option value="">선택</option><?php foreach ($categories as $cid => $option): ?><option value="<?= $cid ?>" title="<?= $this->e($option['title']) ?>" data-public-url="<?= $this->e($public_url . '/c/' . rawurlencode((string) $option['slug'])) ?>"<?= $selected === (string) $cid ? ' selected' : '' ?>><?= $this->e($option['text']) ?></option><?php endforeach ?><?php };
$categoryView = function (string $selected, string $label) use ($categories, $public_url): void {
    $url = isset($categories[(int) $selected]) ? $public_url . '/c/' . rawurlencode((string) $categories[(int) $selected]['slug']) : ''; ?>
  <a class="yc-category-view"<?= $url === '' ? ' hidden' : ' href="' . $this->e($url) . '"' ?> target="_blank" rel="noopener" title="<?= $this->e($label) ?>" aria-label="<?= $this->e($label) ?>" data-yc-category-view><?= $this->icon('external', 15) ?></a>
<?php };
$extraRow = function (string $selected) use ($catOptions, $categoryView): void { ?>
  <div class="yc-category-row" data-yc-category-row-item><select class="select select-bordered select-sm" name="extra_category_ids[]" aria-label="추가 분류" data-yc-category-select><?php $catOptions($selected) ?></select><?php $categoryView($selected, '선택한 추가 분류 상품 보기') ?><button class="btn btn-xs" type="button" data-yc-remove-category aria-label="이 추가 분류 제거">제거</button></div>
<?php };
$shopShippingFee = max(0, (int) ($shop_shipping['fee'] ?? 0));
$shopShippingMinimum = max(0, (int) ($shop_shipping['free_minimum'] ?? 0));
$shopShippingSummary = $shopShippingFee === 0 ? '무료배송'
    : '배송비 ' . number_format($shopShippingFee) . '원 · ' . ($shopShippingMinimum > 0
        ? number_format($shopShippingMinimum) . '원 이상 무료배송' : '무료배송 기준 없음');
?>
<?php $this->insert('admin/_form_nav', ['sections' => ['section-basic' => '판매 정보', 'section-images' => '사진', 'section-description' => '설명', 'section-info' => '상품정보고시', 'section-advanced-sale' => '추가 설정']]) ?>
<form method="post" action="<?= $this->e($admin_url) ?>/products/<?= $id === null ? 'new' : 'edit' ?>" enctype="multipart/form-data" class="yc-edit-form yc-product-form" data-yc-product-form>
  <button type="submit" hidden aria-hidden="true" tabindex="-1"></button>
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <input type="hidden" name="action" value="save" data-yc-action>
  <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="version" value="<?= $this->e($v('version')) ?>"><?php endif ?>
  <?php if ($copy_source !== null): ?><input type="hidden" name="copy_source_id" value="<?= (int) $copy_source['id'] ?>"><?php endif ?>
  <input type="hidden" name="image_key" value="<?= $this->e($v('image_key')) ?>">
  <input type="hidden" name="uploaded_images" value="" data-uploaded-images>
  <section class="card" id="section-basic"><div class="card-body"><h2 class="card-title">판매 정보</h2>
    <div class="yc-fields">
      <?php $field('name', '상품명', 'text', ['maxlength' => 250, 'required' => 'required']); $field('price', '판매가격', 'number', ['min' => 0, 'required' => 'required']); ?>
      <?php $locked = $options_rows !== [] ? ['readonly' => 'readonly', 'title' => '선택옵션이 있는 상품은 옵션별 재고를 사용합니다'] : []; $field('stock', '판매할 수량', 'number', ['min' => 0] + $locked); ?>
      <fieldset class="fieldset"><legend class="fieldset-legend">과세·면세</legend><select class="select select-bordered select-sm" name="tax_free"><option value="0"<?= $v('tax_free') === '0' ? ' selected' : '' ?>>과세</option><option value="1"<?= $v('tax_free') === '1' ? ' selected' : '' ?>>면세</option></select></fieldset>
      <fieldset class="fieldset<?= isset($errors['category_id']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">분류</legend><select class="select select-bordered select-sm" name="category_id" required data-yc-category-select><?php $catOptions($v('category_id')) ?></select><?php if (isset($errors['category_id'])): ?><p class="validator-hint"><?= $this->e($errors['category_id']) ?></p><?php endif ?></fieldset>
    </div>
    <div class="yc-checks"><?php $check('active', '판매하기'); $check('sold_out', '품절 표시'); ?></div>
    <p class="muted" data-yc-option-stock-note<?= $locked === [] ? ' hidden' : '' ?>>옵션이 있는 상품은 <a href="#section-options">옵션별 수량</a>을 사용합니다.</p>
    <p class="muted">배송비는 상점 기본 설정을 사용합니다: <?= $this->e($shopShippingSummary) ?>. 상품별 변경은 추가 설정에서 할 수 있습니다.</p>
  </div></section>
  <section class="card" id="section-images"><div class="card-body"><h2 class="card-title">상품 이미지 (최대 <?= \GnuCms\Shop\Images::MAX ?>장)</h2>
    <?php if (isset($errors['images'])): ?><p class="validator-hint"><?= $this->e($errors['images']) ?></p><?php endif ?>
    <?php if ($copy_source !== null && $images !== []): ?>
      <p class="muted">아래 이미지는 새 상품을 저장할 때 복사됩니다. 저장한 뒤 상품 수정에서 순서를 바꾸거나 삭제할 수 있습니다.</p>
      <ol class="yc-image-list">
        <?php foreach ($images as $image): ?><li>
          <img src="<?= $this->e($public_url . '/image?p=' . $image_owner_id . '&f=' . rawurlencode($image['filename']) . '&s=thumb') ?>" alt="" width="70" height="70">
          <code><?= $this->e($image['filename']) ?></code>
        </li><?php endforeach ?>
      </ol>
    <?php elseif ($images !== []): ?>
      <ol class="yc-image-list" data-yc-image-list>
        <?php foreach ($images as $image): ?><li data-yc-image="<?= (int) $image['id'] ?>">
          <img src="<?= $this->e($public_url . '/image?p=' . $image_owner_id . '&f=' . rawurlencode($image['filename']) . '&s=thumb') ?>" alt="" width="70" height="70">
          <code><?= $this->e($image['filename']) ?></code>
          <button class="btn btn-xs" type="button" data-yc-move="up" aria-label="위로">↑</button><button class="btn btn-xs" type="button" data-yc-move="down" aria-label="아래로">↓</button>
          <label><input class="checkbox checkbox-xs" type="checkbox" name="image_delete[]" value="<?= (int) $image['id'] ?>"> 삭제</label>
        </li><?php endforeach ?>
      </ol>
      <input type="hidden" name="image_order" value="<?= $this->e(implode(',', array_column($images, 'id'))) ?>" data-yc-image-order>
    <?php endif ?>
    <input class="file-input file-input-bordered file-input-sm" type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
  </div></section>
  <section class="card" id="section-description"><div class="card-body"><h2 class="card-title">상품 설명</h2>
    <fieldset class="fieldset"><legend class="fieldset-legend">상세 설명</legend><textarea class="textarea textarea-bordered textarea-block" id="yc-description" name="description" rows="10" data-cms-editor><?= $this->e($v('description')) ?></textarea><input type="hidden" name="uploaded_images" value="" data-uploaded-images></fieldset>
    <details class="yc-description-extra"><summary>요약 설명·관리 메모 (선택)</summary><fieldset class="fieldset"><legend class="fieldset-legend">요약 설명</legend><textarea class="textarea textarea-bordered textarea-block" name="summary" rows="3" maxlength="20000"><?= $this->e($v('summary')) ?></textarea></fieldset>

    <fieldset class="fieldset"><legend class="fieldset-legend">관리자 메모 (공개되지 않음)</legend><textarea class="textarea textarea-bordered textarea-block" name="memo" rows="2" maxlength="5000"><?= $this->e($v('memo')) ?></textarea></fieldset></details>
  </div></section>
  <section class="card" id="section-info"><div class="card-body"><h2 class="card-title">상품정보고시</h2>
    <fieldset class="fieldset"><legend class="fieldset-legend">고시 항목 군</legend><select class="select select-bordered select-sm" name="info_group" data-yc-info-select><option value="">사용 안 함</option><?php foreach ($info_groups as $key => $group): ?><option value="<?= $key ?>"<?= $v('info_group') === $key ? ' selected' : '' ?>><?= $this->e($group['label']) ?></option><?php endforeach ?></select></fieldset>
    <noscript><p class="muted">군을 바꾼 뒤 조합 생성 버튼을 누르면 항목 입력칸이 갱신됩니다.</p></noscript>
    <div class="yc-fields" data-yc-info-fields data-yc-info-groups="<?= $this->e(json_encode(array_map(static fn ($g) => $g['articles'], $info_groups), JSON_UNESCAPED_UNICODE)) ?>">
      <?php foreach ($info_groups[$v('info_group')]['articles'] ?? [] as $index => $article): ?><fieldset class="fieldset"><legend class="fieldset-legend"><?= $this->e($article) ?></legend><input class="input input-bordered input-sm" type="text" name="info[<?= $index ?>]" value="<?= $this->e((string) ($values['info'][$index] ?? '')) ?>" maxlength="500" placeholder="상품페이지 참고"></fieldset><?php endforeach ?>
    </div>
  </div></section>
  <details class="yc-advanced" id="section-advanced-sale"<?= $errors !== [] ? ' open' : '' ?>><summary>추가 판매 설정<small>상품 코드·구매 제한·알림 기준·추가 분류</small></summary><div class="card-body">
    <?php if ($id === null): ?><?php $field('code', '상품 코드 (비워 두면 자동 생성)', 'text', ['maxlength' => 20, 'pattern' => '[A-Za-z0-9_-]{1,20}']) ?><?php else: ?><p class="muted">상품 코드 <?= $this->e($product['code']) ?></p><?php endif ?>
    <div class="yc-fields"><?php $field('list_price', '비교가격 (선택)', 'number', ['min' => 0]); $field('stock_alert', '재고 알림 기준 (선택)', 'number', ['min' => 0] + $locked); $field('buy_min', '최소 구매수량 (0 = 제한 없음)', 'number', ['min' => 0, 'max' => 9999]); $field('buy_max', '최대 구매수량 (0 = 제한 없음)', 'number', ['min' => 0, 'max' => 9999]); ?></div>
    <div class="yc-checks"><?php $check('phone_inquiry', '전화문의(가격 숨김)'); ?></div>
        <fieldset class="fieldset<?= isset($errors['extra_category_ids']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">추가 분류 <span class="legend-hint">이벤트·기획전 분류에도 함께 보이게 합니다. <?= \GnuCms\Shop\Catalog\Products::MAX_EXTRA_CATEGORIES ?>개까지</span></legend>
      <div class="yc-category-rows" data-yc-categories>
        <?php foreach (is_array($values['extra_category_ids'] ?? null) ? array_filter($values['extra_category_ids'], 'is_scalar') : [] as $extraId): ?><?php $extraRow((string) $extraId) ?><?php endforeach ?>
      </div>
      <template data-yc-category-row><?php $extraRow('') ?></template>
      <button class="btn btn-xs" type="button" data-yc-add-category><?= $this->icon('plus', 14) ?> 분류 추가</button>
      <?php if (isset($errors['extra_category_ids'])): ?><p class="validator-hint"><?= $this->e($errors['extra_category_ids']) ?></p><?php endif ?></fieldset>

  </div></details>
  <details class="yc-advanced" id="section-shipping"<?= $v('shipping_type') !== '0' || $v('shipping_method') !== '0' || $errors !== [] ? ' open' : '' ?>><summary>상품별 배송비<small>상점 기본과 다르게 보낼 때</small></summary><div class="card-body">
    <div class="yc-fields">
      <fieldset class="fieldset"><legend class="fieldset-legend">배송비 유형</legend><select class="select select-bordered select-sm" name="shipping_type" data-yc-shipping-type><?php foreach ([0 => '상점 기본', 1 => '무료', 2 => '조건부 무료', 3 => '유료', 4 => '수량별 부과'] as $k => $l): ?><option value="<?= $k ?>"<?= $v('shipping_type') === (string) $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select>
        <div class="yc-shop-shipping-default muted" data-yc-shop-shipping-default aria-live="polite"<?= $v('shipping_type') === '0' ? '' : ' hidden' ?>><span>현재 상점 기본: <strong><?= $this->e($shopShippingSummary) ?></strong></span><a class="btn btn-xs" href="<?= $this->e($admin_url) ?>/settings#settings-shipping" target="_blank" rel="noopener">상점 배송 설정 보기</a></div>
      </fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">결제 방법</legend><select class="select select-bordered select-sm" name="shipping_method"><?php foreach ([0 => '선불', 1 => '착불', 2 => '구매자 선택'] as $k => $l): ?><option value="<?= $k ?>"<?= $v('shipping_method') === (string) $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></fieldset>
      <?php $field('shipping_fee', '배송비', 'number', ['min' => 0]); $field('shipping_free_minimum', '무료배송 기준 금액', 'number', ['min' => 0]); $field('shipping_per_qty', '배송비 부과 수량 단위', 'number', ['min' => 0]); ?>
    </div>
  </div></details>
  <details class="yc-advanced" id="section-options"<?= $options_rows !== [] || $errors !== [] ? ' open' : '' ?>><summary>선택옵션<small>크기·중량 등 선택지를 판매할 때</small></summary><div class="card-body">
    <p class="muted">그룹 이름과 쉼표로 구분한 값을 입력하고 <strong>조합 생성</strong>을 누르면 조합 표가 만들어집니다. 가격은 판매가에 더하는 차액입니다.</p>
    <div class="yc-option-groups<?= isset($errors['option_group']) ? ' is-invalid' : '' ?>"><?php $examples = [1 => ['색상', '빨강,파랑'], 2 => ['사이즈', 'S,M,L'], 3 => ['소재', '면,린넨']]; for ($i = 1; $i <= 3; $i++): ?>
      <div class="yc-option-group" role="group" aria-labelledby="yc-option-label-<?= $i ?>"><strong class="yc-option-label" id="yc-option-label-<?= $i ?>">옵션 <?= $i ?></strong>
        <input class="input input-bordered input-sm" type="text" name="option_group[<?= $i ?>]" value="<?= $this->e((string) ($values['option_group'][$i] ?? '')) ?>" maxlength="100" placeholder="그룹 이름 (예: <?= $examples[$i][0] ?>)" aria-label="옵션 <?= $i ?> 그룹 이름">
        <input class="input input-bordered input-sm" type="text" name="option_values[<?= $i ?>]" value="<?= $this->e((string) ($values['option_values'][$i] ?? '')) ?>" placeholder="값 (예: <?= $examples[$i][1] ?>)" aria-label="옵션 <?= $i ?> 값"></div>
    <?php endfor ?></div>
    <button class="btn btn-sm" type="submit" name="action" value="combine" data-yc-combine formnovalidate>조합 생성</button>
    <?php $optionError = $errors['option_group'] ?? $errors['options'] ?? $errors['option_values'] ?? ''; ?>
    <p class="<?= $optionError === '' ? 'muted' : 'text-error' ?>" data-yc-combine-status role="status" aria-live="polite"<?= $optionError === '' ? ' hidden' : '' ?>><?= $this->e($optionError) ?></p>
    <div data-yc-combinations><?php $this->insert('admin/_option_combinations', ['options_rows' => $options_rows]) ?></div>
  </div></details>
  <details class="yc-advanced" id="section-extras"<?= $extras_rows !== [] || $errors !== [] ? ' open' : '' ?>><summary>추가상품<small>포장·추가 구성품을 판매할 때</small></summary><div class="card-body">
    <p class="muted" id="yc-extra-order-help">항목명과 가격·재고를 입력합니다. 왼쪽 손잡이를 드래그하거나 손잡이에 초점을 두고 위아래 방향키로 순서를 바꿀 수 있습니다. 비어 있는 행은 무시합니다.</p>
    <?php if (isset($errors['extras'])): ?><p class="validator-hint"><?= $this->e($errors['extras']) ?></p><?php endif ?>
    <div class="overflow-x-auto"><table class="table table-sm yc-extras-table" data-yc-extras><thead><tr><th class="yc-extra-control">순서</th><th>항목명</th><th>가격</th><th>재고</th><th>통보</th><th>사용</th><th class="yc-extra-control">삭제</th></tr></thead><tbody>
      <?php $emptyExtra = ['value1' => '', 'value2' => '', 'value3' => '', 'price' => '0', 'stock' => '0', 'stock_alert' => '0', 'active' => '1']; ?>
      <?php foreach ($extras_rows ?: [$emptyExtra] as $i => $row) $this->insert('admin/_extra_option_row', ['i' => $i, 'row' => $row]); ?>
    </tbody></table></div>
    <template data-yc-extra-row><?php $this->insert('admin/_extra_option_row', ['i' => 0, 'row' => $emptyExtra]) ?></template>
    <button class="btn btn-xs" type="button" data-yc-add-extra>행 추가</button>
  </div></details>
  <details class="yc-advanced" id="section-order"><summary>진열 순서 (선택)</summary><div class="card-body">
    <div class="yc-fields"><?php $field('sort_order', '순서', 'number'); ?></div>
  </div></details>
  <details class="yc-advanced" id="section-extra"<?= $errors !== [] ? ' open' : '' ?>><summary>여분필드<small>테마·외부 연동을 위한 추가 항목</small></summary><div class="card-body">
    <div class="yc-fields"><?php for ($i = 1; $i <= 10; $i++): ?><fieldset class="fieldset"><legend class="fieldset-legend">여분 <?= $i ?></legend>
      <input class="input input-bordered input-sm" type="text" name="extra_label[<?= $i ?>]" value="<?= $this->e((string) ($values['extra_label'][$i] ?? '')) ?>" maxlength="100" placeholder="라벨">
      <input class="input input-bordered input-sm" type="text" name="extra_value[<?= $i ?>]" value="<?= $this->e((string) ($values['extra_value'][$i] ?? '')) ?>" maxlength="1000" placeholder="값"></fieldset><?php endfor ?></div>
  </div></details>
  <?php $this->insert('admin/_save_bar', ['save_label' => $copy_source !== null ? '새 상품 저장' : '상품 저장', 'save_action' => true, 'back_url' => $admin_url . '/products', 'view_url' => $public_view_url ?? '']) ?>
</form>
<script src="<?= $this->e($this->base) ?>/vendor/sortablejs/Sortable-1.15.7.min.js" defer></script>
<?php $this->insert('admin/_editor', ['values' => ['image_key' => $values['image_key']], 'editor_required' => false, 'editor_height' => 300]) ?>
<?php $this->stop() ?>
