<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림톡·문자 · 이력 · 작업 #<?= $this->e($job['id']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>messages<?php $this->stop() ?>
<?php $this->start('body') ?>
<?php
// 목록과 달리 여기서는 전체 번호를 보여준다 — 실제로 누구에게 갔는지 확인해야 할
// 사람은 이 상세 화면까지 들어온 관리자뿐이라고 본다. 라벨은 history.php 와 같은
// 원칙을 쓴다: "결과를 기다리는 중"과 "실패"를 절대 같은 말로 보여주지 않는다.
$jobStatusLabels = [
    // 예약된 채로 아직 발송 시각이 오지 않은 작업.
    'scheduled' => ['label' => '예약됨', 'class' => 'badge-info'],
    'sending' => ['label' => '결과를 기다리는 중', 'class' => 'badge-ghost'],
    'sent'    => ['label' => '성공', 'class' => 'badge-success'],
    'failed'  => ['label' => '실패', 'class' => 'badge-error'],
    // history.php 와 같은 라벨을 쓴다 — 실패만이 아니라 취소로도 "일부만" 이 된다.
    'partial' => ['label' => '일부만 발송', 'class' => 'badge-warning'],
    // 7일이 지나도 결과를 알아내지 못해 조회를 포기한 건이 남은 작업. 성공이라고도
    // 실패라고도 말하지 않는다 — 실패로 적으면 관리자가 다시 보내 중복 발송이 된다.
    'unknown' => ['label' => '결과를 알 수 없음', 'class' => 'badge-warning'],
    // 관리자가 멈춘 예약. 실패가 아니다 — 나가지 않도록 의도적으로 멈춘 것이다.
    'cancelled' => ['label' => '취소됨', 'class' => 'badge-ghost'],
];
$recipientStatusLabels = [
    'queued'   => ['label' => '대기 중', 'class' => 'badge-ghost'],
    'accepted' => ['label' => '결과를 기다리는 중', 'class' => 'badge-info'],
    'sent'     => ['label' => '전송 성공', 'class' => 'badge-success'],
    'failed'   => ['label' => '전송 실패', 'class' => 'badge-error'],
    'unknown'  => ['label' => '결과를 알 수 없음', 'class' => 'badge-warning'],
    // 작업 전체가 취소되기 전, 이 수신자의 묶음(mid)이 실제로 취소된 경우.
    'cancelled' => ['label' => '취소됨', 'class' => 'badge-ghost'],
];
$channelLabels = ['at' => '알림톡', 'sms' => '문자'];
$jobStatus = $jobStatusLabels[$job['status']] ?? ['label' => (string) $job['status'], 'class' => 'badge-ghost'];
$fmtDate = function ($v) {
    return $v !== null ? $this->date($v, 'Y.m.d H:i') : '-';
};
?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">알림톡·문자</li></ul></div>
<?php $this->insert('admin/message/_tabs', ['active' => 'history']) ?>
<section class="card settings-card">
  <div class="card-body">
    <div class="page-head">
      <div>
        <h1 class="card-title"><?= $this->icon('history', 19) ?> 작업 #<?= $this->e($job['id']) ?></h1>
        <p class="card-sub">알리고는 결과를 스스로 알려오지 않습니다 — "결과를 기다리는 중"은 실패가 아니라 아직 확인하지 못했다는 뜻입니다. 이 화면을 다시 열면 조금씩 더 확인됩니다.</p>
      </div>
      <div class="row-actions">
        <?php if ($job['status'] === 'scheduled'): ?>
          <form method="post" action="<?= $this->url('admin.messages.history.cancel', ['id' => $job['id']]) ?>">
            <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
            <button class="btn btn-error btn-outline btn-sm" type="submit"
              onclick="return confirm('예약을 취소할까요? 발송 시각이 임박한 일부는 취소되지 않을 수 있습니다.');">
              <?= $this->icon('warning', 15) ?> 예약 취소
            </button>
          </form>
        <?php endif ?>
        <a class="btn btn-outline btn-sm" href="<?= $this->url('admin.messages.history') ?>"><?= $this->icon('arrow-left', 15) ?> 목록으로</a>
      </div>
    </div>

    <?php if ($notice !== null): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span><?= $this->e($notice) ?></span></div><?php endif ?>
    <?php if ($cancel_notice !== null): ?>
      <div class="alert <?= $cancel_notice['ok'] ? 'alert-success' : 'alert-warning' ?>">
        <span aria-hidden="true"><?= $this->icon($cancel_notice['ok'] ? 'check-circle' : 'warning', 18) ?></span>
        <span><?= $this->e($cancel_notice['message']) ?></span>
      </div>
    <?php endif ?>

    <div class="status-badges">
      <span class="badge <?= $this->e($jobStatus['class']) ?> badge-soft"><?= $this->e($jobStatus['label']) ?></span>
      <span class="badge badge-ghost badge-soft"><?= $this->e($channelLabels[$job['channel']] ?? $job['channel']) ?></span>
      <?php if ((int) $job['test_mode'] === 1): ?><span class="badge badge-warning badge-soft">테스트 모드</span><?php endif ?>
      <?php if ((int) $job['failover'] === 1): ?><span class="badge badge-ghost badge-soft">대체발송 켜짐</span><?php endif ?>
    </div>

    <dl class="schema-facts">
      <div><dt>요청 시각</dt><dd><?= $fmtDate($job['created_at']) ?></dd></div>
      <div><dt>발송 예정</dt><dd><?= $fmtDate($job['scheduled_at']) ?></dd></div>
      <div><dt>종료 시각</dt><dd><?= $fmtDate($job['finished_at']) ?></dd></div>
      <div><dt>발신번호</dt><dd><?= $this->e($job['sender_display']) ?></dd></div>
      <?php // 어느 알림이 이 작업을 만들었는가. 비어 있으면 관리자가 손으로 보낸 것이다. ?>
      <div><dt>보낸 알림</dt><dd><?php $eventKey = (string) ($job['event_key'] ?? ''); ?><?= $eventKey === '' ? '관리자 수동 발송' : $this->e($event_labels[$eventKey] ?? $eventKey) ?></dd></div>
      <div><dt>템플릿</dt><dd><?= $job['template_label'] !== null ? $this->e($job['template_label']) : '-' ?></dd></div>
      <div><dt>총 · 성공 · 실패 · 취소</dt><dd><?= $this->e($job['total']) ?> · <?= $this->e($job['success']) ?> · <?= $this->e($job['failure']) ?> · <?= $this->e($job['cancelled']) ?></dd></div>
      <?php if ((string) $job['created_by'] !== ''): ?><div><dt>보낸 사람</dt><dd><?= $this->e($job['created_by']) ?></dd></div><?php endif ?>
    </dl>

    <p class="card-sub">본문</p>
    <pre class="tpl-detail-content"><?= $this->e($job['body']) ?></pre>

    <div class="table-wrap">
      <table class="table table-zebra">
        <thead><tr><th>이름</th><th>번호</th><th>상태</th><th>사유</th><th>요청 시각</th><th>전송 시각</th><th>결과 시각</th><th>대체발송</th></tr></thead>
        <tbody>
        <?php if ($job['recipients'] === []): ?>
          <tr class="table-empty"><td colspan="8">수신자가 없습니다.</td></tr>
        <?php else: foreach ($job['recipients'] as $r): ?>
          <?php
            $rStatus = $recipientStatusLabels[$r['status']] ?? ['label' => (string) $r['status'], 'class' => 'badge-ghost'];
            $fallback = $r['fallback_status'] !== null ? ($recipientStatusLabels[$r['fallback_status']] ?? ['label' => (string) $r['fallback_status'], 'class' => 'badge-ghost']) : null;
          ?>
          <tr>
            <td data-label="이름"><?= $r['name'] !== null && $r['name'] !== '' ? $this->e($r['name']) : '-' ?></td>
            <td data-label="번호"><?= $this->e($r['phone_display']) ?></td>
            <td data-label="상태"><span class="badge badge-sm <?= $this->e($rStatus['class']) ?> badge-soft"><?= $this->e($rStatus['label']) ?></span></td>
            <td data-label="사유"><?= $r['rslt_message'] !== null && $r['rslt_message'] !== '' ? $this->e($r['rslt_message']) : '-' ?></td>
            <td data-label="요청 시각"><?= $fmtDate($r['requested_at']) ?></td>
            <td data-label="전송 시각"><?= $fmtDate($r['sent_at']) ?></td>
            <td data-label="결과 시각"><?= $fmtDate($r['result_at']) ?></td>
            <td data-label="대체발송"><?php if ($fallback !== null): ?><span class="badge badge-sm <?= $this->e($fallback['class']) ?> badge-soft">문자로 <?= $this->e($fallback['label']) ?></span><?php else: ?>-<?php endif ?></td>
          </tr>
        <?php endforeach; endif ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php $this->stop() ?>
