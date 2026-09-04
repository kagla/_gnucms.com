<?php // 갤러리형: 큰 썸네일 격자. 사진 게시판에 맞는다. ?>
<div class="card-grid">
  <?php foreach ($list['data'] as $post): ?>
    <a class="card post-card<?= isset($current_post_id) && (int) $current_post_id === (int) $post['id'] ? ' is-current-post' : '' ?>" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>">
      <?php $this->insert('posts/_thumb', ['post' => $post, 'board_badge' => false]) ?>
      <div class="card-body">
        <h2 class="card-title"><?php if ($post['is_notice']): ?><span class="badge <?= ($post['notice_scope'] ?? 'board') === 'global' ? 'badge-accent' : 'badge-primary' ?> badge-soft badge-sm"><?= ($post['notice_scope'] ?? 'board') === 'global' ? '전체 공지' : '공지' ?></span> <?php endif ?><?= $this->e($post['title']) ?> <?php $this->insert('posts/_count', ['post' => $post]) ?></h2>
        <?php $this->insert('posts/_meta', ['post' => $post, 'inline_views' => true]) ?>
      </div>
    </a>
  <?php endforeach ?>
</div>
