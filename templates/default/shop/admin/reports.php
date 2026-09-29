<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>매출 리포트 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '매출 리포트', 'description' => '결제와 환불이 실제 기록된 날짜를 기준으로 과세·면세 매출을 집계합니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<div class="yc-admin">
  <form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/reports">
    <label>시작일<input class="input input-bordered" type="date" name="from" value="<?= $this->e($range['from']) ?>" required></label>
    <label>종료일<input class="input input-bordered" type="date" name="to" value="<?= $this->e($range['to']) ?>" required></label>
    <label>집계 단위<select class="select select-bordered" name="group"><option value="day"<?= $range['group'] === 'day' ? ' selected' : '' ?>>일별</option><option value="month"<?= $range['group'] === 'month' ? ' selected' : '' ?>>월별</option></select></label>
    <button class="btn btn-primary" type="submit">조회</button>
  </form>
  <?php $summary = $report['summary']; ?>
  <section class="yc-report-summary" aria-label="매출 요약">
    <div><span>결제액</span><strong><?= number_format($summary['paid_amount']) ?>원</strong><small><?= number_format($summary['order_count']) ?>건</small></div>
    <div><span>환불액</span><strong><?= number_format($summary['refunded_amount']) ?>원</strong><small><?= number_format($summary['refund_count']) ?>건</small></div>
    <div class="is-primary"><span>순매출</span><strong><?= number_format($summary['net_amount']) ?>원</strong><small>결제액 − 기간 내 환불액</small></div>
    <div><span>과세 공급가 / 부가세</span><strong><?= number_format($summary['net_supply_amount']) ?>원</strong><small>부가세 <?= number_format($summary['net_vat_amount']) ?>원</small></div>
    <div><span>면세액</span><strong><?= number_format($summary['net_tax_free_amount']) ?>원</strong><small>기간 내 환불 반영</small></div>
    <div><span>주문 배송비</span><strong><?= number_format($summary['shipping_fee']) ?>원</strong><small>결제 주문에 포함된 금액</small></div>
  </section>
  <form class="yc-report-export" method="post" action="<?= $this->e($admin_url) ?>/reports/export"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="from" value="<?= $this->e($range['from']) ?>"><input type="hidden" name="to" value="<?= $this->e($range['to']) ?>"><input type="hidden" name="group" value="<?= $this->e($range['group']) ?>"><button class="btn" type="submit">전체 집계 CSV</button></form>
  <section class="yc-list-panel"><div class="yc-list-heading"><h2><?= $range['group'] === 'month' ? '월별' : '일별' ?> 매출</h2><p>결제일과 환불 처리일 기준</p></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>기간</th><th>주문</th><th>결제액</th><th>환불액</th><th>순매출</th><th>과세액</th><th>부가세</th><th>면세액</th><th>배송비</th></tr></thead><tbody>
    <?php foreach ($report['periods'] as $row): ?><tr><td><?= $this->e($row['period']) ?></td><td class="yc-number"><?= number_format($row['order_count']) ?></td><td class="yc-number"><?= number_format($row['paid_amount']) ?></td><td class="yc-number"><?= number_format($row['refunded_amount']) ?></td><td class="yc-number"><strong><?= number_format($row['net_amount']) ?></strong></td><td class="yc-number"><?= number_format($row['taxable_amount'] - $row['refunded_taxable_amount']) ?></td><td class="yc-number"><?= number_format($row['vat_amount'] - $row['refunded_vat_amount']) ?></td><td class="yc-number"><?= number_format($row['tax_free_amount'] - $row['refunded_tax_free_amount']) ?></td><td class="yc-number"><?= number_format($row['shipping_fee']) ?></td></tr><?php endforeach ?>
    <?php if ($report['periods'] === []): ?><tr><td colspan="9" class="yc-empty">기간 내 결제·환불 내역이 없습니다.</td></tr><?php endif ?>
  </tbody></table></div></section>
  <div class="yc-report-grid">
    <section class="yc-list-panel"><div class="yc-list-heading"><h2>상품별 매출</h2><p>상위 200개</p></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>상품</th><th>수량</th><th>매출</th></tr></thead><tbody><?php foreach ($report['products'] as $row): ?><tr><td><small class="muted"><?= $this->e($row['product_code']) ?></small><br><?= $this->e($row['product_name']) ?></td><td class="yc-number"><?= number_format($row['quantity']) ?></td><td class="yc-number"><?= number_format($row['amount']) ?>원</td></tr><?php endforeach ?><?php if ($report['products'] === []): ?><tr><td colspan="3" class="yc-empty">자료가 없습니다.</td></tr><?php endif ?></tbody></table></div></section>
    <section class="yc-list-panel"><div class="yc-list-heading"><h2>분류별 매출</h2><p>현재 대표 분류 기준</p></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>분류</th><th>수량</th><th>매출</th></tr></thead><tbody><?php foreach ($report['categories'] as $row): ?><tr><td><?= $this->e($row['category_name']) ?></td><td class="yc-number"><?= number_format($row['quantity']) ?></td><td class="yc-number"><?= number_format($row['amount']) ?>원</td></tr><?php endforeach ?><?php if ($report['categories'] === []): ?><tr><td colspan="3" class="yc-empty">자료가 없습니다.</td></tr><?php endif ?></tbody></table></div></section>
  </div>
  <section class="yc-list-panel"><div class="yc-list-heading"><h2>결제 수단별</h2><p>결제 완료액 기준</p></div><div class="overflow-x-auto"><table class="table"><thead><tr><th>결제사</th><th>수단</th><th>주문</th><th>결제액</th></tr></thead><tbody><?php foreach ($report['payments'] as $row): ?><tr><td><?= $this->e($row['provider']) ?></td><td><?= $this->e($row['payment_method'] === '' ? '접수 전용' : $row['payment_method']) ?></td><td class="yc-number"><?= number_format($row['order_count']) ?></td><td class="yc-number"><?= number_format($row['amount']) ?>원</td></tr><?php endforeach ?><?php if ($report['payments'] === []): ?><tr><td colspan="4" class="yc-empty">자료가 없습니다.</td></tr><?php endif ?></tbody></table></div></section>
  <div class="yc-info-note"><?= $this->icon('info', 16) ?><span>부분 환불은 주문 단위로 기록되므로 상품·분류별 표에는 환불액을 임의 배분하지 않습니다. 과세·면세 순매출은 실제 환불 원장의 세금 명세를 반영합니다.</span></div>
</div>
<?php $this->stop() ?>
