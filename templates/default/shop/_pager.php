<?php
if (($list['total_pages'] ?? 0) <= 1) return;
$window = 3;
$start = max(1, $list['page'] - $window);
$end = min($list['total_pages'], $list['page'] + $window);
?>
<nav class="pager" aria-label="페이지 이동"><div class="join">
  <?php if ($list['page'] > 1): ?><a class="join-item btn btn-sm" rel="prev" href="<?= $this->e($page_url($list['page'] - 1)) ?>" aria-label="이전 페이지"><?= $this->icon('chevron-left', 15) ?></a><?php endif ?>
  <?php if ($start > 1): ?><a class="join-item btn btn-sm" href="<?= $this->e($page_url(1)) ?>">1</a><?php if ($start > 2): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?><?php endif ?>
  <?php for ($p = $start; $p <= $end; $p++): ?>
    <?php if ($p === $list['page']): ?><span class="join-item btn btn-sm btn-active" aria-current="page"><?= $p ?></span>
    <?php else: ?><a class="join-item btn btn-sm" href="<?= $this->e($page_url($p)) ?>" aria-label="<?= $p ?> 페이지"><?= $p ?></a><?php endif ?>
  <?php endfor ?>
  <?php if ($end < $list['total_pages']): ?><?php if ($end < $list['total_pages'] - 1): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?><a class="join-item btn btn-sm" href="<?= $this->e($page_url($list['total_pages'])) ?>"><?= $list['total_pages'] ?></a><?php endif ?>
  <?php if ($list['page'] < $list['total_pages']): ?><a class="join-item btn btn-sm" rel="next" href="<?= $this->e($page_url($list['page'] + 1)) ?>" aria-label="다음 페이지"><?= $this->icon('chevron-right', 15) ?></a><?php endif ?>
</div></nav>
