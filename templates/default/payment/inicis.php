<form id="yc-pay-form" method="post" action="<?= $this->e($payment['action'] ?? '') ?>" accept-charset="<?= $this->e($payment['charset'] ?? 'UTF-8') ?>">
<?php foreach ($payment['fields'] as $field => $value): ?><input type="hidden" name="<?= $this->e($field) ?>" value="<?= $this->e((string) $value) ?>"><?php endforeach ?>
</form>
<p id="yc-pay-message" class="yc-help" role="status" hidden></p>
<div id="yc-pay-progress" class="yc-pay-progress" role="status" aria-live="polite" aria-labelledby="yc-pay-progress-label" hidden>
  <div class="yc-pay-progress-card"><span class="yc-pay-spinner" aria-hidden="true"></span><strong id="yc-pay-progress-label">결제 결과를 확인하고 주문 결과를 불러오는 중입니다.</strong><span>잠시만 기다려 주세요.</span></div>
</div>
