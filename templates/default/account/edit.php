<?php $this->layout('layout') ?>
<?php $this->start('nav_section') ?>account<?php $this->stop() ?>
<?php $this->start('title') ?>회원정보 수정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card">
    <div class="card-body">
      <div class="auth-head">
        <?php if (!empty($values['avatar_file'])): ?><img class="auth-profile-image" src="<?= $this->url('avatar.show', ['file' => $values['avatar_file']]) ?>" alt="현재 프로필 이미지"><?php else: ?><span class="auth-mark auth-mark-soft" aria-hidden="true"><?= $this->icon('user', 22) ?></span><?php endif ?>
        <h1 class="card-title">회원정보 수정</h1>
        <p class="card-sub"><?= $has_password ? '표시 이름과 비밀번호를 바꿉니다.' : '표시 이름을 바꿉니다.' ?></p>
      </div>
      <?php if ($saved): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>저장했습니다.</span></div><?php endif ?>
      <?php if ($has_password && $password_notice === 'sent'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>비밀번호 변경 알림을 보냈습니다.</span></div><?php endif ?>
      <?php if ($has_password && $password_notice === 'off'): ?><div class="alert alert-info"><span aria-hidden="true"><?= $this->icon('info', 18) ?></span><span>비밀번호는 바뀌었지만 변경 알림은 어디로도 가지 않았습니다. 이 사이트는 지금 이 알림을 보내지 않도록 설정돼 있습니다.</span></div><?php endif ?>
      <?php if ($has_password && $password_notice === 'failed'): ?><div class="alert alert-warning"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span>비밀번호는 바뀌었지만 변경 알림을 보내지 못했습니다.</span></div><?php endif ?>
      <form method="post" action="<?= $this->url('account.edit') ?>" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <fieldset class="fieldset">
          <legend class="fieldset-legend">이메일 <span class="legend-hint">로그인 아이디. 여기서는 바꿀 수 없습니다</span></legend>
          <?php // 입력칸이 아니라 글자로 둔다. 이메일 칸 + 비밀번호 칸이 나란히 있으면 브라우저가
                // 로그인 폼으로 알아보고 저장된 비밀번호를 자동으로 채워 넣는다. ?>
          <p class="account-email"><?= $this->e($values['email']) ?></p>
        </fieldset>
        <fieldset class="fieldset<?php if (array_key_exists('display_name', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">표시 이름 <span class="legend-hint">한글·영문·숫자만 · 한글 2자 또는 영문 4자 이상</span></legend>
          <input class="input input-bordered input-block" type="text" name="display_name" minlength="2" pattern="[가-힣A-Za-z0-9]+" title="한글·영문·숫자만, 공백 없이" value="<?= $this->e($values['display_name']) ?>" maxlength="100" required>
          <?php if (array_key_exists('display_name', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['display_name']) ?></p><?php endif ?>
        </fieldset>
        <?php
          // 배열(phone[]=x)이 넘어오면 (string) 캐스팅이 경고를 낸다 — 스칼라만 쓴다.
          $phoneValue = is_scalar($values['phone'] ?? null) ? (string) $values['phone'] : '';
          // 저장된 번호는 제출값과 따로 본다. off 는 칸을 잠그므로 제출값이 아예 없고,
          // required 의 "지울 수 없음" 표시도 지금 무엇을 쳤는지가 아니라 무엇이
          // 저장돼 있는지로 정해야 서버 규칙과 어긋나지 않는다.
          $phoneStored = is_scalar($values['phone_stored'] ?? null) ? (string) $values['phone_stored'] : '';
          $phonePolicy = $site['signup_phone'] ?? 'off';
          $phoneOff = $phonePolicy === 'off';
          // 번호를 지울 수 없는 경우에만 required 를 붙인다 — 서버도 딱 그때만 거절한다.
          $phoneLocked = $phonePolicy === 'required' && $phoneStored !== '';
          $phoneShown = $phoneOff ? $phoneStored : $phoneValue;
        ?>
        <?php // 수집이 꺼져 있고 저장된 번호도 없으면 보여 줄 것이 없다 — 가입 화면처럼 칸 자체를 감춘다. ?>
        <?php if (!$phoneOff || $phoneStored !== ''): ?>
        <fieldset class="fieldset<?php if (array_key_exists('phone', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">휴대폰번호 <span class="legend-hint">알림톡·문자 수신 번호</span></legend>
          <input class="input input-bordered input-block" type="tel" name="phone" inputmode="numeric" autocomplete="tel"
                 value="<?= $this->e($phoneShown === '' ? '' : \GnuCms\Aligo\PhoneNumber::format($phoneShown)) ?>"
                 placeholder="010-1234-5678"<?php if ($phoneOff): ?> disabled<?php endif ?><?php if ($phoneLocked): ?> required<?php endif ?>>
          <?php if ($phoneOff): ?>
          <p class="fieldset-label">번호 수집이 꺼져 있어 여기서는 바꿀 수 없습니다. 이미 저장된 번호는 계속 쓰입니다.</p>
          <?php elseif ($phoneLocked): ?>
          <p class="fieldset-label">필수 항목이라 번호를 지울 수는 없습니다. 바꾸려면 새 번호를 입력해 주세요.</p>
          <?php elseif ($phonePolicy === 'required'): ?>
          <p class="fieldset-label">번호를 넣으면 알림톡·문자를 받을 수 있습니다. 비워 두고 저장해도 됩니다.</p>
          <?php else: ?>
          <p class="fieldset-label">비워 두고 저장하면 번호가 지워집니다.</p>
          <?php endif ?>
          <?php if (array_key_exists('phone', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['phone']) ?></p><?php endif ?>
        </fieldset>
        <?php endif ?>
        <fieldset class="fieldset<?= array_key_exists('profile_image', $errors) ? ' is-invalid' : '' ?>">
          <legend class="fieldset-legend">프로필 이미지 <span class="legend-hint">JPG, PNG, WebP · 2MB 이하</span></legend>
          <?php if (!empty($values['avatar_file'])): ?><div class="profile-image-preview"><img src="<?= $this->url('avatar.show', ['file' => $values['avatar_file']]) ?>" alt="현재 프로필 이미지"><label><input class="checkbox checkbox-sm" type="checkbox" name="remove_profile_image" value="1"> 현재 이미지 삭제</label></div><?php endif ?>
          <input class="file-input file-input-bordered input-block" type="file" name="profile_image" accept="image/jpeg,image/png,image/webp">
          <p class="fieldset-label">새 이미지를 선택하면 현재 이미지를 교체합니다. 소셜 회원도 직접 바꿀 수 있습니다.</p>
          <?php if (array_key_exists('profile_image', $errors)): ?><p class="validator-hint"><?= $this->e($errors['profile_image']) ?></p><?php endif ?>
        </fieldset>
        <?php if ($has_password): ?>
        <div class="divider">비밀번호 바꾸기 <span class="legend-hint">비워 두면 그대로</span></div>
        <fieldset class="fieldset<?php if (array_key_exists('current_password', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">현재 비밀번호</legend>
          <label class="input input-bordered input-block">
            <?php // new-password 라고 알려 주면 브라우저가 저장된 비밀번호를 여기에 채우지 않는다.
                  // 자리를 비운 사이 남이 자동 채우기로 비밀번호를 바꾸는 길을 막는다. ?>
            <input type="password" name="current_password" autocomplete="new-password">
            <?php $this->insert('auth/_pw_toggle') ?>
          </label>
          <?php if (array_key_exists('current_password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['current_password']) ?></p><?php endif ?>
        </fieldset>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">새 비밀번호</legend>
            <label class="input input-bordered input-block">
              <input type="password" name="password" minlength="8" autocomplete="new-password">
              <?php $this->insert('auth/_pw_toggle') ?>
            </label>
            <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
          </fieldset>
          <fieldset class="fieldset<?php if (array_key_exists('password_confirmation', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">새 비밀번호 확인</legend>
            <label class="input input-bordered input-block">
              <input type="password" name="password_confirmation" minlength="8" autocomplete="new-password">
              <?php $this->insert('auth/_pw_toggle') ?>
            </label>
            <?php if (array_key_exists('password_confirmation', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password_confirmation']) ?></p><?php endif ?>
          </fieldset>
        </div>
        <?php endif ?>
        <button class="btn btn-primary btn-block btn-lg" type="submit">저장</button>
      </form>

      <div class="account-withdrawal" id="withdrawal">
        <div class="divider">회원 탈퇴</div>
        <div class="alert alert-warning alert-soft">
          <span aria-hidden="true"><?= $this->icon('warning', 18) ?></span>
          <div><strong>탈퇴하면 되돌릴 수 없습니다.</strong><p>이메일과 소셜 연결은 해제되어 다시 가입할 수 있습니다. 작성한 글과 댓글은 삭제되지 않고 작성자만 ‘탈퇴한 회원’으로 바뀌며, 새 계정에 다시 연결되지 않습니다.</p></div>
        </div>
        <?php if (array_key_exists('withdrawal', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['withdrawal']) ?></p><?php endif ?>

        <?php if (!$has_password && !$withdraw_reauthenticated): ?>
          <p class="fieldset-label">탈퇴하려면 연결된 소셜 계정으로 본인 확인을 한 번 더 해 주세요. 인증은 5분 동안 유효합니다.</p>
          <div class="social-list withdrawal-social-list">
            <?php foreach ($social_identities as $identity): ?>
              <a class="btn btn-outline btn-block social-btn social-<?= $this->e($identity['provider']) ?>" href="<?= $this->url('oauth.start', ['provider' => $identity['provider']]) ?>?purpose=withdraw">
                <?= $this->e($identity['label']) ?> 계정으로 본인 확인
              </a>
            <?php endforeach ?>
          </div>
          <?php if ($social_identities === []): ?><p class="validator-hint">연결된 로그인 수단이 없어 직접 탈퇴할 수 없습니다. 관리자에게 문의해 주세요.</p><?php endif ?>
        <?php else: ?>
          <?php if (!$has_password): ?><div class="alert alert-success alert-soft"><span><?= $this->icon('check-circle', 18) ?></span><span>소셜 계정 본인 확인을 마쳤습니다.</span></div><?php endif ?>
          <form method="post" action="<?= $this->url('account.withdraw') ?>" class="withdrawal-form">
            <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
            <?php if ($has_password): ?>
              <fieldset class="fieldset<?= array_key_exists('withdraw_current_password', $errors) ? ' is-invalid' : '' ?>">
                <legend class="fieldset-legend">현재 비밀번호</legend>
                <label class="input input-bordered input-block"><input type="password" name="withdraw_current_password" autocomplete="current-password" required><?php $this->insert('auth/_pw_toggle') ?></label>
                <?php if (array_key_exists('withdraw_current_password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['withdraw_current_password']) ?></p><?php endif ?>
              </fieldset>
            <?php endif ?>
            <fieldset class="fieldset toggle-list<?= array_key_exists('confirm_withdrawal', $errors) ? ' is-invalid' : '' ?>"><label class="label toggle-row"><input class="checkbox checkbox-error" type="checkbox" name="confirm_withdrawal" value="1"><span><strong>안내 내용을 확인했으며 회원 탈퇴에 동의합니다.</strong></span></label><?php if (array_key_exists('confirm_withdrawal', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['confirm_withdrawal']) ?></p><?php endif ?></fieldset>
            <button class="btn btn-error btn-block" type="submit">회원 탈퇴</button>
          </form>
        <?php endif ?>
      </div>
    </div>
  </section>
</div>
<?php $this->insert('auth/_pw_toggle_script') ?>
<?php $this->stop() ?>
