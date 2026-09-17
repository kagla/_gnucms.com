<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림톡·문자 · 이력 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>messages<?php $this->stop() ?>
<?php $this->start('body') ?>
<?php
// 알리고는 결과 웹훅이 없다 — 관리자가 이 화면을 열 때마다 컨트롤러가 조금씩 결과를
// 물어 채운다. 그래서 "sending" 은 "전송 중"이 아니라 "결과를 기다리는 중"이라고
// 적는다 — 실제로 보내는 중이 아니라, 단지 아직 결과를 모를 뿐이다. 그 조회가 계속
// 실패하고 있으면(키 취소 등) 사유를 대기 안내 위에 그대로 적는다 — 이유 없이 결과가
// "결과를 알 수 없음"으로 바뀌는 것만 보이면 관리자가 손쓸 방법이 없다.
$jobStatusLabels = [
    // 예약된 채로 아직 발송 시각이 오지 않은 작업. scheduled_at 열에 그 시각을 보여준다.
    'scheduled' => ['label' => '예약됨', 'class' => 'badge-info'],
    'sending' => ['label' => '결과를 기다리는 중', 'class' => 'badge-ghost'],
    'sent'    => ['label' => '성공', 'class' => 'badge-success'],
    'failed'  => ['label' => '실패', 'class' => 'badge-error'],
    'partial' => ['label' => '일부 실패', 'class' => 'badge-warning'],
    // 7일이 지나도 결과를 알아내지 못해 조회를 포기한 건이 남은 작업. 성공이라고도
    // 실패라고도 말하지 않는다 — 실패로 적으면 관리자가 다시 보내 중복 발송이 된다.
    'unknown' => ['label' => '결과를 알 수 없음', 'class' => 'badge-warning'],
    // 관리자가 멈춘 예약. 실패가 아니다 — 나가지 않도록 의도적으로 멈춘 것이다.
    'cancelled' => ['label' => '취소됨', 'class' => 'badge-ghost'],
];
$channelLabels = ['at' => '알림톡', 'sms' => '문자'];
$totalPages = (int) ceil($listing['total'] / max(1, $listing['per_page']));
$pageUrl = function (int $p) {
    return $this->url('admin.messages.history', [], $p > 1 ? ['page' => $p] : []);
};
?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">알림톡·문자</li></ul></div>
<?php $this->insert('admin/message/_tabs', ['active' => 'history']) ?>
<section class="card settings-card">
  <div class="card-body">
    <div class="page-head">
      <div><h1 class="card-title"><?= $this->icon('history', 19) ?> 이력</h1><p class="card-sub">보낸 작업과 그 결과입니다. 알리고는 결과를 스스로 알려오지 않으므로, 이 화면을 열 때마다 조금씩 물어 채웁니다 — 접속이 없으면 결과 확인도 늦어집니다.</p></div>
    </div>

    <?php if ($refresh_error !== null): ?>
      <div class="alert alert-warning">
        <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
        <span>결과를 물어보다 실패했습니다: <?= $this->e($refresh_error) ?> — 저장된 결과는 그대로 보여줍니다. 계속 실패하면 설정 → 알림톡·문자에서 계정과 API 키를 확인하세요.</span>
      </div>
    <?php endif ?>

    <?php if ($pending > 0): ?>
      <div class="alert alert-info">
        <span aria-hidden="true"><?= $this->icon('info', 18) ?></span>
        <span>아직 결과를 기다리는 중인 발송이 <?= $this->e($pending) ?>건 있습니다. 접속이 없으면 갱신이 늦어질 수 있습니다.</span>
        <form method="post" action="<?= $this->url('admin.messages.history.refresh') ?>">
          <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
          <?php if (($listing['page'] ?? 1) > 1): ?><input type="hidden" name="page" value="<?= $this->e($listing['page']) ?>"><?php endif ?>
          <button class="btn btn-sm btn-outline" type="submit"><?= $this->icon('restore', 15) ?> 갱신</button>
        </form>
      </div>
    <?php endif ?>

    <div class="table-wrap">
      <table class="table table-zebra">
        <thead><tr><th>요청 시각</th><th>발송 예정</th><th>채널</th><th>템플릿</th><th class="right">총</th><th class="right">성공</th><th class="right">실패</th><th>상태</th><th>테스트</th><th class="right">관리</th></tr></thead>
        <tbody>
        <?php if ($listing['items'] === []): ?>
          <tr class="table-empty"><td colspan="10">아직 보낸 작업이 없습니다.</td></tr>
        <?php else: foreach ($listing['items'] as $row): ?>
          <?php $statusInfo = $jobStatusLabels[$row['status']] ?? ['label' => (string) $row['status'], 'class' => 'badge-ghost']; ?>
          <tr>
            <td data-label="요청 시각"><time datetime="<?= $this->e($row['created_at']) ?>"><?= $this->date($row['created_at'], 'Y.m.d H:i') ?></time></td>
            <td data-label="발송 예정"><?php if ($row['scheduled_at'] !== null): ?><time datetime="<?= $this->e($row['scheduled_at']) ?>"><?= $this->date($row['scheduled_at'], 'Y.m.d H:i') ?></time><?php else: ?>-<?php endif ?></td>
            <td data-label="채널"><?= $this->e($channelLabels[$row['channel']] ?? $row['channel']) ?></td>
            <td data-label="템플릿"><?= $row['template_label'] !== null ? $this->e($row['template_label']) : '-' ?></td>
            <td data-label="총" class="right"><?= $this->e($row['total']) ?></td>
            <td data-label="성공" class="right"><?= $this->e($row['success']) ?></td>
            <td data-label="실패" class="right"><?= $this->e($row['failure']) ?></td>
            <td data-label="상태"><span class="badge badge-sm <?= $this->e($statusInfo['class']) ?> badge-soft"><?= $this->e($statusInfo['label']) ?></span></td>
            <td data-label="테스트"><?php if ((int) $row['test_mode'] === 1): ?><span class="badge badge-sm badge-warning badge-soft">테스트</span><?php else: ?>-<?php endif ?></td>
            <td data-label="관리" class="right"><a class="btn btn-outline btn-sm" href="<?= $this->url('admin.messages.history.detail', ['id' => $row['id']]) ?>">상세</a></td>
          </tr>
        <?php endforeach; endif ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <nav class="pager" aria-label="페이지 이동">
        <div class="join">
          <?php if ($listing['page'] > 1): ?><a class="join-item btn btn-sm" rel="prev" href="<?= $pageUrl($listing['page'] - 1) ?>" aria-label="이전 페이지"><?= $this->icon('chevron-left', 15) ?></a><?php endif ?>
          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p === $listing['page']): ?>
              <span class="join-item btn btn-sm btn-active" aria-current="page"><?= $this->e($p) ?></span>
            <?php else: ?>
              <a class="join-item btn btn-sm" href="<?= $pageUrl($p) ?>" aria-label="<?= $this->e($p) ?> 페이지"><?= $this->e($p) ?></a>
            <?php endif ?>
          <?php endfor ?>
          <?php if ($listing['page'] < $totalPages): ?><a class="join-item btn btn-sm" rel="next" href="<?= $pageUrl($listing['page'] + 1) ?>" aria-label="다음 페이지"><?= $this->icon('chevron-right', 15) ?></a><?php endif ?>
        </div>
      </nav>
    <?php endif ?>
  </div>
</section>
<?php $this->stop() ?>
