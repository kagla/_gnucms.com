<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<?php
// 이 화면의 규칙은 하나다: **켤 수 없는 칸은 켤 수 없다고 적고, 켜 두었는데 나가지 않는
// 칸은 왜 나가지 않는지 적는다.** 꺼진 것처럼만 그려 두면 관리자는 자기가 켠 채널이
// 이유 없이 스스로 꺼진 것을 보게 된다. 문장은 전부 서버가 만든다 — 쿼리에 실려 온
// 값을 그대로 찍는 자리는 이 화면에 없다(저장 안내도 이벤트 키만 받아 컨트롤러가
// 라벨을 찾아 문장을 만든다).
$channelLabels = ['mail' => '메일', 'alimtalk' => '알림톡', 'sms' => '문자', 'inbox' => '알림함'];
// 아래 두 문장은 NotifySettings::save() 가 거절할 때 내는 말과 같은 뜻이어야 한다 —
// 화면이 말하는 이유와 저장이 말하는 이유가 다르면 둘 중 하나는 거짓이다.
$phoneReason = '이 알림은 이메일로만 보낼 수 있습니다. 받는 사람이 이메일로만 확인되기 때문입니다.';
$inboxReason = '이 알림은 사이트 안 알림함에 쌓을 수 없습니다. 알림함은 로그인한 회원이 읽는 곳이라'
    . ' 지금은 새 댓글·답글 알림만 받습니다.';
