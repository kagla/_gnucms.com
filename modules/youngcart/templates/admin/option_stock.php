<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>옵션 재고 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '옵션 재고', 'description' => '선택옵션과 추가옵션의 재고입니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/products/option-stock"><input class="input input-bordered input-sm" type="search" name="q" value="<?= $this->e($q) ?>" placeholder="상품명·코드" aria-label="검색어"><button class="btn btn-sm" type="submit">검색</button></form>
<form method="post" action="<?= $this->e($admin_url) ?>/products/option-stock">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>상품</th><th>종류</th><th>옵션</th><th>가격</th><th>재고</th><th>통보 기준</th><th>사용</th></tr></thead><tbody>
    <?php foreach ($list['items'] as $row): $n = 'rows[' . (int) $row['id'] . ']'; ?>
      <tr><td><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['product_id'] ?>"><?= $this->e($row['product_name']) ?></a> <code><?= $this->e($row['product_code']) ?></code></td>
        <td><?= $row['kind'] === 'select' ? '선택' : '추가' ?></td><td><?= $this->e(implode(' / ', array_filter([$row['value1'], $row['value2'], $row['value3']]))) ?></td><td><?= number_format((int) $row['price']) ?></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[stock]" value="<?= (int) $row['stock'] ?>" min="0" required></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[stock_alert]" value="<?= (int) $row['stock_alert'] ?>" min="0"></td>
        <td><input type="hidden" name="<?= $n ?>[active]" value="0"><input class="checkbox checkbox-xs" type="checkbox" name="<?= $n ?>[active]" value="1"<?= (int) $row['active'] === 1 ? ' checked' : '' ?>></td></tr>
    <?php endforeach ?>
  </tbody></table></div>
  <div class="form-actions"><button class="btn btn-sm btn-primary" type="submit">저장</button></div>
</form>
<?php $this->insert('_pager', ['page_url' => fn (int $p): string => $admin_url . '/products/option-stock?' . http_build_query(['q' => $q, 'page' => $p])]) ?>
<?php $this->stop() ?>
