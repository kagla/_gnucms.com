<?php
$tabs = ['dashboard' => ['', '운영 현황', 'dashboard'], 'orders' => ['/orders', '주문', 'document'], 'products' => ['/products', '상품', 'tag'], 'categories' => ['/categories', '분류', 'grid'], 'settings' => ['/settings', '설정', 'cog']];
$current = str_starts_with($page, 'orders') || $page === 'payment-failures' ? 'orders' : (str_starts_with($page, 'categories') ? 'categories' : (str_starts_with($page, 'products') || $page === 'feedback' ? 'products' : $page));
?>
<div class="extension-toolbar yc-admin-toolbar">
  <nav class="tabs yc-admin-nav" aria-label="쇼핑몰 관리">
    <?php foreach ($tabs as $key => [$path, $label, $icon]): ?><a class="tab<?= $current === $key ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url . $path) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= $this->icon($icon, 17) ?><span><?= $label ?></span></a><?php endforeach ?>
  </nav>
  <?php /* 수정 중인 분류·상품이 있으면 그 공개 화면으로, 아니면 쇼핑몰 첫 화면으로 간다. */ ?>
  <div class="row-actions"><a class="btn btn-sm" href="<?= $this->e(($public_view_url ?? '') !== '' ? $public_view_url : $public_url) ?>" target="_blank" rel="noopener"><?= $this->icon('external', 15) ?> 쇼핑몰 보기</a></div>
</div>
<?php if ($current === 'orders'): $ordersSelected = $page === 'orders' || str_starts_with($page, 'orders/'); ?><nav class="tabs tabs-sm yc-subtabs" aria-label="주문 관리"><a class="tab<?= $ordersSelected ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url . '/orders') ?>"<?= $ordersSelected ? ' aria-current="page"' : '' ?>>주문 목록</a><a class="tab<?= $page === 'payment-failures' ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url . '/payment-failures') ?>"<?= $page === 'payment-failures' ? ' aria-current="page"' : '' ?>>결제 오류</a></nav><?php endif ?>
<?php if ($current === 'products'): $sub = ['products' => ['/products', '전체 상품'], 'products/new' => ['/products/new', '상품 등록'], 'feedback' => ['/feedback', '후기·문의'], 'products/stock' => ['/products/stock', '상품 재고'], 'products/option-stock' => ['/products/option-stock', '옵션 재고']]; ?>
  <nav class="tabs tabs-sm yc-subtabs" aria-label="상품 관리"><?php foreach ($sub as $key => [$path, $label]): $selected = $page === $key || (in_array($page, ['products/edit', 'products/settings-copy'], true) && $key === 'products'); ?><a class="tab<?= $selected ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url . $path) ?>"<?= $selected ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach ?></nav>
<?php endif ?>
