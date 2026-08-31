<?php
// 목록의 글쓴이 칸. 회원이면 모달을 여는 단추, 비회원이면 글자.
// 비회원은 이름만 남아 동명이인을 가릴 수 없으므로 누를 수 없다.
$compact = $compact ?? false;
$author_name = (string) $post['author_name'];
$shown = $compact ? $this->truncate($author_name, 8) : $author_name;
$author_id = $post['author_id'] ?? null;
?>
<?php if ($author_id !== null && (string) $author_id !== ''): ?>
  <button type="button" class="link-author" data-author-id="<?= $this->e((string) $author_id) ?>" data-author-name="<?= $this->e($author_name) ?>" title="<?= $this->e($author_name) ?>"><?= $this->e($shown) ?></button>
<?php else: ?>
  <span class="post-list-author" title="<?= $this->e($author_name) ?>"><?= $this->e($shown) ?></span>
<?php endif ?>
