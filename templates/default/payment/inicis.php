<form id="yc-pay-form" method="post" action="<?= $this->e($payment['action'] ?? '') ?>" accept-charset="<?= $this->e($payment['charset'] ?? 'UTF-8') ?>">
<?php foreach ($payment['fields'] as $field => $value): ?><input type="hidden" name="<?= $this->e($field) ?>" value="<?= $this->e((string) $value) ?>"><?php endforeach ?>
<button class="yc-button yc-button-primary" id="yc-pay-button" type="<?= $payment['kind'] === 'inicis' ? 'button' : 'submit' ?>">결제창 열기</button>
</form>
<p id="yc-pay-message" class="yc-help" role="status"></p>
