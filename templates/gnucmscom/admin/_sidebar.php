<aside class="admin-sidebar">
  <div class="admin-sidebar-head">
    <a class="brand" href="<?= $this->url('admin.index') ?>">
      <span class="brand-logo" aria-hidden="true"><?= $this->icon('dashboard', 17) ?></span>
      <span class="admin-brand-copy">
        <strong>관리 콘솔</strong>
        <small><?= $this->e($site['site_name']) ?></small>
      </span>
    </a>
    <button class="btn btn-ghost btn-square btn-sm admin-fold" type="button" aria-expanded="true" aria-label="관리 메뉴 접기"><?= $this->icon('chevron-left', 17) ?></button>
  </div>

  <ul class="menu admin-menu">
    <li class="menu-title">운영</li>
    <li><a href="<?= $this->url('admin.index') ?>"<?php if ($section === 'dashboard'): ?> class="menu-active" aria-current="page"<?php endif ?> title="대시보드"><?= $this->icon('dashboard', 18) ?><span class="menu-text">대시보드</span></a></li>
    <li><a href="<?= $this->url('admin.members') ?>"<?php if ($section === 'members'): ?> class="menu-active" aria-current="page"<?php endif ?> title="회원 관리"><?= $this->icon('users', 18) ?><span class="menu-text">회원 관리</span></a></li>
    <li><a href="<?= $this->url('admin.boards') ?>"<?php if ($section === 'boards'): ?> class="menu-active" aria-current="page"<?php endif ?> title="게시판 관리"><?= $this->icon('board', 18) ?><span class="menu-text">게시판 관리</span></a></li>
    <li><a href="<?= $this->url('admin.content') ?>"<?php if ($section === 'content'): ?> class="menu-active" aria-current="page"<?php endif ?> title="내용 관리"><?= $this->icon('document', 18) ?><span class="menu-text">내용 관리</span></a></li>
    <li><a href="<?= $this->url('admin.terms') ?>"<?php if ($section === 'legal'): ?> class="menu-active" aria-current="page"<?php endif ?> title="약관 관리"><?= $this->icon('scale', 18) ?><span class="menu-text">약관 관리</span></a></li>
    <li class="menu-title">설정</li>
    <li><a href="<?= $this->url('admin.settings') ?>"<?php if ($section === 'site'): ?> class="menu-active" aria-current="page"<?php endif ?> title="사이트 설정"><?= $this->icon('cog', 18) ?><span class="menu-text">사이트 설정</span></a></li>
    <li><a href="<?= $this->url('admin.mail') ?>"<?php if ($section === 'mail'): ?> class="menu-active" aria-current="page"<?php endif ?> title="메일 설정"><?= $this->icon('mail', 18) ?><span class="menu-text">메일 설정</span></a></li>
  </ul>
</aside>
