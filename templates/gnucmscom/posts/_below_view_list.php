<?php
$belowListUrl = function ($q, $category, $page, $selectedView = null) use ($current_post_id, $navigation_scope): string {
    $params = [];
    if ($q) { $params[] = 'q=' . rawurlencode((string) $q); }
    if ($category) { $params[] = 'category=' . rawurlencode((string) $category); }
    if ($selectedView) { $params[] = 'view=' . rawurlencode((string) $selectedView); }
    if ($page && $page > 1) { $params[] = 'page=' . (int) $page; }
    if ($navigation_scope === 'all') { $params[] = 'scope=all'; }

    return $this->url('posts.show', ['id' => $current_post_id])
        . ($params !== [] ? '?' . implode('&', $params) : '') . '#below-view-list';
};
?>
<section class="below-view-list" id="below-view-list" aria-labelledby="below-view-list-title">
  <div class="below-view-list-head">
    <h2 class="section-title" id="below-view-list-title">목록</h2>
    <a class="btn btn-ghost btn-sm" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>">전체보기 <?= $this->icon('chevron-right', 14) ?></a>
  </div>
  <?php $this->insert('posts/_board_listing', [
    'list' => $list, 'board' => $board, 'query' => $query, 'view' => $view,
    'view_types' => $view_types, 'can_write' => $can_write,
    'current_post_id' => $current_post_id, 'list_url' => $belowListUrl,
  ]) ?>
</section>
