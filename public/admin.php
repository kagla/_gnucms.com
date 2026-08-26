<?php

declare(strict_types=1);

// 이 파일은 서버 로직을 갖지 않는다. API 를 호출하는 정적 화면일 뿐이다.
// PHP 파일로 두는 것은 설치 위치와 무관하게 index.php 를 상대경로로 찾기 위해서다.
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>표준 게시판 관리</title>
<style>
  /*
   * 테마는 토큰으로만 바꾼다. 밝은 값이 바탕이고, 어두운 값은
   *   (a) 시스템이 어두울 때  (b) 사용자가 어둡게 골랐을 때
   * 두 경우에 덮어쓴다. (a) 에 :not([data-theme="light"]) 를 붙여야
   * 시스템이 어두워도 "밝게" 를 고른 사용자가 밝은 화면을 본다.
   */
  :root {
    color-scheme: light;
    --bg: #ffffff;
    --fg: #1a1a1a;
    --panel: #ffffff;
    --line: #dddddd;
    --muted: #666666;
    --danger: #b00020;
    --link: #0b57d0;
    --field-bg: #ffffff;
    --field-line: #cccccc;
    --toast-bg: #333333;
    --toast-fg: #ffffff;
  }
  @media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
      color-scheme: dark;
      --bg: #16181c;
      --fg: #e6e8ea;
      --panel: #1c1f24;
      --line: #2c3038;
      --muted: #9aa1ab;
      --danger: #ff7b8a;
      --link: #7cb0ff;
      --field-bg: #1c1f24;
      --field-line: #3a4049;
      --toast-bg: #2a2f36;
      --toast-fg: #f2f4f6;
    }
  }
  :root[data-theme="dark"] {
    color-scheme: dark;
    --bg: #16181c;
    --fg: #e6e8ea;
    --panel: #1c1f24;
    --line: #2c3038;
    --muted: #9aa1ab;
    --danger: #ff7b8a;
    --link: #7cb0ff;
    --field-bg: #1c1f24;
    --field-line: #3a4049;
    --toast-bg: #2a2f36;
    --toast-fg: #f2f4f6;
  }

  * { box-sizing: border-box; }
  body {
    font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif;
    margin: 0;
    color: var(--fg);
    background: var(--bg);
  }
  header {
    display: flex; align-items: center; gap: 8px 12px; flex-wrap: wrap;
    padding: 12px 20px; border-bottom: 1px solid var(--line); background: var(--panel);
  }
  header h1 { font-size: 17px; margin: 0; flex: 1 1 auto; }
  main { max-width: 1040px; margin: 24px auto; padding: 0 20px; }
  section { margin-bottom: 32px; }
  h2 { font-size: 15px; border-bottom: 1px solid var(--line); padding-bottom: 6px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  th { color: var(--muted); font-weight: 600; font-size: 13px; }
  input, select, textarea {
    padding: 6px 8px; border: 1px solid var(--field-line); border-radius: 4px;
    font: inherit; background: var(--field-bg); color: var(--fg); max-width: 100%;
  }
  button {
    padding: 5px 10px; font: inherit; cursor: pointer;
    border: 1px solid var(--field-line); border-radius: 4px;
    background: var(--panel); color: var(--fg);
  }
  button.danger { color: var(--danger); border-color: var(--danger); }
  button.icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; padding: 0; flex: 0 0 auto;
  }
  button.icon svg { width: 18px; height: 18px; display: block; }
  button.link { border: 0; background: none; padding: 0; color: var(--link); text-decoration: underline; text-align: left; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
  .field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--muted); }
  .field input, .field select { color: var(--fg); }
  .cat-row { display: flex; gap: 6px; align-items: center; margin-bottom: 4px; }
  .cat-row input { flex: 1 1 auto; min-width: 0; }
  .cat-row button { flex: 0 0 auto; }
  .muted { color: var(--muted); font-size: 13px; }
  .deleted { text-decoration: line-through; color: var(--muted); }
  .toast {
    position: fixed; right: 20px; bottom: 20px; max-width: calc(100vw - 40px);
    padding: 10px 16px; border-radius: 4px; color: var(--toast-fg); background: var(--toast-bg);
  }
  .toast.error { background: var(--danger); color: #ffffff; }
  .hidden { display: none; }
  .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  /* 늘어나야 하는 것은 글자 입력란뿐이다. 체크박스까지 늘리면 네모가 줄줄이 늘어난다. */
  .row input:not([type="checkbox"]):not([type="radio"]) { flex: 1 1 200px; min-width: 0; }
  .row input[type="checkbox"], .row input[type="radio"] { flex: 0 0 auto; width: auto; margin: 0; }

  /*
   * 좁은 화면에서는 표를 카드로 바꾼다. 열이 6~7개라 가로 스크롤로는 못 읽는다.
   * 머리글을 숨기는 대신 각 칸이 data-label 을 이름표로 달고 나온다.
   */
  @media (max-width: 720px) {
    main { margin: 16px auto; padding: 0 12px; }
    /* iOS 는 16px 미만 입력란에 자동 확대를 건다 */
    input, select, textarea { font-size: 16px; }
    .row input:not([type="checkbox"]):not([type="radio"]) { flex: 1 1 100%; }

    table, tbody, tr, td { display: block; width: 100%; }
    thead { display: none; }
    tr {
      border: 1px solid var(--line); border-radius: 8px;
      padding: 8px 10px; margin-bottom: 10px; background: var(--panel);
    }
    td { border: 0; padding: 3px 0; display: flex; gap: 10px; align-items: baseline; }
    td::before {
      content: attr(data-label);
      flex: 0 0 76px; color: var(--muted); font-size: 13px;
    }
    td:empty { display: none; }
    td.actions { flex-wrap: wrap; padding-top: 8px; }
    .toast { right: 12px; left: 12px; bottom: 12px; max-width: none; }
  }
</style>
<script>
  // 화면이 그려지기 전에 저장해 둔 테마를 붙인다.
  // 본문 끝의 스크립트에서 하면 잠깐 다른 색이 번쩍인다.
  try {
    var savedTheme = localStorage.getItem('sb_theme');
    if (savedTheme === 'light' || savedTheme === 'dark') {
      document.documentElement.setAttribute('data-theme', savedTheme);
    }
  } catch (e) { /* 시크릿 모드 */ }
</script>
</head>
<body>

<header>
  <h1>표준 게시판 관리</h1>
  <span id="who" class="muted"></span>
  <button id="theme" class="icon" type="button" aria-label="어둡게 전환" title="어둡게 전환"></button>
  <button id="logout" class="hidden">로그아웃</button>
</header>

<main>
  <section id="login-view">
    <h2>로그인</h2>
    <div class="row">
      <input id="login-id" placeholder="관리자 아이디" autocomplete="username">
      <input id="login-password" type="password" placeholder="비밀번호" autocomplete="current-password">
      <button id="login-btn">로그인</button>
    </div>
    <p class="muted">호스트 앱이 발급한 토큰이 있다면 아래에 붙여 넣어도 됩니다.</p>
    <div class="row">
      <input id="login-token" placeholder="eyJ..." style="flex:1">
      <button id="token-btn">토큰으로 시작</button>
    </div>
  </section>

  <div id="admin-view" class="hidden">
    <section>
      <h2>게시판</h2>
      <table>
        <thead><tr><th>키</th><th>이름</th><th>권한(읽기/쓰기/댓글)</th><th>옵션</th><th>관리자</th><th></th></tr></thead>
        <tbody id="board-rows"></tbody>
      </table>
      <p class="row" style="margin-top:12px">
        <input id="new-key" placeholder="board_key (영소문자/숫자/_/-)">
        <input id="new-name" placeholder="게시판 이름">
        <button id="create-board">게시판 만들기</button>
      </p>
    </section>

    <section id="board-detail" class="hidden">
      <h2>게시판 설정 — <span id="detail-name"></span></h2>
      <div class="grid">
        <label class="field">이름<input id="f-name"></label>
        <label class="field">읽기 권한<select id="f-perm_read"></select></label>
        <label class="field">쓰기 권한<select id="f-perm_write"></select></label>
        <label class="field">댓글 권한<select id="f-perm_comment"></select></label>
        <label class="field">페이지당 글 수<input id="f-per_page" type="number" min="1" max="100"></label>
        <label class="field">정렬 순서<input id="f-sort_order" type="number"></label>
        <div class="field">분류
          <div id="f-categories"></div>
          <button type="button" id="add-category">분류 추가</button>
        </div>
        <label class="field">게시판 관리자 (쉼표 구분 user id)<input id="f-managers"></label>
        <label class="field">비밀글 사용<select id="f-use_secret"></select></label>
        <label class="field">첨부 사용<select id="f-use_file"></select></label>
        <label class="field">분류 사용<select id="f-use_category"></select></label>
      </div>
      <p class="row" style="margin-top:12px">
        <button id="save-board">설정 저장</button>
        <button id="delete-board" class="danger">게시판 삭제 (글·댓글 함께 삭제)</button>
      </p>
    </section>

    <section id="post-section" class="hidden">
      <h2>글 — <span id="post-board-name"></span></h2>
      <div class="row">
        <input id="post-q" placeholder="검색어">
        <button id="post-search">검색</button>
        <label class="row" style="gap:4px"><input id="show-deleted" type="checkbox"> 삭제된 글 보기</label>
        <span id="post-meta" class="muted"></span>
      </div>
      <table style="margin-top:10px">
        <thead><tr><th>번호</th><th>제목</th><th>작성자</th><th>댓글</th><th>조회</th><th>작성일</th><th></th></tr></thead>
        <tbody id="post-rows"></tbody>
      </table>
      <p class="row"><button id="prev-page">이전</button><button id="next-page">다음</button></p>
    </section>

    <section id="comment-section" class="hidden">
      <h2>댓글 — <span id="comment-post-title"></span> <button id="close-comments" class="link">닫기</button></h2>
      <table>
        <thead><tr><th>번호</th><th>내용</th><th>작성자</th><th>작성일</th><th></th></tr></thead>
        <tbody id="comment-rows"></tbody>
      </table>
    </section>

    <section>
      <h2>유지보수</h2>
      <p class="row">
        <button id="gc">고아 첨부 정리</button>
        <span class="muted">어떤 글에도 연결되지 않은 업로드 파일을 지웁니다.</span>
      </p>
    </section>
  </div>
</main>

<script>
(function () {
  'use strict';

  // mod_rewrite 유무와 무관하게 동작하도록 쿼리스트링 라우팅을 쓴다.
  var API = 'index.php?p=';
  var PERMS = ['guest', 'member', 'admin'];

  var state = { token: null, boards: [], board: null, page: 1, q: '', showDeleted: false, totalPages: 1 };

  function $(id) { return document.getElementById(id); }

  function toast(message, isError) {
    var el = document.createElement('div');
    el.className = 'toast' + (isError ? ' error' : '');
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(function () { el.remove(); }, 3000);
  }

  function url(path, query) {
    return API + encodeURIComponent(path).replace(/%2F/g, '/') + (query || '');
  }

  function api(method, path, body, query) {
    var options = { method: method, headers: {} };
    if (state.token) { options.headers['Authorization'] = 'Bearer ' + state.token; }
    if (body !== undefined && body !== null) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    return fetch(url(path, query), options).then(function (response) {
      if (response.status === 204) { return {}; }
      return response.json().then(function (payload) {
        if (!response.ok) {
          var error = (payload && payload.error) || {};
          var keys = error.details ? Object.keys(error.details) : [];
          var detail = keys.length
            ? ' (' + keys.map(function (k) { return k + ': ' + error.details[k]; }).join(', ') + ')'
            : '';
          throw new Error((error.message || '요청 실패') + detail);
        }
        return payload;
      });
    });
  }

  function fail(e) { toast(e.message, true); }

  function fillSelect(el, values, labels) {
    el.innerHTML = '';
    values.forEach(function (value, i) {
      var option = document.createElement('option');
      option.value = String(value);
      option.textContent = labels ? labels[i] : String(value);
      el.appendChild(option);
    });
  }

  function cell(row, text, className, label) {
    var td = document.createElement('td');
    td.textContent = text;
    if (className) { td.className = className; }
    // 좁은 화면에서 머리글 대신 붙는 이름표다.
    if (label) { td.setAttribute('data-label', label); }
    row.appendChild(td);
    return td;
  }

  /*
   * 분류는 한 줄에 하나씩 편집한다. 쉼표로 나누던 방식은 두 가지가 걸렸다.
   * 이름에 쉼표를 못 쓰고(조용히 둘로 쪼개진다), 어느 줄을 고쳤는지 알 수 없어
   * 이름 변경인지 삭제 후 추가인지 구분할 수 없었다.
   *
   * 각 줄이 원래 값을 data-original 에 들고 있으므로, 서버에는 짐작이 아니라
   * 실제로 고친 짝만 category_renames 로 보낸다.
   */
  function categoryRow(value) {
    var row = document.createElement('div');
    row.className = 'cat-row';

    var input = document.createElement('input');
    input.value = value;
    input.setAttribute('data-original', value);
    input.placeholder = '분류 이름';
    row.appendChild(input);

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'danger';
    remove.textContent = '\u00d7';
    remove.title = '이 분류 지우기';
    remove.setAttribute('aria-label', '이 분류 지우기');
    remove.onclick = function () { row.remove(); };
    row.appendChild(remove);

    return row;
  }

  function renderCategories(list) {
    var box = $('f-categories');
    box.innerHTML = '';
    (list || []).forEach(function (name) { box.appendChild(categoryRow(name)); });
  }

  /** @return {{categories: string[], renames: Object}} */
  function collectCategories() {
    var categories = [];
    var renames = {};

    Array.prototype.forEach.call($('f-categories').querySelectorAll('input'), function (input) {
      var value = input.value.trim();
      if (value === '') { return; }
      if (categories.indexOf(value) === -1) { categories.push(value); }

      var original = input.getAttribute('data-original') || '';
      if (original !== '' && original !== value) { renames[original] = value; }
    });

    return { categories: categories, renames: renames };
  }

  $('add-category').onclick = function () {
    $('f-categories').appendChild(categoryRow(''));
  };

  function splitList(value) {
    return value.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
  }

  // ---- 테마 ------------------------------------------------------------

  // 밝게 <-> 어둡게 두 가지뿐이다. 처음 열었을 때만 운영체제 설정을 따르고,
  // 한 번이라도 누르면 그 선택을 기억한다.
  //
  // 버튼에는 "지금 상태" 가 아니라 "누르면 되는 상태" 의 아이콘을 그린다.
  // 버튼은 상태 표시등이 아니라 동작이기 때문이다. 아이콘만으로는 어느 쪽인지
  // 헷갈릴 수 있으므로 title 과 aria-label 에 말로도 적는다.
  var ICONS = {
    // 달: 누르면 어두워진다
    dark: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
      + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
    // 해: 누르면 밝아진다
    light: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
      + ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<circle cx="12" cy="12" r="4"/>'
      + '<path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4'
      + 'M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/></svg>'
  };
  var LABELS = { dark: '어둡게 전환', light: '밝게 전환' };

  var theme = 'light';

  function applyTheme(mode) {
    document.documentElement.setAttribute('data-theme', mode);
    var next = mode === 'dark' ? 'light' : 'dark';
    var button = $('theme');
    button.innerHTML = ICONS[next];
    button.setAttribute('title', LABELS[next]);
    button.setAttribute('aria-label', LABELS[next]);
  }

  try {
    var storedTheme = localStorage.getItem('sb_theme');
    theme = (storedTheme === 'dark' || storedTheme === 'light')
      ? storedTheme
      // 저장된 선택이 없으면 이번 한 번만 운영체제 설정을 따른다.
      : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  } catch (e) {
    theme = 'light';
  }
  applyTheme(theme);

  $('theme').onclick = function () {
    theme = theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('sb_theme', theme); } catch (e) { /* 시크릿 모드 */ }
    applyTheme(theme);
  };

  // ---- 로그인 ----------------------------------------------------------

  function startSession(token) {
    state.token = token;
    try { sessionStorage.setItem('sb_token', token); } catch (e) { /* 시크릿 모드 */ }
    $('login-view').classList.add('hidden');
    $('admin-view').classList.remove('hidden');
    $('logout').classList.remove('hidden');
    $('who').textContent = '로그인됨';
    loadBoards();
  }

  $('login-btn').onclick = function () {
    api('POST', '/auth/login', { id: $('login-id').value, password: $('login-password').value })
      .then(function (payload) { startSession(payload.token); })
      .catch(fail);
  };

  $('token-btn').onclick = function () {
    var token = $('login-token').value.trim();
    if (token) { startSession(token); }
  };

  $('logout').onclick = function () {
    state.token = null;
    try { sessionStorage.removeItem('sb_token'); } catch (e) { /* 무시 */ }
    location.reload();
  };

  // ---- 게시판 ----------------------------------------------------------

  function loadBoards() {
    api('GET', '/boards').then(function (payload) {
      state.boards = payload.data;
      var tbody = $('board-rows');
      tbody.innerHTML = '';
      state.boards.forEach(function (board) {
        var tr = document.createElement('tr');
        cell(tr, board.board_key, null, '키');
        cell(tr, board.name, null, '이름');
        cell(tr, board.perm_read + ' / ' + board.perm_write + ' / ' + board.perm_comment, null, '권한');
        cell(tr, [board.use_secret ? '비밀글' : '', board.use_file ? '첨부' : '', board.use_category ? '분류' : '']
          .filter(Boolean).join(' ') || '-', 'muted', '옵션');
        cell(tr, (board.managers || []).join(', ') || '-', 'muted', '관리자');

        var td = document.createElement('td');
        td.className = 'actions';
        td.setAttribute('data-label', '작업');
        var manage = document.createElement('button');
        manage.textContent = '관리';
        manage.onclick = function () { openBoard(board.board_key); };
        td.appendChild(manage);
        tr.appendChild(td);

        tbody.appendChild(tr);
      });
    }).catch(fail);
  }

  $('create-board').onclick = function () {
    api('POST', '/boards', { board_key: $('new-key').value.trim(), name: $('new-name').value.trim() })
      .then(function () {
        $('new-key').value = '';
        $('new-name').value = '';
        toast('게시판을 만들었습니다.');
        loadBoards();
      }).catch(fail);
  };

  function openBoard(key) {
    api('GET', '/boards/' + key).then(function (payload) {
      state.board = payload.data;
      state.page = 1;
      state.q = '';
      $('post-q').value = '';
      $('board-detail').classList.remove('hidden');
      $('post-section').classList.remove('hidden');
      $('comment-section').classList.add('hidden');
      $('detail-name').textContent = state.board.name;
      $('post-board-name').textContent = state.board.name;

      ['perm_read', 'perm_write', 'perm_comment'].forEach(function (field) {
        fillSelect($('f-' + field), PERMS);
        $('f-' + field).value = state.board[field];
      });
      ['use_secret', 'use_file', 'use_category'].forEach(function (field) {
        fillSelect($('f-' + field), ['false', 'true'], ['사용 안 함', '사용']);
        $('f-' + field).value = state.board[field] ? 'true' : 'false';
      });
      $('f-name').value = state.board.name;
      $('f-per_page').value = state.board.per_page;
      $('f-sort_order').value = state.board.sort_order;
      renderCategories(state.board.categories);
      $('f-managers').value = (state.board.managers || []).join(', ');

      loadPosts();
    }).catch(fail);
  }

  $('save-board').onclick = function () {
    var cats = collectCategories();
    var payload = {
      name: $('f-name').value,
      perm_read: $('f-perm_read').value,
      perm_write: $('f-perm_write').value,
      perm_comment: $('f-perm_comment').value,
      per_page: Number($('f-per_page').value),
      sort_order: Number($('f-sort_order').value),
      categories: cats.categories,
      managers: splitList($('f-managers').value),
      use_secret: $('f-use_secret').value === 'true',
      use_file: $('f-use_file').value === 'true',
      use_category: $('f-use_category').value === 'true'
    };
    // 고친 줄이 있을 때만 보낸다. 서버는 짝이 없으면 글을 옮기지 않는다.
    if (Object.keys(cats.renames).length > 0) { payload.category_renames = cats.renames; }

    api('PATCH', '/boards/' + state.board.board_key, payload).then(function () {
      toast('저장했습니다.');
      loadBoards();
      openBoard(state.board.board_key);
    }).catch(fail);
  };

  $('delete-board').onclick = function () {
    if (!confirm('게시판 "' + state.board.name + '" 과 그 안의 글·댓글을 모두 지웁니다. 계속할까요?')) { return; }
    api('DELETE', '/boards/' + state.board.board_key).then(function () {
      toast('삭제했습니다.');
      state.board = null;
      $('board-detail').classList.add('hidden');
      $('post-section').classList.add('hidden');
      $('comment-section').classList.add('hidden');
      loadBoards();
    }).catch(fail);
  };

  // ---- 글 --------------------------------------------------------------

  function loadPosts() {
    var query = '&page=' + state.page + '&per_page=20';
    if (state.q) { query += '&q=' + encodeURIComponent(state.q); }
    if (state.showDeleted) { query += '&include_deleted=1'; }

    api('GET', '/boards/' + state.board.board_key + '/posts', null, query).then(function (payload) {
      state.totalPages = payload.total_pages;
      $('post-meta').textContent =
        '전체 ' + payload.total + '개 · ' + state.page + '/' + Math.max(1, payload.total_pages) + ' 쪽';

      var tbody = $('post-rows');
      tbody.innerHTML = '';
      payload.notices.concat(payload.data).forEach(function (post) {
        tbody.appendChild(postRow(post));
      });
    }).catch(fail);
  }

  function postRow(post) {
    var tr = document.createElement('tr');
    cell(tr, String(post.id), null, '번호');

    var titleCell = document.createElement('td');
    titleCell.setAttribute('data-label', '제목');
    var title = document.createElement('button');
    title.className = 'link' + (post.deleted ? ' deleted' : '');
    title.textContent = (post.is_notice ? '[공지] ' : '') + (post.is_secret ? '[비밀] ' : '') + post.title;
    title.onclick = function () { openComments(post); };
    titleCell.appendChild(title);
    tr.appendChild(titleCell);

    cell(tr, post.author_name, null, '작성자');
    cell(tr, String(post.comment_count), null, '댓글');
    cell(tr, String(post.view_count), null, '조회');
    cell(tr, post.created_at, 'muted', '작성일');

    var actions = document.createElement('td');
    actions.className = 'row actions';
    actions.setAttribute('data-label', '작업');

    var notice = document.createElement('button');
    notice.textContent = post.is_notice ? '공지 해제' : '공지 지정';
    notice.onclick = function () {
      api('PATCH', '/posts/' + post.id, { is_notice: !post.is_notice })
        .then(loadPosts).catch(fail);
    };
    actions.appendChild(notice);

    if (post.deleted) {
      var restore = document.createElement('button');
      restore.textContent = '복구';
      restore.onclick = function () {
        api('POST', '/posts/' + post.id + '/restore').then(function () {
          toast('복구했습니다.');
          loadPosts();
        }).catch(fail);
      };
      actions.appendChild(restore);
    } else {
      var remove = document.createElement('button');
      remove.className = 'danger';
      remove.textContent = '삭제';
      remove.onclick = function () {
        api('DELETE', '/posts/' + post.id).then(function () {
          toast('삭제했습니다. "삭제된 글 보기" 에서 복구할 수 있습니다.');
          loadPosts();
        }).catch(fail);
      };
      actions.appendChild(remove);
    }

    tr.appendChild(actions);
    return tr;
  }

  $('post-search').onclick = function () { state.q = $('post-q').value.trim(); state.page = 1; loadPosts(); };
  $('show-deleted').onchange = function () { state.showDeleted = this.checked; state.page = 1; loadPosts(); };
  $('prev-page').onclick = function () { if (state.page > 1) { state.page--; loadPosts(); } };
  $('next-page').onclick = function () { if (state.page < state.totalPages) { state.page++; loadPosts(); } };

  // ---- 댓글 ------------------------------------------------------------

  function flatten(nodes, out) {
    // 트리를 그대로 그리되 표에 넣기 위해 깊이 정보를 유지한 채 펼친다.
    nodes.forEach(function (node) {
      out.push(node);
      if (node.children && node.children.length) { flatten(node.children, out); }
    });
    return out;
  }

  function openComments(post) {
    api('GET', '/posts/' + post.id + '/comments').then(function (payload) {
      $('comment-section').classList.remove('hidden');
      $('comment-post-title').textContent = post.title;

      var tbody = $('comment-rows');
      tbody.innerHTML = '';
      var rows = flatten(payload.data, []);
      if (rows.length === 0) {
        var empty = document.createElement('tr');
        cell(empty, '');
        cell(empty, '댓글이 없습니다.', 'muted');
        tbody.appendChild(empty);
        return;
      }

      rows.forEach(function (comment) {
        var tr = document.createElement('tr');
        cell(tr, String(comment.id), null, '번호');
        cell(tr,
          new Array(comment.depth + 1).join('　') + (comment.depth > 0 ? '└ ' : '') + comment.content,
          comment.deleted ? 'muted' : '', '내용');
        cell(tr, comment.author_name || '-', null, '작성자');
        cell(tr, comment.created_at, 'muted', '작성일');

        var actions = document.createElement('td');
        actions.className = 'actions';
        actions.setAttribute('data-label', '작업');
        if (!comment.deleted) {
          var remove = document.createElement('button');
          remove.className = 'danger';
          remove.textContent = '삭제';
          remove.onclick = function () {
            api('DELETE', '/comments/' + comment.id).then(function () {
              toast('댓글을 삭제했습니다.');
              openComments(post);
              loadPosts();
            }).catch(fail);
          };
          actions.appendChild(remove);
        }
        tr.appendChild(actions);
        tbody.appendChild(tr);
      });
    }).catch(fail);
  }

  $('close-comments').onclick = function () { $('comment-section').classList.add('hidden'); };

  // ---- 유지보수 --------------------------------------------------------

  $('gc').onclick = function () {
    api('POST', '/maintenance/gc').then(function (payload) {
      toast('파일 ' + payload.data.deleted + '개 (' + payload.data.bytes + ' 바이트) 를 정리했습니다.');
    }).catch(fail);
  };

  // ---- 시작 ------------------------------------------------------------

  var saved = null;
  try { saved = sessionStorage.getItem('sb_token'); } catch (e) { /* 무시 */ }
  if (saved) { startSession(saved); }
})();
</script>
</body>
</html>
