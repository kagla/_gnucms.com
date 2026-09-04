<?php
$list = is_array($list ?? null) ? $list : ['data' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0];
?>
<?php if ($list['data'] === []): ?>
  <div class="card empty-card">
    <div class="card-body">
      <span class="empty-icon" aria-hidden="true"><?= $this->icon('search', 26) ?></span>
      <h2 class="card-title">조건에 맞는 댓글이 없습니다</h2>
      <p>다른 검색어로 다시 찾아보세요.</p>
      <div class="card-actions"><a class="btn btn-outline" href="<?= $this->e($clear_url) ?>">전체 글 보기</a></div>
    </div>
  </div>
<?php else: ?>
  <ul class="list card author-comments comment-search-results" aria-label="댓글 검색 결과">
    <?php foreach ($list['data'] as $row): ?>
      <li class="list-row author-comment">
        <a class="author-comment-body" href="<?= $this->url('posts.show', ['id' => $row['post_id']]) ?>#comment-<?= $this->e((string) $row['id']) ?>">
          <span class="author-comment-text"><?= $this->icon('comment', 13) ?> <?= $this->e($row['excerpt']) ?></span>
          <span class="author-comment-post"><?= $this->icon('board', 13) ?> <?= $this->e($row['post_title']) ?> · <?= $this->e($row['author_name']) ?></span>
        </a>
        <time class="author-comment-date" datetime="<?= $this->e($row['created_at']) ?>"><?= $this->compactDate($row['created_at']) ?></time>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?php $this->insert('posts/_pager', ['list' => $list, 'page_url' => $page_url]) ?>
