<?php
// 목록 항목의 글쓴이·날짜·집계. 갤러리에서는 조회수를 날짜 바로 뒤에 붙인다.
$inline_views = $inline_views ?? false;
?>
<div class="post-meta">
  <span class="avatar avatar-placeholder avatar-xs">
    <span class="avatar-inner" data-tone="<?= $this->e(mb_strlen((string) $post['author_name']) % 6) ?>" aria-hidden="true"><span><?= $this->e(mb_strtoupper(mb_substr((string) $post['author_name'], 0, 1))) ?></span></span>
  </span>
  <span class="post-author" title="<?= $this->e($post['author_name']) ?>"><?= $this->e($this->truncate($post['author_name'], 8)) ?></span>
  <span aria-hidden="true">·</span>
  <time datetime="<?= $this->e($post['created_at']) ?>"><?= $this->compactDate($post['created_at']) ?></time>
  <?php if ($inline_views): ?><span class="post-meta-views"><?= $this->icon('eye', 14) ?><?= $this->e($post['view_count']) ?></span><?php endif ?>
</div>
<?php if (!$inline_views || $post['file_count'] > 0): ?>
<div class="post-stats">
  <?php if (!$inline_views): ?><span><?= $this->icon('eye', 14) ?><?= $this->e($post['view_count']) ?></span><?php endif ?>
  <?php if ($post['file_count'] > 0): ?><span><?= $this->icon('clip', 14) ?><?= $this->e($post['file_count']) ?></span><?php endif ?>
</div>
<?php endif ?>
