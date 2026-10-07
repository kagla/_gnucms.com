<?php // 댓글 수는 제목 바로 옆에 붙인다. 목록을 훑을 때 가장 눈에 잘 들어오는 자리다. ?>
<?php if ($post['comment_count'] > 0): ?><span class="badge badge-primary badge-soft badge-xs comment-count" title="댓글 <?= $this->e($post['comment_count']) ?>개"><?= $this->e($post['comment_count']) ?></span><?php endif ?>
