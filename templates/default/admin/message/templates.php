<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림톡·문자 · 템플릿 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>messages<?php $this->stop() ?>
<?php $this->start('body') ?>
<?php
// 알리고 코드를 관리자가 읽을 수 있는 말로 바꾼다. GNUCMS 는 여기서 템플릿을 만들거나
// 고치지 않으므로, 이 표는 카카오가 이미 심사·승인한 결과를 그대로 옮겨 보여줄 뿐이다.
$status_labels = ['A' => '정상', 'S' => '중단', 'R' => '대기', 'M' => '목록 없음'];
$insp_labels = ['REG' => '등록', 'REQ' => '심사요청', 'APR' => '승인', 'REJ' => '반려'];
$type_labels = ['BA' => '기본형', 'EX' => '부가정보형', 'AD' => '광고추가형', 'MI' => '복합형'];
?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">알림톡·문자</li></ul></div>
<?php $this->insert('admin/message/_tabs', ['active' => 'templates']) ?>
<section class="card settings-card">
  <div class="card-body">
    <div class="page-head">
      <div><h1 class="card-title"><?= $this->icon('bell', 19) ?> 템플릿</h1><p class="card-sub">알리고 템플릿의 본문·버튼과 카카오 심사 상태를 확인합니다. 승인된 템플릿은 발송 화면에서 받는 사람과 변수값을 입력해 보낼 수 있습니다.</p></div>
      <form method="post" action="<?= $this->url('admin.messages.templates.fetch') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <button class="btn btn-primary" type="submit"><?= $this->icon('search', 15) ?> 가져오기</button>
      </form>
    </div>

    <?php // aligo_settings.php 와 같은 규칙 — 부분 취소 안내는 성공이 아니라 주의로 보여준다. ?>
    <?php if ($notice !== null): ?><div class="alert <?= $notice['ok'] ? 'alert-success' : 'alert-warning' ?>"><span aria-hidden="true"><?= $this->icon($notice['ok'] ? 'check-circle' : 'warning', 18) ?></span><span><?= $this->e($notice['message']) ?></span></div><?php endif ?>
    <?php if ($error !== null): ?><div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($error) ?></span></div><?php endif ?>

    <div class="table-wrap">
      <table class="table table-zebra">
        <thead><tr><th>코드</th><th>이름</th><th>유형</th><th>상태</th><th>카카오 심사</th><th class="right">관리</th></tr></thead>
        <tbody>
        <?php if ($copies === []): ?>
          <tr class="table-empty"><td colspan="6">아직 가져온 템플릿이 없습니다. "가져오기"를 눌러 알리고 템플릿을 불러오세요.</td></tr>
        <?php else: foreach ($copies as $row): ?>
          <?php
            $canSend = in_array((string) $row['tpl_code'], $usable_codes, true);
            $sendUrl = $this->url('admin.messages.send') . '?' . http_build_query(['tpl_code' => $row['tpl_code']]);
          ?>
          <tr>
            <td data-label="코드"><code><?= $this->e($row['tpl_code']) ?></code></td>
            <td data-label="이름"><?= $this->e($row['name']) ?></td>
            <td data-label="유형"><span class="badge badge-ghost badge-sm"><?= $this->e($type_labels[$row['template_type']] ?? $row['template_type']) ?></span></td>
            <td data-label="상태"><span class="badge badge-sm <?= $row['status'] === 'A' ? 'badge-success' : ($row['status'] === 'S' ? 'badge-error' : 'badge-ghost') ?> badge-soft"><?= $this->e($status_labels[$row['status']] ?? $row['status']) ?></span></td>
            <td data-label="카카오 심사"><span class="badge badge-sm <?= $row['insp_status'] === 'APR' ? 'badge-success' : ($row['insp_status'] === 'REJ' ? 'badge-error' : 'badge-ghost') ?> badge-soft"><?= $this->e($insp_labels[$row['insp_status']] ?? $row['insp_status']) ?></span></td>
            <td data-label="관리" class="right">
              <div class="row-actions">
                <button class="btn btn-outline btn-sm" type="button" data-detail
                  data-code="<?= $this->e($row['tpl_code']) ?>"
                  data-name="<?= $this->e($row['name']) ?>"
                  data-content="<?= $this->e($row['content']) ?>"
                  data-buttons="<?= $this->e($row['buttons']) ?>"
                  data-send-url="<?= $canSend ? $this->e($sendUrl) : '' ?>">본문·버튼 보기</button>
                <?php if ($canSend): ?>
                  <a class="btn btn-primary btn-sm" href="<?= $this->e($sendUrl) ?>">발송 화면</a>
                <?php else: ?>
                  <span class="badge badge-ghost badge-sm">발송 불가</span>
                <?php endif ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<dialog class="modal" id="template-detail-modal" aria-labelledby="template-detail-title">
  <div class="modal-box">
    <h3 id="template-detail-title"></h3>
    <p class="card-sub">본문</p>
    <pre class="tpl-detail-content" data-detail-content></pre>
    <p class="card-sub">버튼</p>
    <ul class="tpl-detail-buttons" data-detail-buttons></ul>
    <div class="modal-action"><a class="btn btn-primary" data-detail-send hidden>발송 화면</a><form method="dialog"><button class="btn btn-ghost">닫기</button></form></div>
  </div>
  <form method="dialog" class="modal-backdrop"><button aria-label="닫기">닫기</button></form>
</dialog>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script>
(function () {
  var modal = document.getElementById('template-detail-modal');
  if (!modal || typeof modal.showModal !== 'function') { return; }
  var title = document.getElementById('template-detail-title');
  var content = modal.querySelector('[data-detail-content]');
  var buttonsList = modal.querySelector('[data-detail-buttons]');
  var sendLink = modal.querySelector('[data-detail-send]');
  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-detail]');
    if (!btn) { return; }
    title.textContent = (btn.dataset.name || '') + ' (' + (btn.dataset.code || '') + ')';
    content.textContent = btn.dataset.content || '';
    buttonsList.innerHTML = '';
    var buttons = [];
    try { buttons = JSON.parse(btn.dataset.buttons || '[]'); } catch (err) { buttons = []; }
    if (!Array.isArray(buttons) || buttons.length === 0) {
      var empty = document.createElement('li');
      empty.textContent = '버튼이 없습니다.';
      buttonsList.appendChild(empty);
    } else {
      buttons.forEach(function (button) {
        var li = document.createElement('li');
        li.textContent = (button.name || '') + ' (' + (button.linkType || '') + ')';
        buttonsList.appendChild(li);
      });
    }
    sendLink.hidden = !btn.dataset.sendUrl;
    if (btn.dataset.sendUrl) sendLink.href = btn.dataset.sendUrl;
    else sendLink.removeAttribute('href');
    modal.showModal();
  });
})();
</script>
<?php $this->stop() ?>
