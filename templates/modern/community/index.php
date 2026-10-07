<?php $this->layout('layout') ?>
<?php $this->start('title') ?>커뮤니티 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>community<?php $this->stop() ?>
<?php $this->start('body_class') ?>modern-community<?php $this->stop() ?>
<?php $this->start('seo_description') ?>GNUCMS 설치와 운영 이야기를 나누고, GNUCMS로 만든 사이트를 갤러리에서 둘러보세요.<?php $this->stop() ?>
<?php $this->start('body') ?>
<header class="cb-community-heading">
  <p class="cb-kicker"><?= $this->icon('comment', 18) ?>GNUCMS COMMUNITY</p>
  <h1>함께 만들고, 운영하는 이야기</h1>
  <p>설치와 사용 경험을 나누고, GNUCMS로 만든 사이트를 둘러보세요.</p>
</header>
<?php $this->insert('community/_nav', ['community_boards' => $boards, 'nav_section' => 'community']) ?>
<?php $this->insert('community/_content', ['boards' => $boards, 'on_home' => false]) ?>
<?php $this->stop() ?>
