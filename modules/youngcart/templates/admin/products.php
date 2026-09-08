<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>상품 목록 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '상품 목록', 'description' => '총 ' . $list['total'] . '개', 'actions' => [['url' => $admin_url . '/products/new', 'label' => '상품 등록']]]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php $this->insert('admin/_filters', ['action' => $admin_url . '/products']) ?>
<?php $sortLink = fn (string $key): string => $admin_url . '/products?' . http_build_query(['q' => $filters['q'], 'field' => $filters['field'], 'ca' => $filters['ca'], 'sort' => $key, 'dir' => $filters['sort'] === $key && $filters['dir'] === 'asc' ? 'desc' : 'asc']); ?>
<form method="post" action="<?= $this->e($admin_url) ?>/products" id="yc-product-list">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="bulk">
  <div class="overflow-x-auto"><table class="table table-sm"><thead><tr>
    <th><input class="checkbox checkbox-xs" type="checkbox" data-yc-check-all aria-label="전체 선택"></th>
    <?php foreach (['code' => '코드', 'name' => '상품명', 'price' => '판매가', 'list_price' => '시중가', 'stock' => '재고', 'active' => '판매', 'sold_out' => '품절', 'sort_order' => '순서', 'hit' => '조회'] as $key => $label): ?><th><a href="<?= $this->e($sortLink($key)) ?>"><?= $label ?><?= $filters['sort'] === $key ? ($filters['dir'] === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th><?php endforeach ?>
    <th>분류</th><th></th></tr></thead><tbody>
    <?php foreach ($list['items'] as $row): $n = 'rows[' . (int) $row['id'] . ']'; ?>
      <tr>
        <td><input class="checkbox checkbox-xs" type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" form="yc-product-delete" aria-label="선택"></td>
        <td><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['id'] ?>"><code><?= $this->e($row['code']) ?></code></a></td>
        <td><input class="input input-bordered input-xs" type="text" name="<?= $n ?>[name]" value="<?= $this->e($row['name']) ?>" maxlength="250" required></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[price]" value="<?= (int) $row['price'] ?>" min="0" required></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[list_price]" value="<?= (int) $row['list_price'] ?>" min="0"></td>
        <td><input type="hidden" name="<?= $n ?>[original_stock]" value="<?= (int) $row['stock'] ?>"><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[stock]" value="<?= (int) $row['stock'] ?>" min="0"></td>
        <td><input type="hidden" name="<?= $n ?>[active]" value="0"><input class="checkbox checkbox-xs" type="checkbox" name="<?= $n ?>[active]" value="1"<?= (int) $row['active'] === 1 ? ' checked' : '' ?>></td>
        <td><input type="hidden" name="<?= $n ?>[sold_out]" value="0"><input class="checkbox checkbox-xs" type="checkbox" name="<?= $n ?>[sold_out]" value="1"<?= (int) $row['sold_out'] === 1 ? ' checked' : '' ?>></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[sort_order]" value="<?= (int) $row['sort_order'] ?>"></td>
        <td><?= (int) $row['hit'] ?></td>
        <td><select class="select select-bordered select-xs" name="<?= $n ?>[category_id]"><?php foreach ($categories as $id => $label): ?><option value="<?= $id ?>"<?= (int) $row['category_id'] === $id ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?></select></td>
        <td class="row-actions"><a class="btn btn-xs" href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['id'] ?>">수정</a><a class="btn btn-xs btn-outline" href="<?= $this->e($public_url) ?>/item?id=<?= $this->e(rawurlencode($row['code'])) ?>" target="_blank" rel="noopener">보기</a></td>
      </tr>
    <?php endforeach ?>
  </tbody></table></div>
  <div class="form-actions"><button class="btn btn-sm btn-primary" type="submit">선택 내용 일괄 저장</button></div>
</form>
<form method="post" action="<?= $this->e($admin_url) ?>/products" id="yc-product-delete" onsubmit="return confirm('선택한 상품을 삭제할까요? 이미지·옵션도 함께 지워집니다.')">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="delete">
  <button class="btn btn-sm btn-error btn-outline" type="submit">선택 삭제</button>
</form>
<form method="post" action="<?= $this->e($admin_url) ?>/products/copy" class="yc-copy-form">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <label>복사할 상품 <select class="select select-bordered select-sm" name="id"><?php foreach ($list['items'] as $row): ?><option value="<?= (int) $row['id'] ?>"><?= $this->e($row['code'] . ' ' . $row['name']) ?></option><?php endforeach ?></select></label>
  <label>새 코드 <input class="input input-bordered input-sm" type="text" name="code" value="<?= time() ?>" maxlength="20" pattern="[A-Za-z0-9_-]{1,20}" required></label>
  <button class="btn btn-sm" type="submit">상품 복사</button>
</form>
<?php $this->insert('_pager', ['page_url' => fn (int $p): string => $admin_url . '/products?' . http_build_query($filters + ['page' => $p])]) ?>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart-admin.js') ?>" defer></script><?php $this->stop() ?>
