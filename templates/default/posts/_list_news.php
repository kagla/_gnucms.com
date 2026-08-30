<?php // 뉴스형: 사진 없이 제목과 발췌문 위주. 공지·소식 게시판에 맞는다. ?>
<ul class="list card post-rows post-rows-text">
  <?php foreach ($list['data'] as $post): ?>
    <li class="list-row">
      <div class="post-row-body">
        <p class="post-row-head">
          <?php if ($board['use_category'] && $post['category']): ?><span class="badge badge-ghost badge-sm"><?= $this->e($post['category']) ?></span><?php endif ?>
          <?php if ($post['is_secret']): ?><span class="post-row-lock" title="비밀글" aria-label="비밀글"><?= $this->icon('lock', 12) ?></span><?php endif ?>
          <a class="post-row-title" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>"><?= $this->e($post['title']) ?> <?php $this->insert('posts/_count', ['post' => $post]) ?></a>
        </p>
        <?php if (!empty($post['excerpt'])): ?><p class="post-row-excerpt"><?= $this->e($post['excerpt']) ?></p><?php endif ?>
        <?php $this->insert('posts/_meta', ['post' => $post]) ?>
      </div>
    </li>
  <?php endforeach ?>
</ul>
