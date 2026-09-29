<?php $this->layout('layout') ?>
<?php $this->start('title') ?>결제 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop"><div class="yc-page-heading"><div><p class="yc-kicker"><?= $this->e($order['number']) ?> <?php if ($order['payment_environment'] === 'test'): ?><span class="yc-test-badge">테스트 결제</span><?php endif ?></p><h1 class="yc-title"><?= $this->e($method_label) ?> 결제창으로 이동합니다</h1></div></div>
<section class="yc-panel"><p class="yc-help"><?= number_format((int) $order['total']) ?>원. 결제창을 엽니다.</p>
<?php $this->insert($checkout_template, ['payment' => $payment]) ?>
<p><a class="yc-more" href="<?= $this->e($url) ?>/order?ref=<?= $this->e($order_ref) ?>">주문 상세로 돌아가기</a></p></section></div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php $this->insert($checkout_template . '_scripts', ['payment' => $payment]) ?>
<?php $this->stop() ?>
