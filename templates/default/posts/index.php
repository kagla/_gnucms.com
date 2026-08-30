<?php $this->layout('layout') ?>
<?php
// 목록 주소 만들기. 이 파일 안에서만 쓰는 클로저다.
// 결과는 이미 이스케이프된 HTML(& 는 &amp;)이므로 호출부에서 e() 를 쓰지 않는다.
$listUrl = function (array $board, $q, $category, $page, $view = null): string {
    $params = [];
    if ($q) { $params[] = 'q=' . rawurlencode((string) $q); }
    if ($category) { $params[] = 'category=' . rawurlencode((string) $category); }
    if ($view) { $params[] = 'view=' . $view; }
    if ($page && $page > 1) { $params[] = 'page=' . $page; }
    return $this->url('posts.index', ['key' => $board['board_key']]) . ($params !== [] ? '?' . implode('&amp;', $params) : '');
};
?>

<?php $this->start('title') ?><?= $this->e($board['name']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>board<?php $this->stop() ?>
<?php $this->start('extra_tabs') ?><a class="tab tab-active" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" aria-current="page"><?= $this->e($board['name']) ?></a><?php $this->stop() ?>

<?php $this->start('header_search') ?>
<form class="header-search" method="get" action="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" role="search">
  <?php if ($query['category'] !== null && $query['category'] !== ''): ?><input type="hidden" name="category" value="<?= $this->e($query['category']) ?>"><?php endif ?>
  <label class="input input-bordered">
    <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 18) ?></span>
    <input type="search" name="q" value="<?= $this->e($query['q']) ?>" placeholder="<?= $this->e($board['name']) ?>에서 검색해 보세요" aria-label="게시글 검색" data-search-input>
  </label>
  <button class="btn btn-primary header-search-btn" type="submit">검색</button>
</form>
<?php $this->stop() ?>

<?php $this->start('subnav') ?>
<div class="subnav">
  <div class="wrap subnav-inner">
    <div class="chip-bar" role="group" aria-label="분류 선택">
      <?php if ($board['use_category'] && $board['categories'] !== []): ?>
        <a class="btn btn-sm chip<?php if ($query['category'] === null || $query['category'] === ''): ?> btn-active<?php endif ?>" href="<?= $listUrl($board, $query['q'], null, 1) ?>">전체</a>
        <?php foreach ($board['categories'] as $name): ?>
          <a class="btn btn-sm chip<?php if (($query['category'] ?? null) === $name): ?> btn-active<?php endif ?>" href="<?= $listUrl($board, $query['q'], $name, 1) ?>"><?= $this->e($name) ?></a>
        <?php endforeach ?>
      <?php else: ?>
        <span class="btn btn-sm chip btn-active">전체</span>
      <?php endif ?>
    </div>
    <span class="badge badge-ghost"><?= $this->e($list['total']) ?>개</span>
  </div>
</div>
<?php $this->stop() ?>

<?php $this->start('body') ?>
<?php
$filtered = ($query['q'] !== null && $query['q'] !== '') || ($query['category'] !== null && $query['category'] !== '');
// 목록 형태는 게시판 설정이 기본, ?view= 로 잠시 바꾼다.
// view 값은 컨트롤러가 허용 목록으로 검증한 뒤 내려준다.
$view = $this->def($view ?? null, 'list');
$view_param = $view !== $this->def($board['list_type'] ?? null, 'list') ? $view : null;
?>

<div class="breadcrumbs">
  <ul>
    <li><a href="<?= $this->url('boards.index') ?>">홈</a></li>
    <li aria-current="page"><?= $this->e($board['name']) ?></li>
  </ul>
</div>

<div class="page-head">
  <div>
    <h1><?= $this->e($board['name']) ?></h1>
    <?php if ($board['description']): ?><p class="page-sub"><?= $this->e($board['description']) ?></p><?php endif ?>
    <p class="page-count">글 <strong><?= $this->e($list['total']) ?></strong>개<?php if ($list['notices'] !== []): ?> · 공지 <?= $this->e(count($list['notices'])) ?>개<?php endif ?></p>
  </div>
  <div class="page-head-actions">
    <?php // 검색과 단추를 한 줄에 둔다. 넓은 화면에서는 머리글에 검색이 있으므로 여기서는 감춘다. ?>
    <form class="inline-search show-sm" method="get" action="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" role="search">
      <?php if ($query['category'] !== null && $query['category'] !== ''): ?><input type="hidden" name="category" value="<?= $this->e($query['category']) ?>"><?php endif ?>
      <?php if ($view_param): ?><input type="hidden" name="view" value="<?= $this->e($view_param) ?>"><?php endif ?>
      <label class="input input-bordered">
        <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 16) ?></span>
        <input type="search" name="q" value="<?= $this->e($query['q']) ?>" placeholder="<?= $this->e($board['name']) ?>에서 검색" aria-label="게시글 검색">
      </label>
      <button class="btn btn-primary" type="submit">검색</button>
    </form>
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

<?php if ($filtered): ?>
  <div class="filter-note">
    <?php if ($query['category'] !== null && $query['category'] !== ''): ?><span class="badge badge-primary badge-soft"><?= $this->e($query['category']) ?></span><?php endif ?>
    <?php if ($query['q'] !== null && $query['q'] !== ''): ?><span class="badge badge-soft">“<?= $this->e($query['q']) ?>”</span><?php endif ?>
    <span>검색 결과 <?= $this->e($list['total']) ?>개</span>
    <a class="link link-hover" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>">필터 지우기</a>
  </div>
<?php endif ?>

