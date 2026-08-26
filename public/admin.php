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
  :root { --line: #ddd; --muted: #666; --danger: #b00020; }
  * { box-sizing: border-box; }
  body { font: 15px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; color: #1a1a1a; }
  header { display: flex; align-items: center; gap: 12px; padding: 12px 20px; border-bottom: 1px solid var(--line); }
  header h1 { font-size: 17px; margin: 0; flex: 1; }
  main { max-width: 1040px; margin: 24px auto; padding: 0 20px; }
  section { margin-bottom: 32px; }
  h2 { font-size: 15px; border-bottom: 1px solid var(--line); padding-bottom: 6px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  th { color: var(--muted); font-weight: 600; font-size: 13px; }
  input, select, textarea { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; font: inherit; }
  button { padding: 5px 10px; font: inherit; cursor: pointer; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
  button.danger { color: var(--danger); border-color: var(--danger); }
  button.link { border: 0; background: none; padding: 0; color: #0b57d0; text-decoration: underline; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
  .field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; color: var(--muted); }
  .field input, .field select { color: #1a1a1a; }
  .muted { color: var(--muted); font-size: 13px; }
  .deleted { text-decoration: line-through; color: var(--muted); }
  .toast { position: fixed; right: 20px; bottom: 20px; padding: 10px 16px; border-radius: 4px; color: #fff; background: #333; }
  .toast.error { background: var(--danger); }
  .hidden { display: none; }
  .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
</style>
</head>
<body>

<header>
  <h1>표준 게시판 관리</h1>
  <span id="who" class="muted"></span>
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
        <label class="field">분류 (쉼표 구분)<input id="f-categories"></label>
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

  function cell(row, text, className) {
    var td = document.createElement('td');
    td.textContent = text;
    if (className) { td.className = className; }
    row.appendChild(td);
    return td;
  }

  function splitList(value) {
    return value.split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
  }

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
        cell(tr, board.board_key);
        cell(tr, board.name);
        cell(tr, board.perm_read + ' / ' + board.perm_write + ' / ' + board.perm_comment);
        cell(tr, [board.use_secret ? '비밀글' : '', board.use_file ? '첨부' : '', board.use_category ? '분류' : '']
          .filter(Boolean).join(' ') || '-', 'muted');
        cell(tr, (board.managers || []).join(', ') || '-', 'muted');

        var td = document.createElement('td');
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
      $('f-categories').value = (state.board.categories || []).join(', ');
      $('f-managers').value = (state.board.managers || []).join(', ');

      loadPosts();
    }).catch(fail);
  }

  $('save-board').onclick = function () {
    api('PATCH', '/boards/' + state.board.board_key, {
      name: $('f-name').value,
      perm_read: $('f-perm_read').value,
      perm_write: $('f-perm_write').value,
      perm_comment: $('f-perm_comment').value,
      per_page: Number($('f-per_page').value),
      sort_order: Number($('f-sort_order').value),
      categories: splitList($('f-categories').value),
      managers: splitList($('f-managers').value),
      use_secret: $('f-use_secret').value === 'true',
      use_file: $('f-use_file').value === 'true',
      use_category: $('f-use_category').value === 'true'
    }).then(function () {
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
    cell(tr, String(post.id));

    var titleCell = document.createElement('td');
    var title = document.createElement('button');
    title.className = 'link' + (post.deleted ? ' deleted' : '');
    title.textContent = (post.is_notice ? '[공지] ' : '') + (post.is_secret ? '[비밀] ' : '') + post.title;
    title.onclick = function () { openComments(post); };
    titleCell.appendChild(title);
    tr.appendChild(titleCell);

    cell(tr, post.author_name);
    cell(tr, String(post.comment_count));
    cell(tr, String(post.view_count));
    cell(tr, post.created_at, 'muted');

    var actions = document.createElement('td');
    actions.className = 'row';

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
        cell(tr, String(comment.id));
        cell(tr,
          new Array(comment.depth + 1).join('　') + (comment.depth > 0 ? '└ ' : '') + comment.content,
          comment.deleted ? 'muted' : '');
        cell(tr, comment.author_name || '-');
        cell(tr, comment.created_at, 'muted');

        var actions = document.createElement('td');
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
