<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>상품 재고 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '상품 재고', 'description' => '선택옵션이 없는 상품의 재고입니다. 옵션 재고는 별도 화면에서 관리합니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/products/stock"><input class="input input-bordered input-sm" type="search" name="q" value="<?= $this->e($q) ?>" placeholder="상품명·코드" aria-label="검색어"><button class="btn btn-sm" type="submit">검색</button></form>
<div class="row-actions yc-stock-modes"><a class="btn btn-sm<?= $stock_mode === 'restock' ? ' btn-primary' : '' ?>" href="<?= $this->e($admin_url) ?>/products/stock?<?= $this->e(http_build_query(['q' => $q])) ?>">재고 보충</a><a class="btn btn-sm<?= $stock_mode === 'adjust' ? ' btn-primary' : '' ?>" href="<?= $this->e($admin_url) ?>/products/stock?<?= $this->e(http_build_query(['q' => $q, 'mode' => 'adjust'])) ?>">수량 조정·판매 설정</a></div>
<?php if ($stock_mode === 'adjust'): ?>
<section class="yc-list-panel"><div class="yc-list-heading"><h2>상품 재고 <span><?= number_format($list['total']) ?></span></h2><p>현재 목록의 변경사항을 모두 저장합니다. <span class="yc-table-hint">표를 좌우로 밀어 확인하세요.</span></p></div>
<form method="post" action="<?= $this->e($admin_url) ?>/products/stock">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="mode" value="adjust"><input type="hidden" name="q" value="<?= $this->e($q) ?>">
  <div class="overflow-x-auto" tabindex="0" role="region" aria-label="상품 재고 일괄 편집"><table class="table table-sm"><thead><tr><th>코드</th><th>상품명</th><th>재고</th><th>통보 기준</th><th>판매</th><th>품절</th></tr></thead><tbody>
    <?php foreach ($list['items'] as $row): $n = 'rows[' . (int) $row['id'] . ']'; ?>
      <tr><td><code><?= $this->e($row['code']) ?></code></td><td><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['id'] ?>"><?= $this->e($row['name']) ?></a></td>
        <td><div class="yc-option-input-group"><input type="hidden" name="<?= $n ?>[original_stock]" value="<?= (int) $row['stock'] ?>"><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[stock]" value="<?= (int) $row['stock'] ?>" min="0" required><button class="btn btn-xs" type="button" data-yc-copy-down="stock" title="이 재고를 아래 모든 상품에 복사" aria-label="이 재고를 아래 모든 상품에 복사">↓</button></div></td>
        <td><div class="yc-option-input-group"><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[stock_alert]" value="<?= (int) $row['stock_alert'] ?>" min="0"><button class="btn btn-xs" type="button" data-yc-copy-down="stock_alert" title="이 통보 기준을 아래 모든 상품에 복사" aria-label="이 통보 기준을 아래 모든 상품에 복사">↓</button></div></td>
        <?php foreach (['active' => '판매 여부', 'sold_out' => '품절 표시 여부'] as $flag => $flagLabel): ?><td><div class="yc-option-input-group"><input type="hidden" name="<?= $n ?>[<?= $flag ?>]" value="0"><input class="checkbox checkbox-xs" type="checkbox" name="<?= $n ?>[<?= $flag ?>]" value="1"<?= (int) $row[$flag] === 1 ? ' checked' : '' ?>><button class="btn btn-xs" type="button" data-yc-copy-down="<?= $flag ?>" title="이 <?= $flagLabel ?>를 아래 모든 상품에 복사" aria-label="이 <?= $flagLabel ?>를 아래 모든 상품에 복사">↓</button></div></td><?php endforeach ?></tr>
    <?php endforeach ?>
  <?php if ($list['items'] === []): ?><tr><td colspan="6"><div class="yc-admin-empty">표시할 항목이 없습니다. 검색 조건을 확인해 주세요.</div></td></tr><?php endif ?>
  </tbody></table></div>
  <?php if ($list['items'] !== []): ?><div class="yc-list-actions"><p>가격과 재고는 저장 시 다시 확인합니다.</p><button class="btn btn-sm btn-primary" type="submit">상품 재고 저장</button></div><?php endif ?>
</form></section>
<?php else: ?>
<?php $this->insert('admin/_stock_replenish', ['stock_options' => false]) ?>
<?php endif ?>
<?php $this->insert('_pager', ['page_url' => fn (int $p): string => $admin_url . '/products/stock?' . http_build_query(['q' => $q, 'page' => $p, 'mode' => $stock_mode === 'adjust' ? 'adjust' : ''])]) ?>
<?php $this->insert('admin/_stock_recent') ?>
<?php $this->stop() ?>
