<?php // 상점 머리: 이름, 1단계 분류, 유형 링크, 검색 폼. $url·$menu·$type_labels·$settings·$admin·$admin_url 을 쓴다. ?>
<header class="yc-header">
  <a class="yc-brand" href="<?= $this->e($url) ?>">쇼핑몰</a>
  <nav class="yc-nav" aria-label="상품 분류">
    <?php foreach ($menu as $category): ?>
      <a href="<?= $this->e($url) ?>/list?ca=<?= $this->e($category['code']) ?>"<?= isset($path) && ($path[0]['code'] ?? '') === $category['code'] ? ' aria-current="page"' : '' ?>><?= $this->e($category['name']) ?></a>
    <?php endforeach ?>
    <?php foreach ($type_labels as $key => $label): if (!($settings['main'][$key]['use'] ?? false)) continue; ?>
      <a class="yc-nav-type" href="<?= $this->e($url) ?>/type?t=<?= $this->e($key) ?>"><?= $this->e($label) ?></a>
    <?php endforeach ?>
  </nav>
  <form class="yc-search" method="get" action="<?= $this->e($url) ?>/search" role="search">
    <input class="input input-bordered input-sm" type="search" name="q" value="<?= $this->e($q ?? '') ?>" maxlength="50" placeholder="상품 검색" aria-label="상품 검색">
    <button class="btn btn-sm" type="submit">검색</button>
  </form>
  <?php if ($admin): ?><a class="btn btn-sm btn-outline" href="<?= $this->e($admin_url) ?>">쇼핑몰 관리</a><?php endif ?>
</header>
