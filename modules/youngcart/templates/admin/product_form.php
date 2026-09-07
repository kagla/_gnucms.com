<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?><?= $id === null ? '상품 등록' : '상품 수정' ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => $id === null ? '상품 등록' : '상품 수정', 'description' => $id === null ? '' : ($values['code'] ?? ''), 'actions' => array_merge([['url' => $admin_url . '/products', 'label' => '상품 목록']], $id === null ? [] : [['url' => $public_url . '/item?id=' . rawurlencode((string) $values['code']), 'label' => '상품 보기']])]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php
$v = static fn (string $key): string => is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
$field = function (string $name, string $label, string $type = 'text', array $attrs = []) use ($v, $errors): void {
    $extra = ''; foreach ($attrs as $k => $val) $extra .= ' ' . $k . '="' . $this->e((string) $val) . '"'; ?>
  <fieldset class="fieldset<?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><?= $this->e($label) ?></legend>
    <input class="input input-bordered input-sm input-block" type="<?= $type ?>" name="<?= $name ?>" value="<?= $this->e($v($name)) ?>"<?= $extra ?>>
    <?php if (isset($errors[$name])): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?></fieldset>
<?php };
$check = function (string $name, string $label) use ($v): void { ?>
  <label class="label cursor-pointer"><input type="hidden" name="<?= $name ?>" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="<?= $name ?>" value="1"<?= $v($name) === '1' ? ' checked' : '' ?>> <?= $this->e($label) ?></label>
<?php };
$apply = function (string $group) use ($apply_fields): void { ?>
  <label class="label cursor-pointer yc-apply"><input class="checkbox checkbox-xs" type="checkbox" name="apply_fields[]" value="<?= $group ?>"> <?= $this->e($apply_fields[$group]) ?> 일괄 적용</label>
<?php };
$catSelect = function (string $name, bool $required) use ($v, $categories, $errors): void { ?>
  <fieldset class="fieldset<?= isset($errors[$name]) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend"><?= $name === 'category_id' ? '대표 분류' : '추가 분류' ?></legend>
    <select class="select select-bordered select-sm" name="<?= $name ?>"<?= $required ? ' required' : '' ?>><option value="">선택</option><?php foreach ($categories as $cid => $label): ?><option value="<?= $cid ?>"<?= $v($name) === (string) $cid ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?></select>
    <?php if (isset($errors[$name])): ?><p class="validator-hint"><?= $this->e($errors[$name]) ?></p><?php endif ?></fieldset>
<?php };
?>
<form method="post" action="<?= $this->e($admin_url) ?>/products/<?= $id === null ? 'new' : 'edit' ?>" enctype="multipart/form-data" data-yc-product-form>
  <button type="submit" hidden aria-hidden="true" tabindex="-1"></button>
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <input type="hidden" name="action" value="save" data-yc-action>
  <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="version" value="<?= $this->e($v('version')) ?>"><?php endif ?>
  <input type="hidden" name="image_key" value="<?= $this->e($v('image_key')) ?>">
  <section class="card" id="section-category"><div class="card-body"><h2 class="card-title">분류</h2>
    <div class="yc-fields"><?php $catSelect('category_id', true); $catSelect('category2_id', false); $catSelect('category3_id', false); ?></div>
  </div></section>
  <section class="card" id="section-basic"><div class="card-body"><h2 class="card-title">기본정보</h2>
    <?php if ($id === null): ?><?php $field('code', '상품 코드 (영문·숫자·-·_ 1~20자)', 'text', ['maxlength' => 20, 'pattern' => '[A-Za-z0-9_-]{1,20}', 'required' => 'required']) ?>
    <?php else: ?><fieldset class="fieldset"><legend class="fieldset-legend">상품 코드</legend><input class="input input-bordered input-sm" type="text" value="<?= $this->e($v('code')) ?>" readonly></fieldset><?php endif ?>
    <?php $field('name', '상품명', 'text', ['maxlength' => 250, 'required' => 'required']) ?>
    <div class="yc-fields"><?php $field('sort_order', '순서', 'number'); $field('maker', '제조사', 'text', ['maxlength' => 100]); $field('origin', '원산지', 'text', ['maxlength' => 100]); $field('brand', '브랜드', 'text', ['maxlength' => 100]); $field('model', '모델', 'text', ['maxlength' => 100]); $field('seller_email', '판매자 메일', 'email', ['maxlength' => 191]); ?></div>
    <div class="yc-checks"><?php foreach ($types as $type => $label) $check($type, $label); ?><?php $apply('types') ?></div>
    <div class="yc-checks"><?php $check('active', '판매가능'); $apply('active'); $check('no_coupon', '쿠폰 대상 제외'); $apply('no_coupon'); $check('phone_inquiry', '전화문의(가격 숨김)'); $apply('phone_inquiry'); ?></div>
    <fieldset class="fieldset"><legend class="fieldset-legend">요약 설명</legend><textarea class="textarea textarea-bordered textarea-block" name="summary" rows="3" maxlength="20000"><?= $this->e($v('summary')) ?></textarea></fieldset>
    <fieldset class="fieldset"><legend class="fieldset-legend">상세 설명</legend><textarea class="textarea textarea-bordered textarea-block" id="yc-description" name="description" rows="10" data-cms-editor><?= $this->e($v('description')) ?></textarea><input type="hidden" name="uploaded_images" value="" data-uploaded-images></fieldset>
    <fieldset class="fieldset"><legend class="fieldset-legend">관리자 메모 (공개되지 않음)</legend><textarea class="textarea textarea-bordered textarea-block" name="memo" rows="2" maxlength="5000"><?= $this->e($v('memo')) ?></textarea></fieldset>
  </div></section>
  <section class="card" id="section-info"><div class="card-body"><h2 class="card-title">상품정보고시</h2>
    <select class="select select-bordered select-sm" name="info_group" data-yc-info-select><option value="">사용 안 함</option><?php foreach ($info_groups as $key => $group): ?><option value="<?= $key ?>"<?= $v('info_group') === $key ? ' selected' : '' ?>><?= $this->e($group['label']) ?></option><?php endforeach ?></select>
    <noscript><p class="muted">군을 바꾼 뒤 조합 생성 버튼을 누르면 항목 입력칸이 갱신됩니다.</p></noscript>
    <div class="yc-fields" data-yc-info-fields data-yc-info-groups="<?= $this->e(json_encode(array_map(static fn ($g) => $g['articles'], $info_groups), JSON_UNESCAPED_UNICODE)) ?>">
      <?php foreach ($info_groups[$v('info_group')]['articles'] ?? [] as $index => $article): ?><fieldset class="fieldset"><legend class="fieldset-legend"><?= $this->e($article) ?></legend><input class="input input-bordered input-sm" type="text" name="info[<?= $index ?>]" value="<?= $this->e((string) ($values['info'][$index] ?? '')) ?>" maxlength="500" placeholder="상품페이지 참고"></fieldset><?php endforeach ?>
    </div>
  </div></section>
  <section class="card" id="section-price"><div class="card-body"><h2 class="card-title">가격·포인트·재고</h2>
    <div class="yc-fields"><?php $field('price', '판매가격', 'number', ['min' => 0, 'required' => 'required']); $field('list_price', '시중가격 (0이면 표시 안 함)', 'number', ['min' => 0]); ?>
      <fieldset class="fieldset"><legend class="fieldset-legend">포인트 방식</legend><select class="select select-bordered select-sm" name="point_type" data-yc-point-type><?php foreach ([0 => '설정 금액', 1 => '판매가 기준 %', 2 => '구매가 기준 %'] as $k => $l): ?><option value="<?= $k ?>"<?= $v('point_type') === (string) $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></fieldset>
      <?php $field('point', '포인트 (정액 또는 0~99%)', 'number', ['min' => 0]); $field('supply_point', '추가옵션 포인트', 'number', ['min' => 0]); ?></div>
    <div class="yc-checks"><?php $check('tax_free', '비과세'); $apply('tax_free'); $apply('point'); ?></div>
    <div class="yc-fields"><?php $field('stock', '재고 (선택옵션 없을 때)', 'number', ['min' => 0]); $field('stock_alert', '재고 통보 기준', 'number', ['min' => 0]); $field('buy_min', '최소 구매수량 (0 = 제한 없음)', 'number', ['min' => 0, 'max' => 9999]); $field('buy_max', '최대 구매수량 (0 = 제한 없음)', 'number', ['min' => 0, 'max' => 9999]); ?></div>
    <div class="yc-checks"><?php $check('sold_out', '품절 표시'); $check('restock_notify', '재입고 알림 신청 허용'); $apply('buy'); ?></div>
  </div></section>
  <section class="card" id="section-options"><div class="card-body"><h2 class="card-title">선택옵션</h2>
    <p class="muted">그룹 이름과 쉼표로 구분한 값을 입력하고 <strong>조합 생성</strong>을 누르면 조합 표가 만들어집니다. 가격은 판매가에 더하는 차액입니다.</p>
    <div class="yc-fields"><?php for ($i = 1; $i <= 3; $i++): ?>
      <fieldset class="fieldset<?= isset($errors['option_group']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">옵션 <?= $i ?></legend>
        <input class="input input-bordered input-sm" type="text" name="option_group[<?= $i ?>]" value="<?= $this->e((string) ($values['option_group'][$i] ?? '')) ?>" maxlength="100" placeholder="그룹 이름 (예: 색상)">
        <input class="input input-bordered input-sm" type="text" name="option_values[<?= $i ?>]" value="<?= $this->e((string) ($values['option_values'][$i] ?? '')) ?>" placeholder="값 (예: 빨강,파랑)"></fieldset>
    <?php endfor ?></div>
    <button class="btn btn-sm" type="submit" name="action" value="combine" formnovalidate>조합 생성</button>
    <?php if (isset($errors['options']) || isset($errors['option_values'])): ?><p class="validator-hint"><?= $this->e($errors['options'] ?? $errors['option_values']) ?></p><?php endif ?>
    <?php if ($options_rows !== []): ?>
      <div class="overflow-x-auto"><table class="table table-sm yc-combo-table" data-yc-combos><thead><tr><th>조합</th><th>차액</th><th>재고</th><th>통보</th><th>사용</th></tr></thead><tbody>
        <?php foreach ($options_rows as $i => $row): ?><tr>
          <td><?= $this->e(implode(' / ', array_filter([$row['value1'], $row['value2'], $row['value3']], static fn ($x) => $x !== ''))) ?><?php for ($k = 1; $k <= 3; $k++): ?><input type="hidden" name="options[<?= $i ?>][value<?= $k ?>]" value="<?= $this->e($row['value' . $k]) ?>"><?php endfor ?></td>
          <td><input class="input input-bordered input-xs" type="number" name="options[<?= $i ?>][price]" value="<?= $this->e($row['price']) ?>"><button class="btn btn-xs" type="button" data-yc-copy-down="price" title="아래 모두 같은 값">↓</button></td>
          <td><input class="input input-bordered input-xs" type="number" name="options[<?= $i ?>][stock]" value="<?= $this->e($row['stock']) ?>" min="0"><button class="btn btn-xs" type="button" data-yc-copy-down="stock" title="아래 모두 같은 값">↓</button></td>
          <td><input class="input input-bordered input-xs" type="number" name="options[<?= $i ?>][stock_alert]" value="<?= $this->e($row['stock_alert']) ?>" min="0"></td>
          <td><input type="hidden" name="options[<?= $i ?>][active]" value="0"><input class="checkbox checkbox-xs" type="checkbox" name="options[<?= $i ?>][active]" value="1"<?= (string) $row['active'] === '1' ? ' checked' : '' ?>></td>
        </tr><?php endforeach ?>
      </tbody></table></div>
    <?php endif ?>
  </div></section>
  <section class="card" id="section-extras"><div class="card-body"><h2 class="card-title">추가옵션</h2>
    <p class="muted">그룹명·항목명·절대가·재고를 행으로 입력합니다. 비어 있는 행은 무시합니다.</p>
    <?php if (isset($errors['extras'])): ?><p class="validator-hint"><?= $this->e($errors['extras']) ?></p><?php endif ?>
    <div class="overflow-x-auto"><table class="table table-sm" data-yc-extras><thead><tr><th>그룹명</th><th>항목명</th><th>가격</th><th>재고</th><th>통보</th><th>사용</th></tr></thead><tbody>
      <?php $extraRows = array_merge($extras_rows, array_fill(0, 3, ['value1' => '', 'value2' => '', 'value3' => '', 'price' => '0', 'stock' => '9999', 'stock_alert' => '100', 'active' => '1'])); foreach ($extraRows as $i => $row): ?><tr>
        <td><input class="input input-bordered input-xs" type="text" name="extras[<?= $i ?>][value1]" value="<?= $this->e($row['value1']) ?>" maxlength="100"></td>
        <td><input class="input input-bordered input-xs" type="text" name="extras[<?= $i ?>][value2]" value="<?= $this->e($row['value2']) ?>" maxlength="100"></td>
        <td><input class="input input-bordered input-xs" type="number" name="extras[<?= $i ?>][price]" value="<?= $this->e($row['price']) ?>" min="0"></td>
        <td><input class="input input-bordered input-xs" type="number" name="extras[<?= $i ?>][stock]" value="<?= $this->e($row['stock']) ?>" min="0"></td>
        <td><input class="input input-bordered input-xs" type="number" name="extras[<?= $i ?>][stock_alert]" value="<?= $this->e($row['stock_alert']) ?>" min="0"></td>
        <td><input type="hidden" name="extras[<?= $i ?>][active]" value="0"><input class="checkbox checkbox-xs" type="checkbox" name="extras[<?= $i ?>][active]" value="1"<?= (string) $row['active'] === '1' ? ' checked' : '' ?>></td>
      </tr><?php endforeach ?>
    </tbody></table></div>
    <button class="btn btn-xs" type="button" data-yc-add-extra>행 추가</button>
  </div></section>
  <section class="card" id="section-shipping"><div class="card-body"><h2 class="card-title">배송비</h2>
    <div class="yc-fields">
      <fieldset class="fieldset"><legend class="fieldset-legend">배송비 유형</legend><select class="select select-bordered select-sm" name="shipping_type" data-yc-shipping-type><?php foreach ([0 => '상점 기본', 1 => '무료', 2 => '조건부 무료', 3 => '유료', 4 => '수량별 부과'] as $k => $l): ?><option value="<?= $k ?>"<?= $v('shipping_type') === (string) $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">결제 방법</legend><select class="select select-bordered select-sm" name="shipping_method"><?php foreach ([0 => '선불', 1 => '착불', 2 => '구매자 선택'] as $k => $l): ?><option value="<?= $k ?>"<?= $v('shipping_method') === (string) $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></fieldset>
      <?php $field('shipping_fee', '배송비', 'number', ['min' => 0]); $field('shipping_free_minimum', '무료배송 기준 금액', 'number', ['min' => 0]); $field('shipping_per_qty', '배송비 부과 수량 단위', 'number', ['min' => 0]); ?>
    </div>
    <?php $apply('shipping') ?>
  </div></section>
  <section class="card" id="section-images"><div class="card-body"><h2 class="card-title">상품 이미지 (최대 <?= \GnuCms\Modules\YoungCart\Images::MAX ?>장)</h2>
    <?php if (isset($errors['images'])): ?><p class="validator-hint"><?= $this->e($errors['images']) ?></p><?php endif ?>
    <?php if ($images !== []): ?>
      <ol class="yc-image-list" data-yc-image-list>
        <?php foreach ($images as $image): ?><li data-yc-image="<?= (int) $image['id'] ?>">
          <img src="<?= $this->e($public_url . '/image?p=' . $id . '&f=' . rawurlencode($image['filename']) . '&s=thumb') ?>" alt="" width="70" height="70">
          <code><?= $this->e($image['filename']) ?></code>
          <button class="btn btn-xs" type="button" data-yc-move="up" aria-label="위로">↑</button><button class="btn btn-xs" type="button" data-yc-move="down" aria-label="아래로">↓</button>
          <label><input class="checkbox checkbox-xs" type="checkbox" name="image_delete[]" value="<?= (int) $image['id'] ?>"> 삭제</label>
        </li><?php endforeach ?>
      </ol>
      <input type="hidden" name="image_order" value="<?= $this->e(implode(',', array_column($images, 'id'))) ?>" data-yc-image-order>
    <?php endif ?>
    <input class="file-input file-input-bordered file-input-sm" type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
  </div></section>
  <section class="card" id="section-relations"><div class="card-body"><h2 class="card-title">관련상품</h2>
    <?php if (isset($errors['relations'])): ?><p class="validator-hint"><?= $this->e($errors['relations']) ?></p><?php endif ?>
    <div class="yc-relation-search"><input class="input input-bordered input-sm" type="search" placeholder="상품명·코드 검색" data-yc-relation-search data-yc-search-url="<?= $this->e($admin_url . '/products/search') ?>" data-yc-exclude="<?= (int) $id ?>"><ul class="menu" data-yc-relation-results></ul></div>
    <ul class="yc-relations" data-yc-relations><?php foreach ($relations as $r): ?><li data-yc-relation="<?= (int) $r['id'] ?>"><code><?= $this->e($r['code']) ?></code> <?= $this->e($r['name']) ?> <button class="btn btn-xs" type="button" data-yc-remove-relation>제거</button></li><?php endforeach ?></ul>
    <input class="input input-bordered input-sm input-block" type="text" name="relations" value="<?= $this->e($v('relations')) ?>" data-yc-relation-ids aria-label="관련상품 ID(쉼표)">
  </div></section>
  <section class="card" id="section-html"><div class="card-body"><h2 class="card-title">상세 위·아래 HTML</h2>
    <fieldset class="fieldset"><legend class="fieldset-legend">상세 위</legend><textarea class="textarea textarea-bordered textarea-block" name="head_html" rows="3"><?= $this->e($v('head_html')) ?></textarea></fieldset>
    <fieldset class="fieldset"><legend class="fieldset-legend">상세 아래</legend><textarea class="textarea textarea-bordered textarea-block" name="tail_html" rows="3"><?= $this->e($v('tail_html')) ?></textarea></fieldset>
    <?php $apply('html'); $apply('seller_email'); ?>
  </div></section>
  <section class="card" id="section-extra"><div class="card-body"><h2 class="card-title">여분필드</h2>
    <div class="yc-fields"><?php for ($i = 1; $i <= 10; $i++): ?><fieldset class="fieldset"><legend class="fieldset-legend">여분 <?= $i ?></legend>
      <input class="input input-bordered input-sm" type="text" name="extra_label[<?= $i ?>]" value="<?= $this->e((string) ($values['extra_label'][$i] ?? '')) ?>" maxlength="100" placeholder="라벨">
      <input class="input input-bordered input-sm" type="text" name="extra_value[<?= $i ?>]" value="<?= $this->e((string) ($values['extra_value'][$i] ?? '')) ?>" maxlength="1000" placeholder="값"></fieldset><?php endfor ?></div>
  </div></section>
  <section class="card"><div class="card-body"><h2 class="card-title">일괄 적용 범위</h2>
    <p class="muted">위에서 체크한 "일괄 적용" 항목을 저장 값으로 함께 반영합니다.</p>
    <label class="label cursor-pointer"><input class="radio radio-sm" type="radio" name="apply_scope" value="" checked> 이 상품만</label>
    <label class="label cursor-pointer"><input class="radio radio-sm" type="radio" name="apply_scope" value="category"> 같은 대표 분류의 모든 상품</label>
    <label class="label cursor-pointer"><input class="radio radio-sm" type="radio" name="apply_scope" value="all"> 전체 상품</label>
  </div></section>
  <div class="form-actions"><button class="btn btn-primary" type="submit" name="action" value="save">상품 저장</button></div>
</form>
<?php $this->insert('admin/_editor', ['values' => ['image_key' => $values['image_key']], 'editor_required' => false, 'editor_height' => 300]) ?>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart-admin.js') ?>" defer></script><?php $this->stop() ?>
