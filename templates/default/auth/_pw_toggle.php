<?php // 비밀값 칸 안의 눈 단추. 스크립트가 켜져야 쓸 수 있어 처음엔 숨긴다. ?>
<?php $toggle_label = isset($toggle_label) ? (string) $toggle_label : '비밀번호' ?>
<button class="pw-toggle" type="button" data-pw-toggle data-pw-label="<?= $this->e($toggle_label) ?>" hidden
        aria-pressed="false" aria-label="<?= $this->e($toggle_label) ?> 표시" title="<?= $this->e($toggle_label) ?> 표시">
  <span class="pw-ico pw-ico-show" aria-hidden="true"><?= $this->icon('eye', 17) ?></span>
  <span class="pw-ico pw-ico-hide" aria-hidden="true"><?= $this->icon('eye-off', 17) ?></span>
</button>
