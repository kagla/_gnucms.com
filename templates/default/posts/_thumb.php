<?php // 목록 썸네일. 이미지 첨부를 먼저 쓰고, 없으면 본문에 넣은 첫 사진을,
      // 그것도 없으면 제목 첫 글자를 쓴다. ?>
<figure class="post-cover" data-tone="<?= $this->e($post['id'] % 6) ?>">
  <?php if ($post['thumbnail_index'] !== null): ?>
    <img class="post-cover-img" src="<?= $this->url('files.image_variant', ['id' => $post['id'], 'index' => $post['thumbnail_index'], 'variant' => 'thumb']) ?>" alt="" loading="lazy" decoding="async" width="480" height="300">
  <?php elseif ($post['thumbnail_url'] !== null): ?>
    <img class="post-cover-img" src="<?= $this->e($post['thumbnail_url']) ?>" alt="" loading="lazy" decoding="async" width="480" height="300">
  <?php else: ?>
    <span class="post-cover-mark" aria-hidden="true"><?= $this->e(mb_substr((string) $post['title'], 0, 1)) ?></span>
  <?php endif ?>
  <span class="badge post-cover-badge"><?php if ($board['use_category'] && $post['category']): ?><?= $this->e($post['category']) ?><?php else: ?><?= $this->e($board['name']) ?><?php endif ?></span>
  <?php if ($post['is_secret']): ?><span class="post-cover-lock" title="비밀글" aria-label="비밀글"><?= $this->icon('lock', 13) ?></span><?php endif ?>
</figure>
