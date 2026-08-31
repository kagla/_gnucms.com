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

    <form method="post" action="<?= $this->url('posts.create', ['key' => $board['board_key']]) ?>" data-post-create-form novalidate>
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="image_key" value="<?= $this->e($values['image_key'] ?? '') ?>">
      <input type="hidden" name="uploaded_images" value="<?= $this->e($values['uploaded_images'] ?? '') ?>" data-uploaded-images>

      <?php if ($current_user['is_guest']): ?>
        <?php // 비회원 글은 이름과 비밀번호(수정·삭제의 소유 증명)가 필수다. 댓글 폼과 같은 짜임. ?>
        <div class="grid-2">
          <fieldset class="fieldset<?php if (array_key_exists('author_name', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">이름</legend>
            <input class="input input-bordered input-block" type="text" name="author_name" autocomplete="off" value="<?= $this->e($values['author_name'] ?? '') ?>" maxlength="20" required>
            <?php if (array_key_exists('author_name', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['author_name']) ?></p><?php endif ?>
          </fieldset>
          <fieldset class="fieldset<?php if (array_key_exists('password', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">비밀번호 <span class="legend-hint"><?= $this->e((string) $password_min) ?>자 이상 · 수정·삭제에 씁니다</span></legend>
            <input class="input input-bordered input-block" type="password" name="password" minlength="<?= $this->e((string) $password_min) ?>" autocomplete="new-password" required>
            <?php if (array_key_exists('password', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['password']) ?></p><?php endif ?>
          </fieldset>
        </div>
      <?php endif ?>

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
        <textarea class="textarea textarea-bordered textarea-block" id="post-content" name="content" rows="14" data-required="1" placeholder="어떤 이야기를 나누고 싶으신가요?" data-min-chars="<?= $this->e((string) ($site['post_min_chars'] ?? 0)) ?>"><?= $this->e($values['content'] ?? '') ?></textarea>
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

      <?php if (!empty($can_manage_board)): ?>
        <?php // 공지는 그 게시판의 관리자만 올린다. 회원에게는 이 칸이 아예 없다. ?>
        <fieldset class="fieldset">
          <legend class="fieldset-legend"><?= $this->icon('megaphone', 15) ?> 공지</legend>
          <div class="chip-bar" role="radiogroup" aria-label="공지 범위">
            <?php foreach (['none' => '공지 아님', 'board' => '이 게시판 공지', 'global' => '전체 게시판 공지'] as $value => $label): ?>
              <label class="btn btn-sm chip-radio">
                <input type="radio" name="notice" value="<?= $this->e($value) ?>"<?= $this->def($values['notice'] ?? null, 'none') === $value ? ' checked' : '' ?>>
                <span><?= $this->e($label) ?></span>
              </label>
            <?php endforeach ?>
          </div>
          <p class="fieldset-label">전체 게시판 공지는 이 게시판을 읽을 수 있는 사람에게만 보입니다.</p>
        </fieldset>
      <?php endif ?>

      <?php if (!empty($board['use_file'])): ?><?php $this->insert('posts/_attachments', ['board' => $board, 'values' => $values, 'errors' => $errors]) ?><?php endif ?>

      <div class="card-actions form-actions post-create-actions">
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
<script>
(function(){
  var form=document.querySelector('[data-post-create-form]');if(!form){return}
  function clearClientErrors(){
    var hints=form.querySelectorAll('.client-validator-hint');
    for(var i=0;i<hints.length;i++){
      var box=hints[i].closest('.fieldset');hints[i].remove();
      if(box&&!box.querySelector('.validator-hint')){box.classList.remove('is-invalid')}
    }
  }
  function show(field,message,editor){
    var box=field?field.closest('.fieldset'):null;if(!box){return}
    box.classList.add('is-invalid');
    var hint=box.querySelector('.validator-hint');
    if(!hint){hint=document.createElement('p');box.appendChild(hint)}
    hint.className='validator-hint client-validator-hint';hint.setAttribute('role','alert');hint.textContent=message;
    box.scrollIntoView({behavior:'smooth',block:'center'});
    window.setTimeout(function(){
      if(editor){editor.focus()}else if(field){try{field.focus({preventScroll:true})}catch(e){field.focus()}}
    },350);
  }
  form.addEventListener('submit',function(event){
    clearClientErrors();
    var textarea=document.getElementById('post-content'),editor=window.CKEDITOR&&window.CKEDITOR.instances['post-content'];
    if(editor){editor.updateElement()}
    var rules=[['author_name','이름을 입력해 주세요.'],['password','비밀번호를 입력해 주세요.'],
      ['category','분류를 선택해 주세요.'],['title','제목을 입력해 주세요.']];
    for(var i=0;i<rules.length;i++){
      var field=form.elements[rules[i][0]];if(!field){continue}
      var first=field.length!==undefined&&!field.tagName?field[0]:field;
      var missing=first&&first.type==='radio'?!form.querySelector('[name="'+rules[i][0]+'"]:checked'):String(first.value||'').trim()==='';
      if(missing){event.preventDefault();event.stopImmediatePropagation();show(first,rules[i][1],null);return}
      var minLength=parseInt(first.getAttribute&&first.getAttribute('minlength'),10)||0;
      if(minLength>0&&Array.from(String(first.value||'')).length<minLength){
        event.preventDefault();event.stopImmediatePropagation();show(first,'비밀번호를 '+minLength+'자 이상 입력해 주세요.',null);return;
      }
    }
    var plain=textarea?textarea.value.replace(/<[^>]*>/g,'').replace(/&nbsp;/g,' ').replace(/\s/g,''):'';
    if(plain===''){
      event.preventDefault();event.stopImmediatePropagation();show(textarea,'내용을 입력해 주세요.',editor);return;
    }
    var minChars=parseInt(textarea&&textarea.getAttribute('data-min-chars'),10)||0;
    if(minChars>0&&plain.length<minChars){
      event.preventDefault();event.stopImmediatePropagation();show(textarea,'내용을 '+minChars+'자 이상 입력해 주세요. 현재 '+plain.length+'자입니다.',editor);
    }
  },true);
  var firstServerError=form.querySelector('.fieldset.is-invalid');
  if(firstServerError){window.setTimeout(function(){firstServerError.scrollIntoView({block:'center'})},0)}
})();
</script>
<?php $this->stop() ?>
