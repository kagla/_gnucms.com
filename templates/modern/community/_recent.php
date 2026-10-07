<?php $today = $this->date(time(), 'Y-m-d'); ?>
<ul class="cb-recent-list">
  <?php foreach ($recent as $entry): $post = $entry['post']; $feedBoard = $entry['board']; ?>
    <?php $shortFormat = $this->date($post['created_at'], 'Y-m-d') === $today ? 'H:i' : 'm-d'; ?>
    <li>
      <div class="cb-recent-title">
        <?php if ($post['is_notice']): ?><span class="cb-notice-label">공지</span><?php endif ?>
        <a href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>"><?= $this->e($post['title']) ?></a>
        <?php if ($post['is_secret']): ?><?= $this->icon('lock', 13) ?><?php endif ?>
        <?php $this->insert('posts/_count', ['post' => $post]) ?>
      </div>
      <div class="cb-recent-meta"><a href="<?= $this->url('posts.index', ['key' => $feedBoard['board_key']]) ?>"><?= $this->e($feedBoard['name']) ?></a><span class="cb-recent-author"><?= $this->e($post['author_name']) ?></span><time datetime="<?= $this->e($post['created_at']) ?>" title="<?= $this->e($this->date($post['created_at'])) ?>"><span<?= $on_home ? ' class="feed-line-date-full"' : '' ?>><?= $this->e($this->date($post['created_at'])) ?></span><?php if ($on_home): ?><span class="feed-line-date-mobile"><?= $this->e($this->date($post['created_at'], $shortFormat)) ?></span><?php endif ?></time></div>
    </li>
  <?php endforeach ?>
  <?php if ($recent === []): ?><li class="cb-feed-empty">아직 등록된 글이 없습니다.</li><?php endif ?>
</ul>
