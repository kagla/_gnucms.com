<?php // 목록의 글쓴이 칸. 회원 글쓴이는 Task 2 에서 모달 단추가 된다.
$compact = $compact ?? false;
$author_name = (string) $post['author_name'];
?>
<span class="post-list-author" title="<?= $this->e($author_name) ?>"><?= $this->e($compact ? $this->truncate($author_name, 8) : $author_name) ?></span>
