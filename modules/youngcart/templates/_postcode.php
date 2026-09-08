<div class="yc-postcode" data-yc-postcode>
  <div class="yc-field">
    <label class="yc-postcode-label" for="yc-postcode">우편번호 <small aria-hidden="true">*</small></label>
    <div class="yc-postcode-controls">
      <input class="input input-bordered" id="yc-postcode" name="postcode" type="text" value="<?= $this->e($input['postcode'] ?? '') ?>" maxlength="5" inputmode="numeric" pattern="[0-9]{5}" autocomplete="shipping postal-code" required<?= isset($errors['postcode']) ? ' aria-invalid="true" aria-describedby="yc-error-postcode"' : '' ?>>
      <button class="yc-button yc-button-outline yc-button-small" type="button" data-yc-postcode-search aria-controls="yc-postcode-panel" aria-expanded="false" hidden>주소 검색</button>
    </div>
    <?php if (isset($errors['postcode'])): ?><small class="yc-inline-error" id="yc-error-postcode"><?= $this->e($errors['postcode']) ?></small><?php endif ?>
  </div>
  <p class="yc-postcode-status" data-yc-postcode-status role="status" aria-live="polite" hidden></p>
  <section class="yc-postcode-panel" id="yc-postcode-panel" data-yc-postcode-panel aria-label="카카오 주소 검색" hidden>
    <div class="yc-postcode-heading"><strong>주소 검색</strong><button class="yc-text-button" type="button" data-yc-postcode-close>검색 닫기</button></div>
    <div class="yc-postcode-host" data-yc-postcode-host></div>
  </section>
</div>
