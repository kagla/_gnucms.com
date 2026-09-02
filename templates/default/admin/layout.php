<?php $this->layout('layout') ?>
<?php // 추적·광고 코드는 관리 콘솔에서 실행하지 않는다. ?>
<?php $this->start('external_service_head') ?><?php $this->stop() ?>
<?php $this->start('body_class') ?>admin-page<?php $this->stop() ?>
<?php $this->start('admin_section') ?>dashboard<?php $this->stop() ?>
<?php $this->start('chrome') ?>
<div class="drawer drawer-lg-open admin-shell">
  <input id="admin-drawer" type="checkbox" class="drawer-toggle" aria-label="관리 메뉴 열기">
  <div class="drawer-content admin-content">
    <header class="navbar admin-navbar">
      <?php // 띠(배경·아래 선)는 화면 끝까지 가되, 안쪽 내용은 아래 본문과 같은 폭에서 멈춘다. ?>
      <div class="admin-navbar-inner">
        <div class="navbar-start">
          <label for="admin-drawer" class="btn btn-ghost btn-square drawer-button admin-menu-btn" aria-label="관리 메뉴 열기"><?= $this->icon('menu', 20) ?></label>
          <a class="admin-navbar-title" href="<?= $this->url('admin.index') ?>"><?= $this->e($site['site_name']) ?> 관리</a>
        </div>
        <div class="navbar-end">
          <a class="btn btn-ghost btn-sm" href="<?= $this->url('boards.index') ?>"><?= $this->icon('external', 15) ?> 사이트 보기</a>
          <div class="dropdown dropdown-end admin-user-dropdown">
            <button class="admin-user" type="button" tabindex="0" aria-haspopup="menu" aria-label="<?= $this->e($current_user['display_name']) ?> 계정 메뉴">
              <span class="avatar avatar-placeholder avatar-sm">
                <span class="avatar-inner" data-tone="<?= $this->e(mb_strlen((string) $current_user['display_name']) % 6) ?>" aria-hidden="true"><?php if (!empty($current_user['avatar_file'])): ?><img src="<?= $this->url('avatar.show', ['file' => $current_user['avatar_file']]) ?>" alt=""><?php else: ?><span><?= $this->e(mb_strtoupper(mb_substr((string) $current_user['display_name'], 0, 1))) ?></span><?php endif ?></span>
              </span>
              <span class="admin-user-name"><?= $this->e($current_user['display_name']) ?></span>
              <span class="admin-user-chevron" aria-hidden="true"><?= $this->icon('chevron-down', 13) ?></span>
            </button>
            <ul class="dropdown-content menu rounded-box shadow-lg admin-user-menu" tabindex="0" role="menu">
              <li class="menu-title"><?= $this->e($current_user['display_name']) ?></li>
              <li><a href="<?= $this->url('admin.login_history') ?>"><?= $this->icon('history', 17) ?> 로그인 기록</a></li>
              <li>
                <form method="post" action="<?= $this->url('auth.logout') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
                  <button type="submit"><?= $this->icon('logout', 17) ?> 로그아웃</button>
                </form>
              </li>
            </ul>
          </div>
          <button class="btn btn-ghost btn-circle theme-toggle" type="button" data-theme-toggle aria-label="다크 모드로 전환">
            <span class="theme-ico theme-ico-light"><?= $this->icon('sun', 19) ?></span>
            <span class="theme-ico theme-ico-dark"><?= $this->icon('moon', 19) ?></span>
          </button>
        </div>
      </div>
    </header>
    <?php $adminBodyClass = trim($this->block('admin_body_class')); ?>
    <div class="admin-body<?= $adminBodyClass !== '' ? ' ' . $this->e($adminBodyClass) : '' ?>" id="main"><?= $this->block('body') ?></div>
  </div>

  <div class="drawer-side">
    <label for="admin-drawer" class="drawer-overlay" aria-label="관리 메뉴 닫기"></label>
    <?php $this->insert('admin/_sidebar', ['section' => trim($this->block('admin_section'))]) ?>
  </div>
</div>
<script>
(function(){
  var root=document.documentElement,fold=document.querySelector('.admin-fold');
  function sync(){
    if(!fold){return}
    var f=root.dataset.adminSidebar==='collapsed';
    fold.setAttribute('aria-expanded',f?'false':'true');
    fold.setAttribute('aria-label',f?'관리 메뉴 펼치기':'관리 메뉴 접기');
    fold.title=fold.getAttribute('aria-label');
  }
  if(fold){
    fold.addEventListener('click',function(){
      var f=root.dataset.adminSidebar==='collapsed';
      if(f){delete root.dataset.adminSidebar}else{root.dataset.adminSidebar='collapsed'}
      try{localStorage.setItem('<?= $this->e(GNUCMS_ID) ?>-admin-sidebar',f?'expanded':'collapsed')}catch(e){}
      sync();
    });
    sync();
  }
  var d=document.getElementById('admin-drawer');
  if(d){document.addEventListener('keydown',function(e){if(e.key==='Escape'&&d.checked){d.checked=false}})}
})();
</script>
<?php $this->stop() ?>
