<?php
$tabs = ['dashboard' => ['', '운영 현황', 'dashboard'], 'orders' => ['/orders', '주문', 'document'], 'products' => ['/products', '상품', 'tag'], 'categories' => ['/categories', '분류', 'grid'], 'settings' => ['/settings', '설정', 'cog']];
$current = str_starts_with($page, 'orders') ? 'orders' : (str_starts_with($page, 'categories') ? 'categories' : (str_starts_with($page, 'products') ? 'products' : $page));
?>
<div class="extension-toolbar yc-admin-toolbar">
  <nav class="tabs yc-admin-nav" aria-label="쇼핑몰 관리">
    <?php foreach ($tabs as $key => [$path, $label, $icon]): ?><a class="tab<?= $current === $key ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url . $path) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= $this->icon($icon, 17) ?><span><?= $label ?></span></a><?php endforeach ?>
  </nav>
  <?php /* 수정 중인 분류·상품이 있으면 그 공개 화면으로, 아니면 쇼핑몰 첫 화면으로 간다. */ ?>
  <div class="row-actions"><a class="btn btn-sm" href="<?= $this->e(($public_view_url ?? '') !== '' ? $public_view_url : $public_url) ?>" target="_blank" rel="noopener"><?= $this->icon('external', 15) ?> 쇼핑몰 보기</a></div>
</div>
<?php if ($current === 'products'): $sub = ['products' => ['/products', '전체 상품'], 'products/new' => ['/products/new', '상품 등록'], 'products/types' => ['/products/types', '진열 유형'], 'products/stock' => ['/products/stock', '상품 재고'], 'products/option-stock' => ['/products/option-stock', '옵션 재고']]; ?>
  <nav class="tabs tabs-sm yc-subtabs" aria-label="상품 관리"><?php foreach ($sub as $key => [$path, $label]): $selected = $page === $key || ($page === 'products/edit' && $key === 'products'); ?><a class="tab<?= $selected ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url . $path) ?>"<?= $selected ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach ?></nav>
<?php endif ?>
