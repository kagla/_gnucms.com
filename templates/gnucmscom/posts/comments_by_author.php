<?php $this->layout('layout') ?>
<?php
$commentsUrl = function (int $page) use ($list): string {
    $params = [];
    if (($list['author'] ?? null) !== null) { $params[] = 'author=' . (int) $list['author']; }
    if ($page > 1) { $params[] = 'page=' . $page; }
    return $this->url('comments.byAuthor') . ($params !== [] ? '?' . implode('&', $params) : '');
};
$who = $list['author_name'] ?? null;
$isAll = (bool) ($list['is_all'] ?? false);
?>
<?php $this->start('title') ?><?= $this->e($isAll ? '전체 댓글' : ($who !== null ? $who . ' 님의 댓글' : '회원 댓글')) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>all<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="page-head">
  <div>
    <h1><?= $this->e($isAll ? '전체 댓글' : ($who !== null ? $who . ' 님의 댓글' : '회원 댓글')) ?></h1>
    <p class="page-sub"><?php if ($isAll): ?>읽을 수 있는 모든 게시판의 댓글을 최신순으로 모았습니다.<?php elseif ($who !== null): ?>이 회원이 남긴 댓글을 최신순으로 모았습니다. 댓글 <strong><?= $this->e((string) $list['total']) ?></strong>개<?php else: ?>회원을 찾을 수 없습니다.<?php endif ?></p>
  </div>
  <?php if ($isAll || $who !== null): ?>
  <div class="page-head-actions"><a class="btn btn-outline btn-sm" href="<?= $this->url('posts.all') ?><?php if (!$isAll): ?>?author=<?= $this->e((string) $list['author']) ?><?php endif ?>"><?php if ($isAll): ?><?= $this->icon('board', 14) ?> 전체 글<?php else: ?>이 회원의 글<?php endif ?></a></div>
  <?php endif ?>
</div>

<section class="card">
  <ul class="list author-comments">
    <?php if ($list['data'] === []): ?>
      <li class="list-row author-comment-empty"><?= $this->e($isAll ? '아직 댓글이 없습니다.' : ($who !== null ? '아직 남긴 댓글이 없습니다.' : '주소의 회원 번호를 확인해 주세요.')) ?></li>
    <?php else: foreach ($list['data'] as $row): ?>
      <li class="list-row author-comment">
        <a class="author-comment-body" href="<?= $this->url('posts.show', ['id' => $row['post_id']]) ?>#comment-<?= $this->e((string) $row['id']) ?>">
          <span class="author-comment-text"><?php if ($row['is_secret']): ?><?= $this->icon('lock', 13) ?> <?php endif ?><?= $this->e($row['excerpt']) ?></span>
          <span class="author-comment-post"><?= $this->icon('board', 13) ?> <?= $this->e($row['post_title']) ?></span>
          <?php if ($isAll): ?><span class="author-comment-post"><?= $this->e($row['author_name']) ?></span><?php endif ?>
        </a>
        <time class="author-comment-date" datetime="<?= $this->e($row['created_at']) ?>"><?= $this->date($row['created_at'], 'Y.m.d') ?></time>
      </li>
    <?php endforeach; endif ?>
  </ul>
</section>

<?php $this->insert('posts/_pager', ['list' => $list, 'page_url' => $commentsUrl]) ?>
<?php $this->stop() ?>
