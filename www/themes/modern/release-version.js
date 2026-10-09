/* upstream 정식 릴리스: 페이지 방문 즉시, 5분마다, 탭 복귀 때 확인한다. */
(function () {
  'use strict';
  var badge = document.querySelector('[data-github-release]');
  if (!badge) { return; }
  var version = badge.querySelector('[data-github-release-version]');
  var downloadVersion = document.querySelector('[data-github-download-version]');
  if (!version || typeof window.fetch !== 'function') { return; }

  var endpoint = 'https://api.github.com/repos/kagla/gnucms/releases/latest';
  var cacheKey = 'gnucms-github-latest-release-v2-' + version.textContent.trim();
  var refreshInterval = 5 * 60 * 1000;
  var minCheckInterval = 60 * 1000;
  var retryDelay = 5 * 60 * 1000;
  var nextCheckAt = 0;
  var lastCheckAt = 0;
  var pending = false;
  var timer = null;

  function show(tag) {
    if (typeof tag !== 'string' || !/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/.test(tag)) { return false; }
    version.textContent = tag;
    if (downloadVersion) { downloadVersion.textContent = tag; }
    badge.classList.add('is-loaded');
    badge.setAttribute('aria-label', 'GitHub 최신 릴리스 ' + tag + ' 보기');
    return true;
  }

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(refresh, Math.max(1000, nextCheckAt - Date.now()));
  }

  function refresh(force) {
    if (pending) { return; }
    if (Date.now() < nextCheckAt && (!force || Date.now() - lastCheckAt < minCheckInterval)) {
      schedule(); return;
    }
    pending = true;
    lastCheckAt = Date.now();
    nextCheckAt = Date.now() + retryDelay;
    var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
    var timeout = controller ? window.setTimeout(function () { controller.abort(); }, 10000) : null;
    var options = {headers: {'Accept': 'application/vnd.github+json'}, cache: 'no-cache'};
    if (controller) { options.signal = controller.signal; }
    window.fetch(endpoint, options).then(function (response) {
      if (!response.ok) { throw new Error('GitHub release request failed'); }
      return response.json();
    }).then(function (release) {
      if (!show(release.tag_name)) { throw new Error('Invalid GitHub release tag'); }
      var checkedAt = Date.now();
      nextCheckAt = checkedAt + refreshInterval;
      try {
        window.localStorage.setItem(cacheKey, JSON.stringify({tag: release.tag_name, checkedAt: checkedAt}));
      } catch (e) {}
    }).catch(function () {
      // 조회 실패 시 기존 표시를 보존하고 짧은 간격으로 다시 확인한다.
      nextCheckAt = Date.now() + retryDelay;
    }).then(function () {
      if (timeout !== null) { window.clearTimeout(timeout); }
      pending = false;
      schedule();
    });
  }

  try {
    var cached = JSON.parse(window.localStorage.getItem(cacheKey) || 'null');
    if (cached && typeof cached.checkedAt === 'number'
        && Number.isFinite(cached.checkedAt) && cached.checkedAt > 0 && cached.checkedAt <= Date.now()) {
      show(cached.tag);
    }
  } catch (e) {}

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') { refresh(true); }
  });
  refresh();
})();
