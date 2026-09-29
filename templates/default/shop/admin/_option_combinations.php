<?php if ($options_rows !== []): ?>
  <div class="overflow-x-auto"><table class="table table-sm yc-combo-table" data-yc-combos><thead><tr><th>조합</th><th>차액</th><th>재고</th><th>통보</th><th>사용</th></tr></thead><tbody>
    <?php foreach ($options_rows as $i => $row): ?><tr>
      <td><?= $this->e(implode(' / ', array_filter([$row['value1'], $row['value2'], $row['value3']], static fn ($x) => $x !== ''))) ?><?php for ($k = 1; $k <= 3; $k++): ?><input type="hidden" name="options[<?= $i ?>][value<?= $k ?>]" value="<?= $this->e($row['value' . $k]) ?>"><?php endfor ?></td>
      <td><div class="yc-option-input-group"><input class="input input-bordered input-xs" type="number" name="options[<?= $i ?>][price]" value="<?= $this->e($row['price']) ?>"><button class="btn btn-xs" type="button" data-yc-copy-down="price" title="이 차액을 아래 모든 조합에 복사" aria-label="이 차액을 아래 모든 조합에 복사">↓</button></div></td>
      <td><div class="yc-option-input-group"><input class="input input-bordered input-xs" type="number" name="options[<?= $i ?>][stock]" value="<?= $this->e($row['stock']) ?>" min="0"><button class="btn btn-xs" type="button" data-yc-copy-down="stock" title="이 재고를 아래 모든 조합에 복사" aria-label="이 재고를 아래 모든 조합에 복사">↓</button></div></td>
      <td><div class="yc-option-input-group"><input class="input input-bordered input-xs" type="number" name="options[<?= $i ?>][stock_alert]" value="<?= $this->e($row['stock_alert']) ?>" min="0"><button class="btn btn-xs" type="button" data-yc-copy-down="stock_alert" title="이 통보 기준을 아래 모든 조합에 복사" aria-label="이 통보 기준을 아래 모든 조합에 복사">↓</button></div></td>
      <td><div class="yc-option-input-group"><label class="yc-option-enabled" title="체크하면 이 옵션을 판매에 사용합니다."><input type="hidden" name="options[<?= $i ?>][active]" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="options[<?= $i ?>][active]" value="1"<?= (string) $row['active'] === '1' ? ' checked' : '' ?>> 사용</label><button class="btn btn-xs" type="button" data-yc-copy-down="active" title="이 사용 여부를 아래 모든 조합에 복사" aria-label="이 사용 여부를 아래 모든 조합에 복사">↓</button></div></td>
    </tr><?php endforeach ?>
  </tbody></table></div>
<?php endif ?>
