<?php $this->layout('layout') ?>
<?php // 전체 글 주소 만들기. 이 파일 안에서만 쓰는 클로저다. 출력할 때 템플릿이 이스케이프한다.
$allUrl = function ($q, $page) use ($list): string {
    $params = [];
    if ($q !== null && $q !== '') { $params[] = 'q=' . rawurlencode((string) $q); }
    if (($list['author'] ?? null) !== null) { $params[] = 'author=' . (int) $list['author']; }
    if ($page && $page > 1) { $params[] = 'page=' . (int) $page; }
    return $this->url('posts.all') . ($params !== [] ? '?' . implode('&', $params) : '');
}; ?>
<?php $this->start('title') ?>전체 글 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $canonical = $site_url . '/posts' . (($list['page'] ?? 1) > 1 ? '?page=' . (int) $list['page'] : ''); ?>
<?php $this->start('seo_meta') ?>
<link rel="canonical" href="<?= $this->e($canonical) ?>">
<meta property="og:type" content="website"><meta property="og:locale" content="ko_KR">
<meta property="og:site_name" content="<?= $this->e($site['site_name']) ?>"><meta property="og:title" content="전체 글 · <?= $this->e($site['site_name']) ?>">
<meta property="og:description" content="<?= $this->e($site['site_tagline']) ?>"><meta property="og:url" content="<?= $this->e($canonical) ?>"><meta name="twitter:card" content="summary">
<?php $this->stop() ?>
<?php $this->start('nav_section') ?>all<?php $this->stop() ?>
<?php $this->start('header_search') ?>
<form class="header-search" method="get" action="<?= $this->url('posts.all') ?>" role="search">
  <?php if (($list['author'] ?? null) !== null): ?><input type="hidden" name="author" value="<?= $this->e((string) $list['author']) ?>"><?php endif ?>
  <label class="input input-bordered">
    <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 18) ?></span>
    <input type="search" name="q" value="<?= $this->e($query['q'] ?? '') ?>" placeholder="모든 게시판에서 검색해 보세요" aria-label="전체 글 검색" data-search-input>
  </label>
  <button class="btn btn-primary header-search-btn" type="submit">검색</button>
</form>
<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="page-head">
  <div>
    <h1><?php if (($list['author_name'] ?? null) !== null): ?><?= $this->e($list['author_name']) ?> 님의 글<?php else: ?>전체 글<?php endif ?></h1>
    <p class="page-sub"><?php if (($list['author_name'] ?? null) !== null): ?>이 회원이 쓴 글을 최신순으로 모았습니다. 글 <strong><?= $this->e((string) $list['total']) ?></strong>개<?php else: ?>읽을 수 있는 모든 게시판의 글을 최신순으로 모았습니다.<?php if ($query['q'] !== null && $query['q'] !== ''): ?> “<?= $this->e($query['q']) ?>” 검색 결과 <?= $this->e((string) $list['total']) ?>건<?php endif ?><?php endif ?></p>
  </div>
  <div class="page-head-actions">
    <a class="btn btn-outline btn-sm" href="<?= $this->url('comments.byAuthor') ?>"><?= $this->icon('comment', 14) ?> 전체 댓글</a>
    <?php if (($list['author_name'] ?? null) !== null || ($query['q'] !== null && $query['q'] !== '')): ?>
    <a class="btn btn-outline btn-sm" href="<?= $this->url('posts.all') ?>">전체 글 보기</a>
    <?php endif ?>
  </div>
</div>

<?php $this->insert('posts/_table', [
  'list' => $list,
  'show_board' => true,
  'navigation_scope' => 'all',
  'empty_text' => ($query['q'] !== null && $query['q'] !== '') ? '조건에 맞는 글이 없습니다.' : '아직 글이 없습니다.',
]) ?>

<?php $this->insert('posts/_pager', ['list' => $list, 'page_url' => fn (int $page): string => $allUrl($query['q'], $page)]) ?>

<div class="board-search-area all-posts-search-area">
  <form class="inline-search board-search all-posts-search" method="get" action="<?= $this->url('posts.all') ?>" role="search">
    <?php if (($list['author'] ?? null) !== null): ?><input type="hidden" name="author" value="<?= $this->e((string) $list['author']) ?>"><?php endif ?>
    <label class="input input-bordered">
      <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 16) ?></span>
      <input type="search" name="q" value="<?= $this->e($query['q'] ?? '') ?>" placeholder="전체 글에서 검색" aria-label="전체 글 검색어" required>
    </label>
    <button class="btn btn-outline board-search-submit" type="submit">검색</button>
  </form>
</div>

<?php $this->insert('posts/_author_modal') ?>
<?php $this->stop() ?>
