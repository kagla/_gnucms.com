<?php $this->layout('layout') ?>
<?php // 전체 글 주소 만들기. 이 파일 안에서만 쓰는 클로저다.
$allUrl = function ($q, $page): string {
    $params = [];
    if ($q !== null && $q !== '') { $params[] = 'q=' . rawurlencode((string) $q); }
    if ($page && $page > 1) { $params[] = 'page=' . (int) $page; }
    return $this->url('posts.all') . ($params !== [] ? '?' . implode('&amp;', $params) : '');
}; ?>
<?php $this->start('title') ?>전체 글 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>all<?php $this->stop() ?>
<?php $this->start('header_search') ?>
<form class="header-search" method="get" action="<?= $this->url('posts.all') ?>" role="search">
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
    <h1>전체 글</h1>
    <p class="page-sub">읽을 수 있는 모든 게시판의 글을 최신순으로 모았습니다.<?php if ($query['q'] !== null && $query['q'] !== ''): ?> “<?= $this->e($query['q']) ?>” 검색 결과 <?= $this->e($list['total']) ?>건<?php endif ?></p>
  </div>
  <?php if ($query['q'] !== null && $query['q'] !== ''): ?>
  <div class="page-head-actions"><a class="btn btn-outline btn-sm" href="<?= $this->url('posts.all') ?>">검색 지우기</a></div>
  <?php endif ?>
</div>

<section class="card">
  <div class="table-wrap">
    <table class="table table-zebra all-posts-table">
      <thead><tr><th>게시판</th><th>제목</th><th>글쓴이</th><th>날짜</th><th class="right">조회</th></tr></thead>
      <tbody>
      <?php if ($list['data'] === []): ?>
        <tr class="table-empty"><td colspan="5"><?php if ($query['q'] !== null && $query['q'] !== ''): ?>조건에 맞는 글이 없습니다.<?php else: ?>아직 글이 없습니다.<?php endif ?></td></tr>
      <?php else: foreach ($list['data'] as $post): ?>
        <tr>
          <td data-label="게시판"><a class="badge badge-ghost badge-sm" href="<?= $this->url('posts.index', ['key' => $post['board_key']]) ?>"><?= $this->e($post['board_name']) ?></a></td>
          <td data-label="제목">
            <?php if ($post['is_notice']): ?><span class="badge badge-primary badge-soft badge-sm">공지</span><?php endif ?>
            <?php if ($post['is_secret']): ?><span class="post-row-lock" title="비밀글" aria-label="비밀글"><?= $this->icon('lock', 12) ?></span><?php endif ?>
            <a class="cell-title link link-hover" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>"><?= $this->e($post['title']) ?> <?php $this->insert('posts/_count', ['post' => $post]) ?></a>
            <?php if ($post['file_count'] > 0): ?><span class="post-row-clip" title="첨부파일 있음" aria-label="첨부파일 있음"><?= $this->icon('clip', 12) ?></span><?php endif ?>
          </td>
          <td data-label="글쓴이"><?= $this->e($post['author_name']) ?></td>
          <td data-label="날짜"><time datetime="<?= $this->e($post['created_at']) ?>"><?= $this->date($post['created_at'], 'Y.m.d') ?></time></td>
          <td data-label="조회" class="right"><?= $this->e($post['view_count']) ?></td>
        </tr>
      <?php endforeach; endif ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($list['total_pages'] > 1): ?>
  <?php $window = 3; $start = max(1, $list['page'] - $window); $end = min($list['total_pages'], $list['page'] + $window); ?>
  <nav class="pager" aria-label="페이지 이동">
    <div class="join">
      <?php if ($list['page'] > 1): ?><a class="join-item btn btn-sm" href="<?= $allUrl($query['q'], $list['page'] - 1) ?>" aria-label="이전 쪽"><?= $this->icon('chevron-left', 15) ?></a><?php endif ?>
      <?php if ($start > 1): ?><a class="join-item btn btn-sm" href="<?= $allUrl($query['q'], 1) ?>">1</a><?php if ($start > 2): ?><span class="join-item btn btn-sm btn-disabled">…</span><?php endif ?><?php endif ?>
      <?php for ($p = $start; $p <= $end; $p++): ?>
        <a class="join-item btn btn-sm<?php if ($p === $list['page']): ?> btn-active<?php endif ?>" href="<?= $allUrl($query['q'], $p) ?>"<?php if ($p === $list['page']): ?> aria-current="page"<?php endif ?>><?= $p ?></a>
      <?php endfor ?>
      <?php if ($end < $list['total_pages']): ?><?php if ($end < $list['total_pages'] - 1): ?><span class="join-item btn btn-sm btn-disabled">…</span><?php endif ?><a class="join-item btn btn-sm" href="<?= $allUrl($query['q'], $list['total_pages']) ?>"><?= $list['total_pages'] ?></a><?php endif ?>
      <?php if ($list['page'] < $list['total_pages']): ?><a class="join-item btn btn-sm" href="<?= $allUrl($query['q'], $list['page'] + 1) ?>" aria-label="다음 쪽"><?= $this->icon('chevron-right', 15) ?></a><?php endif ?>
    </div>
  </nav>
<?php endif ?>
<?php $this->stop() ?>
