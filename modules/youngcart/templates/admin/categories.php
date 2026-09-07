<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>분류 관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '분류 관리', 'description' => '분류 코드는 단계당 2자, 최대 5단계입니다.', 'actions' => [['url' => $admin_url . '/categories/new', 'label' => '1단계 분류 추가']]]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php if ($tree === []): ?><p class="muted">분류가 없습니다. 먼저 1단계 분류를 추가해 주세요.</p><?php else: ?>
<form method="post" action="<?= $this->e($admin_url) ?>/categories">
  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="bulk">
  <div class="overflow-x-auto"><table class="table table-sm"><thead><tr><th>코드</th><th>분류명</th><th>순서</th><th>판매</th><th>열</th><th>행</th><th>이미지 폭</th><th>이미지 높이</th><th>상품</th><th></th></tr></thead><tbody>
    <?php foreach ($tree as $row): $n = 'rows[' . (int) $row['id'] . ']'; ?>
      <tr>
        <td><code><?= $this->e($row['code']) ?></code></td>
        <td style="padding-left:<?= ((int) $row['depth'] - 1) * 1.25 ?>rem"><input class="input input-bordered input-xs" type="text" name="<?= $n ?>[name]" value="<?= $this->e($row['name']) ?>" maxlength="100" required></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[sort_order]" value="<?= (int) $row['sort_order'] ?>"></td>
        <td><input type="hidden" name="<?= $n ?>[active]" value="0"><input class="checkbox checkbox-xs" type="checkbox" name="<?= $n ?>[active]" value="1"<?= (int) $row['active'] === 1 ? ' checked' : '' ?>></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[list_columns]" value="<?= (int) $row['list_columns'] ?>" min="1" max="12"></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[list_rows]" value="<?= (int) $row['list_rows'] ?>" min="1" max="50"></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[image_width]" value="<?= (int) $row['image_width'] ?>" min="0" max="2000"></td>
        <td><input class="input input-bordered input-xs yc-table-input" type="number" name="<?= $n ?>[image_height]" value="<?= (int) $row['image_height'] ?>" min="0" max="2000"></td>
        <td><?= (int) $row['product_count'] ?></td>
        <td class="row-actions">
          <a class="btn btn-xs" href="<?= $this->e($admin_url) ?>/categories/edit?id=<?= (int) $row['id'] ?>">수정</a>
          <?php if ((int) $row['depth'] < 5): ?><a class="btn btn-xs" href="<?= $this->e($admin_url) ?>/categories/new?parent=<?= $this->e($row['code']) ?>">하위 추가</a><?php endif ?>
          <a class="btn btn-xs btn-outline" href="<?= $this->e($public_url) ?>/list?ca=<?= $this->e($row['code']) ?>" target="_blank" rel="noopener">보기</a>
        </td>
      </tr>
    <?php endforeach ?>
  </tbody></table></div>
  <div class="form-actions"><button class="btn btn-sm btn-primary" type="submit">선택 내용 일괄 저장</button></div>
</form>
<h2 class="card-title">삭제</h2>
<p class="muted">하위 분류나 연결된 상품이 있으면 삭제할 수 없습니다.</p>
<?php foreach ($tree as $row): ?>
  <form method="post" action="<?= $this->e($admin_url) ?>/categories" class="inline" onsubmit="return confirm('이 분류를 삭제할까요?')">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
    <button class="btn btn-xs btn-error btn-outline" type="submit"><?= $this->e($row['code']) ?> <?= $this->e($row['name']) ?> 삭제</button>
  </form>
<?php endforeach ?>
<?php endif ?>
<?php $this->stop() ?>
