<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?><?= $id === null ? '분류 추가' : '분류 수정' ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => $id === null ? '분류 추가' : '분류 수정', 'description' => '', 'actions' => array_values(array_filter([$id === null ? null : ['url' => $admin_url . '/products/new?category=' . $id, 'label' => '이 분류에 상품 등록'], ['url' => $admin_url . '/categories', 'label' => '분류 목록']]))]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php $v = static fn (string $key): string => (string) ($values[$key] ?? ''); ?>
<?php $this->insert('admin/_form_nav', ['sections' => ['category-basic' => '기본 설정', 'category-html' => '목록 꾸미기', 'category-extra' => '여분필드']]) ?>
<form class="yc-edit-form" method="post" action="<?= $this->e($admin_url) ?>/categories/<?= $id === null ? 'new' : 'edit' ?>">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <input type="hidden" name="image_key" value="<?= $this->e($v('image_key')) ?>">
  <input type="hidden" name="uploaded_images" value="" data-uploaded-images>
  <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif ?>
  <section class="card" id="category-basic"><div class="card-body"><h2 class="card-title">분류 기본 설정</h2>
    <div class="yc-fields yc-fields-title">
    <fieldset class="fieldset yc-field-wide<?= isset($errors['name']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">분류명</legend><input class="input input-bordered input-block" type="text" name="name" value="<?= $this->e($v('name')) ?>" maxlength="100" required></fieldset>
    <fieldset class="fieldset<?= isset($errors['slug']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">슬러그 <span class="legend-hint">주소 /shop/c/슬러그</span></legend>
      <input class="input input-bordered" type="text" name="slug" value="<?= $this->e($v('slug')) ?>" maxlength="200" placeholder="비우면 이름에서 만듭니다">
      <?php if (isset($errors['slug'])): ?><p class="validator-hint"><?= $this->e($errors['slug']) ?></p><?php endif ?></fieldset>
    </div>
    <div class="yc-fields">
      <fieldset class="fieldset yc-field-wide<?= isset($errors['parent_id']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">상위 분류</legend>
        <select class="select select-bordered" name="parent_id"><option value=""<?= $v('parent_id') === '' ? ' selected' : '' ?>>최상위</option>
        <?php foreach ($parents as $parentId => $parentOption): ?><option value="<?= (int) $parentId ?>" title="<?= $this->e($parentOption['title']) ?>"<?= $v('parent_id') === (string) $parentId ? ' selected' : '' ?>><?= $this->e($parentOption['text']) ?></option><?php endforeach ?></select>
        <?php if (isset($errors['parent_id'])): ?><p class="validator-hint"><?= $this->e($errors['parent_id']) ?></p><?php endif ?></fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">순서</legend><input class="input input-bordered input-sm" type="number" name="sort_order" value="<?= $this->e($v('sort_order')) ?>"></fieldset>
    </div>
    <div class="yc-fields">
      <fieldset class="fieldset"><legend class="fieldset-legend">한 행 상품 수</legend><input class="input input-bordered input-sm" type="number" name="list_columns" value="<?= $this->e($v('list_columns')) ?>" min="1" max="12" required></fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">행 수</legend><input class="input input-bordered input-sm" type="number" name="list_rows" value="<?= $this->e($v('list_rows')) ?>" min="1" max="50" required></fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">이미지 너비</legend><input class="input input-bordered input-sm" type="number" name="image_width" value="<?= $this->e($v('image_width')) ?>" min="0" max="2000" required></fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">이미지 높이(0 = 비율)</legend><input class="input input-bordered input-sm" type="number" name="image_height" value="<?= $this->e($v('image_height')) ?>" min="0" max="2000" required></fieldset>
    </div>
    <div class="yc-checks">
    <label class="label cursor-pointer"><input type="hidden" name="active" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="active" value="1"<?= $v('active') === '1' ? ' checked' : '' ?>> 판매가능</label>
    <label class="label cursor-pointer"><input type="hidden" name="no_coupon" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="no_coupon" value="1"<?= $v('no_coupon') === '1' ? ' checked' : '' ?>> 쿠폰 대상에서 제외</label>
    <label class="label cursor-pointer"><input type="hidden" name="menu_hidden" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="menu_hidden" value="1"<?= $v('menu_hidden') === '1' ? ' checked' : '' ?>> 메뉴에서 숨김 <span class="legend-hint">상단 메뉴·바로가기·하위 분류 칩에 안 보이고 주소·링크·배너로만 들어갑니다</span></label>
    <?php if ($id !== null): ?><label class="label cursor-pointer"><input class="checkbox checkbox-sm" type="checkbox" name="apply_children" value="1"> 판매·쿠폰·목록·이미지 설정을 하위 분류에도 적용</label><?php endif ?>
    </div>
  </div></section>
  <details class="yc-advanced" id="category-html"<?= $errors !== [] ? ' open' : '' ?>><summary>목록 위·아래 HTML<small>분류 목록 상단·하단에 표시할 내용</small></summary><div class="card-body">
    <fieldset class="fieldset"><legend class="fieldset-legend">목록 위</legend><textarea class="textarea textarea-bordered textarea-block" id="yc-head-html" name="head_html" rows="6" data-cms-editor><?= $this->e($v('head_html')) ?></textarea></fieldset>
    <fieldset class="fieldset"><legend class="fieldset-legend">목록 아래</legend><textarea class="textarea textarea-bordered textarea-block" id="yc-tail-html" name="tail_html" rows="6" data-cms-editor><?= $this->e($v('tail_html')) ?></textarea></fieldset>
  </div></details>
  <details class="yc-advanced" id="category-extra"<?= $errors !== [] ? ' open' : '' ?>><summary>여분필드<small>테마·외부 연동을 위한 추가 항목</small></summary><div class="card-body">
    <div class="yc-fields"><?php foreach ($extra as $i => $field): ?><fieldset class="fieldset"><legend class="fieldset-legend">여분 <?= $i ?></legend>
      <input class="input input-bordered input-sm" type="text" name="extra_label[<?= $i ?>]" value="<?= $this->e($field['label']) ?>" maxlength="100" placeholder="라벨">
      <input class="input input-bordered input-sm" type="text" name="extra_value[<?= $i ?>]" value="<?= $this->e($field['value']) ?>" maxlength="1000" placeholder="값"></fieldset><?php endforeach ?></div>
  </div></details>
  <?php $this->insert('admin/_save_bar', ['save_label' => '분류 저장', 'back_url' => $admin_url . '/categories']) ?>
</form>
<?php $this->insert('admin/_editor', ['values' => ['image_key' => $values['image_key']], 'editor_required' => false, 'editor_height' => 220]) ?>
<?php $this->stop() ?>
