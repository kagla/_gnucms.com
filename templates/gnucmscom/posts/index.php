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
?>

<?php $this->start('title') ?><?= $this->e($board['name']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('seo_description') ?><?= $this->e($board['description'] ?: $board['name'] . '의 최신 글과 GNUCMS 커뮤니티 소식을 확인하세요.') ?><?php $this->stop() ?>
<?php $this->start('meta_description') ?><meta name="description" content="<?= $this->e($board['description'] ?: $board['name'] . '의 최신 글과 GNUCMS 커뮤니티 소식을 확인하세요.') ?>"><?php $this->stop() ?>
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

<?php $this->start('body') ?>
<?php
// 목록 형태는 게시판 설정이 기본, ?view= 로 잠시 바꾼다.
// view 값은 컨트롤러가 허용 목록으로 검증한 뒤 내려준다.
$view = $this->def($view ?? null, 'list');
$view_param = $view !== $this->def($board['list_type'] ?? null, 'list') ? $view : null;
$view_labels = ['list' => '목록', 'gallery' => '갤러리', 'magazine' => '매거진', 'news' => '뉴스형'];
$view_icons = ['list' => 'board', 'gallery' => 'grid', 'magazine' => 'document', 'news' => 'megaphone'];
?>

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
    <?php if (isset($view_types) && count($view_types) > 1): ?>
      <div class="dropdown dropdown-end view-select">
        <div tabindex="0" role="button" class="btn btn-sm view-select-btn" aria-label="목록 형태 선택">
          <?= $this->icon($view_icons[$view] ?? 'board', 14) ?> <?= $this->e($this->def($view_labels[$view] ?? null, $view)) ?> <?= $this->icon('chevron-down', 12) ?>
        </div>
        <ul tabindex="0" class="dropdown-content menu rounded-box shadow-lg view-menu">
          <?php foreach ($view_types as $name): ?>
            <li><a<?php if ($name === $view): ?> class="menu-active" aria-current="true"<?php endif ?> href="<?= $this->e($listUrl($board, $query['q'], $query['category'], 1, $name)) ?>"><?= $this->icon($view_icons[$name] ?? 'board', 15) ?> <?= $this->e($this->def($view_labels[$name] ?? null, $name)) ?></a></li>
          <?php endforeach ?>
        </ul>
      </div>
    <?php endif ?>
  </div>
</div>

<?php $this->insert('posts/_board_listing', [
  'board' => $board, 'list' => $list, 'query' => $query, 'view' => $view,
  'view_types' => $view_types, 'can_write' => $can_write,
  'show_view_selector' => false,
  'list_url' => fn ($q, $category, $page, $selectedView = null): string =>
      $listUrl($board, $q, $category, $page, $selectedView),
]) ?>

<?php if ($can_write): ?>
  <a class="fab btn btn-primary btn-circle" href="<?= $this->url('posts.create', ['key' => $board['board_key']]) ?>" aria-label="글쓰기"><?= $this->icon('pencil', 22) ?></a>
<?php endif ?>
<?php $this->insert('posts/_author_modal') ?>
<?php $this->stop() ?>
