<?php
if ($pagination['total_pages'] <= 1) {
    return;
}
$page = $pagination['page'];
$totalPages = $pagination['total_pages'];
$start = max(1, $page - 3);
$end = min($totalPages, $page + 3);
$pageUrl = fn (int $target): string => $this->url('admin.settings.maintenance', [], [
    'backup_page' => $section === 'backup' ? $target : $other_page,
    'garbage_page' => $section === 'garbage' ? $target : $other_page,
]) . '#' . $anchor;
?>
<nav class="pager" aria-label="<?= $this->e($label) ?> 페이지 이동">
  <div class="join">
    <?php if ($page > 1): ?><a class="join-item btn btn-sm" rel="prev" href="<?= $pageUrl($page - 1) ?>" aria-label="이전 페이지"><?= $this->icon('chevron-left', 15) ?></a><?php endif ?>
    <?php if ($start > 1): ?>
      <a class="join-item btn btn-sm" href="<?= $pageUrl(1) ?>" aria-label="1 페이지">1</a>
      <?php if ($start > 2): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?>
    <?php endif ?>
    <?php for ($p = $start; $p <= $end; $p++): ?>
      <?php if ($p === $page): ?>
        <span class="join-item btn btn-sm btn-active" aria-current="page"><?= $this->e((string) $p) ?></span>
      <?php else: ?>
        <a class="join-item btn btn-sm" href="<?= $pageUrl($p) ?>" aria-label="<?= $this->e((string) $p) ?> 페이지"><?= $this->e((string) $p) ?></a>
      <?php endif ?>
    <?php endfor ?>
    <?php if ($end < $totalPages): ?>
      <?php if ($end < $totalPages - 1): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?>
      <a class="join-item btn btn-sm" href="<?= $pageUrl($totalPages) ?>" aria-label="<?= $this->e((string) $totalPages) ?> 페이지"><?= $this->e((string) $totalPages) ?></a>
    <?php endif ?>
    <?php if ($page < $totalPages): ?><a class="join-item btn btn-sm" rel="next" href="<?= $pageUrl($page + 1) ?>" aria-label="다음 페이지"><?= $this->icon('chevron-right', 15) ?></a><?php endif ?>
  </div>
</nav>
