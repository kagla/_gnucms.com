<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>상품 설정 일괄 복사 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '상품 설정 일괄 복사', 'description' => $source['code'] . ' ' . $source['name'] . '의 운영 설정을 다른 상품에 복사합니다.', 'actions' => [['url' => $admin_url . '/products', 'label' => '상품 목록']]]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php
$fieldLabels = ['name' => '상품명', 'code' => '상품 코드'];
$query = ['source' => $source['id'], 'target_q' => $target_filters['q'], 'target_field' => $target_filters['field'], 'target_ca' => $target_filters['ca']];
$hasCopyTarget = max($target_counts) > 0;
?>

<section class="card"><div class="card-body"><h2 class="card-title">1. 대상 상품 검색</h2>
  <p class="muted">결과는 20개씩 나누어 표시합니다. 복사할 때는 현재 페이지가 아니라 검색 조건에 맞는 전체 상품에 적용됩니다.</p>
  <form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/products/settings-copy">
    <input type="hidden" name="source" value="<?= (int) $source['id'] ?>">
    <label>검색 기준<select class="select select-bordered select-sm" name="target_field"><?php foreach ($fields as $field): ?><option value="<?= $this->e($field) ?>"<?= $target_filters['field'] === $field ? ' selected' : '' ?>><?= $this->e($fieldLabels[$field]) ?></option><?php endforeach ?></select></label>
    <label class="yc-filter-query">검색어<input class="input input-bordered input-sm" type="search" name="target_q" value="<?= $this->e($target_filters['q']) ?>" maxlength="100" placeholder="대상 상품 검색"></label>
    <label>분류<select class="select select-bordered select-sm" name="target_ca"><option value="">전체 분류</option><?php foreach ($categories as $id => $label): ?><option value="<?= (int) $id ?>"<?= $target_filters['ca'] === (string) $id ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?></select></label>
    <button class="btn btn-sm" type="submit"><?= $this->icon('search', 16) ?> 대상 확인</button>
  </form>
  <?php if ($target_filters['q'] === '' && $target_filters['ca'] === ''): ?><p class="muted">검색어나 분류를 지정하면 적용 대상과 건수를 미리 확인할 수 있습니다.</p>
  <?php else: ?><div class="yc-list-heading"><h3>적용 대상 <span><?= number_format($target_list['total']) ?></span></h3><p>현재 상품은 제외한 전체 검색 결과입니다.</p></div>
    <?php if ($target_list['items'] === []): ?><div class="yc-admin-empty"><strong>검색된 상품이 없습니다</strong></div>
    <?php else: ?><div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>상품명</th><th>코드</th><th>대표 분류</th></tr></thead><tbody><?php foreach ($target_list['items'] as $row): ?><tr><td><?= $this->e($row['name']) ?></td><td><code><?= $this->e($row['code']) ?></code></td><td><?= $this->e($row['category_name'] ?? '') ?></td></tr><?php endforeach ?></tbody></table></div>
      <?php $this->insert('_pager', ['list' => $target_list, 'page_url' => fn (int $page): string => $admin_url . '/products/settings-copy?' . http_build_query($query + ['target_page' => $page])]) ?>
    <?php endif ?>
  <?php endif ?>
</div></section>

<section class="card"><div class="card-body"><h2 class="card-title">2. 복사할 설정과 범위</h2>
  <form method="post" action="<?= $this->e($admin_url) ?>/products/settings-copy" data-yc-confirm="선택한 범위의 상품 설정을 덮어쓸까요?">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="copy-settings"><input type="hidden" name="source_id" value="<?= (int) $source['id'] ?>">
    <input type="hidden" name="target_q" value="<?= $this->e($target_filters['q']) ?>"><input type="hidden" name="target_field" value="<?= $this->e($target_filters['field']) ?>"><input type="hidden" name="target_ca" value="<?= $this->e($target_filters['ca']) ?>">
    <fieldset class="fieldset"><legend class="fieldset-legend">복사할 설정</legend><div class="yc-checks"><?php foreach ($copy_field_labels as $key => $label): ?><label class="label"><input class="checkbox checkbox-sm" type="checkbox" name="copy_fields[]" value="<?= $this->e($key) ?>"<?= in_array($key, $copy_values['fields'], true) ? ' checked' : '' ?>> <?= $this->e($label) ?></label><?php endforeach ?></div></fieldset>
    <fieldset class="fieldset"><legend class="fieldset-legend">적용 범위</legend>
      <label class="label"><input class="radio radio-sm" type="radio" name="scope" value="search"<?= $copy_values['scope'] === 'search' ? ' checked' : '' ?><?= $target_counts['search'] < 1 ? ' disabled' : '' ?>> 검색 결과 전체 <strong><?= number_format($target_counts['search']) ?>개</strong></label>
      <label class="label"><input class="radio radio-sm" type="radio" name="scope" value="category"<?= $copy_values['scope'] === 'category' ? ' checked' : '' ?><?= $target_counts['category'] < 1 ? ' disabled' : '' ?>> 같은 대표 분류 <span class="muted"><?= $this->e($source['categories'][1]['name'] ?? '') ?></span> <strong><?= number_format($target_counts['category']) ?>개</strong></label>
      <label class="label"><input class="radio radio-sm" type="radio" name="scope" value="all"<?= $copy_values['scope'] === 'all' ? ' checked' : '' ?><?= $target_counts['all'] < 1 ? ' disabled' : '' ?>> 모든 상품 <strong><?= number_format($target_counts['all']) ?>개</strong></label>
    </fieldset>
    <p class="muted">상품명·가격·재고·품절·설명·이미지·옵션은 복사하지 않습니다. 현재 상품도 대상에서 제외합니다.</p>
    <?php if (!$hasCopyTarget): ?><div class="alert alert-warning">설정을 복사할 다른 상품이 없습니다.</div><?php endif ?>
    <button class="btn btn-primary" type="submit"<?= $hasCopyTarget ? '' : ' disabled' ?>>설정 복사</button>
  </form>
</div></section>
<?php $this->stop() ?>
