<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>쇼핑몰 현황 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '쇼핑몰 현황', 'description' => '주문부터 상품까지, 지금 필요한 업무를 한눈에 확인하세요.', 'actions' => $ready ? [['url' => $admin_url . '/products/new', 'label' => '상품 등록']] : []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php if (!$ready): ?>
<section class="card yc-setup"><div class="card-body">
  <span class="yc-admin-symbol"><?= $this->icon('cog', 26) ?></span>
  <h2 class="card-title">쇼핑몰 운영을 준비해 주세요</h2>
  <p class="muted">쇼핑몰 데이터 설치 또는 갱신이 필요합니다. 기존 상품과 분류를 유지하며 필요한 데이터를 준비합니다.</p>
  <form method="post" action="<?= $this->e($admin_url) ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="install"><button class="btn btn-primary" type="submit">데이터 설치</button></form>
</div></section>
<?php else: ?>
<section class="yc-operation-summary" aria-label="상품 현황">
  <?php foreach (['products' => ['전체 상품', 'tag'], 'active' => ['판매 가능', 'check-circle'], 'sold_out' => ['품절 표시', 'warning'], 'categories' => ['상품 분류', 'grid']] as $key => [$label, $icon]): ?>
    <div class="yc-metric" aria-label="<?= $label ?> <?= (int) $stats[$key] ?>개"><div><span><?= $label ?></span><?= $this->icon($icon, 19) ?></div><strong><?= number_format((int) $stats[$key]) ?><small>개</small></strong></div>
  <?php endforeach ?>
</section>
<section class="card yc-order-flow"><div class="card-body">
  <div class="yc-panel-heading"><div><h2 class="card-title">주문 처리 현황</h2><p class="muted">전체 기간 · 현재 주문 상태 기준</p></div><a class="yc-admin-link" href="<?= $this->e($admin_url) ?>/orders">전체 주문 <?= $this->icon('arrow-right', 16) ?></a></div>
  <ol class="yc-workflow">
    <?php foreach (['pending' => ['주문 접수', '확인할 주문'], 'confirmed' => ['상품 준비', '배송을 준비할 주문'], 'shipped' => ['배송 중', '배송을 확인할 주문'], 'completed' => ['배송 완료', '처리가 끝난 주문']] as $key => [$label, $hint]): ?>
      <li><a href="<?= $this->e($admin_url) ?>/orders?status=<?= $key ?>"<?= $key === 'pending' ? ' class="yc-workflow-priority"' : '' ?>><span><?= $label ?></span><strong><?= number_format((int) ($order_stats[$key] ?? 0)) ?><small>건</small></strong><small><?= $hint ?></small></a></li>
    <?php endforeach ?>
  </ol>
  <div class="yc-panel-foot"><span class="muted">주문 상태는 결제 완료를 의미하지 않습니다.</span><a href="<?= $this->e($admin_url) ?>/orders?status=cancelled">취소 <?= (int) ($order_stats['cancelled'] ?? 0) ?>건 <?= $this->icon('chevron-right', 14) ?></a></div>
</div></section>
<div class="yc-dashboard-grid">
  <section class="card"><div class="card-body">
    <div class="yc-panel-heading"><div><h2 class="card-title">최근 접수 주문</h2><p class="muted">최근 접수순으로 최대 5건 표시</p></div><a class="yc-admin-link" href="<?= $this->e($admin_url) ?>/orders">더 보기 <?= $this->icon('chevron-right', 15) ?></a></div>
    <?php if ($recent_orders === []): ?><div class="yc-admin-empty"><?= $this->icon('document', 30) ?><strong>아직 접수된 주문이 없습니다</strong><p>새 주문이 접수되면 이곳에서 확인할 수 있습니다.</p></div><?php else: ?>
      <ul class="yc-recent-orders"><?php foreach ($recent_orders as $order): ?><li><a href="<?= $this->e($admin_url) ?>/orders/detail?id=<?= (int) $order['id'] ?>"><div><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span><strong><?= $this->e($order['buyer_name']) ?></strong><small><?= $this->e($order['number']) ?> · <?= date('m.d H:i', (int) $order['created_at']) ?></small></div><b><?= number_format((int) $order['total']) ?><small>원</small></b><?= $this->icon('chevron-right', 16) ?></a></li><?php endforeach ?></ul>
    <?php endif ?>
  </div></section>
  <section class="card"><div class="card-body">
    <div class="yc-panel-heading"><div><h2 class="card-title">재고 확인이 필요해요</h2><p class="muted">통보 기준 이하의 상품·옵션 · 최대 6건</p></div><?= $this->icon('bell', 20) ?></div>
    <?php if ($low_stock['products'] === [] && $low_stock['options'] === []): ?><div class="yc-admin-empty"><?= $this->icon('check-circle', 30) ?><strong>재고 알림이 없습니다</strong><p>설정한 통보 기준에 따라 표시됩니다.</p></div><?php else: ?>
      <ul class="yc-stock-alerts"><?php foreach (array_slice(array_merge($low_stock['products'], $low_stock['options']), 0, 6) as $row): ?><li><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) ($row['product_id'] ?? $row['id']) ?>"><div><strong><?= $this->e($row['name']) ?></strong><small><?php if (isset($row['product_id'])): ?><?= $this->e(implode(' / ', array_filter([$row['value1'], $row['value2'], $row['value3']]))) ?> · <?php endif ?>통보 기준 <?= (int) $row['stock_alert'] ?>개</small></div><span class="yc-stock-count"><?= (int) $row['stock'] ?>개</span></a></li><?php endforeach ?></ul>
    <?php endif ?>
    <div class="yc-panel-foot"><a href="<?= $this->e($admin_url) ?>/products/stock">상품 재고 <?= $this->icon('chevron-right', 14) ?></a><a href="<?= $this->e($admin_url) ?>/products/option-stock">옵션 재고 <?= $this->icon('chevron-right', 14) ?></a></div>
  </div></section>
</div>
<section class="yc-quick-links" aria-label="빠른 관리">
  <?php foreach ([['/products/new', 'plus', '새 상품 등록', '상품 정보와 판매 조건 입력'], ['/categories', 'grid', '분류 관리', '상품을 찾기 쉬운 카테고리 구성'], ['/settings#settings-shipping', 'cog', '배송·주문 설정', '배송비와 고객 안내문 관리']] as [$path, $icon, $label, $hint]): ?><a href="<?= $this->e($admin_url . $path) ?>"><span class="yc-admin-symbol"><?= $this->icon($icon, 21) ?></span><div><strong><?= $label ?></strong><small><?= $hint ?></small></div><?= $this->icon('chevron-right', 16) ?></a><?php endforeach ?>
</section>
<details class="yc-maintenance"><summary><?= $this->icon('check-circle', 16) ?> 데이터 준비 완료 <span class="muted">설치 정보</span></summary><div><p class="muted">스키마 <?= (int) $status['schema_version'] ?>판 · 갱신은 기존 상품과 주문을 유지합니다.</p><form method="post" action="<?= $this->e($admin_url) ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="install"><button class="btn btn-sm" type="submit">데이터 갱신</button></form></div></details>
<?php endif ?>
<?php $this->stop() ?>
