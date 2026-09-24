<form id="yc-kcp-form" name="order_info" method="post" action="<?= $this->e($payment['action'] ?? '') ?>" accept-charset="UTF-8">
<?php foreach ($payment['fields'] as $field => $value): ?><input type="hidden" name="<?= $this->e($field) ?>" value="<?= $this->e((string) $value) ?>"><?php endforeach ?>
</form>
<p id="yc-kcp-message" class="yc-help" role="status" hidden></p>
<?php if (isset($payment['script'])): ?><script src="<?= $this->e($payment['script']) ?>" charset="UTF-8"></script><?php endif ?>
