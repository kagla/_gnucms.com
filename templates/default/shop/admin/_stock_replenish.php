<?php $stockRoute = $stock_options ? 'option-stock' : 'stock'; ?>
<section class="yc-list-panel"><div class="yc-list-heading"><h2>재고 보충</h2><p>수확·입고한 수량만 입력하세요. 현재 재고에 더하고 일시를 자동 기록합니다.</p></div>
<form method="post" action="<?= $this->e($admin_url) ?>/products/<?= $stockRoute ?>">
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="restock"><input type="hidden" name="restock_token" value="<?= $this->e($restock_token) ?>"><input type="hidden" name="q" value="<?= $this->e($q) ?>">
<table class="table yc-restock-table"><thead><tr><th>상품<?= $stock_options ? '·옵션' : '' ?></th><th>현재 재고</th><th>보충 수량</th></tr></thead><tbody>
<?php foreach ($list['items'] as $row): $stockName = $stock_options ? $row['product_name'] . ' · ' . implode(' / ', array_filter([$row['value1'], $row['value2'], $row['value3']], static fn ($v) => $v !== '')) : $row['name']; ?>
<tr><td><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) ($stock_options ? $row['product_id'] : $row['id']) ?>"><?= $this->e($stockName) ?></a></td><td><?= number_format((int) $row['stock']) ?>개</td><td><input class="input input-bordered input-sm" type="number" name="rows[<?= (int) $row['id'] ?>][addition]" min="0" max="1000000" value="<?= $this->e($errors !== [] && is_scalar($input['rows'][$row['id']]['addition'] ?? null) ? (string) $input['rows'][$row['id']]['addition'] : '') ?>" placeholder="수량" aria-label="<?= $this->e($stockName) ?> 보충 수량"></td></tr>
<?php endforeach ?>
<?php if ($list['items'] === []): ?><tr><td colspan="3" class="yc-empty">표시할 상품이 없습니다.</td></tr><?php endif ?>
</tbody></table>
<?php if ($list['items'] !== []): ?><div class="yc-list-actions"><p>입력한 항목만 보충합니다.</p><button class="btn btn-primary" type="submit">재고 보충</button></div><?php endif ?>
</form></section>
