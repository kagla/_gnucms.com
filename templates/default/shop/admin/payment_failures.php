<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>결제 오류 · 쇼핑몰 관리<?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '결제 오류', 'description' => '결제가 거절되었거나 승인 결과 확인이 필요한 요청입니다.', 'actions' => []]) ?>
<?php $this->insert('admin/_nav') ?>
<?php if ($index_remaining > 0): ?><div class="alert alert-info" role="status"><span>이전 결제 기록을 정리하고 있습니다. 아직 <?= number_format($index_remaining) ?>건이 남았습니다.</span><a class="btn btn-sm" href="<?= $this->e($admin_url . '/payment-failures?' . http_build_query(['status' => $filter, 'q' => $q])) ?>">계속 정리</a></div><?php endif ?>
<section class="card"><div class="card-body">
  <div class="yc-panel-heading"><div><h2 class="card-title">결제 요청 내역</h2><p class="muted">총 <?= number_format((int) $list['total']) ?>건 · 주문 접수 전 발생한 결제 오류도 여기에 기록됩니다.</p></div></div>
  <?php if (isset($errors['payment'])): ?><div class="alert alert-error" role="alert"><?= $this->e($errors['payment']) ?></div><?php endif ?>
  <nav class="tabs tabs-border" aria-label="결제 오류 상태">
    <?php foreach (['' => '전체', 'declined' => '결제 거절', 'review' => '결과 확인 필요'] as $key => $label): ?><a class="tab<?= $filter === $key ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url . '/payment-failures?' . http_build_query(['status' => $key, 'q' => $q])) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach ?>
  </nav>
  <form class="yc-filter" method="get" action="<?= $this->e($admin_url) ?>/payment-failures">
    <input type="hidden" name="status" value="<?= $this->e($filter) ?>">
    <label class="yc-filter-query">참조번호 또는 PG 코드<input class="input input-bordered" type="search" name="q" value="<?= $this->e($q) ?>" maxlength="100" placeholder="결제 참조번호 또는 응답 코드"></label>
    <button class="btn btn-primary" type="submit"><?= $this->icon('search', 16) ?> 검색</button>
    <?php if ($q !== ''): ?><a class="btn" href="<?= $this->e($admin_url . '/payment-failures?' . http_build_query(['status' => $filter])) ?>">검색 초기화</a><?php endif ?>
  </form>
  <?php if ($list['items'] === []): ?><div class="yc-admin-empty"><?= $this->icon('check-circle', 30) ?><strong>표시할 결제 오류가 없습니다</strong><p>PG에서 거절되었거나 결과 확인이 필요한 결제가 이곳에 표시됩니다.</p></div>
  <?php else: ?><div class="overflow-x-auto"><table class="table yc-orders-table"><thead><tr><th>발생 시각</th><th>상태</th><th>환경·수단</th><th>금액</th><th>PG 응답</th><th>결제 참조번호</th><th>처리</th></tr></thead><tbody>
    <?php foreach ($list['items'] as $item): $review = $item['status'] !== 'declined'; ?>
    <tr><td data-label="발생 시각"><?= $item['created_at'] > 0 ? $this->e(date('Y.m.d H:i', $item['created_at'])) : '—' ?></td>
      <td data-label="상태"><span class="yc-status" data-status="<?= $review ? 'pending' : 'cancelled' ?>"><?= $review ? '결과 확인 필요' : '결제 거절' ?></span></td>
      <td data-label="환경·수단"><?= $item['environment'] === 'test' ? '테스트' : '운영' ?> · <?= $this->e($payment_methods[$item['method']] ?? $item['method']) ?></td>
      <td data-label="금액"><?= number_format($item['amount']) ?>원</td>
      <td data-label="PG 응답"><?= $item['code'] !== '' ? '코드 ' . $this->e($item['code']) . ' · ' : '' ?><?= $this->e($item['message'] !== '' ? $item['message'] : 'PG 응답을 확인해 주세요.') ?></td>
      <td data-label="결제 참조번호"><code><?= $this->e($item['reference']) ?></code></td><td data-label="처리"><?php if ($item['recoverable']): ?><form method="post" action="<?= $this->e($admin_url) ?>/payment-failures"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="reference" value="<?= $this->e($item['reference']) ?>"><button class="btn btn-sm btn-primary" type="submit">가상계좌 주문 복구</button></form><?php else: ?>—<?php endif ?></td></tr>
    <?php endforeach ?>
  </tbody></table></div>
  <?php $this->insert('_pager', ['page_url' => fn (int $page): string => $admin_url . '/payment-failures?' . http_build_query(['status' => $filter, 'q' => $q, 'page' => $page])]) ?>
  <?php endif ?>
</div></section>
<?php $this->stop() ?>
