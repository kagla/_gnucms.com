<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>상품 재고 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '상품 재고', 'description' => '선택옵션이 없는 상품의 재고입니다. 옵션 재고는 별도 화면에서 관리합니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/products/stock"><input class="input input-bordered input-sm" type="search" name="q" value="<?= $this->e($q) ?>" placeholder="상품명·코드" aria-label="검색어"><button class="btn btn-sm" type="submit">검색</button></form>
<section class="yc-list-panel"><div class="yc-list-heading"><h2>상품 재고 <span><?= number_format($list['total']) ?></span></h2><p>현재 목록의 변경사항을 모두 저장합니다. <span class="yc-table-hint">표를 좌우로 밀어 확인하세요.</span></p></div>
<form method="post" action="<?= $this->e($admin_url) ?>/products/stock">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <div class="overflow-x-auto" tabindex="0" role="region" aria-label="상품 재고 일괄 편집"><table class="table table-sm"><thead><tr><th>코드</th><th>상품명</th><th>재고</th><th>통보 기준</th><th>판매</th><th>품절</th><th>재입고 알림</th></tr></thead><tbody>
    <?php foreach ($list['items'] as $row): $n = 'rows[' . (int) $row['id'] . ']'; ?>
      <tr><td><code><?= $this->e($row['code']) ?></code></td><td><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['id'] ?>"><?= $this->e($row['name']) ?></a></td>
        <td><input type="hidden" name="<?= $n ?>[original_stock]" value="<?= (int) $row['stock'] ?>"><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[stock]" value="<?= (int) $row['stock'] ?>" min="0" required></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[stock_alert]" value="<?= (int) $row['stock_alert'] ?>" min="0"></td>
        <?php foreach (['active', 'sold_out', 'restock_notify'] as $flag): ?><td><input type="hidden" name="<?= $n ?>[<?= $flag ?>]" value="0"><input class="checkbox checkbox-xs" type="checkbox" name="<?= $n ?>[<?= $flag ?>]" value="1"<?= (int) $row[$flag] === 1 ? ' checked' : '' ?>></td><?php endforeach ?></tr>
    <?php endforeach ?>
  <?php if ($list['items'] === []): ?><tr><td colspan="7"><div class="yc-admin-empty">표시할 항목이 없습니다. 검색 조건을 확인해 주세요.</div></td></tr><?php endif ?>
  </tbody></table></div>
  <?php if ($list['items'] !== []): ?><div class="yc-list-actions"><p>가격과 재고는 저장 시 다시 확인합니다.</p><button class="btn btn-sm btn-primary" type="submit">상품 재고 저장</button></div><?php endif ?>
</form></section>
<?php $this->insert('_pager', ['page_url' => fn (int $p): string => $admin_url . '/products/stock?' . http_build_query(['q' => $q, 'page' => $p])]) ?>
<?php $this->stop() ?>
