<header class="yc-header yc-header-compact">
  <div class="yc-header-bottom">
    <details class="yc-category-dropdown"><summary><?= $this->icon('grid', 18) ?> 카테고리</summary><nav aria-label="전체 상품 분류">
      <?php foreach ($menu as $category): ?><a href="<?= $this->e($url) ?>/c/<?= $this->e(rawurlencode($category['slug'])) ?>"><?= $this->e($category['name']) ?> <span aria-hidden="true">›</span></a><?php endforeach ?>
      <?php if ($menu === []): ?><p class="muted">분류를 준비하고 있습니다.</p><?php endif ?>
    </nav></details>
    <nav class="yc-nav" aria-label="쇼핑 메뉴">
      <a href="<?= $this->e($url) ?>"<?= ($page ?? '') === 'index' ? ' aria-current="page"' : '' ?>>쇼핑홈</a>
      <?php foreach (['best', 'new', 'popular', 'discount'] as $key): if (!($settings['main'][$key]['use'] ?? false)) continue; ?>
        <a href="<?= $this->e($url) ?>/type?t=<?= $this->e($key) ?>"<?= ($type ?? '') === $key ? ' aria-current="page"' : '' ?>><?= $this->e($type_labels[$key]) ?></a>
      <?php endforeach ?>
    </nav>
    <div class="yc-header-actions">
      <details class="yc-search-menu" data-yc-search-menu>
        <summary aria-label="상품 검색" title="상품 검색"><?= $this->icon('search', 21) ?></summary>
        <form class="yc-search" method="get" action="<?= $this->e($url) ?>/search" role="search" aria-label="쇼핑몰 상품 검색">
          <input type="search" name="q" value="<?= $this->e($q ?? '') ?>" maxlength="50" placeholder="어떤 상품을 찾으세요?" aria-label="상품 검색어">
          <button type="submit" aria-label="검색 실행"><?= $this->icon('search', 20) ?></button>
        </form>
      </details>
      <a href="<?= $this->e($url) ?>/orders"<?= in_array($page ?? '', ['orders', 'order'], true) ? ' aria-current="page"' : '' ?>><?= $this->icon('document', 21) ?><span>주문 조회</span></a>
      <a class="yc-cart-link" href="<?= $this->e($url) ?>/cart"<?= ($page ?? '') === 'cart' ? ' aria-current="page"' : '' ?>><?= $this->icon('gift', 21) ?><span>장바구니</span><?php if (($cart_count ?? 0) > 0): ?><b class="yc-count" aria-label="담은 수량 <?= (int) $cart_count ?>개"><?= $cart_count > 99 ? '99+' : (int) $cart_count ?></b><?php endif ?></a>
    </div>
  </div>
</header>
