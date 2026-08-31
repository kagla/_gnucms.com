<?php $this->layout('layout') ?>
<?php $this->start('title') ?>비밀 댓글 확인 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>board<?php $this->stop() ?>
<?php $this->start('extra_tabs') ?><a class="tab tab-active" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" aria-current="page"><?= $this->e($board['name']) ?></a><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs">
  <ul>
    <li><a href="<?= $this->url('boards.index') ?>">홈</a></li>
    <li><a href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>"><?= $this->e($board['name']) ?></a></li>
    <li aria-current="page">비밀 댓글 확인</li>
  </ul>
</div>
<div class="auth-wrap">
  <section class="card auth-card secret-password-card">
    <div class="card-body">
      <span class="auth-mark" aria-hidden="true"><?= $this->icon('lock', 23) ?></span>
      <h1 class="card-title">비밀 댓글입니다</h1>
      <p class="card-sub">댓글·부모 댓글 작성 비밀번호 또는 비회원 원글의 비밀번호를 입력해 주세요.</p>
      <form method="post" action="<?= $this->url('comments.password', ['id' => $comment_id]) ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <fieldset class="fieldset<?php if (isset($errors['password'])): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">비밀번호</legend>
          <label class="input input-bordered input-block">
            <span class="input-icon" aria-hidden="true"><?= $this->icon('lock', 16) ?></span>
            <input type="password" name="password" autocomplete="current-password" required autofocus>
            <?php $this->insert('auth/_pw_toggle') ?>
          </label>
          <?php if (isset($errors['password'])): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
        </fieldset>
        <div class="card-actions form-actions">
          <a class="btn btn-ghost" href="<?= $this->url('posts.show', ['id' => $post_id]) ?>#comment-<?= $this->e($comment_id) ?>">목록</a>
          <button class="btn btn-primary" type="submit">댓글 보기</button>
        </div>
      </form>
    </div>
  </section>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><?php $this->insert('auth/_pw_toggle_script') ?><?php $this->stop() ?>
