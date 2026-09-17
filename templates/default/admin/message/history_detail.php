<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림톡·문자 · 이력 · 작업 #<?= $this->e($job['id']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>messages<?php $this->stop() ?>
<?php $this->start('body') ?>
<?php
// 목록과 달리 여기서는 전체 번호를 보여준다 — 실제로 누구에게 갔는지 확인해야 할
// 사람은 이 상세 화면까지 들어온 관리자뿐이라고 본다. 라벨은 history.php 와 같은
// 원칙을 쓴다: "결과를 기다리는 중"과 "실패"를 절대 같은 말로 보여주지 않는다.
$jobStatusLabels = [
    'sending' => ['label' => '결과를 기다리는 중', 'class' => 'badge-ghost'],
    'sent'    => ['label' => '성공', 'class' => 'badge-success'],
    'failed'  => ['label' => '실패', 'class' => 'badge-error'],
    'partial' => ['label' => '일부 실패', 'class' => 'badge-warning'],
];
$recipientStatusLabels = [
    'queued'   => ['label' => '대기 중', 'class' => 'badge-ghost'],
    'accepted' => ['label' => '결과를 기다리는 중', 'class' => 'badge-info'],
    'sent'     => ['label' => '전송 성공', 'class' => 'badge-success'],
    'failed'   => ['label' => '전송 실패', 'class' => 'badge-error'],
    'unknown'  => ['label' => '결과를 알 수 없음', 'class' => 'badge-warning'],
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
      <a class="btn btn-outline btn-sm" href="<?= $this->url('admin.messages.history') ?>"><?= $this->icon('arrow-left', 15) ?> 목록으로</a>
    </div>

    <?php if ($notice !== null): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span><?= $this->e($notice) ?></span></div><?php endif ?>

    <div class="status-badges">
      <span class="badge <?= $this->e($jobStatus['class']) ?> badge-soft"><?= $this->e($jobStatus['label']) ?></span>
      <span class="badge badge-ghost badge-soft"><?= $this->e($channelLabels[$job['channel']] ?? $job['channel']) ?></span>
      <?php if ((int) $job['test_mode'] === 1): ?><span class="badge badge-warning badge-soft">테스트 모드</span><?php endif ?>
      <?php if ((int) $job['failover'] === 1): ?><span class="badge badge-ghost badge-soft">대체발송 켜짐</span><?php endif ?>
    </div>

    <dl class="schema-facts">
      <div><dt>요청 시각</dt><dd><?= $fmtDate($job['created_at']) ?></dd></div>
      <div><dt>종료 시각</dt><dd><?= $fmtDate($job['finished_at']) ?></dd></div>
      <div><dt>발신번호</dt><dd><?= $this->e($job['sender_display']) ?></dd></div>
      <div><dt>템플릿</dt><dd><?= $job['template_label'] !== null ? $this->e($job['template_label']) : '-' ?></dd></div>
      <div><dt>총 · 성공 · 실패</dt><dd><?= $this->e($job['total']) ?> · <?= $this->e($job['success']) ?> · <?= $this->e($job['failure']) ?></dd></div>
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
