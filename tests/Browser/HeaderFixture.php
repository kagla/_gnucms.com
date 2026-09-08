<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GnuCms\Tests\Support\AdminViewFixture;

$boards = [];
foreach (range(1, ($argv[1] ?? '') === 'overflow' ? 18 : 3) as $index) {
    $boards[] = ['board_key' => 'board' . $index, 'name' => ['공지사항', '자유게시판', '갤러리'][($index - 1) % 3] . ($index > 3 ? ' ' . $index : '')];
}
echo AdminViewFixture::view('/cms')->fetch('layout', [
    'header_boards' => $boards,
    'site_menu' => [['slug' => 'about', 'title' => '회사소개']],
]);
