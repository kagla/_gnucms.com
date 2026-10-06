<?php
// 이 화면의 규칙은 하나다: **켤 수 없는 칸은 켤 수 없다고 적고, 켜 두었는데 나가지 않는
// 칸은 왜 나가지 않는지 적는다.** 꺼진 것처럼만 그려 두면 관리자는 자기가 켠 채널이
// 이유 없이 스스로 꺼진 것을 보게 된다. 문장은 전부 서버가 만든다 — 쿼리에 실려 온
// 값을 그대로 찍는 자리는 이 화면에 없다(저장 안내도 이벤트 키만 받아 컨트롤러가
// 라벨을 찾아 문장을 만든다).
$channelLabels = ['mail' => '메일'];
$phoneMode = $status['phone_mode'] ?? \GnuCms\Aligo\Settings::phoneMode((bool) $status['alimtalk_switch_on'], (bool) $status['sms_switch_on']);
$showAlimtalk = in_array($phoneMode, ['alimtalk_sms', 'alimtalk'], true);
$phoneModeLabel = \GnuCms\Aligo\Settings::PHONE_MODE_LABELS[$phoneMode] ?? '';
// 전화 채널을 지원하지 않는 알림은 비활성 카드 둘을 반복하지 않고 한 줄로 설명한다.
$phoneReason = '이 알림은 이메일 전용입니다. 문자(알림톡 포함)는 선택할 수 없습니다.';
$noTemplates = $templates === [];
?>
<link rel="stylesheet" href="<?= $this->asset('notify-editor.css') ?>">
<section class="card settings-card">
  <div class="card-body">
    <h2 class="card-title"><?= $this->icon('bell', 19) ?> 알림별 발송 규칙</h2>
    <p class="card-sub">알림마다 보낼 항목(메일·문자)을 선택합니다. 문자의 발송 방식은 <a href="<?= $this->url('admin.settings.messaging') ?>">알림·발송 설정</a>을 따릅니다. 기본 문구를 사용하거나 필요한 문구·알림톡 템플릿만 수정하세요. 이메일 수신거부와 알림톡 실패 시 문자 대체 규칙도 공통으로 적용합니다.</p>

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
        <span>알리고 계정이 연결되어 있지 않습니다. 공통 설정에서 알림톡·문자를 켜도 실제로는 나가지 않습니다 — <a href="<?= $this->url('admin.aligo') ?>#aligo">문자·알림톡 설정</a>에서 계정을 먼저 연결해 주세요. 사이트 내 알림은 계속 기록하며, 이메일은 메일 설정과 아래 선택에 따릅니다.</span>
      </div>
    <?php elseif ($phoneMode === 'disabled'): ?>
      <div class="alert alert-warning" data-notify-channel-warning<?= ($status['alimtalk_enabled'] && $status['sms_enabled']) ? ' hidden' : '' ?>>
        <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
        <span>
          <span data-notify-channel-warning-text><?php if (!$status['alimtalk_enabled'] && !$status['sms_enabled']): ?>알림톡 발송과 문자 발송이 모두 꺼져 있습니다.<?php elseif (!$status['alimtalk_enabled']): ?>알림톡 발송이 꺼져 있습니다.<?php elseif (!$status['sms_enabled']): ?>문자 발송이 꺼져 있습니다.<?php endif ?></span>
          <a href="<?= $this->url('admin.settings.messaging') ?>">알림·발송 설정</a>에서 문자 발송 방식을 선택해 주세요.
        </span>
      </div>
    <?php endif ?>
    <?php if ($status['test_mode']): ?>
      <div class="alert alert-info"><span aria-hidden="true"><?= $this->icon('info', 18) ?></span><span>알리고가 테스트 모드입니다. 알림톡·문자는 이력에만 남고 실제 전화기로는 가지 않습니다.</span></div>
    <?php endif ?>
    <?php if ($showAlimtalk && $noTemplates): ?>
      <div class="alert alert-info">
        <span aria-hidden="true"><?= $this->icon('info', 18) ?></span>
        <span>사용할 승인 템플릿이 없어 알림톡은 보내지 않습니다. 문자가 켜져 있으면 기본 문자로 보냅니다. <a href="<?= $this->url('admin.messages.templates') ?>">운영 → 알림톡·문자 → 템플릿</a>에서 먼저 가져와 사용으로 바꿔 주세요.</span>
      </div>
    <?php endif ?>

    <div class="notify-editor-toolbar">
      <label>알림 검색 <input class="input input-bordered" type="search" placeholder="회원, 비밀번호 찾기, 주문…" data-notify-search></label>
      <label class="label toggle-row"><input type="checkbox" data-notify-phone-filter> 문자 편집 가능한 알림만</label>
      <a class="btn btn-outline btn-sm" href="<?= $this->url('admin.messages.send') ?>">직접 문자 보내기</a>
    </div>
    <p class="fieldset-label" data-notify-empty hidden>검색에 맞는 알림이 없습니다.</p>
    <p class="fieldset-label">회원으로 가입·인증·비밀번호 관련 알림을 찾을 수 있습니다. 편집을 누르면 이메일 제목·본문과 문자 문구를 수정하고 수신 내용을 미리 볼 수 있습니다.</p>
    <div class="notify-event-grid">
    <?php foreach ($events as $ev): ?>
      <?php
        $isOpen = $open === $ev['key'];
        $rowErrors = $isOpen ? $errors : [];
        $mailError = array_intersect_key($rowErrors, array_flip(['mail_subject', 'mail_body', 'mail_preview'])) !== [];
        $phoneError = array_intersect_key($rowErrors, array_flip(['sms_body', 'sms_title', 'sms_preview', 'tpl_code', 'var_map', 'tpl_clear'])) !== [];
        $editorChannel = $mailError || $ev['mail_preview'] !== null ? 'mail'
            : ($phoneError || $ev['preview'] !== null ? 'phone' : ($ev['editor_channel'] ?? ($ev['selection']['mail'] ? 'mail' : 'phone')));
        if (!$ev['phone']) $editorChannel = 'mail';

      ?>
      <details class="notify-event" data-notify-event data-notify-search-keywords="<?= $this->e($ev['search_keywords'] ?? '') ?>" data-phone="<?= $ev['phone'] ? '1' : '0' ?>"<?= $isOpen ? ' open data-notify-reopen' : '' ?>>
        <summary aria-label="<?= $this->e($ev['label']) ?> 설정 열기">
          <span class="notify-event-heading">
            <span class="notify-event-name"><?= $this->e($ev['label']) ?></span>
            <span class="notify-event-action" aria-hidden="true">편집 <?= $this->icon('chevron-right', 14) ?></span>
          </span>
          <span class="notify-event-message" data-notify-body-preview tabindex="0" role="region" aria-label="<?= $this->e($ev['label']) ?> <?= $ev['phone'] ? '문자' : '이메일' ?> 문구"><?php if ($ev['phone']): ?><?php if ($ev['sms_title'] !== ''): ?><span class="notify-event-message-title"><?= $this->e($ev['sms_title']) ?></span><?php endif ?><?= $this->e($ev['sms_body'] !== '' ? $ev['sms_body'] : '문자 문구가 없습니다.') ?><?php else: ?><span class="notify-event-message-title"><?= $this->e($ev['mail_subject']) ?></span><?= $this->e($ev['mail_body']) ?><?php endif ?></span>
          <?php if ($ev['phone']): ?><span class="fieldset-label">이메일 제목: <?= $this->e($ev['mail_subject']) ?></span><?php endif ?>
          <span class="notify-event-settings" aria-label="공통 설정에 따른 발송 채널">
            <?php foreach ($channelLabels as $channel => $label): ?>
              <?php if (($channel === 'inbox' && !$ev['inbox_capable']) || (in_array($channel, ['sms', 'alimtalk'], true) && !$ev['phone']) || ($channel !== 'inbox' && !$ev['on'][$channel])) continue; ?>
              <span class="badge badge-sm badge-success badge-soft"><?= $this->e($label) ?> <?= $channel === 'inbox' ? '필수' : '사용' ?></span>
            <?php endforeach ?>
            <?php if ($ev['phone'] && $ev['selection']['phone'] && $phoneMode !== 'disabled'): ?><span class="badge badge-sm badge-success badge-soft">알림톡·문자: <?= $this->e($phoneModeLabel) ?></span><?php endif ?>
            <?php if ($ev['on']['mail'] && !$mail_enabled): ?><span class="badge badge-sm badge-warning badge-soft">메일 전역 꺼짐</span><?php endif ?>
            <?php if ($ev['on']['sms'] && !$status['sms_enabled']): ?><span class="badge badge-sm badge-warning badge-soft">문자 전역 꺼짐</span><?php endif ?>
            <?php if ($ev['alimtalk_notice'] !== null): ?><span class="badge badge-sm badge-warning badge-soft">알림톡 연결 확인</span><?php elseif ($ev['on']['alimtalk'] && !$status['alimtalk_enabled']): ?><span class="badge badge-sm badge-warning badge-soft">알림톡 전역 꺼짐</span><?php endif ?>
            <?php if ($rowErrors !== []): ?><span class="badge badge-sm badge-error badge-soft">입력 확인</span><?php endif ?>
          </span>
        </summary>

        <form method="post" data-notify-editor action="<?= $this->url('admin.settings.notifications.save') ?>#events">
          <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
          <input type="hidden" name="event" value="<?= $this->e($ev['key']) ?>">
          <input type="hidden" name="delivery_choice" value="1">
          <input type="hidden" name="editor_channel" data-notify-editor-channel value="<?= $this->e($editorChannel) ?>">
          <details class="form-section notify-trigger">
            <summary class="form-section-title">발송 시점·수신 대상</summary>
            <dl><dt>발송 시점</dt><dd><?= $this->e($ev['guidance']['trigger']) ?></dd>
              <dt>수신 대상</dt><dd><?= $this->e($ev['guidance']['target']) ?></dd></dl>
            <?php if ($ev['phone']): ?><p class="fieldset-label">회원 연락처가 없으면 전화 채널은 건너뜁니다. 문자·알림톡은 회원별 수신거부 설정이 없습니다.</p><?php endif ?>
            <a class="btn btn-ghost btn-sm" href="<?= $this->url('admin.messages.history') ?>?event=<?= $this->e(rawurlencode($ev['key'])) ?>">이 알림의 발송 이력</a>
            <?php if ($ev['phone']): ?><a class="btn btn-ghost btn-sm" href="<?= $this->url('admin.messages.send') ?>?preset=<?= $this->e(rawurlencode($ev['key'])) ?>">저장된 문구로 직접 발송</a><?php endif ?>
          </details>

          <?php if ($rowErrors !== []): ?>
            <div class="alert alert-error">
              <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
              <span>입력 내용을 확인해 주세요. 아래 표시한 곳을 고쳐 주세요.</span>
            </div>
          <?php endif ?>
          <?php if (array_key_exists('event', $rowErrors)): ?>
            <p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['event']) ?></p>
          <?php endif ?>

          <div class="form-section">
            <h2 class="form-section-title">보낼 항목</h2>
            <div class="notification-channel-controls">
              <label class="label toggle-row"><input class="checkbox checkbox-sm" type="checkbox" name="mail" value="1"<?= $ev['selection']['mail'] ? ' checked' : '' ?>> <span>메일</span></label>
              <?php if ($ev['phone']): ?>
                <label class="label toggle-row"><input class="checkbox checkbox-sm" type="checkbox" name="phone" value="1"<?= $ev['selection']['phone'] ? ' checked' : '' ?>> <span>문자</span></label>
              <?php endif ?>
            </div>
            <?php if (isset($rowErrors['phone'])): ?><p class="validator-hint"><?= $this->e($rowErrors['phone']) ?></p><?php endif ?>
            <p class="fieldset-label">선택 후 아래 저장 버튼을 누르면 적용됩니다. 선택한 항목도 <a href="<?= $this->url('admin.settings.messaging') ?>">공통 설정</a>이 꺼져 있으면 보내지 않습니다.</p>
            <?php if ($ev['phone']): ?><p class="fieldset-label">문자 발송 방식: <strong><?= $this->e($phoneModeLabel) ?></strong> · 발송 방식은 공통 설정에서 변경합니다.</p><?php else: ?><p class="fieldset-label"><?= $this->e($phoneReason) ?></p><?php endif ?>
            <?php if (!$mail_enabled): ?><p class="fieldset-label">공통 설정에서 메일이 꺼져 있습니다.</p><?php endif ?>
          </div>

          <div class="tabs tabs-border notify-editor-tabs" data-notify-editor-tabs role="tablist" aria-label="편집할 채널" hidden>
            <button class="tab" type="button" role="tab" id="notify-<?= $this->e($ev['key']) ?>-mail-tab" aria-controls="notify-<?= $this->e($ev['key']) ?>-mail-panel" data-notify-editor-tab="mail">메일</button>
            <?php if ($ev['phone']): ?><button class="tab" type="button" role="tab" id="notify-<?= $this->e($ev['key']) ?>-phone-tab" aria-controls="notify-<?= $this->e($ev['key']) ?>-phone-panel" data-notify-editor-tab="phone">문자</button><?php endif ?>
          </div>
          <p class="fieldset-label" data-notify-editor-empty hidden>보낼 항목을 선택하면 문구를 편집할 수 있습니다.</p>
          <section class="notify-editor-panel" data-notify-editor-panel="mail" data-notify-mail-editor id="notify-<?= $this->e($ev['key']) ?>-mail-panel" aria-labelledby="notify-<?= $this->e($ev['key']) ?>-mail-tab"<?= $mailError ? ' data-notify-panel-error' : '' ?>>
            <h3 class="form-section-title" data-notify-editor-heading>메일 문구</h3>

            <fieldset class="fieldset<?= isset($rowErrors['mail_subject']) ? ' is-invalid' : '' ?>">
              <legend class="fieldset-legend">제목</legend>
              <input class="input input-bordered input-block" type="text" name="mail_subject" value="<?= $this->e($ev['mail_subject']) ?>" maxlength="<?= \GnuCms\Notify\MailEditor::SUBJECT_LIMIT ?>" data-notify-mail-subject>
              <?php if (isset($rowErrors['mail_subject'])): ?><p class="validator-hint"><?= $this->e($rowErrors['mail_subject']) ?></p><?php endif ?>
            </fieldset>
            <fieldset class="fieldset<?= isset($rowErrors['mail_body']) ? ' is-invalid' : '' ?>">
              <legend class="fieldset-legend">본문</legend>
              <textarea class="textarea textarea-bordered input-block" name="mail_body" rows="6" maxlength="<?= \GnuCms\Notify\MailEditor::BODY_LIMIT ?>" data-notify-mail-body><?= $this->e($ev['mail_body']) ?></textarea>
              <?php if (isset($rowErrors['mail_body'])): ?><p class="validator-hint"><?= $this->e($rowErrors['mail_body']) ?></p><?php endif ?>
            </fieldset>
            <details class="notify-editor-advanced">
              <summary>변수·기본문구</summary>
              <p class="fieldset-label">제목은 최대 <?= \GnuCms\Notify\MailEditor::SUBJECT_LIMIT ?>자, 본문은 최대 <?= number_format(\GnuCms\Notify\MailEditor::BODY_LIMIT) ?>자입니다. HTML 태그는 서식으로 처리하지 않습니다. 변수 버튼은 마지막으로 선택한 제목 또는 본문에 삽입합니다.</p>
              <div class="notify-variable-buttons"><?php foreach ($ev['vars'] as $var): ?><button class="btn btn-outline btn-sm" type="button" data-notify-mail-insert="<?= $this->e($var) ?>">#{<?= $this->e($var) ?>}</button><?php endforeach ?></div>
              <div class="notify-variable-buttons">
                <button class="btn btn-ghost btn-sm" type="button" data-notify-mail-restore data-subject="<?= $this->e($ev['mail_defaults']['subject']) ?>" data-body="<?= $this->e($ev['mail_defaults']['body']) ?>">기본 이메일 문구 넣기</button>
                <button class="btn btn-ghost btn-sm" type="button" data-notify-mail-restore data-subject="<?= $this->e($ev['mail_subject_saved']) ?>" data-body="<?= $this->e($ev['mail_body_saved']) ?>">저장된 이메일 문구로 되돌리기</button>
              </div>
            </details>
            <p class="fieldset-label"><?= $ev['mail_subscription'] ? '수신거부 링크와 재수신 안내는 실제 발송 시 본문 끝에 자동으로 추가됩니다. 이 안내는 편집할 수 없습니다.' : '인증·계정 복구 이메일에는 수신거부 안내를 붙이지 않습니다. 인증·비밀번호 재설정 본문에는 #{링크}와 #{유효시간}을 유지해 주세요.' ?></p>
            <?php if (isset($rowErrors['mail_preview'])): ?><p class="validator-hint"><?= $this->e($rowErrors['mail_preview']) ?></p><?php endif ?>
            <?php if ($ev['mail_preview'] !== null): ?>
              <div class="notify-mail-preview" data-notify-mail-preview tabindex="-1">
                <p class="fieldset-label">예시 수신 화면<?= $ev['mail_subscription'] ? ' · 수신거부 링크도 예시입니다.' : '' ?></p>
                <h3>제목: <?= $this->e($ev['mail_preview']['subject']) ?></h3>
                <pre><?= $this->e($ev['mail_preview']['body']) ?></pre>
              </div>
            <?php endif ?>
          </section>
          <?php if ($ev['phone']): ?>
          <section class="notify-editor-panel" data-notify-editor-panel="phone" id="notify-<?= $this->e($ev['key']) ?>-phone-panel" aria-labelledby="notify-<?= $this->e($ev['key']) ?>-phone-tab"<?= $phoneError ? ' data-notify-panel-error' : '' ?>>
            <h3 class="form-section-title" data-notify-editor-heading>문자 문구</h3>
          <?php if ($ev['alimtalk_notice'] !== null): ?>
            <div class="alert alert-warning">
              <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
              <span><?= $this->e($ev['alimtalk_notice']) ?></span>
            </div>
          <?php endif ?>

              <fieldset class="fieldset<?php if (array_key_exists('sms_body', $rowErrors)): ?> is-invalid<?php endif ?>">
                <legend class="fieldset-legend notify-sms-body-legend">
                  <span>본문</span>
                  <button class="btn btn-ghost btn-sm" type="button" data-notify-sms-default data-default-body="<?= $this->e($ev['sms_body_template']) ?>">기본 문구 넣기</button>
                </legend>
                <?php // maxlength 는 글자를 센다. 실제 한계는 EUC-KR 바이트라 한글은 한 자에
                  // 두 바이트다 — 그래서 이 속성은 한계가 아니라 붙여넣기 상한일 뿐이고,
                  // 진짜 판정은 저장할 때 MessageText 가 한다. 아래 줄이 그 숫자를 미리 보여준다. ?>
                <textarea class="textarea textarea-bordered input-block" name="sms_body" rows="6" maxlength="2000" data-notify-sms-body><?= $this->e($ev['sms_body']) ?></textarea>
                <?php if (array_key_exists('sms_body', $rowErrors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($rowErrors['sms_body']) ?></p><?php endif ?>
                <?php // SMS·LMS 경계(90바이트)를 여기서 다시 세지 않는다 — 판정은 컨트롤러가
                  // MessageText::channelFor() 로 이미 해서 sms_kind 로 넘겨 준다. 여기서 숫자를
                  // 다시 비교하면 그 경계가 두 군데에 생긴다. 두 갈래 문장은 한쪽이 다른 쪽의
                  // 부분 문자열이 되지 않게 적는다: 그래야 "어느 갈래가 그려졌는가"를 물을 수 있다. ?>
                <p class="fieldset-label" data-notify-sms-count>현재 추정 <strong><span data-notify-sms-bytes><?= $this->e(number_format($ev['sms_bytes'])) ?></span>/<?= $this->e(number_format($ev['sms_limit'])) ?>바이트</strong> · <?= $this->e(number_format($ev['sms_boundary'])) ?>바이트 초과 시 LMS · 변수 치환 시 늘어날 수 있습니다.</p>
              </fieldset>
              <details class="notify-editor-advanced"<?= isset($rowErrors['sms_title']) ? ' open' : '' ?>>
                <summary>상세 설정 · LMS 제목·변수</summary>
              <fieldset class="fieldset<?= isset($rowErrors['sms_title']) ? ' is-invalid' : '' ?>">
                <legend class="fieldset-legend">LMS 제목 (선택)</legend>
                <input class="input input-bordered input-block" type="text" name="sms_title" value="<?= $this->e($ev['sms_title']) ?>" maxlength="44" data-notify-sms-title>
                <p class="fieldset-label">고정 문구 44바이트 이내 (한글 22자). SMS에는 제목이 표시되지 않습니다. 알림톡 대체문자는 제목 없이 본문만 사용합니다.</p>
                <?php if (isset($rowErrors['sms_title'])): ?><p class="validator-hint"><?= $this->e($rowErrors['sms_title']) ?></p><?php endif ?>
              </fieldset>
                <p class="fieldset-label">변수 버튼을 누르면 본문의 커서 위치에 삽입합니다. 실제 발송 시 회원·주문 값으로 자동 치환됩니다.</p>
                <div class="notify-variable-buttons"><?php foreach ($ev['vars'] as $var): ?><button class="btn btn-outline btn-sm" type="button" data-notify-insert="<?= $this->e($var) ?>">#{<?= $this->e($var) ?>}</button><?php endforeach ?></div>
                <button class="btn btn-ghost btn-sm" type="button" data-notify-restore data-body="<?= $this->e($ev['sms_body_saved']) ?>" data-title="<?= $this->e($ev['sms_title_saved']) ?>">저장된 문구로 되돌리기</button>
              </details>

            <?php if ($ev['preview'] !== null): ?>
              <div class="notify-sms-preview" data-notify-preview tabindex="-1">
                <p class="fieldset-label">기본 예시 값으로 확인한 내용입니다. 실제 문자는 발송하지 않으며 설정도 저장하지 않습니다.</p>
                <p><strong><?= $ev['preview']['kind'] === 'lms' ? 'LMS' : 'SMS' ?></strong> · <?= (int) $ev['preview']['bytes'] ?>바이트 · 실제 발송 시 수신자 정보에 따라 길이가 달라질 수 있습니다.</p>
                <?php if ($ev['preview']['title'] !== ''): ?><h3><?= $this->e($ev['preview']['title']) ?></h3><?php endif ?>
                <pre><?= $this->e($ev['preview']['body']) ?></pre>
              </div>
            <?php endif ?>

            <?php if ($showAlimtalk): ?>
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
                    <option value="">— 템플릿 미연결 —</option>
                    <?php foreach ($templates as $tpl): ?>
                      <option value="<?= $this->e($tpl['tpl_code']) ?>"<?= $ev['tpl_code'] === $tpl['tpl_code'] ? ' selected' : '' ?>><?= $this->e($tpl['name']) ?> (<?= $this->e($tpl['tpl_code']) ?>)</option>
                    <?php endforeach ?>
                  </select>
                  <p class="fieldset-label">템플릿을 고르면 같은 이름의 변수는 자동으로 연결됩니다. 이름이 다른 변수만 아래에서 확인·수정하세요.</p>
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
                  <p class="fieldset-label">체크하고 저장하면 사용할 수 없는 기존 연결을 지웁니다. 공통 발송 설정은 바뀌지 않습니다.</p>
                </fieldset>
              <?php endif ?>

              <?php if (!$noTemplates): ?>
                <?php foreach ($templates as $tpl): ?>
                  <?php $shown = $ev['tpl_code'] === $tpl['tpl_code']; $autoMap = \GnuCms\Notify\NotifySettings::variableMap($ev['key'], $tpl['vars'], $shown ? $ev['var_map'] : []); ?>
                  <?php // 고르지 않은 템플릿의 칸은 disabled 로 둔다 — 그래야 제출되지 않는다. ?>
                  <details class="fieldset notify-varmap" data-notify-tpl-for="<?= $this->e($ev['key']) ?>" data-tpl-code="<?= $this->e($tpl['tpl_code']) ?>"<?= $shown ? '' : ' hidden' ?><?= isset($rowErrors['var_map']) ? ' open' : '' ?>>
                    <summary class="fieldset-legend">변수 연결 확인·수정 (자동 연결)</summary>
                    <?php if ($tpl['vars'] === []): ?>
                      <p class="fieldset-label">이 템플릿에는 채울 변수가 없습니다.</p>
                    <?php else: foreach ($tpl['vars'] as $var): ?>
                      <label class="label toggle-row">
                        <span><code>#{<?= $this->e($var) ?>}</code></span>
                        <select class="select select-bordered select-sm" name="var_map[<?= $this->e($var) ?>]"<?= $shown ? '' : ' disabled' ?>>
                          <option value="">— 고르지 않음 —</option>
                          <?php foreach ($ev['vars'] as $core): ?>
                            <option value="<?= $this->e($core) ?>"<?= ($autoMap[$var] ?? '') === $core ? ' selected' : '' ?>><?= $this->e($core) ?></option>
                          <?php endforeach ?>
                        </select>
                      </label>
                    <?php endforeach; endif ?>
                  </details>
                <?php endforeach ?>
              <?php endif ?>
            </div>
            <?php endif ?>
            <?php if (isset($rowErrors['sms_preview'])): ?><p class="validator-hint"><?= $this->e($rowErrors['sms_preview']) ?></p><?php endif ?>
          </section>
          <?php endif ?>

          <div class="card-actions form-actions notify-editor-actions">
            <button class="btn btn-outline" type="submit" data-notify-editor-preview data-mail-preview-url="<?= $this->url('admin.settings.notifications.mail_preview') ?>#events" data-phone-preview-url="<?= $this->url('admin.settings.notifications.sms_preview') ?>#events" formaction="<?= $this->url('admin.settings.notifications.mail_preview') ?>#events">미리보기</button>
            <?php if ($ev['phone']): ?><button class="btn btn-outline" type="submit" data-notify-preview-fallback formaction="<?= $this->url('admin.settings.notifications.sms_preview') ?>#events">문자 미리보기</button><?php endif ?>
            <button class="btn btn-primary" type="submit">저장</button>
          </div>
        </form>
      </details>
    <?php endforeach ?>
    </div>
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
<dialog class="modal notify-settings-modal" data-notify-result-modal aria-labelledby="notify-result-title">
  <div class="modal-box">
    <div class="notify-settings-modal-header">
      <h2 id="notify-result-title" tabindex="-1">미리보기</h2>
      <button class="btn btn-ghost btn-sm" type="button" data-notify-result-close>닫기</button>
    </div>
    <div data-notify-result-body></div>
  </div>
  <form method="dialog" class="modal-backdrop"><button aria-label="닫기">닫기</button></form>
</dialog>
