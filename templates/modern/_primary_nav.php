<?php $section = trim($this->block('nav_section')); ?>
<nav class="site-primary-nav" aria-label="주요 메뉴">
  <a href="<?= $this->url('boards.index') ?>#about">소개</a>
  <a href="<?= $this->url('boards.index') ?>#features">기능</a>
  <a href="<?= $this->e($this->base . '/manual') ?>"<?= $section === 'modules/manual' ? ' class="is-active" aria-current="page"' : '' ?>><?= $this->icon('book-open', 16) ?>매뉴얼</a>
  <a href="<?= $this->url('posts.all') ?>"<?= in_array($section, ['board', 'all'], true) ? ' class="is-active" aria-current="page"' : '' ?>><?= $this->icon('comment', 16) ?>커뮤니티</a>
  <?php if ($shop_visible ?? false): ?><a href="<?= $this->url('shop.index') ?>"<?= $section === 'shop' ? ' class="is-active" aria-current="page"' : '' ?>>쇼핑몰</a><?php endif ?>
  <?php foreach ($public_extensions ?? [] as $key => $extension): if ($key === 'modules/manual') continue; ?>
    <a href="<?= $this->e($extension['url']) ?>"<?= $section === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= $this->e($extension['name']) ?></a>
  <?php endforeach ?>
</nav>
