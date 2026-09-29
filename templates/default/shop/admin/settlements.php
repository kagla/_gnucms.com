<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>정산 대사 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '정산 대사', 'description' => 'PG 정산 자료와 주문의 실결제·환불 금액을 공통 형식으로 맞춰 봅니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<div class="yc-admin">
  <form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/settlements">
    <label>결제 시작일<input class="input input-bordered" type="date" name="from" value="<?= $this->e($range['from']) ?>" required></label><label>결제 종료일<input class="input input-bordered" type="date" name="to" value="<?= $this->e($range['to']) ?>" required></label>
    <label>결제사<select class="select select-bordered" name="provider"><option value="">전체</option><?php foreach ($providers as $id => $label): ?><option value="<?= $this->e($id) ?>"<?= $provider === $id ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?></select></label>
    <label>대사 상태<select class="select select-bordered" name="status"><option value="">전체</option><?php foreach (['matched' => '일치', 'missing' => '정산 없음', 'mismatch' => '금액 불일치', 'orphan' => '주문 없음'] as $key => $label): ?><option value="<?= $key ?>"<?= $status_filter === $key ? ' selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label><button class="btn btn-primary" type="submit">조회</button>
  </form>
  <section class="yc-settlement-counts"><div><span>일치</span><strong><?= number_format($list['counts']['matched']) ?></strong></div><div><span>정산 없음</span><strong><?= number_format($list['counts']['missing']) ?></strong></div><div><span>금액 불일치</span><strong><?= number_format($list['counts']['mismatch']) ?></strong></div><div><span>주문 없음</span><strong><?= number_format($list['counts']['orphan']) ?></strong></div></section>
  <section class="card yc-operation-card"><div class="card-body"><div class="yc-list-heading"><div><h2>정산 CSV 가져오기</h2><p>PG 자료를 표준 열 이름으로 맞춘 뒤 가져옵니다. 같은 거래 키는 중복 저장되지 않습니다.</p></div>
    <form method="post" action="<?= $this->e($admin_url) ?>/settlements/template"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><button class="btn" type="submit">표준 양식 내려받기</button></form></div>
    <form class="yc-inline-upload" method="post" enctype="multipart/form-data" action="<?= $this->e($admin_url . '/settlements/import?' . http_build_query(['from' => $range['from'], 'to' => $range['to']])) ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input class="file-input file-input-bordered" type="file" name="file" accept=".csv,text/csv" required><button class="btn btn-primary" type="submit">정산 자료 가져오기</button></form>
    <p class="muted"><code>amount</code>는 결제 양수, 환불 음수입니다. <code>payout_amount</code>에는 실제 지급 예정액을 적습니다.</p>
  </div></section>
  <section class="yc-list-panel"><div class="yc-list-heading"><h2>대사 결과 <span><?= number_format($list['total']) ?></span></h2><p>최대 1,000건</p></div><div class="overflow-x-auto"><table class="table yc-settlement-table"><thead><tr><th>상태</th><th>주문 / 결제 키</th><th>결제사</th><th>주문 순액</th><th>정산 거래액</th><th>수수료</th><th>지급액</th><th>매출일 / 지급일</th></tr></thead><tbody>
  <?php $statusLabels = ['matched' => '일치', 'missing' => '정산 없음', 'mismatch' => '불일치', 'orphan' => '주문 없음']; foreach ($list['items'] as $row): ?><tr>
    <td><span class="yc-reconcile-status" data-status="<?= $this->e($row['status']) ?>"><?= $statusLabels[$row['status']] ?></span></td>
    <td><?php if ($row['order_id'] !== null): ?><a href="<?= $this->e($admin_url) ?>/orders/detail?id=<?= (int) $row['order_id'] ?>"><strong><?= $this->e($row['number']) ?></strong></a><?php else: ?><strong><?= $this->e($row['payment_id']) ?></strong><?php endif ?><br><small class="muted"><?= $this->e($row['payment_id']) ?></small></td>
    <td><?= $this->e($providers[$row['provider']] ?? $row['provider']) ?><br><small class="muted"><?= $this->e($row['environment']) ?></small></td><td class="yc-number"><?= number_format($row['expected_amount']) ?>원</td><td class="yc-number"><?= number_format($row['settlement_amount']) ?>원<br><small class="muted"><?= number_format($row['transaction_count']) ?>건</small></td><td class="yc-number"><?= number_format($row['fee_supply'] + $row['fee_vat']) ?>원</td><td class="yc-number"><?= number_format($row['payout_amount']) ?>원</td><td><?= $this->e($row['sold_date'] ?? '—') ?><br><small class="muted"><?= $this->e($row['payout_date'] ?? '—') ?></small></td>
  </tr><?php endforeach ?><?php if ($list['items'] === []): ?><tr><td colspan="8" class="yc-empty">조건에 맞는 대사 자료가 없습니다.</td></tr><?php endif ?></tbody></table></div></section>
  <div class="yc-info-note"><?= $this->icon('info', 16) ?><span>현재는 모든 PG의 자료를 한 형식으로 가져오는 공통 CSV 어댑터를 제공합니다. PG별 API 연동은 해당 결제사의 정산 API 권한과 운영 상점 키를 받은 뒤 같은 어댑터 계약에 추가할 수 있습니다.</span></div>
</div>
<?php $this->stop() ?>
