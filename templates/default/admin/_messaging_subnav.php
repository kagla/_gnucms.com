<nav class="notification-channel-controls settings-tabs" aria-label="알림·발송 하위 메뉴">
  <a class="btn btn-sm <?= in_array($active, ['messaging', 'mail'], true) ? 'btn-primary' : 'btn-outline' ?>"<?= in_array($active, ['messaging', 'mail'], true) ? ' aria-current="page"' : '' ?> href="<?= $this->url('admin.settings.messaging') ?>">알림·발송 설정</a>
  <a class="btn btn-sm <?= $active === 'aligo' ? 'btn-primary' : 'btn-outline' ?>"<?= $active === 'aligo' ? ' aria-current="page"' : '' ?> href="<?= $this->url('admin.aligo') ?>">알리고 계정·상세 설정</a>
  <a class="btn btn-sm <?= $active === 'notify' ? 'btn-primary' : 'btn-outline' ?>"<?= $active === 'notify' ? ' aria-current="page"' : '' ?> href="<?= $this->url('admin.settings.notifications') ?>">알림별 발송 규칙·문구 편집</a>
</nav>