<?php if ($list['notices'] !== []): ?>
  <ul class="list card notice-list" aria-label="공지">
    <?php foreach ($list['notices'] as $post): ?>
      <li class="list-row">
        <span class="notice-ico" aria-hidden="true"><?= $this->icon('megaphone', 16) ?></span>
        <span class="badge badge-primary badge-soft badge-sm">공지</span>
        <a class="notice-title" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>"><?= $this->e($post['title']) ?></a>
        <time class="notice-date" datetime="<?= $this->e($post['created_at']) ?>"><?= $this->date($post['created_at'], 'm.d') ?></time>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?php if (isset($view_types) && count($view_types) > 1): ?>
  <?php
  $view_labels = ['list' => '목록', 'gallery' => '갤러리', 'magazine' => '매거진', 'news' => '뉴스형'];
  $view_icons = ['list' => 'board', 'gallery' => 'grid', 'magazine' => 'document', 'news' => 'megaphone'];
  ?>
  <nav class="view-switch" aria-label="목록 형태 선택">
    <?php foreach ($view_types as $name): ?>
      <?php if ($name === $view): ?>
        <span class="btn btn-sm btn-active" aria-current="true"><?= $this->icon($view_icons[$name], 14) ?> <?= $this->e($this->def($view_labels[$name] ?? null, $name)) ?></span>
      <?php else: ?>
        <a class="btn btn-sm" href="<?= $listUrl($board, $query['q'], $query['category'], 1, $name) ?>"><?= $this->icon($view_icons[$name], 14) ?> <?= $this->e($this->def($view_labels[$name] ?? null, $name)) ?></a>
      <?php endif ?>
    <?php endforeach ?>
  </nav>
<?php endif ?>

<?php if ($list['data'] === [] && $list['notices'] === []): ?>
  <div class="card empty-card">
    <div class="card-body">
      <span class="empty-icon" aria-hidden="true"><?= $this->icon($filtered ? 'search' : 'document', 26) ?></span>
      <h2 class="card-title"><?php if ($filtered): ?>조건에 맞는 글이 없습니다<?php else: ?>아직 등록된 글이 없습니다<?php endif ?></h2>
      <p><?php if ($filtered): ?>다른 검색어나 분류로 다시 찾아보세요.<?php else: ?>첫 이야기를 남겨 이 게시판을 열어보세요.<?php endif ?></p>
      <div class="card-actions">
        <?php if ($filtered): ?>
          <a class="btn btn-outline" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>">전체 글 보기</a>
        <?php elseif ($can_write): ?>
          <a class="btn btn-primary" href="<?= $this->url('posts.create', ['key' => $board['board_key']]) ?>"><?= $this->icon('pencil', 16) ?> 첫 글 쓰기</a>
        <?php endif ?>
      </div>
    </div>
  </div>
<?php elseif ($list['data'] !== []): ?>
  <?php
  // 파셜이 배포에서 빠져도 오류 화면 대신 목록형으로 떨어지게 한다.
  $this->insert(in_array($view, ['list', 'gallery', 'magazine', 'news'], true) && $this->exists('posts/_list_' . $view) ? 'posts/_list_' . $view : 'posts/_list_list')
  ?>
<?php endif ?>

<?php if ($list['total_pages'] > 1): ?>
  <?php
  $window = 3;
  $start = max(1, $list['page'] - $window);
  $end = min($list['total_pages'], $list['page'] + $window);
  ?>
  <nav class="pager" aria-label="페이지 이동">
    <div class="join">
      <?php if ($list['page'] > 1): ?><a class="join-item btn btn-sm" rel="prev" href="<?= $listUrl($board, $query['q'], $query['category'], $list['page'] - 1, $view_param) ?>" aria-label="이전 페이지"><?= $this->icon('chevron-left', 15) ?></a><?php endif ?>
      <?php if ($start > 1): ?>
        <a class="join-item btn btn-sm" href="<?= $listUrl($board, $query['q'], $query['category'], 1, $view_param) ?>" aria-label="1 페이지">1</a>
        <?php if ($start > 2): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?>
      <?php endif ?>
      <?php for ($p = $start; $p <= $end; $p++): ?>
        <?php if ($p === $list['page']): ?>
          <span class="join-item btn btn-sm btn-active" aria-current="page"><?= $this->e($p) ?></span>
        <?php else: ?>
          <a class="join-item btn btn-sm" href="<?= $listUrl($board, $query['q'], $query['category'], $p, $view_param) ?>" aria-label="<?= $this->e($p) ?> 페이지"><?= $this->e($p) ?></a>
        <?php endif ?>
      <?php endfor ?>
      <?php if ($end < $list['total_pages']): ?>
        <?php if ($end < $list['total_pages'] - 1): ?><span class="join-item btn btn-sm btn-disabled" aria-hidden="true">…</span><?php endif ?>
        <a class="join-item btn btn-sm" href="<?= $listUrl($board, $query['q'], $query['category'], $list['total_pages'], $view_param) ?>" aria-label="<?= $this->e($list['total_pages']) ?> 페이지"><?= $this->e($list['total_pages']) ?></a>
      <?php endif ?>
      <?php if ($list['page'] < $list['total_pages']): ?><a class="join-item btn btn-sm" rel="next" href="<?= $listUrl($board, $query['q'], $query['category'], $list['page'] + 1, $view_param) ?>" aria-label="다음 페이지"><?= $this->icon('chevron-right', 15) ?></a><?php endif ?>
    </div>
  </nav>
<?php endif ?>

<?php if ($can_write): ?>
  <a class="fab btn btn-primary btn-circle" href="<?= $this->url('posts.create', ['key' => $board['board_key']]) ?>" aria-label="글쓰기"><?= $this->icon('pencil', 22) ?></a>
<?php endif ?>
<?php $this->stop() ?>
