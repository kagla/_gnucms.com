<form id="yc-pay-form" method="post" action="<?= $this->e($payment['action'] ?? '') ?>" accept-charset="<?= $this->e($payment['charset'] ?? 'UTF-8') ?>">
<?php foreach ($payment['fields'] as $field => $value): ?><input type="hidden" name="<?= $this->e($field) ?>" value="<?= $this->e((string) $value) ?>"><?php endforeach ?>
</form>
<p id="yc-pay-message" class="yc-help" role="status" hidden></p>
