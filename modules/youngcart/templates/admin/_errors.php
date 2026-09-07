<?php if ($notice !== ''): ?><div class="alert alert-success" role="status"><?= $this->e($notice) ?></div><?php endif ?>
<?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $field => $message): ?><li><?= is_string($field) ? '<code>' . $this->e($field) . '</code> ' : '' ?><?= $this->e($message) ?></li><?php endforeach ?></ul></div><?php endif ?>
