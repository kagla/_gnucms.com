<?php $this->layout('layout') ?>
<?php // 전체 글 주소 만들기. 이 파일 안에서만 쓰는 클로저다. 출력할 때 템플릿이 이스케이프한다.
$allUrl = function ($q, $page): string {
    $params = [];
    if ($q !== null && $q !== '') { $params[] = 'q=' . rawurlencode((string) $q); }
    if ($page && $page > 1) { $params[] = 'page=' . (int) $page; }
    return $this->url('posts.all') . ($params !== [] ? '?' . implode('&', $params) : '');
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

<?php $this->insert('posts/_table', [
  'list' => $list,
  'show_board' => true,
  'empty_text' => ($query['q'] !== null && $query['q'] !== '') ? '조건에 맞는 글이 없습니다.' : '아직 글이 없습니다.',
]) ?>

<?php $this->insert('posts/_pager', ['list' => $list, 'page_url' => fn (int $page): string => $allUrl($query['q'], $page)]) ?>
<?php $this->stop() ?>
