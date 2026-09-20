<?php $this->layout('layout') ?>
<?php $this->start('title') ?>주문서 작성 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>modules/youngcart<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
<?php $this->insert('_header') ?>
<div class="yc-page-heading"><h1 class="yc-title">주문서 작성</h1><?php $this->insert('_steps') ?></div>
<?php $this->insert('_feedback') ?>
<?php if ($user_id === null): ?><div class="yc-guest-banner"><div><strong>비회원으로 주문하고 있어요</strong><p>주문번호와 비밀번호로 주문을 조회할 수 있습니다.</p></div><a class="yc-button yc-button-small" href="<?= $this->e($base) ?>/login?url=<?= $this->e(rawurlencode($url . '/checkout' . ($flow === 'buy' ? '?flow=buy' : ''))) ?>">로그인하고 주문</a></div><?php endif ?>
<?php $field = function(string $name, string $label, string $type = 'text', bool $required = true, string $autocomplete = '', int $max = 100) use ($input, $errors): void { ?>
<label class="yc-field" for="yc-<?= $name ?>"><span><?= $label ?><?= $required ? ' <small aria-hidden="true">*</small>' : ' <small class="muted">선택</small>' ?></span><input class="input input-bordered" id="yc-<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" value="<?= $this->e($input[$name] ?? '') ?>" maxlength="<?= $max ?>"<?= $required ? ' required' : '' ?><?= $autocomplete !== '' ? ' autocomplete="' . $autocomplete . '"' : '' ?><?= isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="yc-error-' . $name . '"' : '' ?>><?php if (isset($errors[$name])): ?><small class="yc-inline-error" id="yc-error-<?= $name ?>"><?= $this->e($errors[$name]) ?></small><?php endif ?></label>
<?php }; ?>
<form method="post" action="<?= $this->e($url) ?>/checkout" class="yc-commerce-grid" data-yc-checkout>
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="flow" value="<?= $this->e($flow) ?>"><input type="hidden" name="checkout_token" value="<?= $this->e($checkout_token) ?>">
<div class="yc-checkout-sections">
<section class="yc-panel"><h2>주문자 정보 <small>* 필수 입력</small></h2><div class="yc-form-grid"><?php $field('buyer_name', '이름', 'text', true, 'section-buyer name'); $field('phone', '연락처', 'tel', true, 'section-buyer tel', 30); $field('email', '이메일', 'email', true, 'email', 191); ?>
<?php if ($user_id === null): ?><label class="yc-field"><span>주문 조회 비밀번호 <small>*</small></span><input class="input input-bordered" type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required><small class="muted">8자 이상으로 설정해 주세요. 주문 조회에 사용합니다.</small></label><?php endif ?>
</div></section>
<section class="yc-panel"><div class="yc-section-heading"><h2>배송지 정보</h2><button class="yc-text-button" type="button" data-yc-copy-buyer hidden>주문자 정보와 동일</button></div><div class="yc-form-grid"><?php $field('recipient', '받는 분', 'text', true, 'section-recipient shipping name'); $field('recipient_phone', '연락처', 'tel', true, 'section-recipient shipping tel', 30); ?></div>
<div class="yc-form-stack"><?php $this->insert('_postcode'); $field('address', '주소', 'text', true, 'shipping address-line1', 250); $field('address_detail', '상세주소', 'text', false, 'shipping address-line2', 250); $field('delivery_note', '배송 요청사항', 'text', false, '', 500); ?></div></section>
<section class="yc-panel"><h2>주문 상품 <small><?= count($quote['items']) ?>개 항목</small></h2><?php foreach ($quote['items'] as $item): ?><div class="yc-order-line"><div><strong><?= $this->e($item['name']) ?></strong><p class="muted"><?= $item['kind'] === 'extra' ? '추가 구성 · ' : '' ?><?= $this->e($item['label']) ?> · <?= $item['quantity'] ?>개</p></div><strong><?= number_format($item['total']) ?>원</strong></div><?php endforeach ?></section>
<section class="yc-panel"><h2>배송비</h2><?php $selectable = false; foreach ($quote['shipping'] as $delivery): ?><div class="yc-shipping-line"><div><strong><?= $this->e($delivery['name']) ?></strong><p class="muted"><?= $delivery['shared'] ? '상점 기본배송 · 같은 결제 방식끼리 묶음' : '상품별 배송' ?></p></div><div><?php if ($delivery['selectable']): $selectable = true; ?><select class="select select-bordered" name="shipping[<?= (int) $delivery['product_id'] ?>]" aria-label="<?= $this->e($delivery['name']) ?> 배송비 결제 방식"><option value="prepaid"<?= $delivery['mode'] === 'prepaid' ? ' selected' : '' ?>>선불</option><option value="cod"<?= $delivery['mode'] === 'cod' ? ' selected' : '' ?>>착불</option></select><?php else: ?><span><?= $delivery['mode'] === 'cod' ? '착불' : '선불' ?></span><?php endif ?> <strong><?= number_format($delivery['fee']) ?>원</strong></div></div><?php endforeach ?>
<?php if ($selectable): ?><button class="yc-button yc-button-small" type="submit" name="action" value="refresh" formnovalidate>배송비 반영하기</button><p class="yc-help">배송 방식을 변경하면 배송비를 반영한 뒤 주문해 주세요. 비회원 비밀번호는 다시 입력해야 합니다.</p><?php endif ?></section>
<?php if ($payment_methods !== []): ?>
<section class="yc-panel" id="yc-payment"><h2>결제 수단</h2><div class="yc-form-stack">
<?php $picked = $input['payment_method'] ?? array_key_first($payment_methods); foreach ($payment_methods as $key => $label): ?>
<label class="yc-choice"><input class="radio radio-sm" type="radio" name="payment_method" value="<?= $this->e($key) ?>" required<?= $picked === $key ? ' checked' : '' ?>><span><?= $this->e($label) ?></span></label>
<?php endforeach ?>
<?php if (isset($payment_methods['manual_transfer'])): ?><div class="yc-manual-transfer"><p class="yc-help">무통장입금 계좌: <?= $this->e($payment['manual']['bank'] . ' ' . $payment['manual']['account']) ?> (예금주 <?= $this->e($payment['manual']['holder']) ?>). 접수 후 <?= (int) $payment['deadline_hours']['manual_transfer'] ?>시간 안에 입금해 주세요.</p>
<label class="yc-field" for="yc-depositor"><span>입금자명 <small class="muted">선택</small></span><input class="input input-bordered" id="yc-depositor" name="depositor" maxlength="100" value="<?= $this->e($input['depositor'] ?? '') ?>"></label></div><?php endif ?>
</div></section>
<?php endif ?>
</div>
<aside class="yc-order-summary"><h2>최종 주문 금액</h2><?php $this->insert('_totals') ?><?php if ($payment_methods === []): ?><div class="yc-order-notice"><strong>주문 접수 안내</strong><p><?= nl2br($this->e($settings['order_notice'])) ?></p><p>온라인 결제는 진행되지 않습니다.</p></div><?php endif ?>
<label class="yc-consent"><input class="checkbox checkbox-sm" type="checkbox" name="agree" value="1" required<?= ($input['agree'] ?? '') === '1' ? ' checked' : '' ?>><span>상품·수량·금액을 확인했으며, 주문 처리와 배송에 필요한 이름·연락처·주소 제공에 동의합니다.</span></label>
<button class="yc-button yc-button-primary yc-button-block" type="submit" name="action" value="place"<?= $quote['errors'] !== [] ? ' disabled' : '' ?>><?= number_format($quote['total']) ?>원 <?= $payment_methods === [] ? '주문 접수' : '주문하고 결제하기' ?></button><a class="yc-continue" href="<?= $this->e($url) ?>/cart">장바구니로 돌아가기</a>
</aside></form>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><script src="<?= $this->asset('youngcart-postcode.js') ?>" defer></script><?php $this->stop() ?>
