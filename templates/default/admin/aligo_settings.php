<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>문자·알림톡 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">문자·알림톡</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'aligo']) ?>
<div class="messaging-section landing" id="aligo">
<?php $this->insert('admin/_aligo_settings', ['values' => $values, 'status' => $status, 'errors' => $errors, 'error' => $error, 'error_at' => $error_at, 'notice' => $notice, 'profiles' => $profiles, 'verified' => $verified]) ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php $this->insert('admin/_aligo_scripts') ?>
<?php $this->stop() ?>
