<?php
// 가입 동의 항목. 관리자가 약관 관리에서 붙인 것만, 정한 차례대로 나온다.
// 필수는 체크해야 가입되고, 선택은 안 해도 가입된다. 어느 쪽이든 기록은 남는다.
?>
<?php if ($consent_documents !== []): ?>
  <fieldset class="fieldset consent">
    <?php foreach ($consent_documents as $doc): ?>
      <?php
      $field = 'agree_' . $doc['id'];
      $is_required = (int) $doc['required'] === 1;
      ?>
      <label class="label check-row<?php if (array_key_exists($field, $errors)): ?> is-invalid<?php endif ?>">
        <input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="<?= $this->e($field) ?>" value="1"<?= ($values[$field] ?? false) ? ' checked' : '' ?><?= $is_required ? ' required' : '' ?>>
        <span><a class="link" href="<?= $this->url('terms.show', ['slug' => $doc['slug']]) ?>" target="_blank" rel="noopener"><?= $this->e($doc['title']) ?></a> 동의
          <span class="badge <?= $is_required ? 'badge-error' : 'badge-ghost' ?> badge-soft badge-xs"><?= $is_required ? '필수' : '선택' ?></span></span>
      </label>
      <?php if (array_key_exists($field, $errors)): ?><p class="validator-hint"><?= $this->e($errors[$field]) ?></p><?php endif ?>
    <?php endforeach ?>
  </fieldset>
<?php endif ?>
