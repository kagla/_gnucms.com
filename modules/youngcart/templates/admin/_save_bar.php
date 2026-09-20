<div class="yc-save-bar">
  <p data-yc-save-status role="status">입력한 내용을 확인한 뒤 저장해 주세요.</p>
  <div class="row-actions">
    <?php if (isset($back_url)): ?><a class="btn btn-sm" href="<?= $this->e($back_url) ?>">목록으로</a><?php endif ?>
    <button class="btn btn-primary" type="submit"<?= !empty($save_action) ? ' name="action" value="save"' : '' ?>><?= $this->icon('check', 17) ?><?= $this->e($save_label ?? '변경사항 저장') ?></button>
  </div>
</div>
