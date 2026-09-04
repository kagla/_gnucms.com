<?php $this->layout('layout') ?>
<?php
// 목록 주소 만들기. 이 파일 안에서만 쓰는 클로저다.
// 결과는 이스케이프하지 않은 순수 주소(& 그대로)다. 출력할 때 템플릿이 이스케이프한다.
$listUrl = function (array $board, $q, $category, $page, $view = null): string {
    $params = [];
    if ($q) { $params[] = 'q=' . rawurlencode((string) $q); }
    if ($category) { $params[] = 'category=' . rawurlencode((string) $category); }
    if ($view) { $params[] = 'view=' . $view; }
    if ($page && $page > 1) { $params[] = 'page=' . $page; }
    return $this->url('posts.index', ['key' => $board['board_key']]) . ($params !== [] ? '?' . implode('&', $params) : '');
};
// 머리글 검색 폼도 목록 partial보다 먼저 현재 보기 값을 써야 한다.
$view = $this->def($view ?? null, 'list');
$view_param = $view !== $this->def($board['list_type'] ?? null, 'list') ? $view : null;
$scope = ($query['scope'] ?? 'posts') === 'comments' ? 'comments' : 'posts';
$commentList = is_array($comment_list ?? null) ? $comment_list : null;
$commentUrl = function (int $page) use ($board, $query, $view_param): string {
    $params = ['scope=comments', 'q=' . rawurlencode((string) ($query['q'] ?? ''))];
    if ($query['category'] !== null && $query['category'] !== '') {
        $params[] = 'category=' . rawurlencode((string) $query['category']);
    }
    if ($view_param) { $params[] = 'view=' . rawurlencode((string) $view_param); }
    if ($page > 1) { $params[] = 'page=' . $page; }
    return $this->url('posts.index', ['key' => $board['board_key']]) . '?' . implode('&', $params);
};
?>

<?php $this->start('title') ?><?= $this->e($board['name']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $canonical = $site_url . '/boards/' . rawurlencode((string) $board['board_key'])
  . (($list['page'] ?? 1) > 1 ? '?page=' . (int) $list['page'] : ''); ?>
<?php $this->start('canonical_url') ?><?= $this->e($canonical) ?><?php $this->stop() ?>
<?php $this->start('feed_links') ?><link rel="alternate" type="application/rss+xml" title="<?= $this->e($board['name']) ?> RSS" href="<?= $this->e($site_url) ?>/boards/<?= rawurlencode((string) $board['board_key']) ?>/rss.xml"><?php $this->stop() ?>
<?php $this->start('seo_description') ?><?= $this->e($board['description'] ?: $board['name'] . '의 최신 글과 GNUCMS 커뮤니티 소식을 확인하세요.') ?><?php $this->stop() ?>
<?php $this->start('meta_description') ?><meta name="description" content="<?= $this->e($board['description'] ?: $board['name'] . '의 최신 글과 GNUCMS 커뮤니티 소식을 확인하세요.') ?>"><?php $this->stop() ?>
<?php $this->start('nav_section') ?>board<?php $this->stop() ?>
<?php $this->start('extra_tabs') ?><a class="tab tab-active" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" aria-current="page"><?= $this->e($board['name']) ?></a><?php $this->stop() ?>

<?php $this->start('header_search') ?>
<form class="header-search" method="get" action="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" role="search">
  <?php if ($scope === 'comments'): ?><input type="hidden" name="scope" value="comments"><?php endif ?>
  <?php if ($query['category'] !== null && $query['category'] !== ''): ?><input type="hidden" name="category" value="<?= $this->e($query['category']) ?>"><?php endif ?>
  <label class="input input-bordered">
    <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 18) ?></span>
    <input type="search" name="q" value="<?= $this->e($query['q']) ?>" placeholder="<?= $this->e($board['name']) ?>에서 검색해 보세요" aria-label="게시글 검색" data-search-input>
  </label>
  <button class="btn btn-primary header-search-btn" type="submit">검색</button>
</form>
<?php $this->stop() ?>

<?php $this->start('body') ?>
<div class="breadcrumbs">
  <ul>
    <li><a href="<?= $this->url('boards.index') ?>">홈</a></li>
    <li aria-current="page"><?= $this->e($board['name']) ?></li>
  </ul>
