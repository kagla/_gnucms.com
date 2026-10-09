<?php
$privacyMode = (string) ($site['privacy_mode'] ?? 'off');
$privacyConfig = [
    'mode' => $privacyMode,
    'analytics' => (string) ($site['analytics_html'] ?? ''),
    // Google 태그는 CMP를 불러오는 진입점이다. 광고 동의는 Google의 TCF/GPP 연동이 처리한다.
    'advertising' => $privacyMode === 'external' ? (string) ($site['adsense_html'] ?? '') : '',
];
?>
<?= (string) ($site['site_verification_html'] ?? '') ?>
<script type="application/json" id="gnucms-privacy-config"><?= json_encode($privacyConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="<?= $this->asset('privacy.js') ?>"></script>
<?= (string) ($site['privacy_cmp_html'] ?? '') ?>
<?php if ($privacyMode !== 'external'): ?>
<?= (string) ($site['adsense_html'] ?? '') ?>
<?php endif ?>
