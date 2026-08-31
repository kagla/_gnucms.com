<?php $this->layout('layout') ?>
<?php $this->start('title') ?>GNucms · 작게 시작해 오래 운영하는 PHP CMS<?php $this->stop() ?>
<?php $this->start('meta_description') ?><meta name="description" content="게시판, 회원, 콘텐츠, 약관과 관리 도구를 한 번에 시작하는 오픈소스 PHP CMS. SQLite, MySQL, PostgreSQL을 지원합니다."><?php $this->stop() ?>
<?php $this->start('nav_section') ?>home<?php $this->stop() ?>
<?php $this->start('body_class') ?>product-home<?php $this->stop() ?>

<?php $this->start('chrome') ?>
<header class="product-header">
  <div class="product-shell product-header-inner">
    <a class="product-brand" href="<?= $this->url('boards.index') ?>" aria-label="GNucms 홈">
      <span class="product-brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      <span>GNU<span>cms</span></span>
    </a>
    <nav class="product-nav" aria-label="제품 안내">
      <a href="#features">기능</a>
      <a href="#install">설치</a>
      <a href="#community">소식</a>
      <?php foreach ($site_menu as $item): ?><a href="<?= $this->url('content.show', ['slug' => $item['slug']]) ?>"><?= $this->e($item['title']) ?></a><?php endforeach ?>
      <a href="https://github.com/kagla/gnucms" target="_blank" rel="noopener">GitHub <?= $this->icon('external', 14) ?></a>
    </nav>
    <?php if ($current_user['is_guest']): ?>
      <a class="product-header-cta" href="https://gnucms.gnuboard.net" target="_blank" rel="noopener">라이브 데모</a>
    <?php else: ?>
      <div class="dropdown dropdown-end product-user">
        <button class="product-header-cta" type="button" tabindex="0"><?= $this->e($current_user['display_name']) ?> <?= $this->icon('chevron-down', 14) ?></button>
        <ul class="dropdown-content menu rounded-box shadow-lg user-menu" tabindex="0">
          <li class="menu-title"><?= $this->e($current_user['display_name']) ?></li>
          <li><a href="<?= $this->url('account.edit') ?>"><?= $this->icon('user', 16) ?> 회원정보 수정</a></li>
          <?php if ($current_user['is_admin']): ?><li><a href="<?= $this->url('admin.index') ?>"><?= $this->icon('cog', 16) ?> 관리 콘솔</a></li><?php endif ?>
          <li><form method="post" action="<?= $this->url('auth.logout') ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><button type="submit"><?= $this->icon('logout', 16) ?> 로그아웃</button></form></li>
        </ul>
      </div>
    <?php endif ?>
  </div>
</header>

