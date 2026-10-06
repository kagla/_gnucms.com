<?php
$deliveryNoteChoices = [
    '문 앞에 놓아주세요.',
    '부재 시 경비실에 맡겨주세요.',
    '부재 시 택배함에 넣어주세요.',
    '배송 전에 연락해 주세요.',
    '부재 시 연락해 주세요.',
];
?>
<div data-yc-delivery-note>
  <div data-yc-delivery-note-choices hidden>
    <label class="yc-field" for="yc-delivery-note-choice">
      <span>배송 요청사항 <small class="muted">선택</small></span>
      <select class="select select-bordered" id="yc-delivery-note-choice" data-yc-delivery-note-choice>
        <option value="">배송 요청사항 없음</option>
        <?php foreach ($deliveryNoteChoices as $choice): ?><option value="<?= $this->e($choice) ?>"><?= $this->e($choice) ?></option><?php endforeach ?>
        <option value="__custom">직접 입력</option>
      </select>
    </label>
  </div>
  <div data-yc-delivery-note-custom>
    <label class="yc-field" for="yc-delivery_note">
      <span>배송 요청사항 직접 입력 <small class="muted">선택</small></span>
      <input class="input input-bordered" id="yc-delivery_note" name="delivery_note" type="text" maxlength="500"
             value="<?= $this->e($input['delivery_note'] ?? '') ?>" placeholder="배송 시 요청할 내용을 입력해 주세요."
             <?= isset($errors['delivery_note']) ? 'aria-invalid="true" aria-describedby="yc-error-delivery_note"' : '' ?>>
      <?php if (isset($errors['delivery_note'])): ?><small class="yc-inline-error" id="yc-error-delivery_note"><?= $this->e($errors['delivery_note']) ?></small><?php endif ?>
    </label>
  </div>
</div>