</div>

<div class="page-head">
  <div>
    <p class="board-eyebrow">GNUCMS COMMUNITY</p>
    <h1><?= $this->e($board['name']) ?></h1>
    <?php if ($board['description']): ?><p class="page-sub"><?= $this->e($board['description']) ?></p><?php endif ?>
    <p class="page-count"><?php if ($scope === 'comments' && $query['q'] !== null && $query['q'] !== ''): ?>“<?= $this->e($query['q']) ?>” 댓글 검색 결과 <strong><?= $this->e((string) ($commentList['total'] ?? 0)) ?></strong>개<?php elseif ($query['q'] !== null && $query['q'] !== ''): ?>“<?= $this->e($query['q']) ?>” 게시글 검색 결과 <strong><?= $this->e((string) $list['total']) ?></strong>개<?php else: ?>글 <strong><?= $this->e((string) $list['total']) ?></strong>개<?php if ($list['notices'] !== []): ?> · 공지 <?= $this->e((string) count($list['notices'])) ?>개<?php endif ?><?php endif ?></p>
  </div>
  <div class="page-head-actions">
    <?php if (!$current_user['is_guest'] && $current_user['is_admin']): ?>
      <?php // 톱니만. 무슨 단추인지는 도움말과 화면 낭독기에 남긴다. ?>
      <a class="btn btn-outline btn-sm btn-square" href="<?= $this->url('admin.boards.edit', ['key' => $board['board_key']]) ?>"
         title="게시판 설정" aria-label="게시판 설정"><?= $this->icon('cog', 15) ?></a>
    <?php endif ?>
    <?php if ($can_write): ?>
      <a class="btn btn-primary hide-sm" href="<?= $this->url('posts.create', ['key' => $board['board_key']]) ?>"><?= $this->icon('pencil', 15) ?> 글쓰기</a>
    <?php endif ?>
  </div>
</div>

<?php if ($scope === 'comments' && $query['q'] !== null && $query['q'] !== ''): ?>
  <?php $this->insert('posts/_comment_search', [
    'list' => $commentList,
    'page_url' => $commentUrl,
    'clear_url' => $listUrl($board, null, $query['category'], 1, $view_param),
  ]) ?>
<?php else: ?>
<?php $this->insert('posts/_board_listing', [
  'board' => $board, 'list' => $list, 'query' => $query, 'view' => $view,
  'view_types' => $view_types, 'can_write' => $can_write,
  'list_url' => fn ($q, $category, $page, $selectedView = null): string =>
      $listUrl($board, $q, $category, $page, $selectedView),
]) ?>
<?php endif ?>

<div class="board-search-area">
  <form class="inline-search board-search" method="get" action="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" role="search">
    <?php if ($query['category'] !== null && $query['category'] !== ''): ?><input type="hidden" name="category" value="<?= $this->e($query['category']) ?>"><?php endif ?>
    <?php if ($view_param): ?><input type="hidden" name="view" value="<?= $this->e($view_param) ?>"><?php endif ?>
    <label class="board-search-select">
      <span class="sr-only">검색 범위</span>
      <select name="scope" aria-label="검색 범위">
        <option value="posts"<?php if ($scope === 'posts'): ?> selected<?php endif ?>>게시글</option>
        <option value="comments"<?php if ($scope === 'comments'): ?> selected<?php endif ?>>댓글</option>
      </select>
      <span class="board-search-select-icon" aria-hidden="true"><?= $this->icon('chevron-down', 13) ?></span>
    </label>
    <label class="input input-bordered">
      <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 16) ?></span>
      <input type="search" name="q" value="<?= $this->e($query['q']) ?>" placeholder="<?= $this->e($board['name']) ?>에서 검색" aria-label="검색어" required>
    </label>
    <button class="btn btn-outline board-search-submit" type="submit">검색</button>
  </form>
</div>

<?php if ($can_write): ?>
  <a class="fab btn btn-primary btn-circle" href="<?= $this->url('posts.create', ['key' => $board['board_key']]) ?>" aria-label="글쓰기"><?= $this->icon('pencil', 22) ?></a>
<?php endif ?>
<?php $this->insert('posts/_author_modal') ?>
<?php $this->stop() ?>
