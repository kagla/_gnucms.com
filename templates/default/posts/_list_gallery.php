<?php // 갤러리형: 큰 썸네일 격자. 사진 게시판에 맞는다. ?>
<div class="card-grid">
  <?php foreach ($list['data'] as $post): ?>
    <a class="card post-card" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>">
      <?php $this->insert('posts/_thumb', ['post' => $post, 'board_badge' => false]) ?>
      <div class="card-body">
        <h2 class="card-title"><?= $this->e($post['title']) ?> <?php $this->insert('posts/_count', ['post' => $post]) ?></h2>
        <?php $this->insert('posts/_meta', ['post' => $post]) ?>
      </div>
    </a>
  <?php endforeach ?>
</div>
