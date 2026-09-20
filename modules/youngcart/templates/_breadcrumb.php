<div class="breadcrumbs"><ul>
  <li><a href="<?= $this->e($url) ?>">쇼핑몰</a></li>
  <?php foreach ($path as $index => $crumb): ?>
    <li<?= $index === count($path) - 1 ? ' aria-current="page"' : '' ?>><a href="<?= $this->e($url) ?>/list?ca=<?= $this->e($crumb['code']) ?>"><?= $this->e($crumb['name']) ?></a></li>
  <?php endforeach ?>
</ul></div>
