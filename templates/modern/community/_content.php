<?php
$on_home = $on_home ?? false;
$galleryBoards = [];
$recent = [];
foreach ($boards as $feedBoard) {
    if (($feedBoard['list_type'] ?? 'list') === 'gallery') {
        $galleryBoards[] = $feedBoard;
        continue;
    }
    foreach ($feedBoard['latest_posts'] as $feedPost) {
        $recent[] = ['board' => $feedBoard, 'post' => $feedPost];
    }
}
usort($recent, static fn (array $left, array $right): int =>
    strcmp((string) $right['post']['created_at'], (string) $left['post']['created_at'])
    ?: ((int) $right['post']['id'] <=> (int) $left['post']['id']));
$recent = array_slice($recent, 0, $on_home ? 6 : 10);
?>
<div class="cb-community-content<?= $on_home ? ' cb-wrap' : '' ?>" id="community">
  <div class="cb-community-columns">
    <section class="cb-recent-panel" aria-labelledby="cb-recent-title">
      <header class="cb-feed-heading"><h2 id="cb-recent-title"><?= $this->icon('comment', 19) ?>최신글</h2><a href="<?= $this->url('posts.all') ?>">전체보기 <?= $this->icon('arrow-right', 16) ?></a></header>
      <?php $this->insert('community/_recent', ['recent' => $recent, 'on_home' => $on_home]) ?>
      <a class="cb-community-more" href="<?= $this->e($this->base . '/community') ?>">커뮤니티 둘러보기 <?= $this->icon('arrow-right', 16) ?></a>
    </section>
    <div class="cb-gallery-panels">
      <?php foreach ($galleryBoards as $galleryBoard): ?>
        <section class="cb-gallery-panel" aria-label="<?= $this->e($galleryBoard['name']) ?>">
          <header class="cb-feed-heading"><div><h2><?= $this->icon('images', 19) ?><?= $this->e($galleryBoard['name']) ?></h2><p>GNUCMS로 만든 사이트를 만나보세요.</p></div><a href="<?= $this->url('posts.index', ['key' => $galleryBoard['board_key']]) ?>">전체보기 <?= $this->icon('arrow-right', 16) ?></a></header>
          <?php $this->insert('community/_gallery', ['board' => $galleryBoard, 'on_home' => $on_home]) ?>
        </section>
      <?php endforeach ?>
      <?php if ($galleryBoards === []): ?><section class="cb-gallery-panel"><header class="cb-feed-heading"><h2><?= $this->icon('images', 19) ?>사이트 갤러리</h2></header><p class="cb-feed-empty">공개된 갤러리 게시판을 준비하고 있습니다.</p></section><?php endif ?>
    </div>
  </div>
</div>
