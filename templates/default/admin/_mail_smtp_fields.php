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
