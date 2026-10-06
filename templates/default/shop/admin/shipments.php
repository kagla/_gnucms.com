<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>배송 작업 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '배송 작업', 'description' => '상품 준비 주문을 모아 피킹·포장하고 운송장을 일괄 등록합니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<div class="yc-admin">
  <form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/shipments">
    <label class="yc-filter-query">주문 검색<input class="input input-bordered" type="search" name="q" value="<?= $this->e($q) ?>" maxlength="100" placeholder="주문번호, 주문자, 받는 분, 상품명"></label>
    <button class="btn btn-primary" type="submit"><?= $this->icon('search', 16) ?> 검색</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= $this->e($admin_url) ?>/shipments">검색 초기화</a><?php endif ?>
  </form>
  <div class="yc-info-note"><?= $this->icon('info', 16) ?><span>피킹·포장 인쇄 또는 CSV를 내려받은 뒤 택배사와 운송장번호를 채워 올리면, 검증된 주문 전체가 한 번에 배송 중으로 바뀝니다.</span></div>
  <form method="post" action="<?= $this->e($admin_url) ?>/shipments/export">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
    <section class="yc-list-panel"><div class="yc-list-heading"><h2>상품 준비 <span><?= number_format($list['total']) ?></span></h2><p>먼저 접수된 주문순</p></div>
      <div class="overflow-x-auto"><table class="table yc-fulfillment-table"><thead><tr><th><input class="checkbox checkbox-sm" type="checkbox" data-yc-check-all aria-label="현재 페이지 전체 선택"></th><th>주문번호 / 결제일</th><th>받는 분</th><th>상품</th><th>배송지</th><th>금액</th></tr></thead><tbody>
      <?php foreach ($list['items'] as $order): ?><tr>
        <td><input class="checkbox checkbox-sm" type="checkbox" name="ids[]" value="<?= (int) $order['id'] ?>" aria-label="<?= $this->e($order['number']) ?> 선택"></td>
        <td><a href="<?= $this->e($admin_url) ?>/orders/detail?id=<?= (int) $order['id'] ?>"><strong><?= $this->e($order['number']) ?></strong></a><br><small class="muted"><?= $this->date((int) $order['paid_at']) ?></small></td>
        <td><strong><?= $this->e($order['recipient']) ?></strong><br><small class="muted"><?= $this->e($order['recipient_phone']) ?></small></td>
        <td><?php foreach ($order['item_summary'] as $summary): ?><div><?= $this->e($summary) ?></div><?php endforeach ?></td>
        <td><small>[<?= $this->e($order['postcode']) ?>] <?= $this->e($order['address'] . ' ' . $order['address_detail']) ?></small></td>
        <td class="yc-number"><?= number_format((int) $order['total']) ?>원</td>
      </tr><?php endforeach ?>
      <?php if ($list['items'] === []): ?><tr><td colspan="6" class="yc-empty"><div class="yc-admin-empty">배송을 준비할 주문이 없습니다.</div></td></tr><?php endif ?>
      </tbody></table></div>
      <div class="yc-list-actions"><p>한 번에 최대 500건을 처리할 수 있습니다.</p><div class="row-actions"><button class="btn" type="submit" formaction="<?= $this->e($admin_url) ?>/shipments/print" formtarget="_blank" data-yc-requires-selection>피킹·포장 인쇄</button><button class="btn btn-primary" type="submit" data-yc-requires-selection>택배 CSV 내려받기</button></div></div>
    </section>
  </form>
  <?php $this->insert('_pager', ['page_url' => fn(int $p): string => $admin_url . '/shipments?' . http_build_query(['q' => $q, 'page' => $p])]) ?>
  <section class="card yc-operation-card"><div class="card-body"><h2 class="card-title">운송장 일괄 등록</h2>
    <p class="muted">내려받은 CSV의 택배사와 운송장번호를 입력한 뒤 UTF-8 CSV로 저장해 올리세요. 모든 행을 먼저 검사하므로 일부 주문만 바뀌지 않습니다.</p>
    <form class="yc-inline-upload" method="post" enctype="multipart/form-data" action="<?= $this->e($admin_url) ?>/shipments/import">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input class="file-input file-input-bordered" type="file" name="file" accept=".csv,text/csv" required><button class="btn btn-primary" type="submit">운송장 등록</button>
    </form>
  </div></section>
</div>
<?php $this->stop() ?>
