<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>분류 관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '분류 관리', 'description' => '상품을 찾기 쉽도록 분류를 구성하고 노출 순서를 관리하세요.', 'actions' => [['url' => $admin_url . '/categories/new', 'label' => '1단계 분류 추가']]]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<section class="yc-list-panel">
<div class="yc-list-heading"><h2>전체 분류 <span><?= count($tree) ?></span></h2><p>최대 5단계 · 하위 분류나 상품이 있으면 삭제할 수 없습니다.</p></div>
<?php if ($tree === []): ?><div class="yc-admin-empty"><?= $this->icon('grid', 30) ?><strong>첫 번째 분류를 만들어 주세요</strong><p>분류를 추가한 뒤 상품을 등록할 수 있습니다.</p><a class="btn btn-sm btn-primary" href="<?= $this->e($admin_url) ?>/categories/new">1단계 분류 추가</a></div><?php else: ?>
<form method="post" action="<?= $this->e($admin_url) ?>/categories" id="yc-category-list">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="bulk">
  <div class="overflow-x-auto" tabindex="0" role="region" aria-label="분류 일괄 편집"><table class="table table-sm yc-category-table"><thead><tr><th>분류명 / 코드</th><th>순서</th><th>판매</th><th>상품 배치</th><th>이미지 크기</th><th>상품 수</th><th>관리</th></tr></thead><tbody>
    <?php foreach ($tree as $row): $n = 'rows[' . (int) $row['id'] . ']'; $label = $this->e($row['name']); ?>
      <tr>
        <td class="yc-cell-product"><div class="yc-category-name" style="--yc-depth:<?= (int) $row['depth'] ?>"><?= $this->icon((int) $row['depth'] === 1 ? 'folder' : 'chevron-right', 17) ?><div class="yc-cell-stack"><input class="input input-bordered input-xs" type="text" name="<?= $n ?>[name]" value="<?= $label ?>" maxlength="100" required aria-label="<?= $label ?> 분류명"><small><code><?= $this->e($row['code']) ?></code> · <?= (int) $row['depth'] ?>단계</small></div></div></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[sort_order]" value="<?= (int) $row['sort_order'] ?>" aria-label="<?= $label ?> 순서"></td>
        <td><input type="hidden" name="<?= $n ?>[active]" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="<?= $n ?>[active]" value="1"<?= (int) $row['active'] === 1 ? ' checked' : '' ?> aria-label="<?= $label ?> 판매"></td>
        <td><div class="yc-cell-stack"><label>열<input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[list_columns]" value="<?= (int) $row['list_columns'] ?>" min="1" max="12" aria-label="<?= $label ?> 한 행 상품 수"></label><label>행<input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[list_rows]" value="<?= (int) $row['list_rows'] ?>" min="1" max="50" aria-label="<?= $label ?> 행 수"></label></div></td>
        <td><div class="yc-cell-stack"><label>너비<input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[image_width]" value="<?= (int) $row['image_width'] ?>" min="0" max="2000" aria-label="<?= $label ?> 이미지 너비"></label><label>높이<input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[image_height]" value="<?= (int) $row['image_height'] ?>" min="0" max="2000" aria-label="<?= $label ?> 이미지 높이"></label></div></td>
        <td><a href="<?= $this->e($admin_url) ?>/products?ca=<?= $this->e($row['code']) ?>"><?= (int) $row['product_count'] ?>개</a></td>
        <td><div class="yc-cell-stack"><div class="row-actions"><a class="btn btn-xs" href="<?= $this->e($admin_url) ?>/categories/edit?id=<?= (int) $row['id'] ?>">수정</a><?php if ((int) $row['depth'] < 5): ?><a class="btn btn-xs" href="<?= $this->e($admin_url) ?>/categories/new?parent=<?= $this->e($row['code']) ?>">하위 추가</a><?php endif ?></div><div class="row-actions"><a class="btn btn-xs btn-outline" href="<?= $this->e($public_url) ?>/list?ca=<?= $this->e($row['code']) ?>" target="_blank" rel="noopener">보기</a><button class="btn btn-xs btn-error btn-outline" type="submit" form="yc-category-delete-<?= (int) $row['id'] ?>" aria-label="<?= $label ?> 삭제">삭제</button></div></div></td>
      </tr>
    <?php endforeach ?>
  </tbody></table></div>
  <div class="yc-list-actions"><p>현재 목록의 변경사항을 모두 저장합니다. <span class="yc-table-hint">표를 좌우로 밀어 확인하세요.</span></p><button class="btn btn-sm btn-primary" type="submit">분류 변경사항 저장</button></div>
</form>
<?php foreach ($tree as $row): ?>
  <form method="post" action="<?= $this->e($admin_url) ?>/categories" id="yc-category-delete-<?= (int) $row['id'] ?>" onsubmit="return confirm('이 분류를 삭제할까요?')">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
  </form>
<?php endforeach ?>
<?php endif ?>
</section>
<?php $this->stop() ?>
