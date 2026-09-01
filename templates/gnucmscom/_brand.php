<?php $brand_compact = (bool) ($compact ?? false); ?>
<span class="brand-lockup<?= $brand_compact ? ' brand-lockup-compact' : '' ?>">
  <span class="brand-symbol" aria-hidden="true"><?= $this->icon('brand', $brand_compact ? 17 : 20, 'brand-glyph') ?></span>
  <?php if (!$brand_compact): ?>
    <span class="brand-name brand-wordmark" aria-hidden="true"><span>GNU</span><span>CMS</span></span>
  <?php endif ?>
</span>
