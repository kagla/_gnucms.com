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
  <?php // 배지: 분류가 있으면 분류. 없으면 게시판 이름 — 단, 어느 게시판인지 문맥으로 알 수 없는 곳에서만.
        // 게시판 자기 목록과 게시판 이름이 제목으로 붙는 홈 피드에서는 같은 이름이 반복돼 소음이다. ?>
  <?php $badge = ($board['use_category'] && $post['category']) ? $post['category'] : (($board_badge ?? true) ? $board['name'] : null); ?>
  <?php if ($badge !== null): ?><span class="badge post-cover-badge"><?= $this->e($badge) ?></span><?php endif ?>
  <?php if ($post['is_secret']): ?><span class="post-cover-lock" title="비밀글" aria-label="비밀글"><?= $this->icon('lock', 13) ?></span><?php endif ?>
</figure>
