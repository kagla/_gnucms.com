<?php
// 이 화면의 규칙은 하나다: **켤 수 없는 칸은 켤 수 없다고 적고, 켜 두었는데 나가지 않는
// 칸은 왜 나가지 않는지 적는다.** 꺼진 것처럼만 그려 두면 관리자는 자기가 켠 채널이
// 이유 없이 스스로 꺼진 것을 보게 된다. 문장은 전부 서버가 만든다 — 쿼리에 실려 온
// 값을 그대로 찍는 자리는 이 화면에 없다(저장 안내도 이벤트 키만 받아 컨트롤러가
// 라벨을 찾아 문장을 만든다).
$channelLabels = ['mail' => '메일', 'alimtalk' => '알림톡', 'sms' => '문자', 'inbox' => '알림함'];
// 전화 채널을 지원하지 않는 알림은 비활성 카드 둘을 반복하지 않고 한 줄로 설명한다.
$phoneReason = $mail_enabled
  ? '전화 채널을 지원하지 않아 메일만 보냅니다.'
  : '전화 채널을 지원하지 않고 이메일도 미사용 상태라 이 알림은 보내지 않습니다.';
$noTemplates = $templates === [];
?>
<section class="card settings-card">
  <div class="card-body">
    <h2 class="card-title"><?= $this->icon('bell', 19) ?> 알림별 발송 규칙</h2>
    <p class="card-sub"><?= $mail_enabled ? '이메일은 모든 코어 알림에 사용합니다.' : '현재 메일 설정에서 이메일을 사용하지 않습니다.' ?> 알림톡을 켜면 먼저 보내고, 실패하거나 사용할 수 없을 때 문자가 켜져 있으면 문자로 보냅니다. 알림함도 별도로 설정할 수 있습니다. 알림을 선택해 설정하고 각각 저장할 수 있습니다.</p>

    <?php if ($notice !== null): ?>
      <div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span><?= $this->e($notice) ?></span></div>
    <?php endif ?>

    <?php // 어느 묶음에도 붙지 못한 오류(카탈로그가 모르는 이벤트 키 등). 이걸 그리지
      // 않으면 422 인데 화면은 평소와 똑같아, 저장이 거절된 사실 자체가 보이지 않는다. ?>
    <?php if ($error !== null): ?>
      <div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($error) ?> 설정을 저장하지 못했습니다. 화면을 새로 고친 뒤 다시 시도해 주세요.</span></div>
    <?php endif ?>

    <?php // 알리고가 없거나 채널 스위치가 꺼져 있으면 여기서 무엇을 켜든 전화로는 나가지 않는다. ?>
    <?php if (!$status['configured']): ?>
      <div class="alert alert-warning">
        <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
        <span>알리고 계정이 연결되어 있지 않습니다. 아래에서 알림톡·문자를 켜 두어도 실제로는 나가지 않습니다 — <a href="<?= $this->url('admin.aligo') ?>#aligo">문자·알림톡 설정</a>에서 계정을 먼저 연결해 주세요. <?= $mail_enabled ? '이메일과 알림함은' : '알림함은' ?> 이 설정과 무관하게 그대로 나갑니다.</span>
      </div>
    <?php else: ?>
      <div class="alert alert-warning" data-notify-channel-warning<?= ($status['alimtalk_enabled'] && $status['sms_enabled']) ? ' hidden' : '' ?>>
        <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
        <span>
          <span data-notify-channel-warning-text><?php if (!$status['alimtalk_enabled'] && !$status['sms_enabled']): ?>알림톡 발송과 문자 발송이 모두 꺼져 있습니다.<?php elseif (!$status['alimtalk_enabled']): ?>알림톡 발송이 꺼져 있습니다.<?php elseif (!$status['sms_enabled']): ?>문자 발송이 꺼져 있습니다.<?php endif ?></span>
          아래에서 켜 두어도 그 채널로는 나가지 않습니다 — <a href="<?= $this->url('admin.aligo') ?>#aligo">문자·알림톡 설정</a>에서 "채널별 발송 허용"을 켜 주세요.
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
      <details class="notify-event" data-notify-event<?= $isOpen ? ' open data-notify-reopen' : '' ?>>
        <summary>
          <span class="notify-event-name"><?= $this->e($ev['label']) ?></span>
          <?php if ($onNow === []): ?>
            <span class="badge badge-sm badge-ghost badge-soft">보내지 않음</span>
          <?php else: foreach ($onNow as $c): ?>
            <span class="badge badge-sm badge-success badge-soft"><?= $this->e($channelLabels[$c]) ?></span>
          <?php endforeach; endif ?>
          <?php if ($ev['alimtalk_notice'] !== null): ?><span class="badge badge-sm badge-warning badge-soft">알림톡이 나가지 않음</span><?php endif ?>
          <span class="notify-event-action" aria-hidden="true">설정</span>
        </summary>

        <form method="post" action="<?= $this->url('admin.settings.notifications.save') ?>#events">
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
            <?php if ($mail_enabled): ?>
              <p class="notify-fixed-channel"><span class="badge badge-sm badge-success badge-soft">메일</span> 항상 발송합니다.</p>
            <?php else: ?>
              <p class="notify-fixed-channel"><span class="badge badge-sm badge-ghost badge-soft">메일</span> <a href="<?= $this->url('admin.mail') ?>#mail">메일 설정</a>에서 이메일을 사용하지 않도록 설정했습니다.</p>
            <?php endif ?>
            <div class="toggle-list notify-channel-options">
              <?php if (array_key_exists('mail', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['mail']) ?></p><?php endif ?>

              <?php if ($ev['phone']): ?>
                <label class="label toggle-row">
                  <input type="checkbox" name="alimtalk" value="1"<?= $ev['on']['alimtalk'] ? ' checked' : '' ?><?= $alimtalkLocked ? ' disabled' : '' ?>>
                  <span>알림톡</span>
                </label>
                <?php if ($alimtalkLocked): ?><p class="fieldset-label">사용할 승인 템플릿이 없습니다.</p><?php endif ?>
                <?php if (array_key_exists('alimtalk', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['alimtalk']) ?></p><?php endif ?>

                <label class="label toggle-row">
                  <input type="checkbox" name="sms" value="1"<?= $ev['on']['sms'] ? ' checked' : '' ?>>
                  <span>문자</span>
                </label>
                <p class="fieldset-label">알림톡을 끄면 문자부터 보내고, 둘 다 켜면 알림톡 실패 시 문자로 보냅니다.</p>
                <?php if (array_key_exists('sms', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['sms']) ?></p><?php endif ?>
              <?php else: ?>
                <p class="fieldset-label"><?= $this->e($phoneReason) ?></p>
                <?php if (array_key_exists('alimtalk', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['alimtalk']) ?></p><?php endif ?>
                <?php if (array_key_exists('sms', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['sms']) ?></p><?php endif ?>
              <?php endif ?>

              <?php if ($ev['inbox_capable']): ?>
                <label class="label toggle-row">
                  <input type="checkbox" name="inbox" value="1"<?= $ev['on']['inbox'] ? ' checked' : '' ?>>
                  <span>사이트 내 알림함</span>
                </label>
              <?php elseif (array_key_exists('inbox', $rowErrors)): ?>
                <p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['inbox']) ?></p>
              <?php endif ?>
            </div>
            <?php if ($ev['key'] === 'comment_new'): ?>
              <p class="fieldset-label">댓글 한 건당 최대 두 명에게 알립니다.</p>
            <?php endif ?>
          </div>

          <?php if ($ev['phone']): ?>
            <div class="form-section">
              <h2 class="form-section-title">문자 본문</h2>
              <?php if ($ev['sms_notice'] !== null): ?>
                <p class="fieldset-label"><?= $this->e($ev['sms_notice']) ?></p>
              <?php endif ?>
              <fieldset class="fieldset<?php if (array_key_exists('sms_body', $rowErrors)): ?> is-invalid<?php endif ?>">
                <legend class="fieldset-legend notify-sms-body-legend">
                  <span>본문</span>
                  <button class="btn btn-ghost btn-sm" type="button" data-notify-sms-default data-default-body="<?= $this->e($ev['sms_body_template']) ?>">기본 문구 넣기</button>
                </legend>
                <?php // maxlength 는 글자를 센다. 실제 한계는 EUC-KR 바이트라 한글은 한 자에
                  // 두 바이트다 — 그래서 이 속성은 한계가 아니라 붙여넣기 상한일 뿐이고,
                  // 진짜 판정은 저장할 때 MessageText 가 한다. 아래 줄이 그 숫자를 미리 보여준다. ?>
                <textarea class="textarea textarea-bordered input-block" name="sms_body" rows="3" maxlength="2000" data-notify-sms-body><?= $this->e($ev['sms_body']) ?></textarea>
                <?php if (array_key_exists('sms_body', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['sms_body']) ?></p><?php endif ?>
                <?php // SMS·LMS 경계(90바이트)를 여기서 다시 세지 않는다 — 판정은 컨트롤러가
                  // MessageText::channelFor() 로 이미 해서 sms_kind 로 넘겨 준다. 여기서 숫자를
                  // 다시 비교하면 그 경계가 두 군데에 생긴다. 두 갈래 문장은 한쪽이 다른 쪽의
                  // 부분 문자열이 되지 않게 적는다: 그래야 "어느 갈래가 그려졌는가"를 물을 수 있다. ?>
                <p class="fieldset-label" data-notify-sms-count>현재 <strong><span data-notify-sms-bytes><?= $this->e(number_format($ev['sms_bytes'])) ?></span>/<?= $this->e(number_format($ev['sms_limit'])) ?>바이트</strong> · <?= $this->e(number_format($ev['sms_boundary'])) ?>바이트 초과 시 LMS · 변수 치환 시 늘어날 수 있습니다.</p>
                <p class="fieldset-label">쓸 수 있는 변수: <?php foreach ($ev['vars'] as $i => $var): ?><?= $i > 0 ? ', ' : '' ?><code>#{<?= $this->e($var) ?>}</code><?php endforeach ?>. 다른 이름을 쓰면 저장할 때 거절합니다.</p>
              </fieldset>
            </div>

            <div class="form-section">
              <h2 class="form-section-title">알림톡 템플릿</h2>
              <?php if ($ev['alimtalk_off_notice'] !== null): ?>
                <p class="fieldset-label"><?= $this->e($ev['alimtalk_off_notice']) ?></p>
              <?php endif ?>
              <?php
                // 오류 표시는 $noTemplates 바깥에 둔다. 예전에는 else 안에 있어서, 쓸 수 있는
                // 템플릿이 하나도 없는 채로 알림톡이 켜진 카드(모든 저장이 422 로 거절되는
                // 바로 그 상태)에서 "저장하지 못했습니다"만 뜨고 어느 칸이 문제인지는 아무
                // 데도 표시되지 않았다. 422 인데 아무것도 가리키지 않는 화면은 거절하지 않은
                // 것과 거의 같다.
                // 지우기 오류는 그 칸이 그려질 때(죽은 참조일 때)는 그 칸 옆에 붙는다.
                // 참조가 그 사이 되살아나 칸 자체가 사라진 경우에는 붙을 곳이 없으므로
                // 여기서 받는다 — 붙을 곳 없는 422 는 아무것도 가리키지 않는 422 다.
                $clearOrphaned = array_key_exists('tpl_clear', $rowErrors) && !$ev['tpl_dead'];
                $tplInvalid = array_key_exists('tpl_code', $rowErrors)
                    || array_key_exists('var_map', $rowErrors) || $clearOrphaned;
              ?>
              <fieldset class="fieldset<?= $tplInvalid ? ' is-invalid' : '' ?>">
                <legend class="fieldset-legend">쓸 템플릿</legend>
                <?php if ($noTemplates): ?>
                  <p class="fieldset-label">승인 템플릿이 없습니다. <a href="<?= $this->url('admin.messages.templates') ?>">템플릿 화면</a>에서 먼저 가져와 주세요.</p>
                <?php else: ?>
                  <select class="select select-bordered input-block" name="tpl_code" data-notify-tpl="<?= $this->e($ev['key']) ?>">
                    <option value="">— 고르지 않음 —</option>
                    <?php foreach ($templates as $tpl): ?>
                      <option value="<?= $this->e($tpl['tpl_code']) ?>"<?= $ev['tpl_code'] === $tpl['tpl_code'] ? ' selected' : '' ?>><?= $this->e($tpl['name']) ?> (<?= $this->e($tpl['tpl_code']) ?>)</option>
                    <?php endforeach ?>
                  </select>
                  <p class="fieldset-label">템플릿 변수를 아래 알림 값과 연결해 주세요.</p>
                <?php endif ?>
                <?php if (array_key_exists('tpl_code', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['tpl_code']) ?></p><?php endif ?>
                <?php if (array_key_exists('var_map', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['var_map']) ?></p><?php endif ?>
                <?php if ($clearOrphaned): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['tpl_clear']) ?></p><?php endif ?>
              </fieldset>

              <?php
                // 죽은 참조를 버리는 **명시적인** 칸. 빈 <select> 가 "고르지 않음"인지 "고를
                // 목록에 없음"인지 추측하는 대신, 뜻이 하나뿐인 칸을 준다. 죽었을 때만 나온다 —
                // 멀쩡한 설정 옆에 지우기 칸을 두면 그것대로 사고의 입구가 된다.
              ?>
              <?php if ($ev['tpl_dead']): ?>
                <fieldset class="fieldset<?php if (array_key_exists('tpl_clear', $rowErrors)): ?> is-invalid<?php endif ?>">
                  <legend class="fieldset-legend">고를 수 없게 된 템플릿 설정</legend>
                  <label class="label toggle-row">
                    <input type="checkbox" name="tpl_clear" value="1"<?= $ev['tpl_clear'] ? ' checked' : '' ?>>
                    <span>고를 수 없게 된 템플릿 설정(<?= $this->e($ev['stored_tpl_code']) ?>) 지우기</span>
                  </label>
                  <?php if (array_key_exists('tpl_clear', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['tpl_clear']) ?></p><?php endif ?>
                  <p class="fieldset-label">알림톡을 끈 뒤 체크하고 저장하면 기존 연결을 지웁니다.</p>
                </fieldset>
              <?php endif ?>

              <?php if (!$noTemplates): ?>
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
<dialog class="modal notify-settings-modal" data-notify-modal aria-labelledby="notify-settings-modal-title">
  <div class="modal-box">
    <div class="notify-settings-modal-header">
      <h2 id="notify-settings-modal-title" tabindex="-1"></h2>
      <button class="btn btn-ghost btn-sm" type="button" data-notify-modal-close>닫기</button>
    </div>
    <div data-notify-modal-body></div>
  </div>
  <form method="dialog" class="modal-backdrop"><button aria-label="닫기">닫기</button></form>
</dialog>
