<?php $this->layout('admin/layout') ?>
<?php $this->start('admin_body_class') ?>extension-admin yc-admin-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('extensions.css') ?>"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><link rel="stylesheet" href="<?= $this->asset('youngcart-admin.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<?= $this->block('extension_body') ?>
<script src="<?= $this->asset('youngcart-admin.js') ?>" defer></script>
<?php $this->stop() ?>
