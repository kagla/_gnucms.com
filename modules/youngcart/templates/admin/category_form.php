<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?><?= $id === null ? '분류 추가' : '분류 수정' ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => $id === null ? '분류 추가' : '분류 수정', 'description' => '', 'actions' => [['url' => $admin_url . '/categories', 'label' => '분류 목록']]]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php $v = static fn (string $key): string => (string) ($values[$key] ?? ''); ?>
<form method="post" action="<?= $this->e($admin_url) ?>/categories/<?= $id === null ? 'new' : 'edit' ?>">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif ?>
  <section class="card"><div class="card-body">
    <fieldset class="fieldset<?= isset($errors['code']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">분류 코드 <span class="legend-hint">단계당 2자, 영문 소문자·숫자</span></legend>
      <?php if ($id === null): ?><input class="input input-bordered" type="text" name="code" value="<?= $this->e($v('code')) ?>" maxlength="10" pattern="[0-9a-zA-Z]{2,10}" required>
      <?php else: ?><input class="input input-bordered" type="text" value="<?= $this->e($v('code')) ?>" readonly><?php endif ?>
      <?php if (isset($errors['code'])): ?><p class="validator-hint"><?= $this->e($errors['code']) ?></p><?php endif ?></fieldset>
    <fieldset class="fieldset<?= isset($errors['name']) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">분류명</legend><input class="input input-bordered input-block" type="text" name="name" value="<?= $this->e($v('name')) ?>" maxlength="100" required></fieldset>
    <div class="yc-fields">
      <fieldset class="fieldset"><legend class="fieldset-legend">순서</legend><input class="input input-bordered input-sm" type="number" name="sort_order" value="<?= $this->e($v('sort_order')) ?>"></fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">한 행 상품 수</legend><input class="input input-bordered input-sm" type="number" name="list_columns" value="<?= $this->e($v('list_columns')) ?>" min="1" max="12" required></fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">행 수</legend><input class="input input-bordered input-sm" type="number" name="list_rows" value="<?= $this->e($v('list_rows')) ?>" min="1" max="50" required></fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">이미지 너비</legend><input class="input input-bordered input-sm" type="number" name="image_width" value="<?= $this->e($v('image_width')) ?>" min="0" max="2000" required></fieldset>
      <fieldset class="fieldset"><legend class="fieldset-legend">이미지 높이(0 = 비율)</legend><input class="input input-bordered input-sm" type="number" name="image_height" value="<?= $this->e($v('image_height')) ?>" min="0" max="2000" required></fieldset>
    </div>
    <label class="label cursor-pointer"><input type="hidden" name="active" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="active" value="1"<?= $v('active') === '1' ? ' checked' : '' ?>> 판매가능</label>
    <label class="label cursor-pointer"><input type="hidden" name="no_coupon" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="no_coupon" value="1"<?= $v('no_coupon') === '1' ? ' checked' : '' ?>> 쿠폰 대상에서 제외</label>
    <?php if ($id !== null): ?><label class="label cursor-pointer"><input class="checkbox checkbox-sm" type="checkbox" name="apply_children" value="1"> 판매·쿠폰·목록·이미지 설정을 하위 분류에도 적용</label><?php endif ?>
  </div></section>
  <section class="card"><div class="card-body"><h2 class="card-title">목록 위·아래 HTML</h2>
    <fieldset class="fieldset"><legend class="fieldset-legend">목록 위</legend><textarea class="textarea textarea-bordered textarea-block" name="head_html" rows="4"><?= $this->e($v('head_html')) ?></textarea></fieldset>
    <fieldset class="fieldset"><legend class="fieldset-legend">목록 아래</legend><textarea class="textarea textarea-bordered textarea-block" name="tail_html" rows="4"><?= $this->e($v('tail_html')) ?></textarea></fieldset>
  </div></section>
  <section class="card"><div class="card-body"><h2 class="card-title">여분필드</h2>
    <div class="yc-fields"><?php foreach ($extra as $i => $field): ?><fieldset class="fieldset"><legend class="fieldset-legend">여분 <?= $i ?></legend>
      <input class="input input-bordered input-sm" type="text" name="extra_label[<?= $i ?>]" value="<?= $this->e($field['label']) ?>" maxlength="100" placeholder="라벨">
      <input class="input input-bordered input-sm" type="text" name="extra_value[<?= $i ?>]" value="<?= $this->e($field['value']) ?>" maxlength="1000" placeholder="값"></fieldset><?php endforeach ?></div>
  </div></section>
  <div class="form-actions"><button class="btn btn-primary" type="submit">저장</button></div>
</form>
<?php $this->stop() ?>
