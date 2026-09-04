<?php $this->layout('layout') ?>
<?php
$commentsUrl = function (int $page) use ($list): string {
    $params = [];
    if (($list['q'] ?? null) !== null && $list['q'] !== '') { $params[] = 'q=' . rawurlencode((string) $list['q']); }
    if (($list['author'] ?? null) !== null) { $params[] = 'author=' . (int) $list['author']; }
    if ($page > 1) { $params[] = 'page=' . $page; }
    return $this->url('comments.byAuthor') . ($params !== [] ? '?' . implode('&', $params) : '');
};
$who = $list['author_name'] ?? null;
$isAll = (bool) ($list['is_all'] ?? false);
?>
<?php $this->start('title') ?><?= $this->e($isAll ? '전체 댓글' : ($who !== null ? $who . ' 님의 댓글' : '회원 댓글')) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>all<?php $this->stop() ?>
<?php $this->start('header_search') ?>
<form class="header-search" method="get" action="<?= $this->url('comments.byAuthor') ?>" role="search">
  <?php if (($list['author'] ?? null) !== null): ?><input type="hidden" name="author" value="<?= $this->e((string) $list['author']) ?>"><?php endif ?>
  <label class="input input-bordered">
    <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 18) ?></span>
    <input type="search" name="q" value="<?= $this->e($list['q'] ?? '') ?>" placeholder="전체 댓글에서 검색해 보세요" aria-label="전체 댓글 검색" data-search-input>
  </label>
  <button class="btn btn-primary header-search-btn" type="submit">검색</button>
</form>
<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="page-head">
  <div>
    <h1><?= $this->e($isAll ? '전체 댓글' : ($who !== null ? $who . ' 님의 댓글' : '회원 댓글')) ?></h1>
    <p class="page-sub"><?php if ($isAll): ?>읽을 수 있는 모든 게시판의 댓글을 최신순으로 모았습니다.<?php elseif ($who !== null): ?>이 회원이 남긴 댓글을 최신순으로 모았습니다.<?php else: ?>회원을 찾을 수 없습니다.<?php endif ?><?php if (($isAll || $who !== null) && ($list['q'] ?? '') !== ''): ?> “<?= $this->e($list['q']) ?>” 검색 결과 <strong><?= $this->e((string) $list['total']) ?></strong>건<?php elseif ($who !== null): ?> 댓글 <strong><?= $this->e((string) $list['total']) ?></strong>개<?php endif ?></p>
  </div>
  <?php if ($isAll || $who !== null): ?>
  <div class="page-head-actions"><a class="btn btn-outline btn-sm" href="<?= $this->url('posts.all') ?><?php if (!$isAll): ?>?author=<?= $this->e((string) $list['author']) ?><?php endif ?>"><?php if ($isAll): ?><?= $this->icon('board', 14) ?> 전체 글<?php else: ?>이 회원의 글<?php endif ?></a></div>
  <?php endif ?>
</div>

<section class="card">
  <ul class="list author-comments">
    <?php if ($list['data'] === []): ?>
      <li class="list-row author-comment-empty"><?= $this->e(($list['q'] ?? '') !== '' ? '조건에 맞는 댓글이 없습니다.' : ($isAll ? '아직 댓글이 없습니다.' : ($who !== null ? '아직 남긴 댓글이 없습니다.' : '주소의 회원 번호를 확인해 주세요.'))) ?></li>
    <?php else: foreach ($list['data'] as $row): ?>
      <li class="list-row author-comment">
        <a class="author-comment-body" href="<?= $this->url('posts.show', ['id' => $row['post_id']]) ?>#comment-<?= $this->e((string) $row['id']) ?>">
          <span class="author-comment-text"><?php if ($row['is_secret']): ?><?= $this->icon('lock', 13) ?> <?php endif ?><?= $this->e($row['excerpt']) ?></span>
          <span class="author-comment-post"><?= $this->icon('board', 13) ?> <?= $this->e($row['post_title']) ?></span>
          <?php if ($isAll): ?><span class="author-comment-post"><?= $this->e($row['author_name']) ?></span><?php endif ?>
        </a>
        <time class="author-comment-date" datetime="<?= $this->e($row['created_at']) ?>"><?= $this->compactDate($row['created_at']) ?></time>
      </li>
    <?php endforeach; endif ?>
  </ul>
</section>

<?php $this->insert('posts/_pager', ['list' => $list, 'page_url' => $commentsUrl]) ?>
<?php if ($isAll || $who !== null): ?>
<div class="board-search-area all-posts-search-area">
  <form class="inline-search board-search all-posts-search all-comments-search" method="get" action="<?= $this->url('comments.byAuthor') ?>" role="search">
    <?php if (($list['author'] ?? null) !== null): ?><input type="hidden" name="author" value="<?= $this->e((string) $list['author']) ?>"><?php endif ?>
    <label class="input input-bordered">
      <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 16) ?></span>
      <input type="search" name="q" value="<?= $this->e($list['q'] ?? '') ?>" placeholder="전체 댓글에서 검색" aria-label="전체 댓글 검색어" required>
    </label>
    <button class="btn btn-outline board-search-submit" type="submit">검색</button>
  </form>
</div>
<?php endif ?>
<?php $this->stop() ?>
