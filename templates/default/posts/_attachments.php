<?php
// 첨부 UI. use_file 게시판의 쓰기/수정 폼이 insert 한다.
//   board   게시판 (board_key)
//   values  폼 값. values['attachments'] = [ {id,name,size,mime,path,sig}, ... ]
//   errors  422 재렌더일 때의 검증 오류. insert() 가 부모 화면 지역 변수를 그대로
//           물려주므로 보통 있지만, 혹시 없는 경로를 대비해 기본값을 둔다.
// 파일은 고르는 즉시 boards.files.upload 로 올라가고, 서명된 디스크립터가
// hidden input 으로 실린다. 목록의 DOM 순서가 곧 저장 순서다.
$errors = $errors ?? [];
$attach_rows = [];
foreach (($values['attachments'] ?? []) as $row) {
    if (is_array($row) && isset($row['sig'])) {
        $attach_rows[] = $row;
    }
}
?>
<fieldset class="fieldset attach-box" data-attachments
  data-url="<?= $this->url('boards.files.upload', ['key' => $board['board_key']]) ?>"
  data-limit="<?= $this->e((string) ($site['attach_limit'] ?? 5)) ?>"
  data-max-bytes="<?= $this->e((string) (($site['attach_max_mb'] ?? 5) * 1048576)) ?>">
  <legend class="fieldset-legend"><?= $this->icon('clip', 15) ?> 첨부파일
    <span class="legend-hint">파일당 <?= $this->e((string) ($site['attach_max_mb'] ?? 5)) ?>MB<?php if ((int) ($site['attach_limit'] ?? 5) > 0): ?> · <?= $this->e((string) $site['attach_limit']) ?>개까지<?php endif ?></span>
  </legend>
  <?php if (array_key_exists('attachments', $errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['attachments']) ?></p><?php endif ?>
  <div class="attach-zone" data-attach-zone>
    <p>파일을 여기에 끌어다 놓거나</p>
    <label class="btn btn-sm">파일 선택<input type="file" multiple hidden data-attach-input></label>
  </div>
  <p class="validator-hint attach-error" data-attach-error hidden></p>
  <ul class="attach-list" data-attach-list>
    <?php foreach ($attach_rows as $i => $row): ?>
    <li class="attach-row" draggable="true">
      <span class="attach-grip" aria-hidden="true"><?= $this->icon('menu', 14) ?></span>
      <span class="attach-name"><?= $this->e($row['name']) ?></span>
      <span class="attach-size"><?= $this->e(number_format(((int) $row['size']) / 1024, 1)) ?> KB</span>
      <span class="attach-tools">
        <button type="button" class="btn btn-ghost btn-xs" data-attach-up aria-label="위로">↑</button>
        <button type="button" class="btn btn-ghost btn-xs" data-attach-down aria-label="아래로">↓</button>
        <button type="button" class="btn btn-ghost btn-xs" data-attach-remove aria-label="삭제"><?= $this->icon('close', 13) ?></button>
      </span>
      <?php foreach (['id', 'name', 'size', 'mime', 'path', 'sig'] as $field): ?>
      <input type="hidden" data-field="<?= $this->e($field) ?>" name="attachments[<?= $this->e((string) $i) ?>][<?= $this->e($field) ?>]" value="<?= $this->e((string) ($row[$field] ?? '')) ?>">
      <?php endforeach ?>
    </li>
    <?php endforeach ?>
  </ul>
</fieldset>
<script>
(function () {
  var box = document.querySelector('[data-attachments]');
  if (!box) { return; }
  var form = box.closest('form');
  var csrf = form ? form.querySelector('[name=csrf_token]') : null;
  var url = box.dataset.url + '?csrf_token=' + encodeURIComponent(csrf ? csrf.value : '');
  var limit = parseInt(box.dataset.limit, 10) || 0;      // 0 = 무제한
  var maxBytes = parseInt(box.dataset.maxBytes, 10) || 0;
  var list = box.querySelector('[data-attach-list]');
  var zone = box.querySelector('[data-attach-zone]');
  var input = box.querySelector('[data-attach-input]');
  var errorBox = box.querySelector('[data-attach-error]');

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = false;
    window.clearTimeout(showError.timer);
    showError.timer = window.setTimeout(function () { errorBox.hidden = true; }, 6000);
  }

  function renumber() {
    Array.prototype.forEach.call(list.children, function (row, index) {
      Array.prototype.forEach.call(row.querySelectorAll('input[type=hidden]'), function (hidden) {
        hidden.name = 'attachments[' + index + '][' + hidden.dataset.field + ']';
      });
    });
  }

  function makeRow(name) {
    var row = document.createElement('li');
    row.className = 'attach-row is-uploading';
    row.draggable = true;
    row.innerHTML = '<span class="attach-grip" aria-hidden="true">≡</span>'
      + '<span class="attach-name"></span><span class="attach-size">올리는 중…</span>'
      + '<span class="attach-tools">'
      + '<button type="button" class="btn btn-ghost btn-xs" data-attach-up aria-label="위로">↑</button>'
      + '<button type="button" class="btn btn-ghost btn-xs" data-attach-down aria-label="아래로">↓</button>'
      + '<button type="button" class="btn btn-ghost btn-xs" data-attach-remove aria-label="삭제">✕</button></span>';
    row.querySelector('.attach-name').textContent = name;
    list.appendChild(row);
    return row;
  }

  function addFile(file) {
    // 업로드 중인 행과 이미 끝난 행 모두 자리를 차지한다. 실패한 행만 빼야
    // 여러 파일을 한 번에 고르거나(멀티 선택), 아직 응답이 안 온 파일들까지
    // 한도에 반영된다. 실패한 행을 세면 그 자리가 영영 못 채워진다.
    if (limit > 0 && list.querySelectorAll('li:not(.is-failed)').length >= limit) {
      showError('첨부는 ' + limit + '개까지입니다.');
      return;
    }
    if (maxBytes > 0 && file.size > maxBytes) {
      showError('"' + file.name + '" 은 파일당 한도를 넘습니다.');
      return;
    }
    var row = makeRow(file.name);
    var body = new FormData();
    body.append('file', file);
    fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
      .then(function (result) {
        if (!result.ok) { throw new Error(result.data.error || '업로드에 실패했습니다.'); }
        row.classList.remove('is-uploading');
        row.querySelector('.attach-size').textContent = result.data.size_label;
        ['id', 'name', 'size', 'mime', 'path', 'sig'].forEach(function (field) {
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.dataset.field = field;
          hidden.value = result.data[field];
          row.appendChild(hidden);
        });
        renumber();
      })
      .catch(function (error) {
        row.classList.add('is-failed');
        row.classList.remove('is-uploading');
        row.querySelector('.attach-size').textContent = '실패: ' + error.message;
      });
  }

  input.addEventListener('change', function () {
    Array.prototype.forEach.call(input.files, addFile);
    input.value = '';
  });
  ['dragover', 'dragleave', 'drop'].forEach(function (type) {
    // zone 이 아니라 전체 상자(box)에 건다. 사용자가 존 몇 픽셀을 벗어나 떨어뜨려도
    // 브라우저가 파일을 열어버리며 페이지를 벗어나는 일을 막기 위해서다.
    box.addEventListener(type, function (event) {
      // 행 드래그(순서 조정)는 무시하고 밖에서 온 파일만 받는다.
      if (!event.dataTransfer || Array.prototype.indexOf.call(event.dataTransfer.types, 'Files') === -1) { return; }
      event.preventDefault();
      zone.classList.toggle('is-over', type === 'dragover');
      if (type === 'drop') { Array.prototype.forEach.call(event.dataTransfer.files, addFile); }
    });
  });

  list.addEventListener('click', function (event) {
    var button = event.target.closest('button');
    if (!button) { return; }
    var row = button.closest('li');
    if (button.hasAttribute('data-attach-remove')) {
      row.remove();
    } else if (button.hasAttribute('data-attach-up') && row.previousElementSibling) {
      list.insertBefore(row, row.previousElementSibling);
    } else if (button.hasAttribute('data-attach-down') && row.nextElementSibling) {
      list.insertBefore(row.nextElementSibling, row);
    }
    renumber();
  });

  // HTML5 드래그로 순서 조정. 라이브러리 없이 li 만 옮긴다.
  var dragging = null;
  list.addEventListener('dragstart', function (event) {
    dragging = event.target.closest('li');
    if (dragging) { dragging.classList.add('is-dragging'); event.dataTransfer.effectAllowed = 'move'; }
  });
  list.addEventListener('dragend', function () {
    if (dragging) { dragging.classList.remove('is-dragging'); dragging = null; renumber(); }
  });
  list.addEventListener('dragover', function (event) {
    if (!dragging) { return; }
    event.preventDefault();
    var over = event.target.closest('li');
    if (!over || over === dragging) { return; }
    var rect = over.getBoundingClientRect();
    var after = event.clientY > rect.top + rect.height / 2;
    list.insertBefore(dragging, after ? over.nextElementSibling : over);
  });

  // 업로드가 끝나지 않은 행이 있으면 저장을 막는다. 실패한 행은 hidden input이
  // 없을 뿐 사용자가 무시하고 저장할 수 있어야 하므로 막지 않는다.
  if (form) {
    form.addEventListener('submit', function (event) {
      if (list.querySelector('.is-uploading')) {
        event.preventDefault();
        showError('파일을 올리는 중입니다. 끝난 뒤 저장해 주세요.');
      }
    });
  }
})();
</script>
