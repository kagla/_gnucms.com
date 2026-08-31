<?php
// 페이지 번호 한 벌. page_url 은 쪽 번호를 받아 주소를 돌려주는 클로저다.
// page_url 은 이스케이프하지 않은 주소를 돌려주고 이 조각이 출력할 때 e() 한다
// (그래서 url() 의 결과가 한 번 더 이스케이프돼도 라우트 경로에는 바꿀 문자가 없다).
if (($list['total_pages'] ?? 0) <= 1) {
    return;
}
$window = 3;
$start = max(1, $list['page'] - $window);
$end = min($list['total_pages'], $list['page'] + $window);
?>
<nav class="pager" aria-label="페이지 이동">
  <div class="join">
    <?php if ($list['page'] > 1): ?><a class="join-item btn btn-sm" rel="prev" href="<?= $this->e($page_url($list['page'] - 1)) ?>" aria-label="이전 페이지"><?= $this->icon('chevron-left', 15) ?></a><?php endif ?>
    <?php if ($start > 1): ?>
      <a class="join-item btn btn-sm" href="<?= $this->e($page_url(1)) ?>" aria-label="1 페이지">1</a>
      <?php if ($start > 2): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?>
    <?php endif ?>
    <?php for ($p = $start; $p <= $end; $p++): ?>
      <?php if ($p === $list['page']): ?>
        <span class="join-item btn btn-sm btn-active" aria-current="page"><?= $this->e((string) $p) ?></span>
      <?php else: ?>
        <a class="join-item btn btn-sm" href="<?= $this->e($page_url($p)) ?>" aria-label="<?= $this->e((string) $p) ?> 페이지"><?= $this->e((string) $p) ?></a>
      <?php endif ?>
    <?php endfor ?>
    <?php if ($end < $list['total_pages']): ?>
      <?php if ($end < $list['total_pages'] - 1): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?>
      <a class="join-item btn btn-sm" href="<?= $this->e($page_url($list['total_pages'])) ?>" aria-label="<?= $this->e((string) $list['total_pages']) ?> 페이지"><?= $this->e((string) $list['total_pages']) ?></a>
    <?php endif ?>
    <?php if ($list['page'] < $list['total_pages']): ?><a class="join-item btn btn-sm" rel="next" href="<?= $this->e($page_url($list['page'] + 1)) ?>" aria-label="다음 페이지"><?= $this->icon('chevron-right', 15) ?></a><?php endif ?>
  </div>
</nav>
