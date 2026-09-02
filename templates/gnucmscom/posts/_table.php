<?php
// 목록 표 한 벌. 전체 글과 게시판 목록형이 함께 쓴다.
//   list           목록 배열(data)
//   show_board     게시판 칸을 낸다 (여러 게시판이 섞이는 화면)
//   show_category  분류 칸을 낸다 (분류를 쓰는 게시판)
//   compact        좁은 칸용. 날짜를 줄이고 이름을 8자에서 자른다
//   empty_text     한 줄도 없을 때 보일 말
$show_board = $show_board ?? false;
$show_category = $show_category ?? false;
$compact = $compact ?? false;
$empty_text = $empty_text ?? '아직 글이 없습니다.';
$notices = $notices ?? [];
$navigation_scope = $navigation_scope ?? 'board';
$current_post_id = isset($current_post_id) ? (int) $current_post_id : null;
$rows = array_merge($notices, $list['data']);
$columns = 4 + ($show_board ? 1 : 0) + ($show_category ? 1 : 0);
?>
<section class="card">
  <div class="table-wrap">
    <table class="table table-zebra posts-table">
      <thead>
        <tr>
          <?php if ($show_board): ?><th class="post-col-board">게시판</th><?php endif ?>
          <?php if ($show_category): ?><th class="post-col-category">분류</th><?php endif ?>
          <th class="post-col-title">제목</th>
          <th class="post-col-author">글쓴이</th>
          <th class="post-col-date">날짜</th>
          <th class="post-col-views right">조회</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr class="table-empty"><td colspan="<?= $this->e((string) $columns) ?>"><?= $this->e($empty_text) ?></td></tr>
      <?php else: foreach ($rows as $post): ?>
        <?php $row_classes = array_filter([$post['is_notice'] ? 'post-notice-row' : null, $current_post_id === (int) $post['id'] ? 'is-current-post' : null]); ?>
        <tr<?= $row_classes !== [] ? ' class="' . $this->e(implode(' ', $row_classes)) . '"' : '' ?>>
          <?php if ($show_board): ?>
            <td data-label="게시판" class="post-col-board"><a class="badge badge-ghost badge-sm" href="<?= $this->url('posts.index', ['key' => $post['board_key']]) ?>"><?= $this->e($post['board_name']) ?></a></td>
          <?php endif ?>
          <?php if ($show_category): ?>
            <td data-label="분류" class="post-col-category"><?php if ($post['category']): ?><span class="badge badge-ghost badge-sm"><?= $this->e($post['category']) ?></span><?php else: ?><span class="cell-sub">—</span><?php endif ?></td>
          <?php endif ?>
          <td data-label="제목" class="post-col-title">
            <div class="post-title-line">
              <?php if ($post['is_notice']): ?>
                <?php if (($post['notice_scope'] ?? 'board') === 'global'): ?>
                  <span class="badge badge-accent badge-soft badge-sm">전체 공지</span>
                <?php else: ?>
                  <span class="badge badge-primary badge-soft badge-sm">공지</span>
                <?php endif ?>
              <?php endif ?>
              <?php if ($post['is_secret']): ?><span class="post-row-lock" title="비밀글" aria-label="비밀글"><?= $this->icon('lock', 16) ?></span><?php endif ?>
              <a class="cell-title link link-hover" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?><?= $navigation_scope === 'all' ? '?scope=all' : '' ?>" title="<?= $this->e($post['title']) ?>"><?= $this->e($post['title']) ?> <?php $this->insert('posts/_count', ['post' => $post]) ?></a>
              <?php if ($post['file_count'] > 0): ?><span class="post-row-clip" title="첨부파일 있음" aria-label="첨부파일 있음"><?= $this->icon('clip', 16) ?></span><?php endif ?>
            </div>
          </td>
          <td data-label="글쓴이" class="cell-author post-col-author"><?php $this->insert('posts/_author', ['post' => $post, 'compact' => $compact]) ?></td>
          <td data-label="날짜" class="post-col-date"><time datetime="<?= $this->e($post['created_at']) ?>"><?= $compact ? $this->compactDate($post['created_at']) : $this->date($post['created_at'], 'Y.m.d') ?></time></td>
          <td data-label="조회" class="post-col-views right"><?= $this->e($post['view_count']) ?></td>
        </tr>
      <?php endforeach; endif ?>
      </tbody>
    </table>
  </div>
</section>