$noTemplates = $templates === [];
?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">알림</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'notify']) ?>
<section class="card settings-card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('bell', 19) ?> 알림 설정</h1>
    <p class="card-sub">코어가 보내는 알림마다 어느 채널로 내보낼지 고릅니다. 알림 하나가 여러 채널로 동시에 나갈 수 있고, 채널 하나가 실패해도 나머지는 그대로 나갑니다. 묶음마다 저장 버튼이 따로 있습니다 — 한 묶음을 저장해도 다른 묶음은 손대지 않습니다.</p>

    <?php if ($notice !== null): ?>
      <div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span><?= $this->e($notice) ?></span></div>
    <?php endif ?>

    <?php // 알리고가 없거나 채널 스위치가 꺼져 있으면 여기서 무엇을 켜든 전화로는 나가지 않는다. ?>
    <?php if (!$status['configured']): ?>
      <div class="alert alert-warning">
        <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
        <span>알리고 계정이 연결되어 있지 않습니다. 아래에서 알림톡·문자를 켜 두어도 실제로는 나가지 않습니다 — <a href="<?= $this->url('admin.aligo') ?>">설정 → 알림톡·문자</a>에서 계정을 먼저 연결해 주세요. 메일과 알림함은 이 설정과 무관하게 그대로 나갑니다.</span>
      </div>
    <?php elseif (!$status['alimtalk_enabled'] || !$status['sms_enabled']): ?>
      <div class="alert alert-warning">
        <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
        <span>
          <?php if (!$status['alimtalk_enabled'] && !$status['sms_enabled']): ?>
            알림톡 발송과 문자 발송이 모두 꺼져 있습니다.
          <?php elseif (!$status['alimtalk_enabled']): ?>
            알림톡 발송이 꺼져 있습니다.
          <?php else: ?>
            문자 발송이 꺼져 있습니다.
          <?php endif ?>
          아래에서 켜 두어도 그 채널로는 나가지 않습니다 — <a href="<?= $this->url('admin.aligo') ?>">설정 → 알림톡·문자</a>의 "채널별 발송 허용"에서 켜 주세요.
        </span>
      </div>
    <?php endif ?>
    <?php if ($status['test_mode']): ?>
      <div class="alert alert-info"><span aria-hidden="true"><?= $this->icon('info', 18) ?></span><span>알리고가 테스트 모드입니다. 알림톡·문자는 이력에만 남고 실제 전화기로는 가지 않습니다.</span></div>
    <?php endif ?>
    <?php if ($noTemplates): ?>
      <div class="alert alert-info">
        <span aria-hidden="true"><?= $this->icon('info', 18) ?></span>
        <span>쓸 수 있는 알림톡 승인 템플릿이 없어 알림톡 칸을 켤 수 없습니다. <a href="<?= $this->url('admin.messages.templates') ?>">운영 → 알림톡·문자 → 템플릿</a>에서 먼저 가져와 사용으로 바꿔 주세요.</span>
      </div>
    <?php endif ?>

    <?php foreach ($events as $ev): ?>
      <?php
        $isOpen = $open === $ev['key'];
        $rowErrors = $isOpen ? $errors : [];
        // 전화로 보낼 수 없는 알림은 그 두 칸을 아예 켤 수 없다. 쓸 수 있는 템플릿이
        // 하나도 없을 때도 알림톡은 켤 수 없다 — 다만 **이미 켜 둔 경우에는 잠그지
        // 않는다**: 잠그면 그 체크가 제출되지 않아, 관리자가 다른 칸만 고쳐 저장하는
        // 순간 켜 두었다는 사실이 조용히 지워진다. 그 경우엔 저장이 시끄럽게 거절한다.
        $alimtalkLocked = !$ev['phone'] || ($noTemplates && !$ev['on']['alimtalk']);
        $onNow = array_values(array_filter(array_keys($channelLabels),
            static fn (string $c): bool => $ev['on'][$c]));
      ?>
      <details class="notify-event"<?= $isOpen ? ' open' : '' ?>>
        <summary>
          <span class="notify-event-name"><?= $this->e($ev['label']) ?></span>
          <?php if ($onNow === []): ?>
            <span class="badge badge-sm badge-ghost badge-soft">보내지 않음</span>
          <?php else: foreach ($onNow as $c): ?>
            <span class="badge badge-sm badge-success badge-soft"><?= $this->e($channelLabels[$c]) ?></span>
          <?php endforeach; endif ?>
          <?php if ($ev['alimtalk_notice'] !== null): ?><span class="badge badge-sm badge-warning badge-soft">알림톡이 나가지 않음</span><?php endif ?>
        </summary>

        <form method="post" action="<?= $this->url('admin.settings.notifications.save') ?>">
          <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
          <input type="hidden" name="event" value="<?= $this->e($ev['key']) ?>">

          <?php if ($rowErrors !== []): ?>
            <div class="alert alert-error">
              <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
              <span>저장하지 못했습니다. 아래 표시한 곳을 고쳐 주세요.</span>
            </div>
          <?php endif ?>
          <?php if (array_key_exists('event', $rowErrors)): ?>
            <p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['event']) ?></p>
          <?php endif ?>

          <?php if ($ev['alimtalk_notice'] !== null): ?>
            <div class="alert alert-warning">
              <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
              <span><?= $this->e($ev['alimtalk_notice']) ?></span>
            </div>
          <?php endif ?>

          <div class="form-section">
            <h2 class="form-section-title">보낼 채널</h2>
            <div class="toggle-list">
              <label class="label toggle-row">
                <input type="checkbox" name="mail" value="1"<?= $ev['on']['mail'] ? ' checked' : '' ?>>
                <span>메일</span>
              </label>
              <?php if (array_key_exists('mail', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['mail']) ?></p><?php endif ?>

              <label class="label toggle-row">
                <input type="checkbox" name="alimtalk" value="1"<?= $ev['on']['alimtalk'] ? ' checked' : '' ?><?= $alimtalkLocked ? ' disabled' : '' ?>>
                <span>알림톡</span>
              </label>
              <?php if (!$ev['phone']): ?>
                <p class="fieldset-label"><?= $this->e($phoneReason) ?></p>
              <?php elseif ($alimtalkLocked): ?>
                <p class="fieldset-label">쓸 수 있는 승인 템플릿이 없어 켤 수 없습니다.</p>
              <?php endif ?>
              <?php if (array_key_exists('alimtalk', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['alimtalk']) ?></p><?php endif ?>

              <label class="label toggle-row">
                <input type="checkbox" name="sms" value="1"<?= $ev['on']['sms'] ? ' checked' : '' ?><?= $ev['phone'] ? '' : ' disabled' ?>>
                <span>문자</span>
              </label>
              <?php if (!$ev['phone']): ?><p class="fieldset-label"><?= $this->e($phoneReason) ?></p><?php endif ?>
              <?php if (array_key_exists('sms', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['sms']) ?></p><?php endif ?>

              <?php // 켤 수 없는 칸을 멀쩡히 보여 주고 저장할 때만 거절하는 것은 같은 결함의 다른 모습이다. ?>
              <label class="label toggle-row">
                <input type="checkbox" name="inbox" value="1"<?= $ev['on']['inbox'] ? ' checked' : '' ?><?= $ev['inbox_capable'] ? '' : ' disabled' ?>>
                <span>사이트 안 알림함</span>
              </label>
              <?php if (!$ev['inbox_capable']): ?><p class="fieldset-label"><?= $this->e($inboxReason) ?></p><?php endif ?>
              <?php if (array_key_exists('inbox', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['inbox']) ?></p><?php endif ?>
            </div>
            <?php if ($ev['key'] === 'comment_new'): ?>
              <p class="fieldset-label">활동이 많은 사이트는 발송량이 빠르게 늘 수 있습니다. 댓글 한 건이 글쓴이와 부모 댓글 작성자에게 각각 한 통씩, 최대 두 통이 됩니다.</p>
            <?php endif ?>
          </div>

          <?php if ($ev['phone']): ?>
            <div class="form-section">
              <h2 class="form-section-title">문자 본문</h2>
              <?php if ($ev['sms_notice'] !== null): ?>
                <p class="fieldset-label"><?= $this->e($ev['sms_notice']) ?></p>
              <?php endif ?>
              <fieldset class="fieldset<?php if (array_key_exists('sms_body', $rowErrors)): ?> is-invalid<?php endif ?>">
                <legend class="fieldset-legend">본문</legend>
                <?php // maxlength 는 글자를 센다. 실제 한계는 EUC-KR 바이트라 한글은 한 자에
                  // 두 바이트다 — 그래서 이 속성은 한계가 아니라 붙여넣기 상한일 뿐이고,
                  // 진짜 판정은 저장할 때 MessageText 가 한다. 아래 줄이 그 숫자를 미리 보여준다. ?>
                <textarea class="textarea textarea-bordered input-block" name="sms_body" rows="4" maxlength="2000"><?= $this->e($ev['sms_body']) ?></textarea>
                <?php if (array_key_exists('sms_body', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['sms_body']) ?></p><?php endif ?>
                <p class="fieldset-label">지금 <strong><?= $this->e(number_format($ev['sms_bytes'])) ?>바이트</strong>를 썼습니다(최대 <?= $this->e(number_format($ev['sms_limit'])) ?>바이트). 한글은 한 자에 2바이트입니다. <?= $ev['sms_bytes'] > 90 ? 'LMS 로 나갑니다' : '90바이트까지는 SMS, 넘으면 LMS 로 나갑니다' ?> — 변수 자리에 들어갈 값만큼 더 늘어나므로 실제 발송은 이보다 깁니다.</p>
                <p class="fieldset-label">쓸 수 있는 변수: <?php foreach ($ev['vars'] as $i => $var): ?><?= $i > 0 ? ', ' : '' ?><code>#{<?= $this->e($var) ?>}</code><?php endforeach ?>. 다른 이름을 쓰면 저장할 때 거절합니다.</p>
              </fieldset>
            </div>

            <div class="form-section">
              <h2 class="form-section-title">알림톡 템플릿</h2>
              <?php if ($ev['alimtalk_off_notice'] !== null): ?>
                <p class="fieldset-label"><?= $this->e($ev['alimtalk_off_notice']) ?></p>
              <?php endif ?>
              <?php if ($noTemplates): ?>
                <p class="fieldset-label">쓸 수 있는 승인 템플릿이 없습니다. <a href="<?= $this->url('admin.messages.templates') ?>">템플릿 화면</a>에서 먼저 가져와 주세요.</p>
              <?php else: ?>
                <fieldset class="fieldset<?php if (array_key_exists('tpl_code', $rowErrors)): ?> is-invalid<?php endif ?>">
                  <legend class="fieldset-legend">쓸 템플릿</legend>
                  <select class="select select-bordered input-block" name="tpl_code" data-notify-tpl="<?= $this->e($ev['key']) ?>">
                    <option value="">— 고르지 않음 —</option>
                    <?php foreach ($templates as $tpl): ?>
                      <option value="<?= $this->e($tpl['tpl_code']) ?>"<?= $ev['tpl_code'] === $tpl['tpl_code'] ? ' selected' : '' ?>><?= $this->e($tpl['name']) ?> (<?= $this->e($tpl['tpl_code']) ?>)</option>
                    <?php endforeach ?>
                  </select>
                  <?php if (array_key_exists('tpl_code', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['tpl_code']) ?></p><?php endif ?>
                  <p class="fieldset-label">승인 템플릿의 변수 이름은 사이트마다 다릅니다. 고른 템플릿의 변수마다 이 알림이 가진 값을 이어 주어야 알림톡을 켤 수 있습니다.</p>
                </fieldset>

                <?php if (array_key_exists('var_map', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['var_map']) ?></p><?php endif ?>

                <?php foreach ($templates as $tpl): ?>
                  <?php $shown = $ev['tpl_code'] === $tpl['tpl_code']; ?>
                  <?php // 고르지 않은 템플릿의 칸은 disabled 로 둔다 — 그래야 제출되지 않는다. ?>
                  <fieldset class="fieldset notify-varmap" data-notify-tpl-for="<?= $this->e($ev['key']) ?>" data-tpl-code="<?= $this->e($tpl['tpl_code']) ?>"<?= $shown ? '' : ' hidden' ?>>
                    <legend class="fieldset-legend"><?= $this->e($tpl['name']) ?> 변수 연결</legend>
                    <?php if ($tpl['vars'] === []): ?>
                      <p class="fieldset-label">이 템플릿에는 채울 변수가 없습니다.</p>
                    <?php else: foreach ($tpl['vars'] as $var): ?>
                      <label class="label toggle-row">
                        <span><code>#{<?= $this->e($var) ?>}</code></span>
                        <select class="select select-bordered select-sm" name="var_map[<?= $this->e($var) ?>]"<?= $shown ? '' : ' disabled' ?>>
                          <option value="">— 고르지 않음 —</option>
                          <?php foreach ($ev['vars'] as $core): ?>
                            <option value="<?= $this->e($core) ?>"<?= ($ev['var_map'][$var] ?? '') === $core ? ' selected' : '' ?>><?= $this->e($core) ?></option>
                          <?php endforeach ?>
                        </select>
                      </label>
                    <?php endforeach; endif ?>
                  </fieldset>
                <?php endforeach ?>
              <?php endif ?>
            </div>
          <?php endif ?>

          <div class="card-actions form-actions">
            <button class="btn btn-primary" type="submit"><?= $this->e($ev['label']) ?> 저장</button>
          </div>
        </form>
      </details>
    <?php endforeach ?>
  </div>
</section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script>
(function(){
  // 템플릿을 바꾸면 그 템플릿의 변수 연결 칸만 보이고 제출된다. 자바스크립트가 없어도
  // 화면은 그대로 쓸 수 있다 — 다른 템플릿을 고르고 저장하면 저장이 "변수를 모두 골라
  // 주세요"로 거절하고, 그 되보여주기에서 새 템플릿의 칸이 나온다.
  document.querySelectorAll('[data-notify-tpl]').forEach(function(select){
    var event=select.getAttribute('data-notify-tpl');
    var blocks=document.querySelectorAll('[data-notify-tpl-for="'+event+'"]');
    select.addEventListener('change',function(){
      blocks.forEach(function(block){
        var on=block.getAttribute('data-tpl-code')===select.value;
        block.hidden=!on;
        block.querySelectorAll('select').forEach(function(field){field.disabled=!on});
      });
    });
  });
})();
</script>
<?php $this->stop() ?>
