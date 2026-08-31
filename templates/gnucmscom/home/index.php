<?php $this->layout('layout') ?>
<?php $this->start('title') ?>GNUCMS · 가벼운 PHP CMS<?php $this->stop() ?>
<?php $this->start('meta_description') ?><meta name="description" content="PHP 7.4부터 사용할 수 있는 가벼운 오픈소스 CMS. 게시판, 회원, 댓글과 관리 기능을 제공합니다."><?php $this->stop() ?>
<?php $this->start('nav_section') ?>home<?php $this->stop() ?>
<?php $this->start('body_class') ?>product-home<?php $this->stop() ?>

<?php $this->start('chrome') ?>
<header class="product-header">
  <div class="product-shell product-header-inner">
    <a class="product-brand" href="<?= $this->url('boards.index') ?>" aria-label="GNUCMS 홈">GNUCMS</a>
    <nav class="product-nav" aria-label="주요 메뉴">
      <a href="#features">기능</a>
      <a href="#install">설치</a>
      <?php if ($boards !== []): ?><a href="#community">소식</a><?php endif ?>
      <?php foreach ($site_menu as $item): ?><a href="<?= $this->url('content.show', ['slug' => $item['slug']]) ?>"><?= $this->e($item['title']) ?></a><?php endforeach ?>
    </nav>
    <div class="product-header-tools">
      <a class="product-github" href="https://github.com/kagla/gnucms" target="_blank" rel="noopener">GitHub</a>
      <?php if (!$current_user['is_guest']): ?>
        <div class="dropdown dropdown-end product-user">
          <button class="product-account" type="button" tabindex="0"><?= $this->e($current_user['display_name']) ?></button>
          <ul class="dropdown-content menu rounded-box shadow-lg user-menu" tabindex="0">
            <li><a href="<?= $this->url('account.edit') ?>">회원정보 수정</a></li>
            <?php if ($current_user['is_admin']): ?><li><a href="<?= $this->url('admin.index') ?>">관리 콘솔</a></li><?php endif ?>
            <li><form method="post" action="<?= $this->url('auth.logout') ?>"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><button type="submit">로그아웃</button></form></li>
          </ul>
        </div>
      <?php endif ?>
      <button class="product-theme-toggle" type="button" data-theme-toggle aria-label="다크 모드로 전환">
        <span class="theme-ico theme-ico-light"><?= $this->icon('sun', 18) ?></span>
        <span class="theme-ico theme-ico-dark"><?= $this->icon('moon', 18) ?></span>
      </button>
    </div>
  </div>
</header>

<main id="main">
  <section class="product-hero">
    <div class="product-shell product-hero-inner">
      <p class="product-label">OPEN SOURCE · PHP 7.4+</p>
      <h1>필요한 것만 담은<br>가벼운 PHP CMS</h1>
      <p class="product-lead">게시판, 회원, 댓글, 콘텐츠와 관리 기능을 한 번에 시작하세요. 일반 PHP 호스팅에 파일을 올려 바로 운영할 수 있습니다.</p>
      <div class="product-actions">
        <a class="product-button product-button-primary" href="https://github.com/kagla/gnucms/archive/refs/heads/main.zip">내려받기</a>
      </div>
      <ul class="product-spec" aria-label="지원 환경">
        <li>PHP 7.4+</li>
        <li>SQLite · MySQL · PostgreSQL</li>
        <li>MIT License</li>
      </ul>
    </div>
  </section>

  <section class="product-section" id="features">
    <div class="product-shell">
      <h2>주요 기능</h2>
      <div class="product-feature-list">
        <article><h3>게시판</h3><p>일반 목록, 갤러리, 뉴스, 매거진 형태와 게시판별 권한을 설정합니다.</p></article>
        <article><h3>회원과 댓글</h3><p>회원·비회원 권한, 비밀글, 답글, 알림과 소셜 로그인을 지원합니다.</p></article>
        <article><h3>관리</h3><p>게시판, 회원, 콘텐츠, 메일과 약관을 하나의 관리 화면에서 다룹니다.</p></article>
      </div>
    </div>
  </section>

  <section class="product-section product-install" id="install">
    <div class="product-shell product-install-inner">
      <div>
        <h2>설치</h2>
        <p>GNUCMS 파일을 서버에 올리고 브라우저로 접속하면 설치 안내가 시작됩니다.</p>
      </div>
      <ol class="product-install-steps">
        <li><span>1</span>파일 전체를 서버에 올립니다.</li>
        <li><span>2</span>문서 루트를 <code>public/</code>으로 지정합니다.</li>
        <li><span>3</span>사이트를 열어 설치를 마칩니다.</li>
      </ol>
    </div>
  </section>

  <?php if ($boards !== []): ?>
  <section class="product-section product-community" id="community">
    <div class="product-shell">
      <h2>프로젝트 소식</h2>
      <div class="product-feeds">
        <?php foreach ($boards as $board): ?>
          <section class="product-feed" aria-labelledby="feed-<?= $this->e($board['board_key']) ?>">
            <div class="product-feed-head">
              <div><h3 id="feed-<?= $this->e($board['board_key']) ?>"><?= $this->e($board['name']) ?></h3><p><?= $this->e($board['description'] ?: '새로운 소식을 확인하세요.') ?></p></div>
              <a href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>">전체보기</a>
            </div>
            <?php if ($board['latest_posts'] === []): ?>
              <p class="product-feed-empty">아직 등록된 글이 없습니다.</p>
            <?php else: ?>
              <?php $__type = $this->def($board['list_type'] ?? null, 'list'); $this->insert(in_array($__type, ['list', 'gallery', 'news', 'magazine'], true) && $this->exists('home/_feed_' . $__type) ? 'home/_feed_' . $__type : 'home/_feed_list', ['board' => $board]) ?>
            <?php endif ?>
          </section>
        <?php endforeach ?>
      </div>
    </div>
  </section>
  <?php endif ?>
</main>

<footer class="product-footer">
  <div class="product-shell product-footer-inner">
    <span>© <?= date('Y') ?> GNUCMS · MIT License</span>
    <nav aria-label="하단 메뉴">
      <a href="https://github.com/kagla/gnucms" target="_blank" rel="noopener">GitHub</a>
      <?php foreach ($legal_pages as $doc): ?><a href="<?= $this->url('terms.show', ['slug' => $doc['slug']]) ?>"><?= $this->e($doc['title']) ?></a><?php endforeach ?>
    </nav>
  </div>
</footer>
<?php $this->stop() ?>
