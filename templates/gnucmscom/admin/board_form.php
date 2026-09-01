<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?><?= $create ? '게시판 만들기' : '게시판 설정' ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>boards<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li><a href="<?= $this->url('admin.boards') ?>">게시판</a></li><li aria-current="page"><?= $create ? '게시판 만들기' : $this->e($values['name']) ?></li></ul></div>
<section class="card">
  <div class="card-body">
    <div class="card-head-row card-head-row-flush">
      <div>
        <h1 class="card-title"><?= $create ? '게시판 만들기' : '게시판 설정' ?></h1>
        <p class="card-sub">기본 정보와 이용 권한을 설정합니다.</p>
      </div>
      <?php if (!$create): ?>
        <a class="btn btn-outline btn-sm" href="<?= $this->url('posts.index', ['key' => $board_key]) ?>" target="_blank" rel="noopener">
          <?= $this->icon('external', 15) ?> 게시판 보기
        </a>
      <?php endif ?>
    </div>
    <form method="post" action="<?= $create ? $this->url('admin.boards.create') : $this->url('admin.boards.edit', ['key' => $board_key]) ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">

      <div class="form-section">
        <h2 class="form-section-title">기본 정보</h2>
        <?php if ($create): ?>
          <fieldset class="fieldset<?php if (array_key_exists('board_key', $errors)): ?> is-invalid<?php endif ?>">
            <legend class="fieldset-legend">게시판 키 <span class="legend-hint">주소에 쓰이는 영문 소문자</span></legend>
            <input class="input input-bordered input-block" type="text" name="board_key" value="<?= $this->e($values['board_key'] ?? '') ?>" maxlength="50" pattern="[a-z0-9_-]+" required>
            <?php if (array_key_exists('board_key', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['board_key']) ?></p><?php endif ?>
          </fieldset>
        <?php else: ?>
          <fieldset class="fieldset">
            <legend class="fieldset-legend">게시판 키 <span class="legend-hint">만든 뒤에는 바꿀 수 없습니다</span></legend>
            <input class="input input-bordered input-block" type="text" value="<?= $this->e($board_key) ?>" readonly aria-describedby="board-key-help">
            <p class="fieldset-label" id="board-key-help">공개 주소: <code class="kbd kbd-sm">/boards/<?= $this->e($board_key) ?></code></p>
          </fieldset>
        <?php endif ?>
        <fieldset class="fieldset<?php if (array_key_exists('name', $errors)): ?> is-invalid<?php endif ?>">
          <legend class="fieldset-legend">게시판 이름</legend>
          <input class="input input-bordered input-block" type="text" name="name" value="<?= $this->e($values['name'] ?? '') ?>" maxlength="100" required>
          <?php if (array_key_exists('name', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['name']) ?></p><?php endif ?>
        </fieldset>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">설명 <span class="legend-hint">한 줄 요약. 카드와 검색 결과 설명에 쓰입니다</span></legend>
          <input class="input input-bordered input-block" type="text" name="description" value="<?= $this->e($values['description'] ?? '') ?>" maxlength="200" placeholder="이 게시판을 한 줄로 소개해 주세요">
        </fieldset>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">이용 권한</h2>
        <?php // 값(guest·member·admin)은 그대로 두고 이름만 사람 말로 보인다. ?>
        <?php $perm_labels = ['guest' => '누구나', 'member' => '회원', 'admin' => '관리자']; ?>
        <div class="grid-3 permission-grid">
          <fieldset class="fieldset permission-field"><legend class="fieldset-legend">읽기</legend>
            <p class="permission-help">게시글을 볼 수 있는 최소 권한입니다.</p>
            <select class="select select-bordered select-block" name="perm_read"><?php foreach (['guest', 'member', 'admin'] as $p): ?><option value="<?= $this->e($p) ?>"<?= ($values['perm_read'] ?? null) === $p ? ' selected' : '' ?>><?= $this->e($perm_labels[$p] ?? $p) ?></option><?php endforeach ?></select></fieldset>
          <fieldset class="fieldset permission-field"><legend class="fieldset-legend">쓰기</legend>
            <p class="permission-help">‘누구나’는 사이트 설정에서 비회원 글쓰기를 켜야 적용됩니다.</p>
            <select class="select select-bordered select-block" name="perm_write"><?php foreach (['guest', 'member', 'admin'] as $p): ?><option value="<?= $this->e($p) ?>"<?= ($values['perm_write'] ?? null) === $p ? ' selected' : '' ?>><?= $this->e($perm_labels[$p] ?? $p) ?></option><?php endforeach ?></select></fieldset>
          <fieldset class="fieldset permission-field"><legend class="fieldset-legend">댓글</legend>
            <p class="permission-help">댓글을 작성할 수 있는 최소 권한입니다.</p>
            <select class="select select-bordered select-block" name="perm_comment"><?php foreach (['guest', 'member', 'admin'] as $p): ?><option value="<?= $this->e($p) ?>"<?= ($values['perm_comment'] ?? null) === $p ? ' selected' : '' ?>><?= $this->e($perm_labels[$p] ?? $p) ?></option><?php endforeach ?></select></fieldset>
        </div>
      </div>

      <div class="form-section">
        <h2 class="form-section-title">기능과 노출</h2>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">분류 <span class="legend-hint">추가하면 칩으로 쌓입니다</span></legend>
          <?php // JS 가 켜지면 칩 UI 를 보이고 textarea 를 숨긴다. 꺼져 있으면 textarea 그대로 쓴다. ?>
          <div class="tag-input" data-tag-input hidden>
            <div class="tag-input-list" data-tag-list></div>
            <div class="tag-input-add">
              <input class="input input-bordered" type="text" data-tag-new placeholder="분류 이름을 입력하고 Enter" aria-label="분류 추가" maxlength="50">
              <button type="button" class="btn btn-outline" data-tag-add>추가</button>
            </div>
          </div>
          <textarea class="textarea textarea-bordered textarea-block" name="categories_text" rows="3" data-tag-store aria-label="분류 (한 줄에 하나씩)"><?= $this->e($values['categories_text'] ?? '') ?></textarea>
        </fieldset>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">목록 형태</legend>
          <?php $list_labels = ['list' => '목록형 (표)', 'gallery' => '갤러리형 (사진 격자)', 'magazine' => '매거진형 (사진 + 발췌)', 'news' => '뉴스형 (제목 + 발췌)']; ?>
          <select class="select select-bordered select-block" name="list_type">
            <?php foreach ($list_labels as $name => $label): ?><option value="<?= $this->e($name) ?>"<?= $this->def($values['list_type'] ?? null, 'list') === $name ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?>
          </select>
          <p class="fieldset-label">보는 사람이 주소에 ?view= 를 붙여 잠시 다른 형태로 볼 수 있습니다.</p>
        </fieldset>
        <div class="grid-2">
          <fieldset class="fieldset"><legend class="fieldset-legend">페이지당 글 수</legend>
            <input class="input input-bordered input-block" type="number" name="per_page" value="<?= $this->e($this->def($values['per_page'] ?? null, 20)) ?>" min="1" max="100"></fieldset>
          <fieldset class="fieldset"><legend class="fieldset-legend">정렬 순서 <span class="legend-hint">작을수록 위</span></legend>
            <input class="input input-bordered input-block" type="number" name="sort_order" value="<?= $this->e($values['sort_order'] ?? 0) ?>" min="-9999" max="9999"></fieldset>
        </div>
        <fieldset class="fieldset">
          <legend class="fieldset-legend">메인에 낼 최신 글 수 <span class="legend-hint">0 이면 메인에서 뺍니다</span></legend>
          <input class="input input-bordered input-block" type="number" name="home_limit" value="<?= $this->e($this->def($values['home_limit'] ?? null, 5)) ?>" min="0" max="10">
          <p class="fieldset-label">첫 화면에 이 게시판의 최신 글을 몇 개 보일지 정합니다. 0 으로 두면 게시판은 그대로 열리되 첫 화면에는 나오지 않습니다.</p>
        </fieldset>
        <fieldset class="fieldset toggle-list">
          <label class="label toggle-row"><input class="toggle toggle-primary" type="checkbox" name="use_category" value="1"<?= ($values['use_category'] ?? false) ? ' checked' : '' ?>><span><strong>분류 사용</strong></span></label>
          <label class="label toggle-row"><input class="toggle toggle-primary" type="checkbox" name="use_secret" value="1"<?= ($values['use_secret'] ?? false) ? ' checked' : '' ?>><span><strong>비밀글 사용</strong></span></label>
          <label class="label toggle-row"><input class="toggle toggle-primary" type="checkbox" name="use_file" value="1"<?= ($values['use_file'] ?? false) ? ' checked' : '' ?>><span><strong>첨부파일 사용</strong></span></label>
        </fieldset>
      </div>

      <div class="card-actions form-actions">
        <a class="btn btn-ghost" href="<?= $this->url('admin.boards') ?>">취소</a>
        <button class="btn btn-primary" type="submit"><?= $create ? '게시판 만들기' : '변경사항 저장' ?></button>
      </div>
    </form>
  </div>
  <?php if (!$create): ?>
    <form class="danger-zone" method="post" action="<?= $this->url('admin.boards.delete', ['key' => $board_key]) ?>" onsubmit="return confirm('게시판의 글과 댓글도 함께 삭제됩니다. 정말 삭제할까요?')">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <div><strong><?= $this->icon('warning', 15) ?> 게시판 삭제</strong><p>게시글과 댓글을 포함해 되돌릴 수 없이 삭제합니다.</p></div>
      <button class="btn btn-error btn-outline" type="submit"><?= $this->icon('trash', 15) ?> 삭제</button>
    </form>
  <?php endif ?>
</section>
<?php $this->stop() ?>
<?php $this->start('scripts') ?>
<script>
(function(){
  var box=document.querySelector('[data-tag-input]'),store=document.querySelector('[data-tag-store]');
  if(!box||!store){return}
  var list=box.querySelector('[data-tag-list]'),input=box.querySelector('[data-tag-new]'),addBtn=box.querySelector('[data-tag-add]');
  box.hidden=false;store.hidden=true;
  function items(){return store.value.split('\n').map(function(s){return s.trim()}).filter(Boolean)}
  function save(v){store.value=v.join('\n')}
  function render(){
    var v=items();list.textContent='';
    if(!v.length){
      var empty=document.createElement('p');empty.className='tag-input-empty';
      empty.textContent='아직 분류가 없습니다. 비워 두면 분류 없이 운영합니다.';
      list.appendChild(empty);return;
    }
    v.forEach(function(name,i){
      var chip=document.createElement('span');chip.className='tag-chip';
      var label=document.createElement('span');label.textContent=name;chip.appendChild(label);
      var del=document.createElement('button');del.type='button';del.className='tag-chip-remove';
      del.setAttribute('aria-label',name+' 분류 삭제');del.title=del.getAttribute('aria-label');
      del.textContent='\u00d7';
      del.addEventListener('click',function(){var cur=items();cur.splice(i,1);save(cur);render();input.focus()});
      chip.appendChild(del);list.appendChild(chip);
    });
  }
  function add(){
    var name=(input.value||'').trim();
    if(!name){input.focus();return}
    var cur=items();
    if(cur.indexOf(name)!==-1){input.select();return}
    cur.push(name);save(cur);input.value='';render();input.focus();
  }
  addBtn.addEventListener('click',add);
  input.addEventListener('keydown',function(e){
    if(e.key==='Enter'){e.preventDefault();add()}
    else if(e.key==='Backspace'&&input.value===''){var cur=items();if(cur.length){cur.pop();save(cur);render()}}
  });
  render();
})();
</script>
<?php $this->stop() ?>
