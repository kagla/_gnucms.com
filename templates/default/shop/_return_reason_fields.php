<?php $returnReason = is_string($input['return_reason'] ?? null) ? $input['return_reason'] : ''; ?>
<label class="yc-field"><span>반품 사유</span><select class="select select-bordered" name="return_reason" required>
<option value="">사유를 선택해 주세요</option><?php foreach ($return_reasons as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $returnReason === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?>
</select></label>
<label class="yc-field"><span>상세 사유 <small>기타 선택 시 필수</small></span><input class="input input-bordered" name="return_detail" maxlength="400" value="<?= $this->e(is_string($input['return_detail'] ?? null) ? $input['return_detail'] : '') ?>"></label>
