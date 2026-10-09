<?php $privacyMode = (string) ($site['privacy_mode'] ?? 'off'); ?>
<?php if (in_array($privacyMode, ['europe', 'europe_us'], true)): ?>
<button class="link link-hover privacy-preferences-link" type="button" data-privacy-preferences="europe" hidden>개인정보·쿠키 설정</button>
<?php endif ?>
<?php if (in_array($privacyMode, ['us_states', 'europe_us'], true)): ?>
<button class="link link-hover privacy-preferences-link" type="button" data-privacy-preferences="us_states" hidden>개인정보 판매·공유 거부</button>
<?php endif ?>
<?php if ($privacyMode === 'external'): ?>
<button class="link link-hover privacy-preferences-link" type="button" data-privacy-preferences="external" hidden>개인정보·쿠키 설정</button>
<?php endif ?>
