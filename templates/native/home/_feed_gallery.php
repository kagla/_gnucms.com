<?php // 갤러리형: 커버 카드 캐러셀. 사진 게시판에 맞는다. ?>
<div class="carousel">
  <?php foreach ($board['latest_posts'] as $post): ?>
    <div class="carousel-item">
      <a class="card post-card" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>">
        <?php $this->insert('posts/_thumb', ['post' => $post]) ?>
        <div class="card-body">
          <h3 class="card-title"><?= $this->e($post['title']) ?> <?php $this->insert('posts/_count', ['post' => $post]) ?></h3>
          <?php $this->insert('posts/_meta', ['post' => $post]) ?>
        </div>
      </a>
    </div>
  <?php endforeach ?>
</div>
