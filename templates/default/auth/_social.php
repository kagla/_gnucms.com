<?php if ($oauth_providers !== []): ?>
  <div class="divider">또는</div>
  <div class="social-list">
    <?php foreach ($oauth_providers as $provider): ?>
      <a class="btn btn-outline btn-block social-btn social-<?= $this->e($provider['key']) ?>" href="<?= $this->url('oauth.start', ['provider' => $provider['key']]) ?>">
        <span class="social-mark" aria-hidden="true"><?php if ($provider['key'] === 'google'): ?>G<?php elseif ($provider['key'] === 'naver'): ?>N<?php elseif ($provider['key'] === 'kakao'): ?>K<?php else: ?>⌘<?php endif ?></span>
        <?= $this->e($provider['label']) ?>로 계속하기
      </a>
    <?php endforeach ?>
  </div>
  <?php $this->insert('auth/_social_consent') ?>
<?php endif ?>
