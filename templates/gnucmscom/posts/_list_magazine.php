<?php // 매거진형: 썸네일과 발췌문을 나란히. 읽을거리 중심 게시판에 맞는다. ?>
<ul class="list card post-rows">
  <?php foreach ($list['data'] as $post): ?>
    <li class="list-row">
      <a class="post-row-thumb" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>" tabindex="-1" aria-hidden="true">
        <?php $this->insert('posts/_thumb', ['post' => $post, 'board_badge' => false]) ?>
      </a>
      <div class="post-row-body">
        <a class="post-row-title" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>"><?= $this->e($post['title']) ?> <?php $this->insert('posts/_count', ['post' => $post]) ?></a>
        <?php if (!empty($post['excerpt'])): ?><p class="post-row-excerpt"><?= $this->e($post['excerpt']) ?></p><?php endif ?>
        <?php $this->insert('posts/_meta', ['post' => $post]) ?>
      </div>
    </li>
  <?php endforeach ?>
</ul>
