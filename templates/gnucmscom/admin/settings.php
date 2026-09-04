<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>사이트 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">설정</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'general']) ?>
<section class="card settings-card"><div class="card-body">
  <h1 class="card-title"><?= $this->icon('cog', 19) ?> 기본·홈 설정</h1>
  <p class="card-sub">사이트 이름과 홈 화면, 템플릿 및 회원가입 여부를 정합니다.</p>
  <?php if (($query['saved'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>기본 설정을 저장했습니다.</span></div><?php endif ?>
  <form method="post" action="<?= $this->url('admin.settings') ?>">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
    <div class="form-section"><h2 class="form-section-title">사이트 정보</h2>
      <fieldset class="fieldset<?= array_key_exists('site_name', $errors) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">사이트 이름</legend><input class="input input-bordered input-block" type="text" name="site_name" value="<?= $this->e($values['site_name'] ?? '') ?>" maxlength="50" required><?php if (array_key_exists('site_name', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['site_name']) ?></p><?php endif ?></fieldset>
      <fieldset class="fieldset<?= array_key_exists('site_tagline', $errors) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">짧은 소개</legend><input class="input input-bordered input-block" type="text" name="site_tagline" value="<?= $this->e($values['site_tagline'] ?? '') ?>" maxlength="120" required><?php if (array_key_exists('site_tagline', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['site_tagline']) ?></p><?php endif ?></fieldset>
    </div>
    <div class="form-section"><h2 class="form-section-title">홈 화면</h2>
      <fieldset class="fieldset<?= array_key_exists('home_title', $errors) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">홈 제목</legend><input class="input input-bordered input-block" type="text" name="home_title" value="<?= $this->e($values['home_title'] ?? '') ?>" maxlength="120" required><?php if (array_key_exists('home_title', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['home_title']) ?></p><?php endif ?></fieldset>
      <fieldset class="fieldset<?= array_key_exists('home_intro', $errors) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">홈 소개</legend><textarea class="textarea textarea-bordered textarea-block" name="home_intro" rows="4" maxlength="500" required><?= $this->e($values['home_intro'] ?? '') ?></textarea><?php if (array_key_exists('home_intro', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['home_intro']) ?></p><?php endif ?></fieldset>
    </div>
    <div class="form-section"><h2 class="form-section-title">표시와 가입</h2>
      <fieldset class="fieldset<?= array_key_exists('theme', $errors) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">템플릿</legend><select class="select select-bordered select-block" name="theme" required><?php foreach ($available_themes as $theme_name): ?><option value="<?= $this->e($theme_name) ?>"<?= $this->def($values['theme'] ?? null, $active_theme) === $theme_name ? ' selected' : '' ?>><?= $this->e($theme_name === 'default' ? 'default (기본)' : $theme_name) ?></option><?php endforeach ?></select><?php if (array_key_exists('theme', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['theme']) ?></p><?php endif ?></fieldset>
      <fieldset class="fieldset<?= array_key_exists('timezone', $errors) ? ' is-invalid' : '' ?>"><legend class="fieldset-legend">시간대</legend><select class="select select-bordered select-block" name="timezone" required><?php foreach ($timezones as $timezone): ?><option value="<?= $this->e($timezone) ?>"<?= ($values['timezone'] ?? 'Asia/Seoul') === $timezone ? ' selected' : '' ?>><?= $this->e($timezone) ?></option><?php endforeach ?></select><p class="fieldset-label">글과 댓글의 표시 시각에 적용됩니다. 저장 시각은 UTC로 유지됩니다.</p><?php if (array_key_exists('timezone', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['timezone']) ?></p><?php endif ?></fieldset>
      <div class="membership-policy-grid" data-membership-policy>
        <fieldset class="fieldset toggle-list"><label class="label toggle-row"><input class="toggle toggle-primary" type="checkbox" name="password_login_enabled" value="1" data-login-toggle="regular"<?= ($values['password_login_enabled'] ?? false) ? ' checked' : '' ?>><span><strong>일반 회원 로그인 허용</strong><small>끄면 일반 회원의 이메일 로그인을 막습니다. 관리자는 계속 로그인할 수 있습니다.</small></span></label></fieldset>
        <fieldset class="fieldset toggle-list"><label class="label toggle-row"><input class="toggle toggle-primary" type="checkbox" name="registration_enabled" value="1" data-signup-toggle="regular"<?= ($values['registration_enabled'] ?? false) ? ' checked' : '' ?>><span><strong>신규 일반 회원가입 허용</strong><small>이메일과 비밀번호로 새 계정을 만들 수 있습니다.</small></span></label></fieldset>
        <fieldset class="fieldset toggle-list"><label class="label toggle-row"><input class="toggle toggle-primary" type="checkbox" name="social_login_enabled" value="1" data-login-toggle="social"<?= ($values['social_login_enabled'] ?? false) ? ' checked' : '' ?>><span><strong>소셜 회원 로그인 허용</strong><small>활성화된 제공자에 연결된 기존 회원이 로그인할 수 있습니다.</small></span></label></fieldset>
        <fieldset class="fieldset toggle-list"><label class="label toggle-row"><input class="toggle toggle-primary" type="checkbox" name="social_registration_enabled" value="1" data-signup-toggle="social"<?= ($values['social_registration_enabled'] ?? false) ? ' checked' : '' ?>><span><strong>신규 소셜 회원가입 허용</strong><small>활성화된 소셜 제공자로 새 계정을 만들 수 있습니다.</small></span></label></fieldset>
      </div>
    </div>
    <div class="form-section"><h2 class="form-section-title">외부 서비스 코드</h2>
      <p class="card-sub">서비스에서 안내한 전체 태그를 그대로 붙여넣으세요. 저장한 코드는 공개 사이트의 <code>&lt;head&gt;</code>에만 적용됩니다. 신뢰할 수 있는 서비스의 코드만 사용하세요.</p>
      <fieldset class="fieldset<?= array_key_exists('site_verification_html', $errors) ? ' is-invalid' : '' ?>">
        <legend class="fieldset-legend">사이트 소유 확인 태그</legend>
        <textarea class="textarea textarea-bordered textarea-block code-textarea" name="site_verification_html" rows="3" maxlength="20000" spellcheck="false" placeholder="&lt;meta name=&quot;naver-site-verification&quot; content=&quot;...&quot;&gt;"><?= $this->e($values['site_verification_html'] ?? '') ?></textarea>
        <p class="fieldset-label">네이버 서치어드바이저, Google Search Console 등의 HTML 메타 태그를 넣습니다.</p>
        <?php if (array_key_exists('site_verification_html', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['site_verification_html']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?= array_key_exists('analytics_html', $errors) ? ' is-invalid' : '' ?>">
        <legend class="fieldset-legend">애널리틱스 코드</legend>
        <textarea class="textarea textarea-bordered textarea-block code-textarea" name="analytics_html" rows="7" maxlength="20000" spellcheck="false" placeholder="Google Analytics 또는 네이버 애널리틱스에서 받은 코드를 붙여넣으세요."><?= $this->e($values['analytics_html'] ?? '') ?></textarea>
        <?php if (array_key_exists('analytics_html', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['analytics_html']) ?></p><?php endif ?>
      </fieldset>
      <fieldset class="fieldset<?= array_key_exists('adsense_html', $errors) ? ' is-invalid' : '' ?>">
        <legend class="fieldset-legend">애드센스 코드</legend>
        <textarea class="textarea textarea-bordered textarea-block code-textarea" name="adsense_html" rows="5" maxlength="20000" spellcheck="false" placeholder="Google AdSense에서 받은 사이트 연결 코드를 붙여넣으세요."><?= $this->e($values['adsense_html'] ?? '') ?></textarea>
        <p class="fieldset-label">자동 광고 또는 사이트 연결용 head 코드를 넣습니다. 개별 광고 단위 코드는 게시물이나 템플릿의 표시 위치에 따로 배치해야 합니다.</p>
        <?php if (array_key_exists('adsense_html', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['adsense_html']) ?></p><?php endif ?>
      </fieldset>
    </div>
    <div class="card-actions form-actions"><a class="btn btn-ghost" href="<?= $this->url('admin.index') ?>">취소</a><button class="btn btn-primary" type="submit">설정 저장</button></div>
  </form>
</div></section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script>
(function(){
  var root=document.querySelector('[data-membership-policy]');
  if(!root){return}
  ['regular','social'].forEach(function(kind){
    var login=root.querySelector('[data-login-toggle="'+kind+'"]');
    var signup=root.querySelector('[data-signup-toggle="'+kind+'"]');
    if(!login||!signup){return}
    function sync(){
      if(!login.checked){signup.checked=false}
      signup.disabled=!login.checked;
    }
    login.addEventListener('change',sync);
    sync();
  });
})();
</script><?php $this->stop() ?>
