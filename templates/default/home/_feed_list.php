<?php // 목록형: 제목과 날짜만. 가장 조밀하다. 공지·자료 게시판에 맞는다. ?>
<ul class="list card feed-lines">
  <?php foreach ($board['latest_posts'] as $post): ?>
    <li class="list-row">
      <?php if ($post['is_notice']): ?><span class="badge badge-primary badge-soft badge-sm">공지</span>
      <?php elseif ($post['category']): ?><span class="badge badge-ghost badge-sm"><?= $this->e($post['category']) ?></span><?php endif ?>
      <a class="feed-line-title" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>"><?= $this->e($post['title']) ?></a>
      <?php $this->insert('posts/_count', ['post' => $post]) ?>
      <?php if ($post['is_secret']): ?><span class="feed-line-lock" title="비밀글" aria-label="비밀글"><?= $this->icon('lock', 12) ?></span><?php endif ?>
      <time class="feed-line-date" datetime="<?= $this->e($post['created_at']) ?>"><?= $this->date($post['created_at'], 'm.d') ?></time>
    </li>
  <?php endforeach ?>
</ul>
