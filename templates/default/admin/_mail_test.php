<?php if ($mail_tested): ?><div class="alert alert-success" role="status"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>테스트 메일 발송을 접수했습니다. 입력한 주소의 수신함을 확인해 주세요.</span></div><?php endif ?>
<?php if ($test_error !== null): ?><div class="alert alert-error" role="alert"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($test_error) ?></span></div><?php endif ?>
<?php if ($test_mode === 'disabled'): ?>
  <div class="alert alert-info alert-soft"><span aria-hidden="true"><?= $this->icon('info', 18) ?></span><span><?= $this->e($test_errors['test_email'] ?? '메일을 사용하지 않는 상태입니다. 메일 방식을 선택하고 저장한 뒤 테스트해 주세요.') ?></span></div>
<?php else: ?>
  <p class="fieldset-label">현재 저장된 <strong><?= $test_mode === 'smtp' ? 'SMTP' : '자체 서버' ?></strong> 설정으로 보냅니다. 설정을 변경했다면 먼저 메일 설정을 저장하세요.</p>
  <p class="fieldset-label">테스트 메일에는 발송 사이트 도메인·웹서버 IP·발송 시각이 포함됩니다. SMTP 발송 서버의 IP와는 다를 수 있습니다.</p>
  <form class="test-mail" method="post" action="<?= $this->url('admin.mail.test') ?>#mail-test">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
    <fieldset class="fieldset<?php if (array_key_exists('test_email', $test_errors)): ?> is-invalid<?php endif ?>">
      <legend class="fieldset-legend">수신 이메일</legend>
      <div class="mail-test-controls">
        <input class="input input-bordered" type="email" name="test_email" aria-label="테스트 메일 수신 이메일" value="<?= $this->e($test_values['test_email'] ?? '') ?>" maxlength="254" placeholder="name@example.com" autocomplete="email" required>
        <button class="btn btn-outline" type="submit"><?= $this->icon('mail', 15) ?> 테스트 메일 보내기</button>
      </div>
      <?php if (array_key_exists('test_email', $test_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($test_errors['test_email']) ?></p><?php endif ?>
    </fieldset>
  </form>
<?php endif ?>
