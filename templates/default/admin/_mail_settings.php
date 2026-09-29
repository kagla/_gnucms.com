<?php $mailMode = in_array(($values['mode'] ?? ''), ['disabled', 'native', 'smtp'], true)
  ? (string) $values['mode'] : 'native'; ?>
<section class="card settings-card">
  <div class="card-body">
    <h2 class="card-title"><?= $this->icon('mail', 19) ?> 메일 설정</h2>
    <p class="card-sub">사이트에서 이메일을 보내지 않거나, 서버 기본 메일 또는 SMTP를 사용할 수 있습니다.</p>
    <?php if (($query['saved'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>메일 설정을 저장했습니다.</span></div><?php endif ?>
    <?php if (($query['tested'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span><?= ($query['transport'] ?? '') === 'smtp' ? 'SMTP' : '서버 기본 메일 기능' ?>으로 테스트 메일 발송을 접수했습니다. 입력한 주소의 수신함을 확인해 주세요.</span></div><?php endif ?>
    <?php if ($test_error): ?><div class="alert alert-error"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><?= $this->e($test_error) ?></span></div><?php endif ?>

    <form method="post" action="<?= $this->url('admin.mail') ?>#mail">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <fieldset class="fieldset">
        <legend class="fieldset-legend">이메일 사용 방식</legend>
        <div class="mail-mode-options" data-mail-modes>
          <label class="mail-method-item">
            <span><input class="radio radio-sm" type="radio" name="mode" value="disabled"<?= $mailMode === 'disabled' ? ' checked' : '' ?>> <strong>이메일 미사용</strong></span>
            <p>모든 이메일 발송을 중지합니다. 일반 회원가입은 이메일 인증 없이 바로 완료됩니다.</p>
          </label>
          <label class="mail-method-item">
            <span><input class="radio radio-sm" type="radio" name="mode" value="native"<?= $mailMode === 'native' ? ' checked' : '' ?>> <strong>서버 기본 메일 사용</strong></span>
            <p>서버의 기본 메일 기능으로 보냅니다. 서버 메일 설정에 따라 스팸으로 분류되거나 발송되지 않을 수 있습니다.</p>
          </label>
          <label class="mail-method-item">
            <span><input class="radio radio-sm" type="radio" name="mode" value="smtp"<?= $mailMode === 'smtp' ? ' checked' : '' ?>> <strong>SMTP 사용</strong></span>
            <p>메일 계정과 앱 비밀번호로 보냅니다. 회원 인증 같은 운영 메일에 권장합니다.</p>
          </label>
        </div>
      </fieldset>

      <div data-mail-smtp-settings<?= $mailMode === 'smtp' ? '' : ' hidden' ?>>
      <div class="form-section">
        <h2 class="form-section-title">서버</h2>
        <div class="grid-2">
          <fieldset class="fieldset">
            <legend class="fieldset-legend">메일 서비스</legend>
            <select class="select select-bordered select-block" name="provider" data-mail-provider>
              <option value="gmail"<?= $this->def($values['provider'] ?? null, 'gmail') === 'gmail' ? ' selected' : '' ?>>Gmail</option>
              <option value="naver"<?= ($values['provider'] ?? '') === 'naver' ? ' selected' : '' ?>>네이버 메일</option>
              <option value="daum"<?= ($values['provider'] ?? '') === 'daum' ? ' selected' : '' ?>>다음 메일</option>
              <option value="custom"<?= ($values['provider'] ?? '') === 'custom' ? ' selected' : '' ?>>직접 설정</option>
            </select>
          </fieldset>
        </div>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('host', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">SMTP 서버</legend>
            <input class="input input-bordered input-block" type="text" name="host" value="<?= $this->e($this->def($values['host'] ?? null, 'smtp.gmail.com')) ?>" maxlength="253" data-mail-host<?= $mailMode === 'smtp' ? ' required' : '' ?>>
            <?php if (array_key_exists('host', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['host']) ?></p><?php endif ?>
          </fieldset>
          <fieldset class="fieldset">
            <legend class="fieldset-legend">포트</legend>
            <input class="input input-bordered input-block" type="number" name="port" value="<?= $this->e($this->def($values['port'] ?? null, 465)) ?>" min="1" max="65535" data-mail-port<?= $mailMode === 'smtp' ? ' required' : '' ?>>
          </fieldset>
        </div>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">보안 연결</legend>
          <select class="select select-bordered select-block" name="encryption" data-mail-encryption>
            <option value="ssl"<?= $this->def($values['encryption'] ?? null, 'ssl') === 'ssl' ? ' selected' : '' ?>>SSL/TLS</option>
            <option value="tls"<?= ($values['encryption'] ?? '') === 'tls' ? ' selected' : '' ?>>STARTTLS</option>
          </select>
        </fieldset>
        <div class="alert alert-info alert-soft" data-mail-help></div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">계정</h2>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('username', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">SMTP 사용자 이름</legend>
            <input class="input input-bordered input-block" type="text" name="username" value="<?= $this->e($values['username'] ?? '') ?>" maxlength="254" autocomplete="username"<?= $mailMode === 'smtp' ? ' required' : '' ?>>
            <?php if (array_key_exists('username', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['username']) ?></p><?php endif ?>
          </fieldset>
          <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">앱 비밀번호</legend>
            <label class="input input-bordered input-block">
              <input type="password" name="password" value="" autocomplete="new-password" placeholder="<?= ($values['password_set'] ?? false) ? '••••••••••••••••' : '앱 비밀번호 입력' ?>">
              <button class="pw-toggle" type="button" data-mail-password-toggle
                      data-password-url="<?= $this->url('admin.mail.password') ?>"
                      data-csrf="<?= $this->e($csrf_token) ?>"
                      data-password-set="<?= ($values['password_set'] ?? false) ? '1' : '0' ?>"
                      aria-pressed="false" aria-label="앱 비밀번호 표시" title="앱 비밀번호 표시">
                <span class="pw-ico pw-ico-show" aria-hidden="true"><?= $this->icon('eye', 17) ?></span>
                <span class="pw-ico pw-ico-hide" aria-hidden="true"><?= $this->icon('eye-off', 17) ?></span>
              </button>
            </label>
            <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
            <p class="fieldset-label"><?= ($values['password_set'] ?? false) ? '앱 비밀번호가 저장되어 있습니다. 보안상 기존 값은 표시하지 않으며, 변경할 때만 새 값을 입력하세요.' : '일반 로그인 비밀번호가 아니라 메일 서비스에서 발급한 앱 비밀번호를 사용하세요.' ?></p>
          </fieldset>
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">발신 정보</h2>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('from_email', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">발신 이메일</legend>
            <input class="input input-bordered input-block" type="email" name="from_email" value="<?= $this->e($values['from_email'] ?? '') ?>" maxlength="254"<?= $mailMode === 'smtp' ? ' required' : '' ?>>
            <?php if (array_key_exists('from_email', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['from_email']) ?></p><?php endif ?>
            <p class="fieldset-label">SMTP를 사용할 때는 로그인한 메일 계정과 같거나 메일 서비스에서 발신을 허용한 주소를 입력하세요.</p>
          </fieldset>
          <fieldset class="fieldset">
            <legend class="fieldset-legend">발신 이름</legend>
            <input class="input input-bordered input-block" type="text" name="from_name" value="<?= $this->e($this->def($values['from_name'] ?? null, GNUCMS)) ?>" maxlength="100"<?= $mailMode === 'smtp' ? ' required' : '' ?>>
          </fieldset>
        </div>
      </div>
      </div>

      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a>
        <button class="btn btn-primary" type="submit">설정 저장</button>
      </div>
    </form>

    <div class="form-section" id="mail-test">
      <h2 class="form-section-title">테스트 메일</h2>
      <div data-mail-test-disabled<?= $mailMode === 'disabled' ? '' : ' hidden' ?> class="alert alert-info alert-soft"><span aria-hidden="true"><?= $this->icon('info', 18) ?></span><span><?= $this->e($test_errors['test_email'] ?? '이메일 미사용 상태에서는 테스트 메일을 보낼 수 없습니다.') ?></span></div>
      <div data-mail-test-enabled<?= $mailMode === 'disabled' ? ' hidden' : '' ?>>
      <p class="fieldset-label">저장된 현재 설정으로 발송합니다. 지금은 <strong><?= $mailMode === 'smtp' ? 'SMTP' : '서버 기본 메일 기능' ?></strong>을 사용합니다.</p>
      <form class="test-mail" method="post" action="<?= $this->url('admin.mail.test') ?>#mail-test">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <fieldset class="fieldset<?php if (array_key_exists('test_email', $test_errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">수신 이메일</legend>
          <div class="mail-test-controls">
            <input class="input input-bordered" type="email" name="test_email" value="<?= $this->e($test_values['test_email'] ?? '') ?>" maxlength="254" placeholder="name@example.com" autocomplete="email" required>
            <button class="btn btn-outline" type="submit"><?= $this->icon('mail', 15) ?> 테스트 메일 보내기</button>
          </div>
          <?php if (array_key_exists('test_email', $test_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($test_errors['test_email']) ?></p><?php endif ?>
        </fieldset>
      </form>
      </div>
    </div>
  </div>
</section>
