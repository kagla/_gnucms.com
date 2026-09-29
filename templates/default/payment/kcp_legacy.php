<form id="yc-kcp-legacy-form" name="order_info" method="post" action="<?= $this->e($payment['action'] ?? '') ?>" accept-charset="UTF-8">
<?php foreach ($payment['fields'] as $field => $value): ?><input type="hidden" name="<?= $this->e($field) ?>" value="<?= $this->e((string) $value) ?>"><?php endforeach ?>
</form>
<p id="yc-kcp-legacy-message" class="yc-help" role="status" hidden></p>
<?php if (isset($payment['script'])): ?><script src="<?= $this->e($payment['script']) ?>" charset="EUC-KR"></script><?php endif ?>
