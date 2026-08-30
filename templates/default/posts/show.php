<?php $this->layout('layout') ?>
<?php $this->start('title') ?><?= $this->e($post['title']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('meta_description') ?><meta name="description" content="<?= $this->e(mb_substr(strip_tags((string) $post['content']), 0, 150)) ?>"><?php $this->stop() ?>
<?php $this->start('nav_section') ?>board<?php $this->stop() ?>
<?php $this->start('extra_tabs') ?><a class="tab tab-active" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>" aria-current="page"><?= $this->e($board['name']) ?></a><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="read-progress" aria-hidden="true"></div>

<div class="breadcrumbs">
  <ul>
    <li><a href="<?= $this->url('boards.index') ?>">홈</a></li>
    <li><a href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>"><?= $this->e($board['name']) ?></a></li>
    <li aria-current="page">게시글</li>
  </ul>
</div>

<article class="card article">
  <div class="card-body article-head">
    <span class="article-tags">
      <span class="badge badge-primary badge-soft"><?= $this->icon('board', 13) ?> <?= $this->e($board['name']) ?></span>
      <?php if ($post['category']): ?><span class="badge badge-ghost"><?= $this->icon('tag', 13) ?> <?= $this->e($post['category']) ?></span><?php endif ?>
      <?php if ($post['is_secret']): ?><span class="badge badge-ghost"><?= $this->icon('lock', 12) ?> 비밀글</span><?php endif ?>
    </span>
    <h1 class="card-title article-title"><?= $this->e($post['title']) ?></h1>
    <div class="article-writer">
      <span class="avatar avatar-placeholder avatar-sm">
        <span class="avatar-inner" data-tone="<?= $this->e(mb_strlen((string) $post['author_name']) % 6) ?>" aria-hidden="true"><span><?= $this->e(mb_strtoupper(mb_substr((string) $post['author_name'], 0, 1))) ?></span></span>
      </span>
      <span class="article-writer-copy">
        <strong><?= $this->e($post['author_name']) ?></strong>
        <span class="article-writer-meta">
          <time datetime="<?= $this->e($post['created_at']) ?>"><?= $this->date($post['created_at'], 'y-m-d H:i:s') ?></time>
          <span class="stat-inline"><?= $this->icon('eye', 14) ?> 조회 <?= $this->e($post['view_count']) ?></span>
          <?php // 댓글 수를 누르면 댓글 자리로 간다. 따로 "댓글 보기" 를 둘 이유가 없다. ?>
          <a class="stat-inline stat-inline-link" href="#comments"><?= $this->icon('comment', 14) ?> 댓글 <?= $this->e($post['comment_count']) ?></a>
        </span>
      </span>
      <span class="article-actions-inline">
        <?php
        $mine = $current_user['is_admin']
          || ($post['author_id'] !== null && $current_user['id'] !== null && $post['author_id'] == $current_user['id'])
          || $post['author_id'] === null;
        ?>
        <?php if ($mine): ?>
          <a class="btn btn-outline btn-sm" href="<?= $this->url('posts.edit', ['id' => $post['id']]) ?>"><?= $this->icon('pencil', 14) ?> 수정</a>
        <?php endif ?>
        <a class="btn btn-outline btn-sm" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>"><?= $this->icon('arrow-left', 15) ?> 목록</a>
      </span>
    </div>
  </div>

  <div class="divider divider-flush"></div>

  <div class="card-body article-body prose"><?= $this->html($post['content']) ?></div>

  <?php if (!empty($post['attachments'])): ?>
    <div class="card-body article-files">
      <h2 class="section-title"><?= $this->icon('clip', 16) ?> 첨부파일 <span class="badge badge-ghost badge-sm"><?= $this->e(count($post['attachments'])) ?></span></h2>
      <ul class="list file-list">
        <?php foreach ($post['attachments'] as $file): ?>
          <li class="list-row">
            <span class="file-ico" aria-hidden="true"><?= $this->icon('document', 17) ?></span>
            <a class="file-name link link-hover" href="<?= $this->url('files.download', ['id' => $post['id'], 'index' => $file['index']]) ?>"><?= $this->e($file['name']) ?></a>
            <span class="file-size"><?= $this->e(round($file['size'] / 1024)) ?> KB</span>
          </li>
        <?php endforeach ?>
      </ul>
    </div>
  <?php endif ?>
</article>

<section class="card comments" id="comments" aria-labelledby="comments-title">
  <div class="card-body">
    <h2 class="section-title" id="comments-title"><?= $this->icon('comment', 16) ?> 댓글 <span class="badge badge-primary badge-soft badge-sm"><?= $this->e($post['comment_count']) ?></span></h2>
    <?php // 댓글이 없을 때는 아무것도 그리지 않는다. 머리에 이미 "댓글 0" 이 있고,
          // 바로 아래가 쓰는 자리라 빈 자리를 알리는 그림은 자리만 차지한다. ?>
    <?php if (!empty($comments)): ?>
      <?php $this->insert('posts/_comments', ['nodes' => $comments, 'nested' => false, 'can_comment' => $can_comment], true) ?>
    <?php endif ?>

    <?php if ($can_comment): ?>
      <?php // 답글을 쓰면 폼이 그 댓글 아래로 옮겨 간다. 취소하면 이 자리로 돌아온다. ?>
      <div data-comment-form-home hidden></div>
      <form class="comment-form" id="comment-form" method="post" action="<?= $this->url('comments.create', ['id' => $post['id']]) ?>">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
        <input type="hidden" name="image_key" value="<?= $this->e($comment_values['image_key'] ?? '') ?>">
        <input type="hidden" name="uploaded_images" value="<?= $this->e($comment_values['uploaded_images'] ?? '') ?>" data-uploaded-images>
        <input type="hidden" name="parent_id" value="<?= $this->e($comment_values['parent_id'] ?? '') ?>" data-parent-id>

        <p class="comment-reply-to" data-reply-to hidden>
          <span data-reply-name></span>님에게 답글
          <button class="btn btn-ghost btn-sm" type="button" data-reply-cancel>취소</button>
        </p>
        <p class="comment-reply-to comment-edit-to" data-edit-to hidden>
          내 댓글 고치는 중
          <button class="btn btn-ghost btn-sm" type="button" data-edit-cancel>취소</button>
        </p>

        <?php if ($current_user['is_guest']): ?>
          <div class="grid-2" data-guest-fields>
            <fieldset class="fieldset<?php if (array_key_exists('author_name', $comment_errors)): ?> is-invalid<?php endif ?>" data-name-field>
              <legend class="fieldset-legend">이름</legend>
              <input class="input input-bordered input-block" type="text" name="author_name" value="<?= $this->e($comment_values['author_name'] ?? '') ?>" maxlength="100" required>
              <?php if (array_key_exists('author_name', $comment_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($comment_errors['author_name']) ?></p><?php endif ?>
            </fieldset>
            <fieldset class="fieldset<?php if (array_key_exists('password', $comment_errors)): ?> is-invalid<?php endif ?>">
              <legend class="fieldset-legend">비밀번호 <span class="legend-hint">수정·삭제에 씁니다</span></legend>
              <input class="input input-bordered input-block" type="password" name="password" required>
              <?php if (array_key_exists('password', $comment_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($comment_errors['password']) ?></p><?php endif ?>
            </fieldset>
          </div>
        <?php endif ?>

        <fieldset class="fieldset<?php if (array_key_exists('content', $comment_errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend sr-only">댓글 내용</legend>
          <textarea class="textarea textarea-bordered textarea-block" id="comment-content" name="content" rows="4" required placeholder="댓글을 남겨 주세요"><?= $this->e($comment_values['content'] ?? '') ?></textarea>
          <?php if (array_key_exists('content', $comment_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($comment_errors['content']) ?></p><?php endif ?>
        </fieldset>

        <div class="comment-form-foot">
          <?php if ($board['use_secret']): ?>
            <label class="label toggle-row comment-secret">
              <input class="toggle toggle-primary" type="checkbox" name="is_secret" value="1"<?php if ($comment_values['is_secret'] ?? false): ?> checked<?php endif ?>>
              <span><strong><?= $this->icon('lock', 14) ?> 비밀 댓글</strong></span>
            </label>
          <?php endif ?>
          <button class="btn btn-primary" type="submit" data-submit>댓글 등록</button>
          <?php // 고치는 중에만 나온다. 같은 폼을 삭제 주소로 보내므로 비밀번호 칸도 함께 간다. ?>
          <button class="btn btn-error btn-outline btn-delete" type="submit" data-delete hidden formnovalidate
                  onclick="return confirm('이 댓글을 삭제할까요? 되돌릴 수 없습니다.')"><?= $this->icon('trash', 15) ?> 삭제</button>
        </div>
      </form>
    <?php else: ?>
      <p class="comment-denied"><?php if ($current_user['is_guest']): ?>댓글을 쓰려면 <a class="link" href="<?= $this->url('auth.login') ?>">로그인</a>이 필요합니다.<?php else: ?>이 게시판에 댓글을 쓸 권한이 없습니다.<?php endif ?></p>
    <?php endif ?>
  </div>
</section>

<div class="article-actions">
  <a class="btn btn-outline btn-lg" href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>"><?= $this->icon('arrow-left', 16) ?> <?= $this->e($board['name']) ?> 목록으로</a>
  <?php if ($can_write ?? false): ?>
    <a class="btn btn-primary btn-lg" href="<?= $this->url('posts.create', ['key' => $board['board_key']]) ?>"><?= $this->icon('pencil', 16) ?> 나도 글쓰기</a>
  <?php endif ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<?php if ($can_comment): ?>
  <?php $this->insert('posts/_editor', [
    'editor_id' => 'comment-content',
    'upload_url' => $this->url('comment.editor.images', ['id' => $post['id']]) . '?csrf_token=' . rawurlencode($csrf_token) . '&image_key=' . rawurlencode($comment_values['image_key'] ?? ''),
    'discard_url' => $this->url('comment.editor.images.discard', ['id' => $post['id']]) . '?csrf_token=' . rawurlencode($csrf_token) . '&image_key=' . rawurlencode($comment_values['image_key'] ?? ''),
    'editor_mini' => true,
  ], true) ?>
  <script>
  (function(){
    var form=document.getElementById('comment-form');
    if(!form){return}
    var home=document.querySelector('[data-comment-form-home]'),
        parent=form.querySelector('[data-parent-id]'),bar=form.querySelector('[data-reply-to]'),
        name=form.querySelector('[data-reply-name]'),cancel=form.querySelector('[data-reply-cancel]'),
        editBar=form.querySelector('[data-edit-to]'),editCancel=form.querySelector('[data-edit-cancel]'),
        del=form.querySelector('[data-delete]'),
        imageKey=form.querySelector('[name="image_key"]'),
        nameField=form.querySelector('[data-name-field]'),
        submit=form.querySelector('[data-submit]'),
        createAction=form.getAttribute('action'),
        submitLabel=submit?submit.textContent:'댓글 등록';

    /* 폼을 옮길 때는 편집기를 껐다 켜야 한다. 편집기가 아직 없으면 그냥 옮긴다. */
    function place(move){
      var api=window.<?= $this->e(GNUCMS_ID) ?>Editor&&window.<?= $this->e(GNUCMS_ID) ?>Editor['comment-content'];
      if(api){api.remount(move)}else{move()}
    }

    function toReply(button){
      var id=button.getAttribute('data-reply'),target=document.getElementById('comment-'+id);
      if(!target){return}
      parent.value=id;
      name.textContent=button.getAttribute('data-reply-author')||'';
      bar.hidden=false;
      form.classList.add('is-reply');
      place(function(){
        /* 답글의 답글이 이미 달려 있으면 그 묶음 뒤에 놓아야 순서가 맞다. */
        var after=target.nextElementSibling;
        if(after&&after.classList.contains('comment-thread-sub')){target=after}
        target.parentNode.insertBefore(form,target.nextSibling);
      });
      focusSoon();
    }

    /* 댓글을 이 자리에서 고친다. 폼을 그 댓글 아래로 옮기고 보낼 곳만 바꾼다.
       화면에 보이는 본문은 사진이 축소본으로 바뀐 것이라 그대로 쓰면 안 되므로,
       템플릿에 담아 둔 원래 내용을 편집기에 넣는다. */
    function toEdit(link){
      var id=link.getAttribute('data-edit'),
          target=document.getElementById('comment-'+id),
          source=document.querySelector('template[data-source="'+id+'"]');
      if(!target||!source){return}

      parent.value='';
      bar.hidden=true;
      editBar.hidden=false;
      form.classList.remove('is-reply');
      form.classList.add('is-editing');
      form.setAttribute('action',link.getAttribute('data-edit-action'));
      if(nameField){nameField.hidden=true}
      if(submit){submit.textContent='댓글 저장'}
      if(del){del.hidden=false;del.setAttribute('formaction',link.getAttribute('data-delete-action')||'')}
      if(imageKey){imageKey.value=''}

      var textarea=document.getElementById('comment-content');
      place(function(){
        textarea.value=source.content.textContent;
        target.parentNode.insertBefore(form,target.nextSibling);
      });
      focusSoon();
    }

    /* 답글·수정 어느 쪽이든 처음 상태로 되돌린다. */
    function reset(){
      parent.value='';
      bar.hidden=true;
      editBar.hidden=true;
      form.classList.remove('is-reply','is-editing');
      form.setAttribute('action',createAction);
      if(nameField){nameField.hidden=false}
      if(submit){submit.textContent=submitLabel}
      if(del){del.hidden=true;del.removeAttribute('formaction')}
      var textarea=document.getElementById('comment-content');
      place(function(){
        textarea.value='';
        if(home){home.parentNode.insertBefore(form,home.nextSibling)}
      });
    }

    function focusSoon(){
      form.scrollIntoView({block:'center',behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
      var api=window.<?= $this->e(GNUCMS_ID) ?>Editor&&window.<?= $this->e(GNUCMS_ID) ?>Editor['comment-content'];
      if(api){window.setTimeout(api.focus,120)}
    }

    document.addEventListener('click',function(e){
      if(!e.target.closest){return}
      var button=e.target.closest('[data-reply]');
      if(button){toReply(button);return}
      var link=e.target.closest('[data-edit]');
      if(link&&!e.metaKey&&!e.ctrlKey&&!e.shiftKey){e.preventDefault();toEdit(link)}
    });
    if(cancel){cancel.addEventListener('click',reset)}
    if(editCancel){editCancel.addEventListener('click',reset)}
  })();
  </script>
<?php endif ?>
<?php $this->stop() ?>
