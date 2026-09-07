<?php $opts = $product['options']; ?>
<div class="yc-options" data-yc-options="<?= $this->e(json_encode($options_json, JSON_UNESCAPED_UNICODE)) ?>">
  <?php foreach ($opts['select_groups'] as $index => $group): $values = array_values(array_unique(array_column($opts['select'], 'value' . ($index + 1)))); ?>
    <label class="yc-option-row"><span><?= $this->e($group) ?></span>
      <select class="select select-bordered select-sm" data-yc-select="<?= $index ?>" name="option<?= $index + 1 ?>">
        <option value=""><?= $this->e($group) ?> 선택</option>
        <?php foreach ($values as $value): if ($value === '') continue; ?><option value="<?= $this->e($value) ?>"><?= $this->e($value) ?></option><?php endforeach ?>
      </select>
    </label>
  <?php endforeach ?>
  <?php foreach ($options_json['extra']['groups'] as $group): ?>
    <label class="yc-option-row"><span><?= $this->e($group['name']) ?> <small class="muted">(추가옵션)</small></span>
      <select class="select select-bordered select-sm" data-yc-extra="<?= $this->e($group['name']) ?>">
        <option value="">선택 안 함</option>
        <?php foreach ($group['items'] as $item): ?><option value="<?= $this->e($item['name']) ?>" data-price="<?= (int) $item['price'] ?>" data-stock="<?= (int) $item['stock'] ?>"<?= $item['stock'] < 1 ? ' disabled' : '' ?>><?= $this->e($item['name']) ?> (+<?= number_format($item['price']) ?>원)<?= $item['stock'] < 1 ? ' [품절]' : '' ?></option><?php endforeach ?>
      </select>
    </label>
  <?php endforeach ?>
  <?php if ($opts['select_groups'] === []): ?>
    <label class="yc-option-row"><span>수량</span><input class="input input-bordered input-sm" type="number" name="quantity" value="1" min="1" max="9999" data-yc-quantity></label>
  <?php endif ?>
  <ul class="yc-selected" data-yc-selected aria-live="polite"></ul>
  <p class="yc-total">합계 <strong data-yc-total><?= $sold_out ? '품절' : number_format((int) $product['price']) . '원' ?></strong></p>
</div>
