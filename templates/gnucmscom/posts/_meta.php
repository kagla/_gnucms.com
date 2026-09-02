<?php // 목록 항목의 글쓴이·날짜·집계. 형태마다 같은 정보를 같은 모양으로 쓴다. ?>
<div class="post-meta">
  <span class="avatar avatar-placeholder avatar-xs">
    <span class="avatar-inner" data-tone="<?= $this->e(mb_strlen((string) $post['author_name']) % 6) ?>" aria-hidden="true"><?php if (!empty($post['author_avatar_file'])): ?><img src="<?= $this->url('avatar.show', ['file' => $post['author_avatar_file']]) ?>" alt=""><?php else: ?><span><?= $this->e(mb_strtoupper(mb_substr((string) $post['author_name'], 0, 1))) ?></span><?php endif ?></span>
  </span>
  <span class="post-author" title="<?= $this->e($post['author_name']) ?>"><?= $this->e($this->truncate($post['author_name'], 8)) ?></span>
  <span aria-hidden="true">·</span>
  <time datetime="<?= $this->e($post['created_at']) ?>"><?= $this->compactDate($post['created_at']) ?></time>
</div>
<div class="post-stats">
  <span><?= $this->icon('eye', 14) ?><?= $this->e($post['view_count']) ?></span>
  <?php if ($post['file_count'] > 0): ?><span><?= $this->icon('clip', 14) ?><?= $this->e($post['file_count']) ?></span><?php endif ?>
</div>
