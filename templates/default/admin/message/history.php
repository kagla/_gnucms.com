<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림톡·문자 · 이력 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>messages<?php $this->stop() ?>
<?php $this->start('admin_body_class') ?>message-history-body<?php $this->stop() ?>
<?php $this->start('body') ?>
<link rel="stylesheet" href="<?= $this->asset('message-history.css') ?>">
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
    // 일부만 나갔다 — 확인된 실패가 있거나, 관리자가 일부를 취소했거나, 둘 다다.
    // "일부 실패"라 부르면 실패 0 · 취소 2 인 줄이 제 배지와 모순된다(JobStatus 참고).
    'partial' => ['label' => '일부만 발송', 'class' => 'badge-warning'],
    // 7일이 지나도 결과를 알아내지 못해 조회를 포기한 건이 남은 작업. 성공이라고도
    // 실패라고도 말하지 않는다 — 실패로 적으면 관리자가 다시 보내 중복 발송이 된다.
    'unknown' => ['label' => '결과를 알 수 없음', 'class' => 'badge-warning'],
    // 관리자가 멈춘 예약. 실패가 아니다 — 나가지 않도록 의도적으로 멈춘 것이다.
    'cancelled' => ['label' => '취소됨', 'class' => 'badge-ghost'],
];
$channelLabels = ['at' => '알림톡', 'sms' => '문자(SMS)', 'lms' => '장문(LMS)'];
// 어느 알림이 이 작업을 만들었는가. 관리자가 발송 화면에서 손으로 보낸 것은 event_key
// 가 비어 있다(설계 문서: 관리자 수동 발송이면 NULL). 카탈로그에서 빠진 옛 키는 라벨을
// 찾을 수 없으므로 키를 그대로 보여준다 — "-" 로 뭉개면 수동 발송과 구별되지 않는다.
$eventLabel = function ($key) use ($event_labels) {
    $key = (string) ($key ?? '');
    if ($key === '') {
        return ['label' => '관리자 수동 발송', 'class' => 'badge-ghost'];
    }

    return ['label' => $event_labels[$key] ?? $key, 'class' => 'badge-info'];
};
$totalPages = (int) ceil($listing['total'] / max(1, $listing['per_page']));
$filterQuery = $event_filter !== '' ? ['event' => $event_filter] : [];
$pageUrl = function (int $p) use ($filterQuery) {
    return $this->url('admin.messages.history', [],
        $p > 1 ? $filterQuery + ['page' => $p] : $filterQuery);
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
          <?php if ($event_filter !== ''): ?><input type="hidden" name="event" value="<?= $this->e($event_filter) ?>"><?php endif ?>
          <button class="btn btn-sm btn-outline" type="submit"><?= $this->icon('restore', 15) ?> 갱신</button>
        </form>
      </div>
    <?php endif ?>

    <?php // 알림 한 건이 작업 하나를 만든다 — 댓글이 활발한 사이트에서는 알림이 일괄
      // 발송을 금세 덮으므로, 운영자가 보러 온 것만 남길 수 있어야 한다. 쿼리에 실리는
      // 것은 열거값 하나뿐이고(빈 값·manual·이벤트 키), 그 밖의 값은 거르지 않은 것으로
      // 본다 — 컨트롤러가 그렇게 받는다. ?>
    <form class="post-filter" method="get" action="<?= $this->url('admin.messages.history') ?>">
      <select class="select select-bordered" name="event" aria-label="알림 유형으로 검색">
        <option value=""<?= $event_filter === '' ? ' selected' : '' ?>>전체</option>
        <option value="manual"<?= $event_filter === 'manual' ? ' selected' : '' ?>>관리자 수동 발송</option>
        <?php foreach ($event_labels as $key => $label): ?>
          <option value="<?= $this->e($key) ?>"<?= $event_filter === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option>
        <?php endforeach ?>
      </select>
      <button class="btn btn-outline" type="submit"><?= $this->icon('search', 15) ?> 검색</button>
    </form>

    <div class="message-history-surface">
      <table class="table table-zebra message-history-table">
        <colgroup><col class="history-col-time"><col class="history-col-event"><col class="history-col-delivery"><col class="history-col-counts"><col class="history-col-status"><col class="history-col-manage"></colgroup>
        <thead><tr><th>요청·예약 시각</th><th>알림</th><th>발송 정보</th><th>발송 건수</th><th>상태</th><th>관리</th></tr></thead>
        <tbody>
        <?php if ($listing['items'] === []): ?>
          <tr class="table-empty"><td colspan="6"><?= $event_filter === '' ? '아직 보낸 작업이 없습니다.' : '검색 조건에 맞는 발송 이력이 없습니다.' ?></td></tr>
        <?php else: foreach ($listing['items'] as $row): ?>
          <?php $statusInfo = $jobStatusLabels[$row['status']] ?? ['label' => (string) $row['status'], 'class' => 'badge-ghost']; $event = $eventLabel($row['event_key'] ?? null); ?>
          <tr>
            <td data-label="요청·예약 시각" class="message-history-time">
              <time datetime="<?= $this->e($row['created_at']) ?>"><?= $this->date($row['created_at']) ?></time>
              <?php if ($row['scheduled_at'] !== null): ?><small>예약 <time datetime="<?= $this->e($row['scheduled_at']) ?>"><?= $this->date($row['scheduled_at']) ?></time></small><?php else: ?><small>즉시 발송</small><?php endif ?>
            </td>
            <td data-label="알림" class="message-history-event"><span class="badge badge-sm <?= $this->e($event['class']) ?> badge-soft"><?= $this->e($event['label']) ?></span></td>
            <td data-label="발송 정보" class="message-history-delivery">
              <strong><?= $this->e($channelLabels[$row['channel']] ?? $row['channel']) ?></strong>
              <?php if ($row['template_label'] !== null): ?><small>템플릿 <?= $this->e($row['template_label']) ?></small><?php endif ?>
              <?php if (($row['provider_cost'] ?? null) !== null): ?>
                <small title="알리고 발송 응답 금액입니다. 환불·취소·대체발송을 반영한 정산 확정액은 아닙니다.">알리고 반환 비용<br><strong><?= $this->e($row['provider_cost']['amount']) ?></strong><?= $row['provider_cost']['missing'] > 0 ? ' (일부 응답)' : '' ?></small>
              <?php endif ?>
            </td>
            <td data-label="발송 건수" class="message-history-counts">
              <div class="message-history-stats">
                <span><span>총</span><strong><?= $this->e($row['total']) ?></strong></span>
                <span><span>성공</span><strong><?= $this->e($row['success']) ?></strong></span>
                <span><span>실패</span><strong><?= $this->e($row['failure']) ?></strong></span>
                <span><span>취소</span><strong><?= $this->e($row['cancelled'] ?? 0) ?></strong></span>
              </div>
            </td>
            <td data-label="상태" class="message-history-status">
              <span class="badge badge-sm <?= $this->e($statusInfo['class']) ?> badge-soft"><?= $this->e($statusInfo['label']) ?></span>
              <?php if ($row['test_mode']): ?><small>테스트</small><?php endif ?>
            </td>
            <td data-label="관리" class="message-history-manage"><a class="btn btn-outline btn-sm" href="<?= $this->url('admin.messages.history.detail', ['id' => $row['id']]) ?>">상세</a></td>
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
