<?php
// posts/_comments.html.twig 를 옮긴 파셜. 답글은 같은 파일을 다시 부르는 재귀다.
// insert() 를 $only=true 로 부르므로 nodes/nested/can_comment 만 명시해 넘겨야 하고,
// current_user 는 전역이라 $only 여도 그대로 물려받는다.
$nested = $nested ?? false;
?>
<div class="comment-thread<?php if ($nested): ?> comment-thread-sub<?php endif ?>">
<?php foreach ($nodes as $comment): ?>
  <div class="chat chat-start<?php if ($comment['deleted']): ?> chat-removed<?php endif ?>" id="comment-<?= $this->e($comment['id']) ?>">
    <div class="chat-image avatar avatar-placeholder avatar-sm">
      <span class="avatar-inner" data-tone="<?= $this->e($comment['deleted'] ? 0 : mb_strlen((string) $comment['author_name']) % 6) ?>" aria-hidden="true"><span><?= $this->e($comment['deleted'] ? '·' : mb_strtoupper(mb_substr((string) $comment['author_name'], 0, 1))) ?></span></span>
    </div>
    <div class="chat-header">
      <?php if ($comment['deleted']): ?>삭제된 댓글<?php else: ?><?= $this->e($comment['author_name']) ?><?php endif ?>
      <time datetime="<?= $this->e($comment['created_at']) ?>"><?= $this->date($comment['created_at'], 'y-m-d H:i:s') ?></time>
      <?php if ($comment['is_secret']): ?><span class="chat-lock" title="비밀 댓글" aria-label="비밀 댓글"><?= $this->icon('lock', 12) ?></span><?php endif ?>
    </div>
    <div class="chat-bubble"><?= $this->html($comment['content']) ?></div>
    <?php if (!$comment['deleted']):
      // 회원 댓글은 글쓴이만, 비회원 댓글은 비밀번호를 아는 사람만 고칠 수 있다.
      // 실제 판단은 서버가 다시 하고, 여기서는 보일 만한 사람에게만 길을 열어 준다.
      $mine = $current_user['is_admin']
        || $comment['author_id'] === null
        || ($current_user['id'] !== null && $comment['author_id'] == $current_user['id']);
      if (($can_comment ?? false) || $mine): ?>
        <div class="chat-footer">
          <?php if ($can_comment ?? false): ?>
            <button class="btn btn-ghost btn-sm" type="button" data-reply="<?= $this->e($comment['id']) ?>" data-reply-author="<?= $this->e($comment['author_name']) ?>">답글</button>
          <?php endif ?>
          <?php if ($mine):
            // 비밀번호를 물어야 하는데 화면에 그 칸이 없는 경우(회원이 비회원 댓글을 고칠 때)만
            // 따로 만든 수정 화면으로 보낸다. 나머지는 이 자리에서 바로 고친다.
            $inline = $current_user['is_admin']
              || $comment['author_id'] !== null
              || $current_user['is_guest'];
          ?>
            <a class="btn btn-ghost btn-sm" href="<?= $this->url('comments.edit', ['id' => $comment['id']]) ?>"
               <?php if ($inline): ?>data-edit="<?= $this->e($comment['id']) ?>"
               data-edit-action="<?= $this->url('comments.edit', ['id' => $comment['id']]) ?>"
               data-delete-action="<?= $this->url('comments.delete', ['id' => $comment['id']]) ?>"<?php endif ?>><?= $this->icon('pencil', 13) ?> 수정</a>
            <?php if ($inline): ?>
              <?php // 화면에 보이는 본문은 사진이 축소본으로 바뀐 것이라 그대로 저장하면 안 된다.
                    // 편집기에 넣을 원래 내용을 따로 담아 둔다. ?>
              <template data-source="<?= $this->e($comment['id']) ?>"><?= $this->e($comment['content']) ?></template>
            <?php endif ?>
          <?php endif ?>
        </div>
      <?php endif ?>
    <?php endif ?>
  </div>
  <?php if (!empty($comment['children'])): ?>
    <?php // can_comment 를 함께 넘겨야 답글의 답글에도 버튼이 남는다. ?>
    <?php $this->insert('posts/_comments', ['nodes' => $comment['children'], 'nested' => true, 'can_comment' => $can_comment ?? false], true) ?>
  <?php endif ?>
<?php endforeach ?>
</div>
