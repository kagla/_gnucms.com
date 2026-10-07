<div class="cb-gallery-grid">
  <?php foreach (array_slice($board['latest_posts'], 0, $on_home ? 3 : 8) as $post): ?>
    <article class="cb-gallery-card">
      <a class="cb-gallery-link" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>">
        <?php $this->insert('posts/_thumb', ['post' => $post, 'board' => $board, 'board_badge' => false]) ?>
        <div class="cb-gallery-title"><h3><?= $this->e($post['title']) ?></h3><?php $this->insert('posts/_count', ['post' => $post]) ?></div>
        <?php $this->insert('posts/_meta', ['post' => $post, 'compact_date' => !$on_home, 'compact_mobile_date' => $on_home]) ?>
      </a>
    </article>
  <?php endforeach ?>
</div>
<?php if ($board['latest_posts'] === []): ?><p class="cb-feed-empty">아직 등록된 사이트가 없습니다.</p><?php endif ?>
