<?php if ($turnstile_enabled): ?>
  <fieldset class="fieldset<?= array_key_exists('turnstile', $errors) ? ' is-invalid' : '' ?>">
    <legend class="fieldset-legend sr-only">자동 등록 방지</legend>
    <?php if ($turnstile_configured): ?>
      <div class="cf-turnstile" data-sitekey="<?= $this->e($turnstile_site_key) ?>" data-action="<?= $this->e($action) ?>" data-theme="auto" data-size="flexible"></div>
    <?php else: ?>
      <p class="validator-hint" role="alert">자동 등록 방지 서비스 설정을 확인해 주세요.</p>
    <?php endif ?>
    <?php if (array_key_exists('turnstile', $errors)): ?><p class="validator-hint" role="alert"><?= $this->e($errors['turnstile']) ?></p><?php endif ?>
  </fieldset>
<?php endif ?>
