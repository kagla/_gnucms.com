<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>메일 설정 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>site<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.settings') ?>">설정</a></li><li aria-current="page">메일</li></ul></div>
<?php $this->insert('admin/_settings_tabs', ['active' => 'mail']) ?>
<div class="messaging-section landing" id="mail">
<?php $this->insert('admin/_mail_settings', [
  'values' => $values, 'errors' => $errors, 'query' => $query, 'test_error' => $test_error,
  'test_values' => $test_values, 'test_errors' => $test_errors,
]) ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php $this->insert('admin/_mail_scripts') ?>
<?php $this->stop() ?>
