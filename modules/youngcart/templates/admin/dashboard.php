<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>쇼핑몰 현황 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>modules<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'modules', 'heading' => '쇼핑몰', 'description' => '영카트 모듈 현황', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<?php if ($ready): ?><section class="card"><div class="card-body"><div class="yc-section-heading"><h2 class="card-title">주문 현황</h2><a class="btn btn-sm" href="<?= $this->e($admin_url) ?>/orders">주문 관리 →</a></div>
  <ul class="yc-stats"><?php foreach (['pending' => '주문 접수', 'confirmed' => '상품 준비', 'shipped' => '배송 중', 'completed' => '배송 완료'] as $key => $label): ?><li><a href="<?= $this->e($admin_url) ?>/orders?status=<?= $key ?>"><small class="muted"><?= $label ?></small><br><?= (int) ($order_stats[$key] ?? 0) ?><small>건</small></a></li><?php endforeach ?></ul>
</div></section><?php endif ?>
<section class="card"><div class="card-body">
  <h2 class="card-title">데이터</h2>
  <?php if ($ready): ?>
    <p>스키마 <?= (int) $status['schema_version'] ?>판이 설치되어 있습니다.</p>
  <?php else: ?>
    <p class="muted">쇼핑몰 데이터 설치 또는 갱신이 필요합니다. 설치하면 <code>yc_</code> 테이블 12개를 만듭니다. 상태: <?= $this->e($status['state'] ?? '없음') ?></p>
  <?php endif ?>
  <form method="post" action="<?= $this->e($admin_url) ?>">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="action" value="install">
    <button class="btn btn-sm btn-primary" type="submit"><?= $ready ? '데이터 갱신' : '데이터 설치' ?></button>
  </form>
</div></section>
<?php if ($stats !== null): ?>
<section class="card"><div class="card-body">
  <h2 class="card-title">요약</h2>
  <ul class="yc-stats">
    <li>상품 <?= $stats['products'] ?></li><li>판매중 <?= $stats['active'] ?></li><li>품절 <?= $stats['sold_out'] ?></li><li>분류 <?= $stats['categories'] ?></li>
  </ul>
</div></section>
<section class="card"><div class="card-body">
  <h2 class="card-title">재고 부족</h2>
  <?php if ($low_stock['products'] === [] && $low_stock['options'] === []): ?><p class="muted">통보 기준 이하의 재고가 없습니다.</p><?php else: ?>
    <table class="table table-sm"><thead><tr><th>상품</th><th>옵션</th><th>재고</th><th>통보 기준</th></tr></thead><tbody>
      <?php foreach ($low_stock['products'] as $row): ?><tr><td><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['id'] ?>"><?= $this->e($row['name']) ?></a></td><td>—</td><td><?= (int) $row['stock'] ?></td><td><?= (int) $row['stock_alert'] ?></td></tr><?php endforeach ?>
      <?php foreach ($low_stock['options'] as $row): ?><tr><td><a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $row['product_id'] ?>"><?= $this->e($row['name']) ?></a></td><td><?= $this->e(implode(' / ', array_filter([$row['value1'], $row['value2'], $row['value3']]))) ?></td><td><?= (int) $row['stock'] ?></td><td><?= (int) $row['stock_alert'] ?></td></tr><?php endforeach ?>
    </tbody></table>
  <?php endif ?>
</div></section>
<?php endif ?>
<?php $this->stop() ?>
