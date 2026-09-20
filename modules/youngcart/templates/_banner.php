<?php $copy = $banner['settings']; ?>
<?php if ($copy['use']): ?>
<div class="yc-hero">
  <div class="yc-hero-copy">
    <?php if ($copy['eyebrow'] !== ''): ?><span class="yc-eyebrow"><?= $this->e($copy['eyebrow']) ?></span><?php endif ?>
    <h1><?= nl2br($this->e($copy['title']), false) ?></h1>
    <?php if ($copy['description'] !== ''): ?><p><?= nl2br($this->e($copy['description']), false) ?></p><?php endif ?>
    <?php if ($copy['button_label'] !== '' && $banner['button_url'] !== ''): ?><a class="yc-button yc-button-dark" href="<?= $this->e($banner['button_url']) ?>"><?= $this->e($copy['button_label']) ?> <?= $this->icon('arrow-right', 18) ?></a><?php endif ?>
  </div>
  <?php if ($banner['image'] !== null): ?>
    <?php if ($banner['image_url'] !== ''): ?><a class="yc-hero-product" href="<?= $this->e($banner['image_url']) ?>"<?= $banner['alt'] === '' && $banner['caption'] === '' ? ' aria-label="' . $this->e($copy['title']) . '"' : '' ?>><?php else: ?><div class="yc-hero-product"><?php endif ?>
      <img src="<?= $this->e($banner['image']) ?>" alt="<?= $this->e($banner['alt']) ?>">
      <?php if ($banner['caption'] !== ''): ?><span><?= $this->e($banner['caption']) ?><?php if ($banner['image_url'] !== ''): ?> <?= $this->icon('arrow-right', 17) ?><?php endif ?></span><?php endif ?>
    <?php if ($banner['image_url'] !== ''): ?></a><?php else: ?></div><?php endif ?>
  <?php else: ?><div class="yc-hero-symbol" aria-hidden="true"><?= $this->icon('gift', 140) ?></div><?php endif ?>
</div>
<?php endif ?>
