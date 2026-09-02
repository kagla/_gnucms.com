<?php
$all = $scope === 'all';
$paginate = (bool) ($paginate ?? false);
?>
<nav class="post-adjacent" aria-label="<?= $all ? '전체 글' : '게시판 글' ?> 이전·다음 탐색">
  <div class="post-adjacent-grid">
    <?php foreach ([['previous', '이전 글', 'arrow-left'], ['next', '다음 글', 'arrow-right']] as [$key, $label, $icon]): ?>
      <?php $item = $adjacent[$key] ?? null; ?>
      <?php if ($item !== null): ?>
        <?php
        $params = [];
        if ($all) { $params[] = 'scope=all'; }
        if (!$all && $paginate && ($item['page'] ?? 1) > 1) { $params[] = 'page=' . (int) $item['page']; }
        $suffix = $params !== [] ? '?' . implode('&', $params) : '';
        ?>
        <a class="post-adjacent-link post-adjacent-<?= $this->e($key) ?>" rel="<?= $key === 'previous' ? 'prev' : 'next' ?>" href="<?= $this->url('posts.show', ['id' => $item['id']]) ?><?= $this->e($suffix) ?>">
          <span class="post-adjacent-icon"><?= $this->icon($icon, 14) ?></span>
          <span class="post-adjacent-title"><?php if ($item['is_secret']): ?><?= $this->icon('lock', 13) ?> <?php endif ?><?= $this->e($item['title']) ?></span>
          <span class="post-adjacent-mobile-label"><?= $label ?></span>
        </a>
      <?php else: ?>
        <span class="post-adjacent-link post-adjacent-empty post-adjacent-<?= $this->e($key) ?>" aria-hidden="true">
          <span class="post-adjacent-icon"><?= $this->icon($icon, 14) ?></span>
          <span class="post-adjacent-mobile-label"><?= $label ?></span>
        </span>
      <?php endif ?>
    <?php endforeach ?>
  </div>
</nav>
