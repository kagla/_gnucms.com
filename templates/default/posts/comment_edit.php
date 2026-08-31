<?php $this->layout('layout') ?>
<?php $this->start('title') ?>댓글 수정 · <?= $this->e($board['name']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>board<?php $this->stop() ?>
<?php $this->start('extra_tabs') ?><a class="tab tab-active" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" aria-current="page"><?= $this->e($board['name']) ?></a><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs">
  <ul>
    <li><a href="<?= $this->url('boards.index') ?>">홈</a></li>
    <li><a href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>"><?= $this->e($board['name']) ?></a></li>
    <li><a href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>"><?= $this->e($post['title']) ?></a></li>
    <li aria-current="page">댓글 수정</li>
  </ul>
</div>

<section class="card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('pencil', 19) ?> 댓글 수정</h1>
    <p class="card-sub"><?= $this->e($post['title']) ?>에 남긴 댓글을 고칩니다.</p>

    <form method="post" action="<?= $this->url('comments.edit', ['id' => $comment['id']]) ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="image_key" value="<?= $this->e($values['image_key'] ?? '') ?>">
      <input type="hidden" name="uploaded_images" value="" data-uploaded-images>

      <?php if ($needs_password): ?>
        <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">비밀번호 <span class="legend-hint">댓글을 쓸 때 정한 비밀번호</span></legend>
          <input class="input input-bordered input-block" type="password" name="password" autocomplete="new-password" required autofocus>
          <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
        </fieldset>
      <?php endif ?>

      <fieldset class="fieldset<?php if (array_key_exists('content', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend sr-only">댓글 내용</legend>
        <textarea class="textarea textarea-bordered textarea-block" id="comment-edit-content" name="content" rows="5" data-required="1"><?= $this->e($this->def($values['content'] ?? null, $comment['content'])) ?></textarea>
        <?php if (array_key_exists('content', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['content']) ?></p><?php endif ?>
      </fieldset>

      <?php if ($board['use_secret']): ?>
        <label class="label toggle-row">
          <input class="toggle toggle-primary" type="checkbox" name="is_secret" value="1"<?php if ($comment['is_secret']): ?> checked<?php endif ?>>
          <span><strong><?= $this->icon('lock', 14) ?> 비밀 댓글</strong></span>
        </label>
      <?php endif ?>

      <div class="form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>#comment-<?= $this->e($comment['id']) ?>">취소</a>
        <button class="btn btn-primary" type="submit"><?= $this->icon('check', 16) ?> 저장</button>
        <?php // 같은 폼을 삭제 주소로 보낸다. 비밀번호 칸도 그대로 함께 간다. ?>
        <button class="btn btn-error btn-outline btn-delete" type="submit"
                formaction="<?= $this->url('comments.delete', ['id' => $comment['id']]) ?>" formnovalidate
                onclick="return confirm('이 댓글을 삭제할까요? 되돌릴 수 없습니다.')"><?= $this->icon('trash', 15) ?> 삭제</button>
      </div>
    </form>
  </div>

</section>

<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php $this->insert('posts/_editor', [
  'editor_id' => 'comment-edit-content',
  'upload_url' => $this->url('comment.editor.images', ['id' => $post['id']]) . '?csrf_token=' . rawurlencode($csrf_token) . '&image_key=' . rawurlencode($values['image_key'] ?? ''),
  'discard_url' => $this->url('comment.editor.images.discard', ['id' => $post['id']]) . '?csrf_token=' . rawurlencode($csrf_token) . '&image_key=' . rawurlencode($values['image_key'] ?? ''),
  'editor_mini' => true,
], true) ?>
<?php $this->stop() ?>
