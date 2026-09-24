<?php $this->layout('layout') ?>
<?php $this->start('title') ?>주문서 작성 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
<?php $this->insert('_header') ?>
<div class="yc-page-heading"><h1 class="yc-title">주문서 작성</h1><?php $this->insert('_steps') ?></div>
<?php $this->insert('_feedback') ?>
<?php $field = function(string $name, string $label, string $type = 'text', bool $required = true, string $autocomplete = '', int $max = 100) use ($input, $errors): void { ?>
<?php $value = (string) ($input[$name] ?? ''); if ($type === 'tel') $value = \GnuCms\Aligo\PhoneNumber::format($value); ?>
<label class="yc-field" for="yc-<?= $name ?>"><span><?= $label ?><?= $required ? ' <small aria-hidden="true">*</small>' : ' <small class="muted">선택</small>' ?></span><input class="input input-bordered" id="yc-<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" value="<?= $this->e($value) ?>" maxlength="<?= $max ?>"<?= $type === 'tel' ? ' placeholder="010-1234-5678"' : '' ?><?= $required ? ' required' : '' ?><?= $autocomplete !== '' ? ' autocomplete="' . $autocomplete . '"' : '' ?><?= isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="yc-error-' . $name . '"' : '' ?>><?php if (isset($errors[$name])): ?><small class="yc-inline-error" id="yc-error-<?= $name ?>"><?= $this->e($errors[$name]) ?></small><?php endif ?></label>
<?php }; ?>
<form method="post" action="<?= $this->e($url) ?>/checkout" class="yc-commerce-grid" data-yc-checkout>
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="flow" value="<?= $this->e($flow) ?>"><input type="hidden" name="checkout_token" value="<?= $this->e($checkout_token) ?>">
<div class="yc-checkout-sections">
<section class="yc-panel"><h2>배송지 정보</h2>
<?php if ($previous_addresses !== []): ?><label class="yc-field yc-previous-address" for="yc-previous-address"><span>이전 배송지</span><select class="select select-bordered" id="yc-previous-address" data-yc-previous-address>
  <option value="" selected disabled>배송지를 선택해 주세요</option><option value="new">새 배송지 입력</option>
  <?php foreach ($previous_addresses as $index => $previous): ?><option value="<?= (int) $previous['id'] ?>"
    data-recipient="<?= $this->e($previous['recipient']) ?>" data-recipient-phone="<?= $this->e($previous['recipient_phone']) ?>"
    data-postcode="<?= $this->e($previous['postcode']) ?>" data-address="<?= $this->e($previous['address']) ?>"
    data-address-detail="<?= $this->e($previous['address_detail']) ?>" data-delivery-note="<?= $this->e($previous['delivery_note']) ?>"><?= $this->e(((int) $previous['default_address'] === 1 ? '기본 배송지' : ($index === 0 ? '최근 배송지' : '이전 배송지')) . ' · ' . $previous['recipient'] . ' · (' . $previous['postcode'] . ') ' . $previous['address'] . ' ' . $previous['address_detail']) ?></option><?php endforeach ?>
