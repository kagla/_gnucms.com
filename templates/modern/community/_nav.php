<?php
$communityBoards = $community_boards ?? $header_boards;
$currentKey = (string) ($board['board_key'] ?? '');
$section = (string) ($nav_section ?? '');
$hasGallery = false;
foreach ($communityBoards as $navBoard) {
    if ($navBoard['board_key'] === 'gallery') $hasGallery = true;
}
?>
<nav class="cb-community-nav" aria-label="커뮤니티 메뉴">
  <a href="<?= $this->e($this->base . '/community') ?>"<?= $section === 'community' ? ' aria-current="page"' : '' ?>><?= $this->icon('home', 16) ?>커뮤니티 홈</a>
  <a href="<?= $this->url('posts.all') ?>"<?= $section === 'all' ? ' aria-current="page"' : '' ?>>전체 글</a>
  <?php foreach ($communityBoards as $navBoard): ?>
    <a href="<?= $this->url('posts.index', ['key' => $navBoard['board_key']]) ?>"<?= $currentKey === $navBoard['board_key'] ? ' aria-current="page"' : '' ?>><?= $this->icon(($navBoard['list_type'] ?? 'list') === 'gallery' ? 'images' : 'board', 16) ?><?= $this->e($navBoard['name']) ?></a>
  <?php endforeach ?>
  <?php if (!$hasGallery): ?><a href="<?= $this->url('posts.index', ['key' => 'gallery']) ?>"<?= $currentKey === 'gallery' ? ' aria-current="page"' : '' ?>><?= $this->icon('images', 16) ?>사이트 갤러리</a><?php endif ?>
</nav>