<main id="main">
  <section class="product-hero">
    <div class="product-shell product-hero-grid">
      <div class="product-hero-copy">
        <p class="product-kicker"><span></span> OPEN SOURCE · PHP 8.1+</p>
        <h1>운영에 필요한 것은<br><em>처음부터.</em></h1>
        <p class="product-lead">게시판 하나를 만들기 위해 회원, 권한, 댓글, 메일, 약관을 다시 조립하지 마세요. GNucms는 작은 사이트가 실제 운영까지 가는 데 필요한 뼈대를 담았습니다.</p>
        <div class="product-hero-actions">
          <a class="product-btn product-btn-primary" href="https://github.com/kagla/gnucms/archive/refs/heads/main.zip">지금 내려받기 <?= $this->icon('arrow-right', 17) ?></a>
          <a class="product-btn product-btn-quiet" href="https://github.com/kagla/gnucms" target="_blank" rel="noopener"><?= $this->icon('brand', 17) ?> 소스 보기</a>
        </div>
        <ul class="product-proof" aria-label="제품 사양">
          <li><strong>3</strong><span>지원 데이터베이스</span></li>
          <li><strong>MIT</strong><span>오픈소스 라이선스</span></li>
          <li><strong>0</strong><span>프런트 빌드 단계</span></li>
        </ul>
      </div>

      <div class="product-console" aria-label="GNucms 관리 화면 미리보기">
        <div class="product-console-bar">
          <span class="product-console-dots"><i></i><i></i><i></i></span>
          <span>GNucms / admin</span>
          <span class="product-console-status"><i></i> online</span>
        </div>
        <div class="product-console-body">
          <aside class="product-console-side">
            <strong><span class="product-mini-mark"></span> Console</strong>
            <ul>
              <li class="is-active"><?= $this->icon('dashboard', 15) ?> Dashboard</li>
              <li><?= $this->icon('users', 15) ?> Members</li>
              <li><?= $this->icon('board', 15) ?> Boards</li>
              <li><?= $this->icon('document', 15) ?> Content</li>
            </ul>
          </aside>
          <div class="product-console-main">
            <div class="product-console-head"><div><small>OVERVIEW</small><strong>오늘의 사이트</strong></div><span>Aug 31</span></div>
            <div class="product-metrics">
              <div><span>새 글</span><strong>24</strong><small>+18%</small></div>
              <div><span>회원</span><strong>1,284</strong><small>+32</small></div>
              <div><span>댓글</span><strong>86</strong><small>today</small></div>
            </div>
            <div class="product-board-card">
              <div class="product-board-title"><strong>게시판</strong><span>전체 관리 →</span></div>
              <div class="product-board-row"><i class="tone-a"></i><span><b>공지사항</b><small>/boards/notice</small></span><em>12</em></div>
              <div class="product-board-row"><i class="tone-b"></i><span><b>자유게시판</b><small>/boards/free</small></span><em>248</em></div>
              <div class="product-board-row"><i class="tone-c"></i><span><b>갤러리</b><small>/boards/gallery</small></span><em>64</em></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="product-strip" aria-label="지원 환경">
    <div class="product-shell"><span>RUNS WITH</span><strong>PHP 8.1+</strong><i></i><strong>SQLite</strong><i></i><strong>MySQL</strong><i></i><strong>PostgreSQL</strong><i></i><strong>Apache / Nginx</strong></div>
  </section>

  <section class="product-section product-intro" id="features">
    <div class="product-shell">
      <div class="product-section-head">
        <p class="product-kicker"><span></span> BUILT FOR REAL SITES</p>
        <h2>설치보다 운영이<br>더 쉬워야 하니까.</h2>
        <p>기능 목록을 길게 늘이는 대신, 사이트를 열고 유지할 때 반복해서 마주치는 문제부터 해결했습니다.</p>
      </div>
      <div class="product-feature-grid">
        <article class="product-feature product-feature-wide"><span class="product-feature-no">01</span><div class="product-feature-icon"><?= $this->icon('board', 23) ?></div><h3>게시판을 필요한 만큼</h3><p>일반 목록, 갤러리, 뉴스, 매거진 형태를 게시판마다 고르고 분류·권한·첨부 규칙을 따로 설정합니다.</p><div class="product-list-preview"><span>공지사항</span><span>질문과 답변</span><span>프로젝트 갤러리</span></div></article>
        <article class="product-feature"><span class="product-feature-no">02</span><div class="product-feature-icon"><?= $this->icon('shield', 23) ?></div><h3>운영 기준이 흔들리지 않게</h3><p>회원·비회원·관리자 권한과 비밀글, 차단, 신고 흐름을 한곳에서 관리합니다.</p></article>
        <article class="product-feature"><span class="product-feature-no">03</span><div class="product-feature-icon"><?= $this->icon('cog', 23) ?></div><h3>관리자가 바로 이해하는 화면</h3><p>사이트 설정부터 게시판, 회원, 메일, 약관까지 일관된 관리 콘솔에서 다룹니다.</p></article>
        <article class="product-feature"><span class="product-feature-no">04</span><div class="product-feature-icon"><?= $this->icon('comment', 23) ?></div><h3>대화가 이어지는 댓글</h3><p>깊이 제한 없는 답글, 비밀댓글과 알림으로 커뮤니티의 흐름을 놓치지 않습니다.</p></article>
        <article class="product-feature"><span class="product-feature-no">05</span><div class="product-feature-icon"><?= $this->icon('mail', 23) ?></div><h3>메일과 소셜 로그인</h3><p>SMTP 설정과 OAuth 로그인을 관리자 화면에서 연결하고 확인할 수 있습니다.</p></article>
        <article class="product-feature product-feature-dark"><span class="product-feature-no">06</span><div class="product-feature-icon"><?= $this->icon('sparkle', 23) ?></div><h3>코드를 소유하세요</h3><p>잠금 없는 MIT 라이선스. 원하는 호스팅에서 운영하고 필요한 만큼 직접 바꿀 수 있습니다.</p><a href="https://github.com/kagla/gnucms" target="_blank" rel="noopener">저장소 살펴보기 <?= $this->icon('arrow-right', 15) ?></a></article>
      </div>
    </div>
  </section>

  <section class="product-section product-install" id="install">
    <div class="product-shell product-install-grid">
      <div class="product-install-copy">
        <p class="product-kicker"><span></span> FIVE-MINUTE START</p>
        <h2>파일을 올리고,<br>브라우저를 여세요.</h2>
        <p>복잡한 배포 파이프라인 없이 일반 PHP 호스팅에서도 시작할 수 있습니다. 설치 마법사가 서버와 데이터베이스를 확인하고 첫 관리자를 만듭니다.</p>
        <ol class="product-steps">
          <li><b>1</b><span><strong>소스 업로드</strong><small>문서 루트를 public/으로 지정합니다.</small></span></li>
          <li><b>2</b><span><strong>데이터베이스 선택</strong><small>SQLite, MySQL, PostgreSQL 중 고릅니다.</small></span></li>
          <li><b>3</b><span><strong>관리자 생성</strong><small>사이트 이름과 관리자 계정을 입력하면 끝입니다.</small></span></li>
        </ol>
      </div>
      <div class="product-code-card">
        <div class="product-code-tabs"><span class="is-active">Terminal</span><span>Requirements</span></div>
        <pre><code><span class="code-comment"># 소스를 내려받습니다</span>
