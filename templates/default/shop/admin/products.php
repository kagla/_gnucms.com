<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>상품 목록 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '상품 관리', 'description' => '상품을 검색하고 가격·재고·판매 상태를 한 번에 관리하세요.', 'actions' => [['url' => $admin_url . '/products/new', 'label' => '상품 등록']]]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php $this->insert('admin/_filters', ['action' => $admin_url . '/products']) ?>
<?php
$sortLink = fn (string $key): string => $admin_url . '/products?' . http_build_query(['q' => $filters['q'], 'field' => $filters['field'], 'ca' => $filters['ca'], 'sort' => $key, 'dir' => $filters['sort'] === $key && $filters['dir'] === 'asc' ? 'desc' : 'asc']);
$sort = function (string $key, string $label) use ($sortLink, $filters): void { ?><a href="<?= $this->e($sortLink($key)) ?>"><?= $label ?><?= $filters['sort'] === $key ? ($filters['dir'] === 'asc' ? ' ↑' : ' ↓') : '' ?></a><?php };
?>
<section class="yc-list-panel">
<div class="yc-list-heading"><h2>상품 목록 <span><?= number_format($list['total']) ?></span></h2><p>체크한 상품은 선택 삭제 대상입니다. <span class="yc-table-hint">표를 좌우로 밀어 확인하세요.</span></p></div>
<?php if ($list['items'] === []): ?>
<div class="yc-admin-empty"><?= $this->icon('tag', 30) ?><strong>표시할 상품이 없습니다</strong><p>검색 조건을 바꾸거나 새 상품을 등록해 주세요.</p><a class="btn btn-sm" href="<?= $this->e($admin_url) ?>/products/new">상품 등록</a></div>
<?php else: ?>
<form method="post" action="<?= $this->e($admin_url) ?>/products" id="yc-product-list">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="bulk">
  <div class="overflow-x-auto" tabindex="0" role="region" aria-label="상품 일괄 편집"><table class="table table-sm yc-edit-table"><thead><tr>
    <th><input class="checkbox checkbox-sm" type="checkbox" data-yc-check-all aria-label="현재 페이지 상품 전체 선택"></th>
    <th><?php $sort('name', '상품명'); $sort('code', '코드'); ?></th><th><?php $sort('price', '판매가'); $sort('list_price', '시중가'); ?></th><th><?php $sort('stock', '재고'); ?></th><th><?php $sort('active', '판매'); $sort('sold_out', '품절'); ?></th><th>분류 / <?php $sort('sort_order', '순서'); ?></th><th><?php $sort('hit', '조회'); ?></th><th>관리</th>
  </tr></thead><tbody>
    <?php foreach ($list['items'] as $row): $n = 'rows[' . (int) $row['id'] . ']'; $label = $this->e($row['name']); ?>
      <tr>
        <td><input class="checkbox checkbox-sm" type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>" form="yc-product-delete" aria-label="<?= $label ?> 선택"></td>
        <td class="yc-cell-product"><div class="yc-cell-stack"><input class="input input-bordered input-xs" type="text" name="<?= $n ?>[name]" value="<?= $label ?>" maxlength="250" required aria-label="<?= $label ?> 상품명"><div class="yc-cell-meta"><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['id'] ?>"><code><?= $this->e($row['code']) ?></code></a><a href="<?= $this->e($public_url) ?>/item?id=<?= $this->e(rawurlencode($row['code'])) ?>" target="_blank" rel="noopener">상품 보기 <?= $this->icon('external', 12) ?></a></div></div></td>
        <td><div class="yc-cell-stack"><label>판매가<input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[price]" value="<?= (int) $row['price'] ?>" min="0" required aria-label="<?= $label ?> 판매가"></label><label>시중가<input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[list_price]" value="<?= (int) $row['list_price'] ?>" min="0" aria-label="<?= $label ?> 시중가"></label></div></td>
        <td><input type="hidden" name="<?= $n ?>[original_stock]" value="<?= (int) $row['stock'] ?>"><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[stock]" value="<?= (int) $row['stock'] ?>" min="0" aria-label="<?= $label ?> 재고"></td>
        <td><div class="yc-cell-stack"><label><input type="hidden" name="<?= $n ?>[active]" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="<?= $n ?>[active]" value="1"<?= (int) $row['active'] === 1 ? ' checked' : '' ?> aria-label="<?= $label ?> 판매"> 판매</label><label><input type="hidden" name="<?= $n ?>[sold_out]" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="<?= $n ?>[sold_out]" value="1"<?= (int) $row['sold_out'] === 1 ? ' checked' : '' ?> aria-label="<?= $label ?> 품절"> 품절</label></div></td>
        <td><div class="yc-cell-stack"><select class="select select-bordered select-xs" name="<?= $n ?>[category_id]" aria-label="<?= $label ?> 분류"><?php foreach ($categories as $id => $category): ?><option value="<?= $id ?>"<?= (int) $row['category_id'] === $id ? ' selected' : '' ?>><?= $this->e($category) ?></option><?php endforeach ?></select><label>순서<input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[sort_order]" value="<?= (int) $row['sort_order'] ?>" aria-label="<?= $label ?> 순서"></label></div></td>
        <td><?= number_format((int) $row['hit']) ?></td><td><a class="btn btn-xs" href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['id'] ?>" aria-label="<?= $label ?> 수정">수정</a></td>
      </tr>
    <?php endforeach ?>
  </tbody></table></div>
</form>
<form method="post" action="<?= $this->e($admin_url) ?>/products" id="yc-product-delete" onsubmit="return confirm('선택한 상품을 삭제할까요? 이미지·옵션도 함께 지워집니다.')"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="delete"></form>
<div class="yc-list-actions"><p><span data-yc-selection-count role="status">0개 선택</span> · 저장은 현재 페이지의 모든 입력에 적용됩니다.</p><div class="row-actions"><button class="btn btn-sm btn-error btn-outline" type="submit" form="yc-product-delete" data-yc-delete-selected>선택 삭제</button><button class="btn btn-sm btn-primary" type="submit" form="yc-product-list">목록 변경사항 저장</button></div></div>
<?php endif ?>
</section>
<?php $this->insert('_pager', ['page_url' => fn (int $p): string => $admin_url . '/products?' . http_build_query($filters + ['page' => $p])]) ?>
<?php if ($list['items'] !== []): ?>
<details class="yc-copy-panel"><summary><?= $this->icon('copy', 16) ?> 기존 상품으로 새 상품 만들기</summary>
<form method="post" action="<?= $this->e($admin_url) ?>/products/copy" class="yc-copy-form">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <label>복사할 상품 <select class="select select-bordered select-sm" name="id"><?php foreach ($list['items'] as $row): ?><option value="<?= (int) $row['id'] ?>"><?= $this->e($row['code'] . ' ' . $row['name']) ?></option><?php endforeach ?></select></label>
  <label>새 상품 코드 <input class="input input-bordered input-sm" type="text" name="code" value="<?= time() ?>" maxlength="20" pattern="[A-Za-z0-9_\-]{1,20}" required></label>
  <button class="btn btn-sm" type="submit">상품 복사</button>
</form></details>
<?php endif ?>
<?php $this->stop() ?>
