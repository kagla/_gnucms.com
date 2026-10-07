<?php $selectedReason = is_string($input['cancel_reason'] ?? null) ? $input['cancel_reason'] : ''; ?>
<label class="yc-field" for="yc-cancel-reason"><span>취소 사유 <small><?= ($cancel_reason_required ?? false) ? '필수' : '취소 선택 시 필수' ?></small></span>
  <select class="select select-bordered" id="yc-cancel-reason" name="cancel_reason"<?= ($cancel_reason_required ?? false) ? ' required' : '' ?><?= isset($errors['cancel_reason']) ? ' aria-invalid="true" aria-describedby="yc-cancel-reason-error"' : '' ?>>
    <option value="">사유를 선택해 주세요</option>
    <?php foreach ($cancel_reasons as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $selectedReason === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?>
  </select>
  <?php if (isset($errors['cancel_reason'])): ?><small class="yc-inline-error" id="yc-cancel-reason-error"><?= $this->e($errors['cancel_reason']) ?></small><?php endif ?>
</label>
<label class="yc-field" for="yc-cancel-detail"><span>상세 사유 <small>기타 선택 시 필수</small></span>
  <textarea class="textarea textarea-bordered" id="yc-cancel-detail" name="cancel_detail" maxlength="400" rows="3" placeholder="취소 이유를 자세히 적어 주세요."<?= isset($errors['cancel_detail']) ? ' aria-invalid="true" aria-describedby="yc-cancel-detail-error"' : '' ?>><?= $this->e(is_string($input['cancel_detail'] ?? null) ? $input['cancel_detail'] : '') ?></textarea>
  <?php if (isset($errors['cancel_detail'])): ?><small class="yc-inline-error" id="yc-cancel-detail-error"><?= $this->e($errors['cancel_detail']) ?></small><?php endif ?>
</label>
