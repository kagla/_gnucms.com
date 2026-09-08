<header class="yc-header">
  <div class="yc-header-main">
    <a class="yc-brand" href="<?= $this->e($url) ?>"><span class="yc-brand-mark" aria-hidden="true"><?= $this->icon('gift', 22) ?></span>쇼핑몰<span class="yc-brand-caption"><?= $this->e($site['site_name']) ?></span></a>
    <form class="yc-search" method="get" action="<?= $this->e($url) ?>/search" role="search">
      <?= $this->icon('search', 20) ?>
      <input type="search" name="q" value="<?= $this->e($q ?? '') ?>" maxlength="50" placeholder="어떤 상품을 찾으세요?" aria-label="상품 검색">
      <button type="submit">검색</button>
    </form>
    <div class="yc-header-actions">
      <a href="<?= $this->e($url) ?>/orders"<?= in_array($page ?? '', ['orders', 'order'], true) ? ' aria-current="page"' : '' ?>><?= $this->icon('document', 21) ?><span>주문 조회</span></a>
      <a class="yc-cart-link" href="<?= $this->e($url) ?>/cart"<?= ($page ?? '') === 'cart' ? ' aria-current="page"' : '' ?>><?= $this->icon('gift', 21) ?><span>장바구니</span><?php if (($cart_count ?? 0) > 0): ?><b class="yc-count" aria-label="담은 수량 <?= (int) $cart_count ?>개"><?= $cart_count > 99 ? '99+' : (int) $cart_count ?></b><?php endif ?></a>
    </div>
  </div>
  <div class="yc-header-bottom">
    <details class="yc-category-dropdown"><summary><?= $this->icon('grid', 18) ?> 카테고리</summary><nav aria-label="전체 상품 분류">
      <?php foreach ($menu as $category): ?><a href="<?= $this->e($url) ?>/list?ca=<?= $this->e($category['code']) ?>"><?= $this->e($category['name']) ?> <span aria-hidden="true">›</span></a><?php endforeach ?>
      <?php if ($menu === []): ?><p class="muted">분류를 준비하고 있습니다.</p><?php endif ?>
    </nav></details>
    <nav class="yc-nav" aria-label="쇼핑 메뉴">
      <a href="<?= $this->e($url) ?>"<?= ($page ?? '') === 'index' ? ' aria-current="page"' : '' ?>>쇼핑홈</a>
      <?php foreach ($type_labels as $key => $label): if (!($settings['main'][$key]['use'] ?? false)) continue; ?>
        <a href="<?= $this->e($url) ?>/type?t=<?= $this->e($key) ?>"<?= ($type ?? '') === $key ? ' aria-current="page"' : '' ?>><?= $this->e(['hit' => '베스트', 'new' => '신상품', 'recommend' => '추천상품', 'discount' => '할인상품', 'popular' => '인기상품'][$key]) ?></a>
      <?php endforeach ?>
    </nav>
    <?php if ($admin): ?><a class="yc-manage-link" href="<?= $this->e($admin_url) ?>">쇼핑몰 관리 <?= $this->icon('chevron-right', 14) ?></a><?php endif ?>
  </div>
</header>