<span class="code-prompt">$</span> git clone https://github.com/kagla/gnucms.git

<span class="code-comment"># PHP 의존성을 설치합니다</span>
<span class="code-prompt">$</span> composer install --no-dev

<span class="code-comment"># 브라우저에서 설치를 마칩니다</span>
<span class="code-ok">✓</span> Server requirements
<span class="code-ok">✓</span> Database connected
<span class="code-ok">✓</span> Administrator created

<span class="code-ready">Ready at https://your-site.example</span></code></pre>
        <div class="product-code-foot"><span><i></i> No Node.js required</span><button type="button" data-copy-install>명령 복사</button></div>
      </div>
    </div>
  </section>

  <?php if ($boards !== []): ?>
  <section class="product-section product-community" id="community">
    <div class="product-shell">
      <div class="product-section-head product-section-head-row"><div><p class="product-kicker"><span></span> FROM THE PROJECT</p><h2>프로젝트 소식</h2></div><p>업데이트와 활용 방법, GNucms를 만드는 과정을 전합니다.</p></div>
      <div class="product-feeds">
        <?php foreach ($boards as $board): ?>
          <section class="product-feed" aria-labelledby="feed-<?= $this->e($board['board_key']) ?>">
            <div class="product-feed-head"><div><h3 id="feed-<?= $this->e($board['board_key']) ?>"><?= $this->e($board['name']) ?></h3><p><?= $this->e($board['description'] ?: '새로운 소식을 확인하세요.') ?></p></div><a href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>">전체보기 <?= $this->icon('arrow-right', 14) ?></a></div>
            <?php if ($board['latest_posts'] === []): ?>
              <div class="product-feed-empty">아직 등록된 글이 없습니다.</div>
            <?php else: ?>
              <?php $__type = $this->def($board['list_type'] ?? null, 'list'); $this->insert(in_array($__type, ['list', 'gallery', 'news', 'magazine'], true) && $this->exists('home/_feed_' . $__type) ? 'home/_feed_' . $__type : 'home/_feed_list', ['board' => $board]) ?>
            <?php endif ?>
          </section>
        <?php endforeach ?>
      </div>
    </div>
  </section>
  <?php endif ?>

  <section class="product-final">
    <div class="product-shell product-final-inner"><div><p class="product-kicker"><span></span> MAKE IT YOURS</p><h2>당신의 사이트를<br>오늘 시작하세요.</h2></div><div><p>무료로 내려받고, 원하는 서버에 설치하고,<br>당신의 방식으로 바꾸세요.</p><div class="product-hero-actions"><a class="product-btn product-btn-primary" href="https://github.com/kagla/gnucms/archive/refs/heads/main.zip">GNucms 내려받기 <?= $this->icon('arrow-right', 17) ?></a><a class="product-btn product-btn-quiet" href="https://gnucms.gnuboard.net" target="_blank" rel="noopener">데모 둘러보기</a></div></div></div>
  </section>
</main>

<footer class="product-footer">
  <div class="product-shell product-footer-main"><a class="product-brand" href="<?= $this->url('boards.index') ?>"><span class="product-brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span>GNU<span>cms</span></span></a><p>작게 시작해 오래 운영하는<br>오픈소스 PHP CMS.</p><nav aria-label="하단 메뉴"><a href="#features">기능</a><a href="#install">설치</a><a href="https://github.com/kagla/gnucms">GitHub</a><?php foreach ($site_menu as $item): ?><a href="<?= $this->url('content.show', ['slug' => $item['slug']]) ?>"><?= $this->e($item['title']) ?></a><?php endforeach ?><?php foreach ($legal_pages as $doc): ?><a href="<?= $this->url('terms.show', ['slug' => $doc['slug']]) ?>"><?= $this->e($doc['title']) ?></a><?php endforeach ?></nav></div>
  <div class="product-shell product-footer-bottom"><span>© <?= date('Y') ?> GNucms. Released under the MIT License.</span><span>Built for the open web.</span></div>
</footer>
<?php $this->stop() ?>

<?php $this->start('scripts') ?>
<script>
(function(){
  var button=document.querySelector('[data-copy-install]');if(!button){return}
  button.addEventListener('click',function(){
    var command='git clone https://github.com/kagla/gnucms.git\ncd gnucms\ncomposer install --no-dev';
    var done=function(){button.textContent='복사됨';window.setTimeout(function(){button.textContent='명령 복사'},1600)};
    if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(command).then(done);return}
    var area=document.createElement('textarea');area.value=command;area.style.position='fixed';area.style.opacity='0';document.body.appendChild(area);area.select();document.execCommand('copy');area.remove();done();
  });
})();
</script>
<?php $this->stop() ?>
