<?php // 발송·템플릿·이력 세 탭. $active 는 'send'|'templates'|'history'.
// 아직 없는 라우트로 링크를 걸면 urlFor() 가 던져 관리자 화면 전체가 깨지므로,
// 각 탭은 그 라우트가 생긴 과제에서만 한 줄씩 추가한다(15과: 템플릿만, 16과: 발송, 17과: 이력). ?>
<nav class="tabs tabs-border settings-tabs" aria-label="알림톡·문자 구분">
  <a class="tab<?= $active === 'send' ? ' tab-active' : '' ?>"<?= $active === 'send' ? ' aria-current="page"' : '' ?> href="<?= $this->url('admin.messages.send') ?>">발송</a>
  <a class="tab<?= $active === 'templates' ? ' tab-active' : '' ?>"<?= $active === 'templates' ? ' aria-current="page"' : '' ?> href="<?= $this->url('admin.messages.templates') ?>">템플릿</a>
</nav>
