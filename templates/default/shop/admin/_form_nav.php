<nav class="tabs yc-form-nav" aria-label="입력 구역" data-yc-form-nav>
  <?php foreach ($sections as $anchor => $label): ?><a href="#<?= $this->e($anchor) ?>"><?= $this->e($label) ?></a><?php endforeach ?>
</nav>
