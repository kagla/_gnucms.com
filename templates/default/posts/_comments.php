<?php
// 댓글 목록 조각. 답글은 같은 파일을 다시 부르는 재귀다.
// insert() 를 $only=true 로 부르므로 nodes/nested/can_comment 만 명시해 넘겨야 하고,
// current_user 는 전역이라 $only 여도 그대로 물려받는다.
$nested = $nested ?? false;
?>
<div class="comment-thread<?php if ($nested): ?> comment-thread-sub<?php endif ?>">
<?php foreach ($nodes as $comment): ?>
  <div class="chat chat-start<?php if ($comment['deleted']): ?> chat-removed<?php endif ?>" id="comment-<?= $this->e($comment['id']) ?>">
    <div class="chat-image avatar avatar-placeholder avatar-sm">
      <span class="avatar-inner" data-tone="<?= $this->e($comment['deleted'] ? 0 : mb_strlen((string) $comment['author_name']) % 6) ?>" aria-hidden="true"><?php if (!$comment['deleted'] && !empty($comment['author_avatar_file'])): ?><img src="<?= $this->url('avatar.show', ['file' => $comment['author_avatar_file']]) ?>" alt=""><?php else: ?><span><?= $this->e($comment['deleted'] ? '·' : mb_strtoupper(mb_substr((string) $comment['author_name'], 0, 1))) ?></span><?php endif ?></span>
    </div>
    <div class="chat-header">
      <?php if ($comment['deleted']): ?>삭제된 댓글<?php else: ?><?= $this->e($comment['author_name']) ?><?php endif ?>
      <time datetime="<?= $this->e($comment['created_at']) ?>"><?= $this->date($comment['created_at'], 'y-m-d H:i:s') ?></time>
      <?php if (!$comment['deleted'] && !empty($comment['author_ip_masked'])): ?><span class="author-ip"><?= $this->e($comment['author_ip_masked']) ?></span><?php endif ?>
    </div>
    <div class="chat-bubble<?php if ($comment['is_secret']): ?> chat-bubble-secret<?php endif ?>">
      <?php if ($comment['is_secret']): ?>
        <span class="chat-lock" title="비밀 댓글" aria-label="비밀 댓글"><?= $this->icon('lock', 15) ?></span>
        <div class="chat-bubble-content">
          <?= $this->html($comment['content']) ?>
          <?php if (($comment['secret_masked'] ?? false) && ($comment['secret_unlockable'] ?? false)): ?>
            <a class="comment-secret-unlock" href="<?= $this->url('comments.password', ['id' => $comment['id']]) ?>">비밀번호 확인</a>
          <?php endif ?>
        </div>
      <?php else: ?>
        <?= $this->html($comment['content']) ?>
      <?php endif ?>
    </div>
    <?php if (!$comment['deleted']):
      // 열람과 편집 소유권은 별개다. 비회원 댓글은 비밀번호 확인 전까지 편집자로 보지 않는다.
      $canEdit = (bool) ($comment['can_edit'] ?? false);
      $needsEditPassword = (bool) ($comment['needs_edit_password'] ?? false);
      if (($can_comment ?? false) || $canEdit || $needsEditPassword): ?>
        <div class="chat-footer">
          <?php if ($can_comment ?? false): ?>
            <button class="btn btn-ghost btn-sm" type="button" data-reply="<?= $this->e($comment['id']) ?>" data-reply-author="<?= $this->e($comment['author_name']) ?>">답글</button>
          <?php endif ?>
          <?php if ($canEdit): ?>
            <a class="btn btn-ghost btn-sm" href="<?= $this->url('comments.edit', ['id' => $comment['id']]) ?>"
               data-edit="<?= $this->e($comment['id']) ?>"
               data-edit-action="<?= $this->url('comments.edit', ['id' => $comment['id']]) ?>"
               data-delete-action="<?= $this->url('comments.delete', ['id' => $comment['id']]) ?>"
               data-edit-secret="<?= $comment['is_secret'] ? '1' : '0' ?>"
               data-edit-reply="<?= $comment['parent_id'] !== null ? '1' : '0' ?>"
               <?php if ($comment['author_id'] === null): ?>data-password-verified="1"<?php endif ?>><?= $this->icon('pencil', 13) ?> 수정</a>
            <?php // 화면에 보이는 본문은 사진이 축소본으로 바뀐 것이라 그대로 저장하면 안 된다.
                  // 편집기에 넣을 원래 내용을 따로 담아 둔다. ?>
            <template data-source="<?= $this->e($comment['id']) ?>"><?= $this->e($comment['content']) ?></template>
          <?php elseif ($needsEditPassword): ?>
            <button class="btn btn-ghost btn-sm" type="button"
                    data-guest-edit="<?= $this->e($comment['id']) ?>"
                    data-owner-action="<?= $this->url('comments.ownership', ['id' => $comment['id']]) ?>">
              <?= $this->icon('pencil', 13) ?> 수정
            </button>
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