</select></label><?php endif ?>
<div class="yc-form-grid"><?php $field('recipient', '받는 분', 'text', true, 'section-recipient shipping name'); $field('recipient_phone', '연락처', 'tel', true, 'section-recipient shipping tel', 30); ?></div>
<div class="yc-form-stack"><?php $this->insert('_postcode'); $field('address', '주소', 'text', true, 'shipping address-line1', 250); $field('address_detail', '상세주소', 'text', false, 'shipping address-line2', 250); $field('delivery_note', '배송 요청사항', 'text', false, '', 500); ?></div>
<?php if ($buyer_email_missing): ?><div class="yc-form-stack yc-delivery-contact"><?php $field('email', '주문 연락 이메일', 'email', true, 'email', 191); ?></div><?php endif ?>
<label class="yc-consent yc-save-address"><input class="checkbox checkbox-sm" type="checkbox" name="save_default_address" value="1"<?= ($input['save_default_address'] ?? '') === '1' ? ' checked' : '' ?>><span>이 배송지와 배송 요청사항을 기본값으로 저장</span></label></section>
<?php $this->insert('_order_products', ['items' => $quote['items']]) ?>
<section class="yc-panel"><h2>배송비</h2><?php $selectable = false; foreach ($quote['shipping'] as $delivery): ?><div class="yc-shipping-line"><div><strong><?= $this->e($delivery['name']) ?></strong><p class="muted"><?= $delivery['shared'] ? '상점 기본배송 · 같은 결제 방식끼리 묶음' : '상품별 배송' ?></p></div><div><?php if ($delivery['selectable']): $selectable = true; ?><select class="select select-bordered" name="shipping[<?= (int) $delivery['product_id'] ?>]" aria-label="<?= $this->e($delivery['name']) ?> 배송비 결제 방식"><option value="prepaid"<?= $delivery['mode'] === 'prepaid' ? ' selected' : '' ?>>선불</option><option value="cod"<?= $delivery['mode'] === 'cod' ? ' selected' : '' ?>>착불</option></select><?php else: ?><span><?= $delivery['mode'] === 'cod' ? '착불' : '선불' ?></span><?php endif ?> <strong><?= number_format($delivery['fee']) ?>원</strong></div></div><?php endforeach ?>
<?php if ($selectable): ?><button class="yc-button yc-button-small" type="submit" name="action" value="refresh" formnovalidate>배송비 반영하기</button><p class="yc-help">배송 방식을 변경하면 배송비를 반영한 뒤 주문해 주세요.</p><?php endif ?></section>
<?php if ($payment_methods !== []): ?>
<section class="yc-panel" id="yc-payment"><h2>결제 수단</h2><div class="yc-form-stack">
<?php $picked = $input['payment_method'] ?? array_key_first($payment_methods); foreach ($payment_methods as $key => $label): ?>
<label class="yc-choice"><input class="radio radio-sm" type="radio" name="payment_method" value="<?= $this->e($key) ?>" required<?= $picked === $key ? ' checked' : '' ?>><span><?= $this->e($label) ?></span><?php if ($key === 'card' && $payment['environment'] === 'test'): ?><span class="yc-test-badge">테스트 결제</span><?php endif ?></label>
<?php endforeach ?>
<?php if (isset($payment_methods['manual_transfer'])): ?><div class="yc-manual-transfer" data-yc-manual-transfer<?= $picked === 'manual_transfer' ? '' : ' hidden' ?>><p class="yc-help">무통장입금 계좌: <?= $this->e($payment['manual']['bank'] . ' ' . $payment['manual']['account']) ?> (예금주 <?= $this->e($payment['manual']['holder']) ?>). 접수 후 <?= (int) $payment['deadline_hours']['manual_transfer'] ?>시간 안에 입금해 주세요.</p>
<label class="yc-field" for="yc-depositor"><span>입금자명 <small class="muted">선택</small></span><input class="input input-bordered" id="yc-depositor" name="depositor" maxlength="100" value="<?= $this->e($input['depositor'] ?? '') ?>"<?= $picked === 'manual_transfer' ? '' : ' disabled' ?>></label></div><?php endif ?>
</div></section>
<?php endif ?>
</div>
<aside class="yc-order-summary"><h2>최종 주문 금액</h2><?php $this->insert('_totals') ?><?php if ($payment_methods === []): ?><div class="yc-order-notice"><strong>주문 접수 안내</strong><p><?= nl2br($this->e($settings['order_notice'])) ?></p><p>온라인 결제는 진행되지 않습니다.</p></div><?php endif ?>
<label class="yc-consent"><input class="checkbox checkbox-sm" type="checkbox" name="agree" value="1" required<?= ($input['agree'] ?? '') === '1' ? ' checked' : '' ?>><span>상품·수량·금액을 확인했으며, 주문 처리와 배송에 필요한 이름·연락처·주소 제공에 동의합니다.</span></label>
<button class="yc-button yc-button-primary yc-button-block" type="submit" name="action" value="place"<?= $quote['errors'] !== [] ? ' disabled' : '' ?>><?= number_format($quote['total']) ?>원 <?= $payment_methods === [] ? '주문 접수' : '주문하고 결제하기' ?></button><a class="yc-continue" href="<?= $this->e($url) ?>/cart">장바구니로 돌아가기</a>
</aside></form>
<?php if (isset($checkout_payment)): ?><section class="yc-panel yc-checkout-payment" aria-labelledby="yc-checkout-payment-title"><h2 id="yc-checkout-payment-title">카드 결제 <?php if ($payment['environment'] === 'test'): ?><span class="yc-test-badge">테스트 결제</span><?php endif ?></h2><p class="yc-help">결제창이 열리지 않으면 아래 버튼을 눌러 주세요. 승인되기 전에는 주문이 접수되지 않습니다.</p><?php $this->insert($checkout_template, ['payment' => $checkout_payment]) ?></section><?php endif ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script>(()=>{'use strict';const form=document.querySelector('[data-yc-checkout]');if(!form)return;const key='yc-checkout-scroll-<?= $this->e($checkout_token) ?>';try{const saved=sessionStorage.getItem(key);if(saved!==null){sessionStorage.removeItem(key);const y=Number(saved);if(Number.isFinite(y)){window.scrollTo(0,y);requestAnimationFrame(()=>window.scrollTo(0,y))}}form.addEventListener('submit',event=>{const button=event.submitter;if(!button||(button.name==='action'&&button.value==='place'))sessionStorage.setItem(key,String(window.scrollY))})}catch(e){}})();</script><script src="<?= $this->asset('youngcart.js') ?>" defer></script><script src="<?= $this->asset('youngcart-postcode.js') ?>" defer></script><?php if (isset($checkout_payment)) $this->insert($checkout_template . '_scripts', ['payment' => $checkout_payment]); ?><?php $this->stop() ?>
