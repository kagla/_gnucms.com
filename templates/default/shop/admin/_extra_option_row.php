<tr>
  <td class="yc-extra-control"><button class="btn btn-xs btn-ghost yc-extra-drag" type="button" data-yc-extra-drag aria-label="추가옵션 순서 변경" aria-describedby="yc-extra-order-help" title="드래그하거나 위아래 방향키로 순서 변경"><?= $this->icon('menu', 16) ?></button></td>
  <td><input type="hidden" name="extras[<?= $i ?>][value1]" value="<?= $this->e($row['value1']) ?>"><input class="input input-bordered input-xs yc-extra-name" type="text" name="extras[<?= $i ?>][value2]" value="<?= $this->e($row['value2']) ?>" maxlength="100" aria-label="추가옵션 항목명"></td>
  <?php foreach (['price' => '가격', 'stock' => '재고', 'stock_alert' => '통보'] as $field => $label): ?>
    <td><div class="yc-option-input-group"><input class="input input-bordered input-xs" type="number" name="extras[<?= $i ?>][<?= $field ?>]" value="<?= $this->e($row[$field]) ?>" min="0"><button class="btn btn-xs" type="button" data-yc-copy-down="<?= $field ?>" title="<?= $label ?> 값을 아래 모든 추가옵션에 복사" aria-label="<?= $label ?> 값을 아래 모든 추가옵션에 복사">↓</button></div></td>
  <?php endforeach ?>
  <td><div class="yc-option-input-group"><label class="yc-option-enabled"><input type="hidden" name="extras[<?= $i ?>][active]" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="extras[<?= $i ?>][active]" value="1"<?= (string) $row['active'] === '1' ? ' checked' : '' ?>> 사용</label><button class="btn btn-xs" type="button" data-yc-copy-down="active" title="이 사용 여부를 아래 모든 추가옵션에 복사" aria-label="이 사용 여부를 아래 모든 추가옵션에 복사">↓</button></div></td>
  <td class="yc-extra-control"><button class="btn btn-xs btn-ghost text-error" type="button" data-yc-remove-extra aria-label="이 추가옵션 삭제" title="추가옵션 삭제"><?= $this->icon('trash', 16) ?></button></td>
</tr>
