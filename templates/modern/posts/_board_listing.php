<?php
// 게시판 목록 화면과 게시글 보기 아래 목록이 함께 쓰는 실제 목록 영역이다.
// 필터·목록 형태·공지·빈 상태·페이지 이동을 한곳에 두어 두 화면이 어긋나지 않게 한다.
$query = is_array($query ?? null) ? $query : ['q' => null, 'category' => null];
$query += ['q' => null, 'category' => null];
$view = $this->def($view ?? null, 'list');
$view_types = is_array($view_types ?? null) ? $view_types : [];
$current_post_id = isset($current_post_id) ? (int) $current_post_id : null;
$filtered = ($query['q'] !== null && $query['q'] !== '')
    || ($query['category'] !== null && $query['category'] !== '');
$view_param = $view !== $this->def($board['list_type'] ?? null, 'list') ? $view : null;
$view_labels = ['list' => '목록', 'gallery' => '갤러리', 'magazine' => '매거진', 'news' => '뉴스형'];
$view_icons = ['list' => 'board', 'gallery' => 'grid', 'magazine' => 'document', 'news' => 'megaphone'];
$show_chips = $board['use_category'] && $board['categories'] !== [];
$show_views = count($view_types) > 1 && ($show_view_selector ?? true);
?>

<?php if ($show_chips): ?>
<div class="list-tools">
  <div class="chip-bar" role="group" aria-label="분류 선택">
    <a class="btn btn-sm chip<?php if ($query['category'] === null || $query['category'] === ''): ?> btn-active<?php endif ?>" href="<?= $this->e($list_url($query['q'], null, 1)) ?>">전체</a>
    <?php foreach ($board['categories'] as $name): ?>
      <a class="btn btn-sm chip<?php if (($query['category'] ?? null) === $name): ?> btn-active<?php endif ?>" href="<?= $this->e($list_url($query['q'], $name, 1)) ?>"><?= $this->e($name) ?></a>
    <?php endforeach ?>
  </div>
</div>
<?php endif ?>

<?php if ($view !== 'list' && $list['notices'] !== []): ?>
  <ul class="list card notice-list" aria-label="공지">
    <?php foreach ($list['notices'] as $notice): ?>
      <li class="list-row<?= $current_post_id === (int) $notice['id'] ? ' is-current-post' : '' ?>">
        <span class="notice-ico" aria-hidden="true"><?= $this->icon('megaphone', 16) ?></span>
        <?php if (($notice['notice_scope'] ?? 'board') === 'global'): ?>
          <span class="badge badge-accent badge-soft badge-sm notice-scope">전체 공지</span>
        <?php else: ?>
          <span class="badge badge-primary badge-soft badge-sm">공지</span>
        <?php endif ?>
        <a class="notice-title" href="<?= $this->url('posts.show', ['id' => $notice['id']]) ?>"><?= $this->e($notice['title']) ?></a>
        <time class="notice-date" datetime="<?= $this->e($notice['created_at']) ?>"><?= $this->compactDate($notice['created_at']) ?></time>
      </li>
    <?php endforeach ?>
  </ul>
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
        <?php elseif ($can_write ?? false): ?>
          <a class="btn btn-primary" href="<?= $this->url('posts.create', ['key' => $board['board_key']]) ?>"><?= $this->icon('pencil', 16) ?> 첫 글 쓰기</a>
        <?php endif ?>
      </div>
    </div>
  </div>
<?php elseif ($list['data'] !== [] || ($view === 'list' && $list['notices'] !== [])): ?>
  <?php
  $partial = in_array($view, ['list', 'gallery', 'magazine', 'news'], true) && $this->exists('posts/_list_' . $view)
      ? 'posts/_list_' . $view : 'posts/_list_list';
  $this->insert($partial, [
      'list' => $list, 'board' => $board, 'current_post_id' => $current_post_id,
  ]);
  ?>
<?php endif ?>

<?php $this->insert('posts/_pager', [
  'list' => $list,
  'page_url' => fn (int $page): string => $list_url($query['q'], $query['category'], $page, $view_param),
]) ?>

<?php if ($show_views): ?>
<div class="list-view-tools">
  <div class="dropdown dropdown-top view-select">
    <div tabindex="0" role="button" class="btn btn-sm view-select-btn" aria-label="목록 형태 선택">
      <?= $this->icon($view_icons[$view] ?? 'board', 14) ?> <?= $this->e($this->def($view_labels[$view] ?? null, $view)) ?> <?= $this->icon('chevron-down', 12) ?>
    </div>
    <ul tabindex="0" class="dropdown-content menu rounded-box shadow-lg view-menu">
      <?php foreach ($view_types as $name): ?>
        <li><a<?php if ($name === $view): ?> class="menu-active" aria-current="true"<?php endif ?> href="<?= $this->e($list_url($query['q'], $query['category'], 1, $name)) ?>"><?= $this->icon($view_icons[$name] ?? 'board', 15) ?> <?= $this->e($this->def($view_labels[$name] ?? null, $name)) ?></a></li>
      <?php endforeach ?>
    </ul>
  </div>
</div>
<?php endif ?>
