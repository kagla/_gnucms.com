<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>알림별 발송 규칙 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li><a href="<?= $this->url('admin.settings.messaging') ?>">알림·발송</a></li><li aria-current="page">알림별 발송 규칙</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'notify']) ?>
<div class="messaging-section landing" id="events">
<?php $this->insert('admin/_notify_settings', ['events' => $events, 'templates' => $templates, 'open' => $open, 'errors' => $errors, 'error' => $error, 'notice' => $notice, 'status' => $status]) ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php $this->insert('admin/_notify_scripts') ?>
<?php $this->stop() ?>
