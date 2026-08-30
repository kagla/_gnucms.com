<?php $this->layout('admin/layout') ?>
<?php
// admin/posts.html.twig 4~10행의 매크로 pageUrl 을 이 파일 안에서만 쓰는 클로저로 옮긴다.
// 결과는 이미 이스케이프된 HTML(& 는 &amp;)이므로 호출부에서 e() 를 쓰지 않는다.
// query.q / query.board 는 Twig 의 bare 진리성(if x)으로 검사하므로 !empty() 가 그대로 맞는다.
$pageUrl = function (array $query, int $p): string {
    $params = [];
    if (!empty($query['q']))     { $params[] = 'q=' . rawurlencode((string) $query['q']); }
    if (!empty($query['board'])) { $params[] = 'board=' . rawurlencode((string) $query['board']); }
    if ($p > 1)                  { $params[] = 'page=' . $p; }
    return $this->url('admin.posts') . ($params !== [] ? '?' . implode('&amp;', $params) : '');
};
?>
<?php $this->start('title') ?>전체 글 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>posts<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">전체 글</li></ul></div>
<div class="page-head">
  <div>
    <h1>전체 글</h1>
    <p class="page-sub">게시판을 가로질러 글을 훑어봅니다. 전체 <?= $this->e($list['total']) ?>개</p>
  </div>
</div>

<form class="post-filter" method="get" action="<?= $this->url('admin.posts') ?>" role="search">
  <select class="select select-bordered" name="board" aria-label="게시판 선택">
    <option value="">전체 게시판</option>
    <?php foreach ($boards as $board): ?>
      <option value="<?= $this->e($board['board_key']) ?>"<?php if (($query['board'] ?? null) === $board['board_key']): ?> selected<?php endif ?>><?= $this->e($board['name']) ?></option>
    <?php endforeach ?>
  </select>
  <label class="input input-bordered">
    <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 16) ?></span>
    <input type="search" name="q" value="<?= $this->e($query['q']) ?>" placeholder="제목이나 내용 검색" aria-label="글 검색" data-search-input>
  </label>
  <button class="btn btn-primary" type="submit">검색</button>
  <?php if (!empty($query['q']) || !empty($query['board'])): ?><a class="btn btn-ghost" href="<?= $this->url('admin.posts') ?>">초기화</a><?php endif ?>
</form>

<section class="card">
  <div class="table-wrap">
    <table class="table table-zebra">
      <thead><tr><th>게시판</th><th>제목</th><th>글쓴이</th><th>날짜</th><th class="right">조회</th><th class="right">댓글</th></tr></thead>
      <tbody>
      <?php if ($list['data'] === []): ?>
        <tr class="table-empty"><td colspan="6"><?php if (!empty($query['q']) || !empty($query['board'])): ?>조건에 맞는 글이 없습니다.<?php else: ?>아직 등록된 글이 없습니다.<?php endif ?></td></tr>
      <?php else: foreach ($list['data'] as $post): ?>
        <tr>
          <td data-label="게시판">
            <?php if ($post['board_key']): ?>
              <a class="link link-hover" href="<?= $this->url('posts.index', ['key' => $post['board_key']]) ?>"><?= $this->e($post['board_name']) ?></a>
            <?php else: ?>
              <span class="cell-sub"><?= $this->e($post['board_name']) ?></span>
            <?php endif ?>
          </td>
          <td data-label="제목">
            <?php if ($post['is_notice']): ?><span class="badge badge-primary badge-soft badge-sm">공지</span><?php endif ?>
            <?php if ($post['is_secret']): ?><span class="post-row-lock" title="비밀글" aria-label="비밀글"><?= $this->icon('lock', 12) ?></span><?php endif ?>
            <a class="cell-title link link-hover" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>"><?= $this->e($post['title']) ?></a>
            <?php if ($post['file_count'] > 0): ?><span class="post-row-clip" title="첨부파일 있음" aria-label="첨부파일 있음"><?= $this->icon('clip', 12) ?></span><?php endif ?>
          </td>
          <td data-label="글쓴이"><?= $this->e($post['author_name']) ?></td>
          <td data-label="날짜"><time datetime="<?= $this->e($post['created_at']) ?>"><?= $this->date($post['created_at'], 'Y.m.d H:i') ?></time></td>
          <td data-label="조회" class="right"><?= $this->e($post['view_count']) ?></td>
          <td data-label="댓글" class="right"><?= $this->e($post['comment_count']) ?></td>
        </tr>
      <?php endforeach; endif ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($list['total_pages'] > 1): ?>
  <?php
  $window = 3;
  $start = max(1, $list['page'] - $window);
  $end = min($list['total_pages'], $list['page'] + $window);
  ?>
  <nav class="pager" aria-label="페이지 이동">
    <div class="join">
      <?php if ($list['page'] > 1): ?><a class="join-item btn btn-sm" rel="prev" href="<?= $pageUrl($query, $list['page'] - 1) ?>" aria-label="이전 페이지"><?= $this->icon('chevron-left', 15) ?></a><?php endif ?>
      <?php if ($start > 1): ?>
        <a class="join-item btn btn-sm" href="<?= $pageUrl($query, 1) ?>" aria-label="1 페이지">1</a>
        <?php if ($start > 2): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?>
      <?php endif ?>
      <?php for ($p = $start; $p <= $end; $p++): ?>
        <?php if ($p === $list['page']): ?>
          <span class="join-item btn btn-sm btn-active" aria-current="page"><?= $this->e($p) ?></span>
        <?php else: ?>
          <a class="join-item btn btn-sm" href="<?= $pageUrl($query, $p) ?>" aria-label="<?= $this->e($p) ?> 페이지"><?= $this->e($p) ?></a>
        <?php endif ?>
      <?php endfor ?>
      <?php if ($end < $list['total_pages']): ?>
        <?php if ($end < $list['total_pages'] - 1): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?>
        <a class="join-item btn btn-sm" href="<?= $pageUrl($query, $list['total_pages']) ?>" aria-label="<?= $this->e($list['total_pages']) ?> 페이지"><?= $this->e($list['total_pages']) ?></a>
      <?php endif ?>
      <?php if ($list['page'] < $list['total_pages']): ?><a class="join-item btn btn-sm" rel="next" href="<?= $pageUrl($query, $list['page'] + 1) ?>" aria-label="다음 페이지"><?= $this->icon('chevron-right', 15) ?></a><?php endif ?>
    </div>
  </nav>
<?php endif ?>
<?php $this->stop() ?>
