<?php $this->layout('layout') ?>
<?php $this->start('title') ?>주문 상세 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>modules/youngcart<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
<?php $this->insert('_header') ?>
<?php if ($just_ordered): ?><?php $this->insert('_steps', ['step' => 'order']) ?><section class="yc-order-success"><span><?= $this->icon('check', 34) ?></span><h1>주문이 접수되었어요</h1><p>주문번호를 보관해 주세요. 주문 조회에서 진행 상황을 확인할 수 있습니다.</p><strong><?= $this->e($order['number']) ?></strong></section>
<?php else: ?><div class="yc-page-heading"><div><p class="yc-kicker"><?= $this->e($order['number']) ?></p><h1 class="yc-title">주문 상세</h1></div><a class="yc-more" href="<?= $this->e($url) ?>/orders">주문 조회 <?= $this->icon('chevron-right', 17) ?></a></div><?php endif ?>
<?php $this->insert('_feedback') ?>
<div class="yc-commerce-grid"><div class="yc-checkout-sections"><section class="yc-panel"><div class="yc-section-heading"><h2>진행 상황</h2><span class="yc-status" data-status="<?= $this->e($order['status']) ?>"><?= $this->e($statuses[$order['status']]) ?></span></div><ol class="yc-order-timeline"><?php foreach ($order['history'] as $event): ?><li><strong><?= $this->e($statuses[$event['status']]) ?></strong><time><?= date('Y.m.d H:i', (int) $event['created_at']) ?></time></li><?php endforeach ?></ol></section>
<?php $this->insert('_order_detail') ?>
</div><aside class="yc-order-summary"><h2><?= $order['status'] === 'cancelled' ? '취소된 주문 금액' : '주문 금액' ?></h2><?php $this->insert('_totals', ['quote' => $order]) ?><div class="yc-order-notice"><strong>주문 접수 안내</strong><p><?= nl2br($this->e($order['order_notice'])) ?></p><?php if ($order['payment_method'] === ''): ?><p>온라인 결제 내역이 없는 주문입니다.</p><?php endif ?></div>
<?php if ($pay_in_progress): ?><div class="yc-order-notice"><strong>결제 확인 중</strong><p>결제 결과를 확인하는 중입니다. 잠시 후 이 화면을 다시 열어 주세요.</p></div>
<?php elseif ($pay_url !== null): ?><div class="yc-order-notice"><strong>결제 대기</strong><p><?= $pay_state === 'closed' ? '결제창이 닫혔습니다. ' : ($pay_state === 'failed' ? '결제 결과를 확인하지 못했습니다. 다시 시도하거나 상점에 문의해 주세요. ' : '') ?>결제 기한 <?= $this->e(date('Y-m-d H:i', (int) $order['pay_by'])) ?></p><a class="yc-button yc-button-primary yc-button-block" href="<?= $this->e($pay_url) ?>">결제하기</a></div>
<?php elseif ($pay_expired): ?><div class="yc-order-notice"><strong>결제 대기</strong><p>결제 기한이 지났습니다. 주문을 다시 접수해 주세요.</p></div><?php endif ?>
<?php if ($order['payment_method'] === 'manual_transfer' && $order['status'] === 'pending'): ?><div class="yc-order-notice"><strong>입금 안내</strong><p><?= $this->e(($order['payment']['bank'] ?? '') . ' ' . ($order['payment']['account'] ?? '') . ' (예금주 ' . ($order['payment']['holder'] ?? '') . ')') ?></p><p>입금자명 <?= $this->e(($order['payment']['depositor'] ?? '') !== '' ? $order['payment']['depositor'] : $order['buyer_name']) ?> · 입금 기한 <?= $this->e(date('Y-m-d H:i', (int) $order['pay_by'])) ?></p></div><?php endif ?>
<?php if ((int) $order['paid_at'] > 0): ?><div class="yc-order-notice"><strong>결제 완료</strong><p><?= $this->e($method_labels[$order['payment_method']] ?? $order['payment_method']) ?> · <?= number_format((int) $order['paid_amount']) ?>원 · <?= $this->e(date('Y-m-d H:i', (int) $order['paid_at'])) ?><?= (int) $order['refunded_amount'] > 0 ? ' · 환불 ' . number_format((int) $order['refunded_amount']) . '원' : '' ?></p></div><?php endif ?>
<?php if ($order['status'] === 'pending' && !$pay_in_progress): ?><details class="yc-cancel"><summary>주문 취소</summary><p>주문 전체가 취소됩니다. 계속하시겠어요?</p><form method="post" action="<?= $this->e($url) ?>/order/cancel"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="number" value="<?= $this->e($order['number']) ?>"><button class="yc-button yc-button-outline yc-button-block" type="submit">전체 주문 취소하기</button></form></details><?php endif ?>
<a class="yc-button yc-button-primary yc-button-block" href="<?= $this->e($url) ?>">계속 쇼핑하기</a></aside></div>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
