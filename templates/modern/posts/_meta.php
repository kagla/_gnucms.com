<?php
// 목록 항목의 글쓴이·날짜·집계. 갤러리에서는 조회수를 날짜 바로 뒤에 붙인다.
$inline_views = $inline_views ?? false;
$compact_date = $compact_date ?? false;
$compact_mobile_date = $compact_mobile_date ?? false;
?>
<div class="post-meta">
  <span class="avatar avatar-placeholder avatar-xs">
    <span class="avatar-inner" data-tone="<?= $this->e(mb_strlen((string) $post['author_name']) % 6) ?>" aria-hidden="true"><?php if (!empty($post['author_avatar_file'])): ?><img src="<?= $this->url('avatar.show', ['file' => $post['author_avatar_file']]) ?>" alt=""><?php else: ?><span><?= $this->e(mb_strtoupper(mb_substr((string) $post['author_name'], 0, 1))) ?></span><?php endif ?></span>
  </span>
  <span class="post-author" title="<?= $this->e($post['author_name']) ?>"><?= $this->e($this->truncate($post['author_name'], 8)) ?></span>
  <span aria-hidden="true">·</span>
  <?php if ($compact_date || $compact_mobile_date): ?>
    <time datetime="<?= $this->e($post['created_at']) ?>" title="<?= $this->e($this->date($post['created_at'])) ?>">
      <?php if ($compact_date): ?>
        <?= $this->e($this->compactDate($post['created_at'])) ?>
      <?php else: ?>
        <span class="feed-line-date-full"><?= $this->e($this->date($post['created_at'])) ?></span>
        <span class="feed-line-date-mobile"><?= $this->e($this->compactDate($post['created_at'])) ?></span>
      <?php endif ?>
    </time>
  <?php else: ?>
    <time datetime="<?= $this->e($post['created_at']) ?>" title="<?= $this->e($this->date($post['created_at'])) ?>"><?= $this->compactDate($post['created_at']) ?></time>
  <?php endif ?>
  <?php if ($inline_views): ?><span class="post-meta-views"><?= $this->icon('eye', 14) ?><?= $this->e($post['view_count']) ?></span><?php endif ?>
</div>
<?php if (!$inline_views || $post['file_count'] > 0): ?>
<div class="post-stats">
  <?php if (!$inline_views): ?><span><?= $this->icon('eye', 14) ?><?= $this->e($post['view_count']) ?></span><?php endif ?>
  <?php if ($post['file_count'] > 0): ?><span><?= $this->icon('clip', 14) ?><?= $this->e($post['file_count']) ?></span><?php endif ?>
</div>
<?php endif ?>
