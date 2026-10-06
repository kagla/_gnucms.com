<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림·발송 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<?php $mailMode = in_array(($values['mode'] ?? ''), ['disabled', 'native', 'smtp'], true) ? (string) $values['mode'] : 'native'; ?>
<?php $phoneMode = $status['phone_mode'] ?? \GnuCms\Aligo\Settings::phoneMode((bool) $status['alimtalk_switch_on'], (bool) $status['sms_switch_on']); ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">알림·발송</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'messaging']) ?>
<section class="card settings-card"><div class="card-body">
  <h1 class="card-title"><?= $this->icon('bell', 19) ?> 알림·발송 설정</h1>
  <p class="card-sub">사이트 전체에서 사용할 알림 채널을 정합니다.</p>
  <?php if ($mail_saved): ?><div class="alert alert-success"><span><?= $this->icon('check-circle', 18) ?></span><span>메일 설정을 저장했습니다.</span></div><?php endif ?>
  <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><span><?= $this->icon('warning', 18) ?></span><div>메일 설정을 저장하지 못했습니다.<ul><?php foreach ($errors as $message): ?><li><?= $this->e($message) ?></li><?php endforeach ?></ul></div></div><?php endif ?>
  <div id="aligo-result" class="landing" role="status" aria-live="polite" data-channel-status-url="<?= $this->url('admin.aligo.status') ?>">
    <?php if ($notice !== null): ?><div class="alert <?= $notice['ok'] ? 'alert-success' : 'alert-warning' ?>"><span><?= $this->e($notice['message']) ?></span></div><?php endif ?>
    <?php if ($channel_errors !== []): ?><div class="alert alert-error"><span><?= $this->e(implode(' ', $channel_errors)) ?></span></div><?php endif ?>
  </div>

  <div class="notification-channel-list">
    <div class="notification-channel-row">
      <div><h2 class="notification-channel-name">알림</h2><p class="fieldset-label">사이트 내 알림 기능</p></div>
      <div class="notification-channel-controls"><span class="badge badge-success badge-soft">항상 사용 · 필수</span></div>
    </div>

    <form class="notification-channel-row" id="channel-mail" method="post" action="<?= $this->url('admin.settings.messaging.mail') ?>#channel-mail">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <div id="mail"><h2 class="notification-channel-name">메일</h2><p class="fieldset-label">이메일 발송 방식</p></div>
      <div class="notification-channel-main">
        <fieldset class="fieldset">
          <legend class="sr-only">이메일 사용 방식</legend>
          <div class="notification-mail-methods" data-mail-modes>
            <?php foreach (['disabled' => '사용 안 함', 'native' => '자체 서버', 'smtp' => 'SMTP'] as $mode => $label): ?>
              <label class="notification-mail-method"><input class="radio radio-sm" type="radio" name="mode" value="<?= $mode ?>"<?= $mailMode === $mode ? ' checked' : '' ?>> <?= $label ?></label>
            <?php endforeach ?>
          </div>
        </fieldset>
        <?php $this->insert('admin/_mail_smtp_fields', ['values' => $values, 'errors' => $errors, 'mailMode' => $mailMode]) ?>
        <div class="notification-channel-controls">
          <button class="btn btn-primary btn-sm" type="submit">메일 설정 저장</button>
        </div>
      </div>
    </form>

    <div class="notification-channel-row" id="mail-test">
      <div><h2 class="notification-channel-name">테스트 메일</h2><p class="fieldset-label">저장된 메일 설정으로 발송</p></div>
      <div class="notification-channel-main">
        <?php $this->insert('admin/_mail_test', ['test_mode' => $test_mode, 'mail_tested' => $mail_tested, 'test_error' => $test_error, 'test_values' => $test_values, 'test_errors' => $test_errors]) ?>
      </div>
    </div>

    <form class="notification-channel-row" method="post" action="<?= $this->url('admin.aligo.toggle') ?>#aligo-result" data-phone-mode-form>
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="return_to" value="settings_messaging">
      <div><h2 class="notification-channel-name">알림톡·문자</h2><p class="fieldset-label">휴대폰 알림 발송 방식</p></div>
      <div class="notification-channel-main">
        <fieldset class="fieldset">
          <legend class="sr-only">알림톡·문자 사용 방식</legend>
          <div class="notification-mail-methods">
            <?php foreach (\GnuCms\Aligo\Settings::PHONE_MODE_LABELS as $mode => $label): ?>
              <label class="notification-mail-method"><input class="radio radio-sm" type="radio" name="phone_mode" value="<?= $this->e($mode) ?>"<?= $phoneMode === $mode ? ' checked' : '' ?><?= $mode !== 'disabled' && !$status['configured'] ? ' disabled' : '' ?>> <?= $this->e($label) ?></label>
            <?php endforeach ?>
          </div>
        </fieldset>
        <div class="notification-channel-controls"><button class="btn btn-primary btn-sm" type="submit">발송 방식 저장</button></div>
      </div>
    </form>
  </div>

  <div class="notification-channel-guidance">
    <p data-notification-phone-mode role="status" aria-live="polite"><?php if ($status['alimtalk_switch_on']): ?><?= $status['sms_switch_on'] ? '알림톡을 먼저 보내고, 실패하거나 사용할 수 없으면 문자로 보냅니다.' : '알림톡만 보냅니다. 실패해도 문자는 보내지 않습니다.' ?><?php else: ?><?= $status['sms_switch_on'] ? '문자만 보냅니다.' : '알림톡과 문자를 보내지 않습니다.' ?><?php endif ?></p>
    <p class="fieldset-label">옵션을 선택하면 즉시 저장되며, 지원하는 모든 알림에 적용됩니다.</p>
    <?php if (!$status['configured']): ?><p class="fieldset-label">알림톡·문자를 켜려면 먼저 알리고 계정을 연결해 주세요.</p><?php endif ?>
    <?php if ($status['test_mode']): ?><p class="fieldset-label">알리고 테스트 모드입니다. 현재 알림톡·문자는 실제로 발송되지 않습니다.</p><?php endif ?>
  </div>
</div></section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php $this->insert('admin/_mail_scripts') ?>
<?php $this->insert('admin/_aligo_scripts') ?>
<?php $this->insert('admin/_phone_mode_scripts') ?>
<?php $this->stop() ?>
