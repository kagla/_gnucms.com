<?php
$tabs = ['dashboard' => ['', '현황'], 'categories' => ['/categories', '분류'], 'products' => ['/products', '상품'], 'orders' => ['/orders', '주문'], 'settings' => ['/settings', '설정']];
$current = str_starts_with($page, 'orders') ? 'orders' : (str_starts_with($page, 'categories') ? 'categories' : (str_starts_with($page, 'products') ? 'products' : $page));
?>
<div class="extension-toolbar">
  <nav class="tabs tabs-border settings-tabs" aria-label="쇼핑몰 관리">
    <?php foreach ($tabs as $key => [$path, $label]): ?><a class="tab<?= $current === $key ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url . $path) ?>"<?= $current === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach ?>
  </nav>
  <div class="row-actions"><a class="btn btn-sm" href="<?= $this->e($public_url) ?>" target="_blank" rel="noopener"><?= $this->icon('external', 15) ?> 쇼핑몰 보기</a></div>
</div>
<?php if ($current === 'products'): $sub = ['products' => ['/products', '목록'], 'products/new' => ['/products/new', '등록'], 'products/types' => ['/products/types', '유형'], 'products/stock' => ['/products/stock', '재고'], 'products/option-stock' => ['/products/option-stock', '옵션 재고']]; ?>
  <nav class="tabs tabs-sm yc-subtabs" aria-label="상품 관리"><?php foreach ($sub as $key => [$path, $label]): ?><a class="tab<?= $page === $key ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url . $path) ?>"><?= $label ?></a><?php endforeach ?></nav>
<?php endif ?>
