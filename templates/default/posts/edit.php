<?php $this->layout('layout') ?>
<?php $this->start('title') ?>글 수정 · <?= $this->e($board['name']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>board<?php $this->stop() ?>
<?php $this->start('extra_tabs') ?><a class="tab tab-active" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" aria-current="page"><?= $this->e($board['name']) ?></a><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs">
  <ul>
    <li><a href="<?= $this->url('boards.index') ?>">홈</a></li>
    <li><a href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>"><?= $this->e($board['name']) ?></a></li>
    <li><a href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>">게시글</a></li>
    <li aria-current="page">수정</li>
  </ul>
</div>

<section class="card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('pencil', 19) ?> 글 수정</h1>
    <p class="card-sub"><?= $this->e($board['name']) ?>에 올린 글을 고칩니다.</p>

    <form method="post" action="<?= $this->url('posts.edit', ['id' => $post['id']]) ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="image_key" value="<?= $this->e($values['image_key'] ?? '') ?>">
      <input type="hidden" name="uploaded_images" value="<?= $this->e($values['uploaded_images'] ?? '') ?>" data-uploaded-images>

      <?php if ($needs_password): ?>
        <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">비밀번호 <span class="legend-hint">글을 쓸 때 정한 비밀번호</span></legend>
          <input class="input input-bordered input-block" type="password" name="password" autocomplete="new-password" required autofocus>
          <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
        </fieldset>
      <?php endif ?>

      <?php if ($board['use_category'] && $board['categories'] !== []): ?>
        <fieldset class="fieldset<?php if (array_key_exists('category', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">분류</legend>
          <div class="chip-bar">
            <?php foreach ($board['categories'] as $name): ?>
              <label class="btn btn-sm chip-radio">
                <input type="radio" name="category" value="<?= $this->e($name) ?>"<?php if (($values['category'] ?? '') === $name): ?> checked<?php endif ?> required>
                <span><?= $this->e($name) ?></span>
              </label>
            <?php endforeach ?>
          </div>
          <?php if (array_key_exists('category', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['category']) ?></p><?php endif ?>
        </fieldset>
      <?php endif ?>

      <fieldset class="fieldset<?php if (array_key_exists('title', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">제목</legend>
        <input class="input input-bordered input-block" type="text" name="title" value="<?= $this->e($values['title'] ?? '') ?>" maxlength="200" required placeholder="제목을 입력해 주세요">
        <?php if (array_key_exists('title', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['title']) ?></p><?php endif ?>
      </fieldset>

      <fieldset class="fieldset<?php if (array_key_exists('content', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">내용</legend>
        <textarea class="textarea textarea-bordered textarea-block" id="post-content" name="content" rows="14" data-required="1" data-min-chars="<?= $this->e((string) ($site['post_min_chars'] ?? 0)) ?>"><?= $this->e($values['content'] ?? '') ?></textarea>
        <?php if (array_key_exists('content', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['content']) ?></p><?php endif ?>
      </fieldset>

      <?php if ($board['use_secret']): ?>
        <label class="label toggle-row">
          <input class="toggle toggle-primary" type="checkbox" name="is_secret" value="1"<?php if ($values['is_secret'] ?? false): ?> checked<?php endif ?>>
          <span><strong><?= $this->icon('lock', 14) ?> 비밀글로 두기</strong><small>작성자와 관리자만 볼 수 있어요.</small></span>
        </label>
      <?php endif ?>

      <?php if (!empty($can_manage_board)): ?>
        <?php // 공지는 그 게시판의 관리자만 올린다. 회원에게는 이 칸이 아예 없다. ?>
        <fieldset class="fieldset<?php if (array_key_exists('notice', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend"><?= $this->icon('megaphone', 15) ?> 공지</legend>
          <?php if (array_key_exists('notice', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['notice']) ?></p><?php endif ?>
          <?php // 이미 전체 공지인 글을 게시판 관리자가 고칠 때. 셀렉트는 라디오와 달리
                // 언제나 값을 보내므로, 고를 수 없는 사람에게 열린 셀렉트를 주면 첫 항목
                // ('공지 아님')이 제출돼 공지가 내려가거나 403 이 난다. 잠근 셀렉트는
                // 아무것도 보내지 않으므로 글만 고치고 공지는 그대로 남는다. ?>
          <?php if (empty($can_pin_global) && ($notice_current ?? 'none') === 'global'): ?>
            <select class="select select-bordered select-block" aria-label="공지 범위" disabled>
              <option selected>전체 게시판 공지</option>
            </select>
            <p class="fieldset-label">현재 전체 공지입니다. 사이트 관리자만 바꿀 수 있습니다.</p>
          <?php else: ?>
            <select class="select select-bordered select-block" name="notice" aria-label="공지 범위">
              <?php foreach (['none' => '공지 아님', 'board' => '이 게시판 공지', 'global' => '전체 게시판 공지'] as $value => $label): ?>
                <?php if ($value === 'global' && empty($can_pin_global)): continue; endif ?>
                <option value="<?= $this->e($value) ?>"<?= $this->def($values['notice'] ?? null, 'none') === $value ? ' selected' : '' ?>><?= $this->e($label) ?></option>
              <?php endforeach ?>
            </select>
          <?php endif ?>
          <?php if (!empty($can_pin_global)): ?>
            <p class="fieldset-label">전체 게시판 공지는 이 게시판을 읽을 수 있는 사람에게만 보입니다.</p>
          <?php endif ?>
        </fieldset>
      <?php endif ?>

      <?php if (!empty($board['use_file'])): ?><?php $this->insert('posts/_attachments', ['board' => $board, 'values' => $values, 'errors' => $errors]) ?><?php endif ?>

      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>">취소</a>
        <button class="btn btn-primary" type="submit">저장</button>
        <?php // 같은 폼을 삭제 주소로 보낸다. 비밀번호 칸도 그대로 함께 간다. ?>
        <button class="btn btn-error btn-outline btn-delete" type="submit"
                formaction="<?= $this->url('posts.delete', ['id' => $post['id']]) ?>" formnovalidate
                onclick="return confirm('이 글을 삭제할까요? 되돌릴 수 없습니다.')"><?= $this->icon('trash', 15) ?> 삭제</button>
      </div>
    </form>
  </div>

</section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php $this->insert('posts/_editor', [
  'editor_id' => 'post-content',
  'upload_url' => $this->url('board.editor.images', ['key' => $board['board_key']]) . '?csrf_token=' . rawurlencode($csrf_token) . '&image_key=' . rawurlencode($values['image_key'] ?? ''),
  'discard_url' => $this->url('board.editor.images.discard', ['key' => $board['board_key']]) . '?csrf_token=' . rawurlencode($csrf_token) . '&image_key=' . rawurlencode($values['image_key'] ?? ''),
  'editor_mini' => false,
], true) ?>
<?php $this->stop() ?>
