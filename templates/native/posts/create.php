<?php $this->layout('layout') ?>
<?php $this->start('title') ?>글쓰기 · <?= $this->e($board['name']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>board<?php $this->stop() ?>
<?php $this->start('extra_tabs') ?><a class="tab tab-active" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" aria-current="page"><?= $this->e($board['name']) ?></a><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs">
  <ul>
    <li><a href="<?= $this->url('boards.index') ?>">홈</a></li>
    <li><a href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>"><?= $this->e($board['name']) ?></a></li>
    <li aria-current="page">글쓰기</li>
  </ul>
</div>

<section class="card">
  <div class="card-body">
    <h1 class="card-title"><?= $this->icon('pencil', 19) ?> 글쓰기</h1>
    <p class="card-sub"><?= $this->e($board['name']) ?>에 남길 이야기를 적어 주세요.</p>

    <form method="post" action="<?= $this->url('posts.create', ['key' => $board['board_key']]) ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="image_key" value="<?= $this->e($values['image_key'] ?? '') ?>">
      <input type="hidden" name="uploaded_images" value="<?= $this->e($values['uploaded_images'] ?? '') ?>" data-uploaded-images>

      <?php if ($board['use_category'] && $board['categories'] !== []): ?>
        <fieldset class="fieldset">
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
        <input class="input input-bordered input-block" type="text" name="title" value="<?= $this->e($values['title'] ?? '') ?>" maxlength="200" required autofocus placeholder="제목을 입력해 주세요">
        <?php if (array_key_exists('title', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['title']) ?></p><?php endif ?>
      </fieldset>

      <fieldset class="fieldset<?php if (array_key_exists('content', $errors)): ?> is-invalid<?php endif ?>">
        <legend class="fieldset-legend">내용</legend>
        <textarea class="textarea textarea-bordered textarea-block" id="post-content" name="content" rows="14" data-required="1" placeholder="어떤 이야기를 나누고 싶으신가요?"><?= $this->e($values['content'] ?? '') ?></textarea>
        <?php if (array_key_exists('content', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['content']) ?></p><?php endif ?>
      </fieldset>

      <?php if ($board['use_secret']): ?>
        <fieldset class="fieldset">
          <label class="label toggle-row">
            <input class="toggle toggle-primary" type="checkbox" name="is_secret" value="1"<?php if ($values['is_secret'] ?? false): ?> checked<?php endif ?>>
            <span><strong><?= $this->icon('lock', 14) ?> 비밀글로 작성</strong><small>작성자와 관리자만 볼 수 있어요.</small></span>
          </label>
        </fieldset>
      <?php endif ?>

      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>">취소</a>
        <button class="btn btn-primary" type="submit">등록하기</button>
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
