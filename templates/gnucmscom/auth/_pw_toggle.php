<?php // 비밀번호 칸 안의 눈 단추. 스크립트가 켜져야 쓸 수 있어 처음엔 숨긴다. auth/_pw_toggle_script 가 꺼낸다. ?>
<button class="pw-toggle" type="button" data-pw-toggle hidden
        aria-pressed="false" aria-label="비밀번호 표시" title="비밀번호 표시">
  <span class="pw-ico pw-ico-show" aria-hidden="true"><?= $this->icon('eye', 17) ?></span>
  <span class="pw-ico pw-ico-hide" aria-hidden="true"><?= $this->icon('eye-off', 17) ?></span>
</button>
