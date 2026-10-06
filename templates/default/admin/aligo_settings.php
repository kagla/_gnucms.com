<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>문자·알림톡 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li><a href="<?= $this->url('admin.settings.messaging') ?>">알림·발송</a></li><li aria-current="page">알리고 계정·상세 설정</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'aligo']) ?>
<?php $this->insert('admin/_alimtalk_info', ['info_values' => $info_values ?? [], 'info_defaults' => $info_defaults ?? [], 'info_errors' => $info_errors ?? [], 'info_saved' => $info_saved ?? false]) ?>
<div class="messaging-section landing" id="aligo">
<?php $this->insert('admin/_aligo_settings', ['values' => $values, 'status' => $status, 'errors' => $errors, 'error' => $error, 'error_at' => $error_at, 'notice' => $notice, 'profiles' => $profiles, 'profiles_loaded' => $profiles_loaded ?? false, 'verified' => $verified]) ?>
</div>
<?php if ($profiles !== []): ?><?php $this->insert('admin/_aligo_profile_template_modal') ?><?php endif ?>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php $this->insert('admin/_aligo_scripts') ?>
<?php $this->stop() ?>
