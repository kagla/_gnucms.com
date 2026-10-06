<?php $this->layout('layout') ?>
<?php $this->start('title') ?>주문서 작성 · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?><meta name="robots" content="noindex,nofollow"><script>(()=>{try{history.scrollRestoration='manual';const y=Number(sessionStorage.getItem('yc-checkout-scroll-<?= $this->e($checkout_token) ?>'));if(Number.isFinite(y)&&y>0){document.documentElement.dataset.ycScrollRestore='';document.documentElement.style.setProperty('--yc-scroll-restore-y',y+'px')}}catch(e){}})();</script><style>html[data-yc-scroll-restore] body{position:relative;top:calc(var(--yc-scroll-restore-y)*-1)}</style><link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>"><link rel="stylesheet" href="<?= $this->asset('youngcart-checkout-simple.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop">
<?php $this->insert('_header') ?>
<div class="yc-page-heading"><h1 class="yc-title">주문서 작성</h1><?php $this->insert('_steps') ?></div>
<?php $this->insert('_feedback') ?>
<?php $field = function(string $name, string $label, string $type = 'text', bool $required = true, string $autocomplete = '', int $max = 100) use ($input, $errors): void { ?>
<?php $value = (string) ($input[$name] ?? ''); if ($type === 'tel') $value = \GnuCms\Aligo\PhoneNumber::format($value); ?>
<label class="yc-field" for="yc-<?= $name ?>"><span><?= $label ?><?= $required ? ' <small aria-hidden="true">*</small>' : ' <small class="muted">선택</small>' ?></span><input class="input input-bordered" id="yc-<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" value="<?= $this->e($value) ?>" maxlength="<?= $max ?>"<?= $type === 'tel' ? ' placeholder="010-1234-5678"' : '' ?><?= $required ? ' required' : '' ?><?= $autocomplete !== '' ? ' autocomplete="' . $autocomplete . '"' : '' ?><?= isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="yc-error-' . $name . '"' : '' ?>><?php if (isset($errors[$name])): ?><small class="yc-inline-error" id="yc-error-<?= $name ?>"><?= $this->e($errors[$name]) ?></small><?php endif ?></label>
<?php }; ?>
<?php $shippingDetail = static function (array $delivery): string {
    $type = (int) ($delivery['type'] ?? -1);
    if ($type === 0) {
        $detail = '상점 기본배송 · ' . number_format((int) $delivery['bundle_count']) . '개 상품 '
            . number_format((int) $delivery['bundle_subtotal']) . '원 묶음';
        $minimum = (int) $delivery['free_minimum'];
        if ($minimum > 0) {
            $remaining = max(0, $minimum - (int) $delivery['bundle_subtotal']);
            return $detail . ' · 무료 기준 ' . number_format($minimum) . '원 '
                . ($remaining === 0 ? '충족' : '미달 (' . number_format($remaining) . '원 남음)');
        }
        return $detail . ' · 기본 배송비 ' . number_format((int) $delivery['unit_fee']) . '원';
    }
    if ($type === 1) return '상품별 무료배송';
    if ($type === 2) {
        $minimum = (int) $delivery['free_minimum'];
        $remaining = max(0, $minimum - (int) $delivery['subtotal']);
        return '상품 금액 ' . number_format((int) $delivery['subtotal']) . '원 · ' . number_format($minimum) . '원 이상 무료 · '
            . ($remaining === 0 ? '기준 충족' : number_format($remaining) . '원 더 담으면 무료');
    }
    if ($type === 3) return '상품별 고정 배송비 ' . number_format((int) $delivery['unit_fee']) . '원';
    if ($type === 4) return '기본 상품 ' . number_format((int) $delivery['quantity']) . '개 · '
        . number_format((int) $delivery['per_quantity']) . '개 단위 ' . number_format((int) $delivery['charge_count']) . '회 × '
        . number_format((int) $delivery['unit_fee']) . '원';
    return '배송비 계산 기준을 확인해 주세요.';
}; ?>
<form method="post" action="<?= $this->e($url) ?>/checkout" class="yc-commerce-grid" data-yc-checkout>
<input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="flow" value="<?= $this->e($flow) ?>"><input type="hidden" name="checkout_token" value="<?= $this->e($checkout_token) ?>">
<div class="yc-checkout-sections">
<?php if ($has_previous_addresses): ?><select class="yc-visually-hidden" id="yc-previous-address" name="previous_order_id" data-yc-previous-address aria-hidden="true" tabindex="-1">
  <option value="new">새 배송지 입력</option>
</select><?php endif ?>
<section class="yc-panel">
  <div class="yc-checkout-panel-heading"><h2>주문자 정보</h2><a class="yc-more" href="<?= $this->url('account.edit') ?>">회원정보 수정</a></div>
  <?php if ($buyer_missing['buyer_name'] || $buyer_missing['phone']): ?><p class="yc-help">처음 주문하실 때 주문자명과 휴대폰번호를 입력해 주세요. 주문이 접수되면 회원정보에 저장해 다음 주문부터 자동으로 사용합니다.</p><?php endif ?>
  <dl class="yc-buyer-summary">
    <?php foreach (['buyer_name' => '주문자명', 'phone' => '휴대폰번호'] as $name => $label): ?>
      <?php if (!$buyer_missing[$name]): ?>
        <div><dt><?= $this->e($label) ?></dt><dd><?= $this->e($name === 'phone' ? \GnuCms\Aligo\PhoneNumber::format($buyer_profile[$name]) : $buyer_profile[$name]) ?></dd></div>
      <?php endif ?>
    <?php endforeach ?>
  </dl>
  <?php foreach ($buyer_profile as $name => $value): ?>
    <?php if (!$buyer_missing[$name]): ?><input type="hidden" id="yc-<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" value="<?= $this->e($value) ?>"><?php endif ?>
  <?php endforeach ?>
  <?php if ($buyer_missing['buyer_name'] || $buyer_missing['phone']): ?>
    <div class="yc-form-grid">
      <?php if ($buyer_missing['buyer_name']) $field('buyer_name', '주문자명', 'text', true, 'name'); ?>
      <?php if ($buyer_missing['phone']) $field('phone', '휴대폰번호', 'tel', true, 'tel', 30); ?>

    </div>
<p class="yc-help">회원 이메일이 있으면 주문 알림에 사용합니다. 별도 이메일은 입력하지 않습니다.</p>
  <?php endif ?>
</section>
<?php if ($has_previous_addresses): ?>
<dialog class="yc-address-dialog" data-yc-previous-dialog data-endpoint="<?= $this->e($url) ?>/checkout/previous-addresses" aria-labelledby="yc-previous-title">
  <div class="yc-address-dialog-panel">
    <div class="yc-checkout-panel-heading"><h2 id="yc-previous-title">이전 배송지 불러오기</h2><button class="yc-button yc-button-small" type="button" data-yc-close-previous aria-label="닫기">닫기</button></div>
    <label class="yc-field"><span>주소 검색</span><input class="input input-bordered" type="search" data-yc-previous-search maxlength="100" placeholder="주소, 우편번호, 받는 분 검색"></label>
    <p class="yc-help" data-yc-previous-count role="status" aria-live="polite"></p>
    <div class="yc-previous-list" data-yc-previous-list aria-live="polite"></div>
    <div class="yc-previous-pagination"><button class="yc-button yc-button-small" type="button" data-yc-previous-prev>이전</button><span data-yc-previous-page></span><button class="yc-button yc-button-small" type="button" data-yc-previous-next>다음</button></div>
    <div class="yc-previous-dialog-footer"><button class="yc-button" type="button" data-yc-previous-new>새 배송지 입력</button></div>
  </div>
</dialog>
<?php endif ?>
<section class="yc-panel"><div class="yc-checkout-panel-heading"><h2>배송지 정보</h2><div class="yc-checkout-heading-actions"><?php if ($has_previous_addresses): ?><button class="yc-button yc-button-small" type="button" data-yc-open-previous>이전 배송지 불러오기</button><?php endif ?></div></div>
<?php $recipientSame = array_key_exists('recipient_same', $input) ? ($input['recipient_same'] === '1') : ((($input['recipient'] ?? '') === '' || ($input['recipient'] ?? '') === ($input['buyer_name'] ?? '')) && (($input['recipient_phone'] ?? '') === '' || preg_replace('/\D/', '', $input['recipient_phone'] ?? '') === preg_replace('/\D/', '', $input['phone'] ?? ''))); ?>
<label class="yc-consent yc-save-address"><input type="hidden" name="recipient_same" value="0"><input class="checkbox checkbox-sm" type="checkbox" name="recipient_same" value="1" data-yc-recipient-same<?= $recipientSame ? ' checked' : '' ?>><span>받는 분은 주문자와 같습니다</span></label>
<div class="yc-form-grid"><?php $field('recipient', '받는 분', 'text', true, 'section-recipient shipping name'); $field('recipient_phone', '연락처', 'tel', true, 'section-recipient shipping tel', 30); ?></div>
<div class="yc-form-stack"><?php $this->insert('_postcode'); $field('address', '주소', 'text', true, 'shipping address-line1', 250); $field('address_detail', '상세주소', 'text', false, 'shipping address-line2', 250); $this->insert('_delivery_note'); ?></div>
<label class="yc-consent yc-save-address"><input class="checkbox checkbox-sm" type="checkbox" name="save_default_address" value="1"<?= ($input['save_default_address'] ?? '') === '1' ? ' checked' : '' ?>><span>이 배송지·배송 요청사항을 다음 주문 기본값으로 저장</span></label></section>
<?php $this->insert('_order_products', ['items' => $quote['items']]) ?>
<details class="yc-panel yc-checkout-shipping-detail"><summary>배송비 상세</summary><p class="yc-help">상점 기본배송은 선불과 착불을 구분해 각각 한 번만 계산합니다.</p><?php $selectable = false; foreach ($quote['shipping'] as $delivery): ?><div class="yc-shipping-line"><div class="yc-shipping-line-info"><strong><?= $this->e($delivery['name']) ?></strong><p><?= $this->e($shippingDetail($delivery)) ?></p></div><div class="yc-shipping-charge"><?php if ($delivery['selectable']): $selectable = true; ?><select class="select select-bordered" name="shipping[<?= (int) $delivery['product_id'] ?>]" aria-label="<?= $this->e($delivery['name']) ?> 배송비 결제 방식"><option value="prepaid"<?= $delivery['mode'] === 'prepaid' ? ' selected' : '' ?>>선불</option><option value="cod"<?= $delivery['mode'] === 'cod' ? ' selected' : '' ?>>착불</option></select><?php else: ?><span><?= $delivery['mode'] === 'cod' ? '착불' : '선불' ?></span><?php endif ?><?php if ($delivery['shared'] && !$delivery['bundle_lead']): ?><strong class="yc-shipping-included">묶음 포함</strong><?php else: ?><strong><?= (int) $delivery['fee'] === 0 ? '무료' : number_format((int) $delivery['fee']) . '원' ?></strong><?php endif ?></div></div><?php endforeach ?>
<div class="yc-shipping-totals"><div><span>선불 배송비 합계<small>주문 금액에 포함</small></span><strong><?= (int) $quote['shipping_fee'] === 0 ? '무료' : number_format((int) $quote['shipping_fee']) . '원' ?></strong></div><div><span>착불 배송비 합계<small>상품 수령 시 별도 결제</small></span><strong><?= number_format((int) $quote['cod_fee']) ?>원</strong></div></div>
<?php if ($selectable): ?><button class="yc-button yc-button-small" type="submit" name="action" value="refresh" formnovalidate>배송비 반영하기</button><p class="yc-help">선불·착불을 변경한 뒤 배송비 반영하기를 눌러 합계 금액을 확인해 주세요.</p><?php endif ?></details>
<?php if ($payment_methods !== []): ?>
<section class="yc-panel" id="yc-payment"><h2>결제 수단</h2><div class="yc-form-stack">
<?php $picked = $input['payment_method'] ?? array_key_first($payment_methods); foreach ($payment_methods as $key => $label): ?>
<label class="yc-choice"><input class="radio radio-sm" type="radio" name="payment_method" value="<?= $this->e($key) ?>" required<?= $picked === $key ? ' checked' : '' ?>><span><?= $this->e($key === 'card' ? $label . ' · ' . $payment_provider_label : $label) ?></span><?php if ($key === 'card' && $payment['environment'] === 'test'): ?><span class="yc-test-badge">테스트 결제</span><?php endif ?></label>
<?php endforeach ?>
<?php if (isset($payment_methods['manual_transfer'])): ?><div class="yc-manual-transfer" data-yc-manual-transfer<?= $picked === 'manual_transfer' ? '' : ' hidden' ?>><p class="yc-help">무통장입금 계좌: <?= $this->e($payment['manual']['bank'] . ' ' . $payment['manual']['account']) ?> (예금주 <?= $this->e($payment['manual']['holder']) ?>). 접수 후 <?= (int) $payment['deadline_hours']['manual_transfer'] ?>시간 안에 입금해 주세요.</p>
<details class="yc-depositor-extra"><summary>입금자명이 주문자와 다른가요?</summary><label class="yc-field" for="yc-depositor"><span>입금자명 <small class="muted">선택</small></span><input class="input input-bordered" id="yc-depositor" name="depositor" maxlength="100" value="<?= $this->e($input['depositor'] ?? '') ?>"<?= $picked === 'manual_transfer' ? '' : ' disabled' ?>></label></details></div><?php endif ?>
</div></section>
<?php endif ?>
</div>
<aside class="yc-order-summary"><h2>최종 주문 금액</h2><?php $this->insert('_totals') ?><?php if ($payment_methods === []): ?><div class="yc-order-notice"><strong>주문 접수 안내</strong><p><?= nl2br($this->e($settings['order_notice'])) ?></p><p>온라인 결제는 진행되지 않습니다.</p></div><?php endif ?>
<label class="yc-consent"><input class="checkbox checkbox-sm" type="checkbox" name="agree" value="1" required<?= ($input['agree'] ?? '') === '1' ? ' checked' : '' ?>><span>상품·수량·금액을 확인했으며, <?= $buyer_missing['buyer_name'] || $buyer_missing['phone'] ? '주문자명·휴대폰번호의 회원정보 저장과 ' : '' ?>주문 처리와 배송에 필요한 이름·연락처·주소 및 등록된 이메일 사용에 동의합니다.</span></label>
<button class="yc-button yc-button-primary yc-button-block" type="submit" name="action" value="place"<?= $quote['errors'] !== [] ? ' disabled' : '' ?>><?= number_format($quote['total']) ?>원 <?= $payment_methods === [] ? '주문 접수' : '주문하고 결제하기' ?></button><a class="yc-continue" href="<?= $this->e($url) ?>/cart">장바구니로 돌아가기</a>
</aside></form>
<?php if (isset($checkout_payment)): ?><div class="yc-checkout-payment" aria-live="polite"><?php $this->insert($checkout_template, ['payment' => $checkout_payment]) ?></div><?php endif ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script>(()=>{'use strict';const form=document.querySelector('[data-yc-checkout]');if(!form)return;const key='yc-checkout-scroll-<?= $this->e($checkout_token) ?>';try{const saved=sessionStorage.getItem(key);if(saved!==null){const y=Number(saved);if(Number.isFinite(y)){const root=document.documentElement;const behavior=root.style.scrollBehavior;root.style.scrollBehavior='auto';window.scrollTo(0,y);root.style.scrollBehavior=behavior;root.removeAttribute('data-yc-scroll-restore');root.style.removeProperty('--yc-scroll-restore-y')}sessionStorage.removeItem(key)}form.addEventListener('submit',event=>{const button=event.submitter;if(!button||(button.name==='action'&&button.value==='place'))sessionStorage.setItem(key,String(window.scrollY))})}catch(e){}})();</script><script src="<?= $this->asset('youngcart.js') ?>" defer></script><script src="<?= $this->asset('youngcart-delivery-note.js') ?>" defer></script><script src="<?= $this->asset('youngcart-postcode.js') ?>" defer></script><script src="<?= $this->asset('youngcart-checkout-simple.js') ?>" defer></script><?php if (isset($checkout_payment)) $this->insert($checkout_template . '_scripts', ['payment' => $checkout_payment]); ?><?php $this->stop() ?>
