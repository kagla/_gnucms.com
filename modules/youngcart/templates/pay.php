<?php $this->layout('layout') ?>
<?php $this->start('title') ?>결제 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>modules/youngcart<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop"><div class="yc-page-heading"><div><p class="yc-kicker"><?= $this->e($order['number']) ?></p><h1 class="yc-title"><?= $this->e($method_label) ?> 결제창으로 이동합니다</h1></div></div>
<section class="yc-panel"><p class="yc-help"><?= number_format((int) $order['total']) ?>원. 결제창이 열리지 않으면 아래 버튼을 눌러 주세요.</p>
<form id="yc-pay-form" method="post" action="<?= $this->e($payment['action'] ?? '') ?>" accept-charset="<?= $this->e($payment['charset'] ?? 'UTF-8') ?>">
<?php foreach ($payment['fields'] as $field => $value): ?><input type="hidden" name="<?= $this->e($field) ?>" value="<?= $this->e((string) $value) ?>"><?php endforeach ?>
<button class="yc-button yc-button-primary" id="yc-pay-button" type="<?= $payment['kind'] === 'inicis' ? 'button' : 'submit' ?>">결제창 열기</button>
</form>
<p id="yc-pay-message" class="yc-help" role="status"></p>
<p><a class="yc-more" href="<?= $this->e($url) ?>/order?number=<?= rawurlencode($order['number']) ?>">주문 상세로 돌아가기</a></p></section></div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php if ($payment['kind'] === 'inicis'): ?>
<script src="<?= $this->e($payment['script']) ?>"></script>
<script>(()=>{'use strict';const button=document.getElementById('yc-pay-button'),message=document.getElementById('yc-pay-message');const open=()=>{if(!window.INIStdPay){message.textContent='결제창 연결을 확인해 주세요. 잠시 후 다시 시도해 주세요.';return}window.INIStdPay.pay('yc-pay-form')};button.addEventListener('click',open);window.setTimeout(open,300);})();</script>
<?php else: ?>
<script>(()=>{'use strict';window.setTimeout(()=>{document.getElementById('yc-pay-form').submit();},300);})();</script>
<?php endif ?>
<?php $this->stop() ?>
