<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>상품 유형 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '상품 유형', 'description' => '히트·추천·최신·인기·할인 표시를 한 번에 바꿉니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php $this->insert('admin/_filters', ['action' => $admin_url . '/products/types']) ?>
<section class="yc-list-panel"><div class="yc-list-heading"><h2>진열 유형 <span><?= number_format($list['total']) ?></span></h2><p>현재 목록의 변경사항을 모두 저장합니다. <span class="yc-table-hint">표를 좌우로 밀어 확인하세요.</span></p></div>
<form method="post" action="<?= $this->e($admin_url) ?>/products/types">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
  <div class="overflow-x-auto" tabindex="0" role="region" aria-label="진열 유형 일괄 편집"><table class="table table-sm"><thead><tr><th>코드</th><th>상품명</th><?php foreach (['is_hit' => '히트', 'is_recommended' => '추천', 'is_new' => '최신', 'is_popular' => '인기', 'is_discount' => '할인'] as $type => $label): ?><th><?= $label ?></th><?php endforeach ?></tr></thead><tbody>
    <?php foreach ($list['items'] as $row): $n = 'rows[' . (int) $row['id'] . ']'; ?>
      <tr><td><code><?= $this->e($row['code']) ?></code></td><td><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['id'] ?>"><?= $this->e($row['name']) ?></a></td>
        <?php foreach ($types as $type): ?><td><input type="hidden" name="<?= $n ?>[<?= $type ?>]" value="0"><input class="checkbox checkbox-xs" type="checkbox" name="<?= $n ?>[<?= $type ?>]" value="1"<?= (int) $row[$type] === 1 ? ' checked' : '' ?>></td><?php endforeach ?></tr>
    <?php endforeach ?>
  <?php if ($list['items'] === []): ?><tr><td colspan="7"><div class="yc-admin-empty">표시할 항목이 없습니다. 검색 조건을 확인해 주세요.</div></td></tr><?php endif ?>
  </tbody></table></div>
  <?php if ($list['items'] !== []): ?><div class="yc-list-actions"><p>체크한 유형에 따라 쇼핑몰 진열 위치가 달라집니다.</p><button class="btn btn-sm btn-primary" type="submit">진열 유형 저장</button></div><?php endif ?>
</form></section>
<?php $this->insert('_pager', ['page_url' => fn (int $p): string => $admin_url . '/products/types?' . http_build_query($filters + ['page' => $p])]) ?>
<?php $this->stop() ?>
