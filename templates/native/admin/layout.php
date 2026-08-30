<?php $this->layout('layout') ?>
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
          <button class="btn btn-ghost btn-circle theme-toggle" type="button" data-theme-toggle aria-label="다크 모드로 전환">
            <span class="theme-ico theme-ico-light"><?= $this->icon('sun', 19) ?></span>
            <span class="theme-ico theme-ico-dark"><?= $this->icon('moon', 19) ?></span>
          </button>
        </div>
      </div>
    </header>
    <div class="admin-body" id="main"><?= $this->block('body') ?></div>
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
