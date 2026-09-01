<?php $this->layout('layout') ?>
<?php $this->start('title') ?>로그인 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('body_class') ?>auth-page<?php $this->stop() ?>
<?php $this->start('nav_section') ?>auth<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="auth-wrap">
  <section class="card auth-card">
    <div class="card-body">
      <div class="auth-head">
        <span class="auth-mark auth-brand-mark" aria-hidden="true"><?php $this->insert('_brand', ['compact' => true]) ?></span>
        <p class="auth-eyebrow">GNUCMS ACCOUNT</p>
        <h1 class="card-title"><?= $this->e($site['site_name']) ?>에 로그인</h1>
        <p class="card-sub">갤러리와 커뮤니티 활동을 이어가세요.</p>
      </div>
      <?php if ($unverified_email !== null): ?>
        <div class="alert alert-warning alert-soft auth-notice">
          <span aria-hidden="true"><?= $this->icon('mail', 18) ?></span>
          <div>
            <strong>아직 이메일 인증이 끝나지 않았습니다.</strong>
            <p>가입 때 보낸 인증 메일의 링크를 열어야 로그인할 수 있어요. 받은편지함과 스팸함을 확인해 주세요.</p>
            <form method="post" action="<?= $this->url('auth.verify.resend') ?>">
              <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
              <input type="hidden" name="email" value="<?= $this->e($unverified_email) ?>">
              <button class="btn btn-warning btn-sm" type="submit"><?= $this->icon('mail', 15) ?> 인증 메일 다시 보내기</button>
            </form>
          </div>
        </div>
      <?php endif ?>
      <form method="post" action="<?= $this->url('auth.login') ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <fieldset class="fieldset<?php if (array_key_exists('email', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">이메일</legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('mail', 16) ?></span>
            <input type="email" name="email" value="<?= $this->e($values['email'] ?? '') ?>" autocomplete="email" placeholder="you@example.com" required>
          </label>
          <?php if (array_key_exists('email', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['email']) ?></p><?php endif ?>
        </fieldset>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">비밀번호</legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('lock', 16) ?></span>
            <?php // current-password 여야 브라우저가 저장해 둔 계정을 보여 주고 채워 준다.
                  // 자동 채우기를 막고 싶으면 new-password 로 바꾸면 되지만, 그러면 저장된 계정도 안 나온다. ?>
            <input type="password" name="password" autocomplete="current-password" required>
            <?php $this->insert('auth/_pw_toggle') ?>
          </label>
        </fieldset>
        <button class="btn btn-primary btn-block btn-lg" type="submit">로그인</button>
      </form>
      <?php $this->insert('auth/_social') ?>
      <p class="auth-switch">
        <span>아직 회원이 아니신가요? <a class="link" href="<?= $this->url('auth.register') ?>">회원가입</a></span>
        <span class="auth-switch-sep" aria-hidden="true"></span>
        <a class="link link-hover" href="<?= $this->url('auth.forgot') ?>">비밀번호 찾기</a>
      </p>
    </div>
  </section>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php $this->insert('auth/_pw_toggle_script') ?>
<?php $this->stop() ?>
