<?php $this->layout('layout') ?>
<?php $this->start('title') ?>GNUCMS · 가벼운 PHP CMS<?php $this->stop() ?>
<?php $this->start('seo_description') ?>GNUCMS는 PHP 8.2 이상과 SQLite, MySQL, PostgreSQL을 지원하는 가벼운 오픈소스 CMS입니다. 게시판, 회원, 댓글, 콘텐츠 관리와 소셜 로그인을 제공합니다.<?php $this->stop() ?>
<?php $this->start('meta_description') ?><meta name="description" content="GNUCMS는 PHP 8.2 이상과 SQLite, MySQL, PostgreSQL을 지원하는 가벼운 오픈소스 CMS입니다. 게시판, 회원, 댓글, 콘텐츠 관리와 소셜 로그인을 제공합니다."><?php $this->stop() ?>
<?php $this->start('extra_head') ?>
<script type="application/ld+json"><?php echo json_encode([
  '@context' => 'https://schema.org',
  '@type' => 'SoftwareApplication',
  'name' => 'GNUCMS',
  'applicationCategory' => 'ContentManagementSystem',
  'operatingSystem' => 'Web server with PHP 8.2 or later',
  'description' => '게시판, 회원, 댓글, 콘텐츠 관리 기능을 제공하는 가벼운 오픈소스 PHP CMS',
  'url' => 'https://gnucms.com/',
  'downloadUrl' => 'https://github.com/kagla/gnucms/archive/refs/heads/main.zip',
  'softwareRequirements' => 'PHP 8.2+, PDO SQLite/MySQL/PostgreSQL',
  'license' => 'https://opensource.org/license/mit',
  'codeRepository' => 'https://github.com/kagla/gnucms',
  'inLanguage' => 'ko-KR',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php $this->stop() ?>
<?php $this->start('nav_section') ?>home<?php $this->stop() ?>
<?php $this->start('body_class') ?>product-home<?php $this->stop() ?>
<?php
$galleryBoards = array_values(array_filter($boards, static fn (array $item): bool => ($item['list_type'] ?? 'list') === 'gallery'));
$communityBoards = array_values(array_filter($boards, static fn (array $item): bool => ($item['list_type'] ?? 'list') !== 'gallery'));
$searchBoard = $communityBoards[0] ?? $galleryBoards[0] ?? null;
$activity = [];
foreach ($boards as $activityBoard) {
    foreach ($activityBoard['latest_posts'] as $activityPost) {
        $activity[] = ['board' => $activityBoard, 'post' => $activityPost];
    }
}
usort($activity, static function (array $left, array $right): int {
    $leftTime = strtotime((string) $left['post']['created_at']) ?: 0;
    $rightTime = strtotime((string) $right['post']['created_at']) ?: 0;
    return $rightTime <=> $leftTime;
});
$activity = array_slice($activity, 0, 3);
$freshAfter = time() - 86400;
?>

<?php $this->start('header_search') ?>
<?php if ($searchBoard !== null): ?>
<form class="header-search" method="get" action="<?= $this->url('posts.index', ['key' => $searchBoard['board_key']]) ?>" role="search">
  <label class="input input-bordered">
    <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 18) ?></span>
    <input type="search" name="q" value="" placeholder="<?= $this->e($searchBoard['name']) ?>에서 검색해 보세요" aria-label="게시글 검색" data-search-input>
  </label>
  <button class="btn btn-primary header-search-btn" type="submit">검색</button>
</form>
<?php endif ?>
<?php $this->stop() ?>

<?php $this->start('body') ?>
  <section class="product-hero">
    <div class="product-shell product-hero-inner">
      <p class="product-label">OPEN SOURCE · PHP 8.2+</p>
      <h1>필요한 것만 담은 가벼운 PHP CMS</h1>
      <div class="product-hero-summary">
        <p class="product-lead">일반 웹호스팅에 바로 올려 쓰는 게시판 중심 오픈소스 CMS입니다.</p>
        <div class="product-actions">
          <a class="product-button product-button-primary" href="https://github.com/kagla/gnucms#readme" target="_blank" rel="noopener">README 보기</a>
          <a class="product-button product-button-secondary" href="https://github.com/kagla/gnucms" target="_blank" rel="noopener">GitHub 저장소</a>
          <a class="product-button product-button-tertiary" href="https://kagla10.mycafe24.com" target="_blank" rel="noopener">카페24 절약형 호스팅 데모</a>
        </div>
      </div>
    </div>
  </section>

  <section class="product-activity" aria-labelledby="activity-title">
    <div class="product-shell">
      <div class="product-activity-head">
        <h2 id="activity-title"><span class="product-live-dot" aria-hidden="true"></span>지금 GNUCMS에서</h2>
        <a href="<?= $this->url('posts.all') ?>">전체 글 보기</a>
      </div>
      <?php if ($activity === []): ?>
        <div class="product-activity-empty">
          <p>새로운 이야기를 기다리고 있습니다.</p>
          <?php if ($searchBoard !== null): ?><a href="<?= $this->url('posts.create', ['key' => $searchBoard['board_key']]) ?>">첫 글 남기기</a><?php endif ?>
        </div>
      <?php else: ?>
        <div class="product-activity-grid">
          <?php foreach ($activity as $item): ?>
            <?php $isFresh = (strtotime((string) $item['post']['created_at']) ?: 0) >= $freshAfter; ?>
            <article class="product-activity-item<?= $isFresh ? ' is-fresh' : '' ?>">
              <div class="product-activity-meta">
                <?php if ($isFresh): ?><span class="product-new-badge">NEW</span><?php endif ?>
                <span><?= $this->e($item['board']['name']) ?></span>
                <time datetime="<?= $this->e($item['post']['created_at']) ?>"><?= $this->compactDate($item['post']['created_at']) ?></time>
              </div>
              <a href="<?= $this->url('posts.show', ['id' => $item['post']['id']]) ?>"><?= $this->e($item['post']['title']) ?></a>
              <?php if ($item['post']['comment_count'] > 0): ?><span class="product-activity-comments"><?= $this->icon('comment', 12) ?> <?= $this->e($item['post']['comment_count']) ?></span><?php endif ?>
            </article>
          <?php endforeach ?>
        </div>
      <?php endif ?>
    </div>
  </section>

  <section class="product-section product-hub product-gallery-section" id="gallery">
    <div class="product-shell">
      <div class="product-hub-panel product-gallery-panel" aria-labelledby="gallery-title">
          <div class="product-hub-head">
            <h3 id="gallery-title">GNUCMS 사이트 갤러리</h3>
            <?php if ($galleryBoards !== []): ?><a href="<?= $this->url('posts.index', ['key' => $galleryBoards[0]['board_key']]) ?>">갤러리 전체보기</a><?php endif ?>
          </div>

          <?php if ($galleryBoards === []): ?>
            <div class="product-showcase-empty">
              <span aria-hidden="true"><?= $this->icon('grid', 24) ?></span>
              <div><strong>첫 번째 GNUCMS 사이트를 기다리고 있습니다.</strong><p>목록 형태가 ‘갤러리’인 게시판을 만들면 이곳에 사이트가 자동으로 표시됩니다.</p></div>
              <?php if (!$current_user['is_guest'] && $current_user['is_admin']): ?><a class="product-button" href="<?= $this->url('admin.boards.create') ?>">갤러리 만들기</a><?php endif ?>
            </div>
          <?php else: ?>
            <div class="product-gallery-boards">
              <?php foreach ($galleryBoards as $board): ?>
                <section id="feed-<?= $this->e($board['board_key']) ?>" aria-label="<?= $this->e($board['name']) ?>">
                <?php if ($board['latest_posts'] === []): ?>
                  <p class="product-feed-empty"><?= $this->e($board['name']) ?>에 아직 등록된 사이트가 없습니다.</p>
                <?php else: ?>
                  <?php $this->insert('home/_feed_gallery', ['board' => $board]) ?>
                <?php endif ?>
                </section>
              <?php endforeach ?>
            </div>
          <?php endif ?>
      </div>
    </div>
  </section>

  <section class="product-section product-hub product-community-section" id="community">
    <div class="product-shell">
      <div class="product-section-heading">
        <div><p class="product-label">COMMUNITY</p><h2>질문과 경험을 나누는 게시판</h2></div>
        <p>설치 질문부터 운영 노하우와 테마 제작 이야기까지, GNUCMS 사용자와 함께 나눠보세요.</p>
      </div>

      <div class="product-hub-panel product-board-panel" aria-labelledby="community-title">
          <div class="product-hub-head">
            <div>
              <span class="product-hub-index">COMMUNITY BOARD</span>
              <h3 id="community-title">커뮤니티 게시판</h3>
              <p>설치, 운영, 테마 제작에 관한 이야기를 나눠보세요.</p>
            </div>
            <?php if ($communityBoards !== []): ?><a href="<?= $this->url('posts.index', ['key' => $communityBoards[0]['board_key']]) ?>">게시판 전체보기</a><?php endif ?>
          </div>
          <?php if ($communityBoards === []): ?>
            <p class="product-feed-empty">아직 공개된 게시판이 없습니다.</p>
          <?php else: ?>
            <div class="product-board-feeds">
              <?php foreach ($communityBoards as $board): ?>
                <section id="feed-<?= $this->e($board['board_key']) ?>" aria-labelledby="hub-feed-<?= $this->e($board['board_key']) ?>">
                  <div class="product-board-feed-title"><h4 id="hub-feed-<?= $this->e($board['board_key']) ?>"><?= $this->e($board['name']) ?></h4><a href="<?= $this->url('posts.index', ['key' => $board['board_key']]) ?>">전체보기</a></div>
                  <?php if ($board['latest_posts'] === []): ?>
                    <p class="product-feed-empty">아직 등록된 글이 없습니다.</p>
                  <?php else: ?>
                    <?php $__type = $this->def($board['list_type'] ?? null, 'list'); ?>
                    <?php $this->insert(in_array($__type, ['list', 'gallery', 'news', 'magazine'], true) && $this->exists('home/_feed_' . $__type) ? 'home/_feed_' . $__type : 'home/_feed_list', ['board' => $board]) ?>
                  <?php endif ?>
                </section>
              <?php endforeach ?>
            </div>
          <?php endif ?>
      </div>
    </div>
  </section>

  <section class="product-section product-about" id="about">
    <div class="product-shell product-section-split">
      <div>
        <p class="product-label">ABOUT GNUCMS</p>
        <h2>작은 사이트부터 커뮤니티까지</h2>
      </div>
      <div class="product-rich-copy">
        <p>게시판, 회원, 댓글, 첨부파일과 콘텐츠 관리를 한 시스템에서 운영합니다. SQLite로 시작해 MySQL 또는 PostgreSQL로 확장할 수 있고, 별도의 프런트엔드 빌드 과정이 필요하지 않습니다.</p>
      </div>
    </div>
  </section>

  <section class="product-section" id="features">
    <div class="product-shell">
      <div class="product-section-heading">
        <div><p class="product-label">CORE FEATURES</p><h2>운영에 필요한 핵심 기능</h2></div>
        <p>설치 직후 시작하고 관리 콘솔에서 사이트에 맞게 조정합니다.</p>
      </div>
      <div class="product-feature-list product-feature-list-compact">
        <article><span>01</span><h3>유연한 게시판</h3><p>네 가지 목록 형태와 분류, 공지, 비밀글, 게시판별 권한을 설정합니다.</p></article>
        <article><span>02</span><h3>회원과 소셜 로그인</h3><p>이메일 인증과 Google·Kakao·Naver 로그인을 지원합니다.</p></article>
        <article><span>03</span><h3>댓글과 운영 도구</h3><p>계층형 댓글, 알림, 첨부파일과 관리 기능을 제공합니다.</p></article>
      </div>
    </div>
  </section>

  <section class="product-section product-foundation" id="foundation">
    <div class="product-shell">
      <div class="product-section-heading">
        <div><p class="product-label">BUILT FOR REAL HOSTING</p><h2>현실적인 호스팅 환경을 기준으로</h2></div>
        <p>저가형 공유 호스팅부터 독립 서버까지, PHP가 실행되는 환경이라면 같은 코드로 운영할 수 있습니다.</p>
      </div>
      <dl class="product-facts">
        <div><dt>Runtime</dt><dd>PHP 8.2 이상</dd></div>
        <div><dt>Database</dt><dd>SQLite · MySQL · PostgreSQL</dd></div>
        <div><dt>Rendering</dt><dd>서버 렌더링 PHP 템플릿</dd></div>
        <div><dt>License</dt><dd>MIT 오픈소스 라이선스</dd></div>
      </dl>
      <div class="product-principles">
        <article><h3>보안을 기본값으로</h3><p>CSRF 보호, 비밀번호 해시, 로그인 시도 제한, HTML 정제와 권한 검사를 기본 흐름에 포함합니다.</p></article>
        <article><h3>데이터베이스 선택 자유</h3><p>세 데이터베이스에서 동일한 기능을 제공하며, 작은 사이트는 별도 DB 서버 없이 SQLite로 시작할 수 있습니다.</p></article>
        <article><h3>직접 소유하는 데이터</h3><p>애플리케이션과 데이터가 자신의 서버에 남습니다. 외부 SaaS에 콘텐츠 운영을 종속시키지 않습니다.</p></article>
      </div>
    </div>
  </section>

  <section class="product-section product-install" id="install">
    <div class="product-shell product-install-inner">
      <div>
        <p class="product-label">QUICK START</p>
        <h2>설치</h2>
        <p>GNUCMS 파일을 서버에 올리고 브라우저로 접속하면 서버 점검, 데이터베이스 연결, 사이트 정보와 관리자 생성을 차례로 안내합니다.</p>
      </div>
      <ol class="product-install-steps">
        <li><span>1</span><div><strong>파일 업로드</strong><small>배포 파일 전체를 서버에 올립니다.</small></div></li>
        <li><span>2</span><div><strong>웹 루트 지정</strong><small>도메인의 문서 루트를 <code>www/</code>로 지정합니다.</small></div></li>
        <li><span>3</span><div><strong>브라우저 설치</strong><small>사이트에 접속해 데이터베이스와 첫 관리자를 설정합니다.</small></div></li>
      </ol>
    </div>
  </section>

  <section class="product-section product-faq" id="faq">
    <div class="product-shell product-section-split">
      <div><p class="product-label">FAQ</p><h2>자주 묻는 질문</h2></div>
      <div class="product-faq-list">
        <details>
          <summary>카페24 절약형 호스팅에서도 사용할 수 있나요?</summary>
          <p>네, 사용할 수 있습니다. GNUCMS는 서버에서 Composer나 npm을 실행하는 별도의 빌드 과정이 필요하지 않습니다. 배포 파일을 업로드한 뒤 브라우저에서 설치를 진행하면 됩니다. 실제 카페24 절약형 호스팅에서 운영 중인 사이트를 아래에서 확인할 수 있습니다.<br><a class="product-faq-example" href="https://kagla10.mycafe24.com/" target="_blank" rel="noopener">카페24 운영 예시 보기 <?= $this->icon('external', 14) ?></a></p>
        </details>
        <details><summary>게시판마다 디자인을 다르게 표시할 수 있나요?</summary><p>관리 화면에서 목록, 갤러리, 뉴스, 매거진 형태를 선택할 수 있으며, 전용 테마의 PHP 템플릿과 CSS를 수정해 화면을 확장할 수 있습니다.</p></details>
        <details><summary>업데이트할 때 데이터를 다시 설치해야 하나요?</summary><p>코드 파일을 새 버전으로 교체하면 필요한 데이터베이스 스키마 변경을 자동으로 적용합니다. 운영 환경에서는 업데이트 전에 DB 백업을 권장합니다.</p></details>
        <details><summary>상업용 사이트에도 사용할 수 있나요?</summary><p>GNUCMS는 MIT 라이선스로 배포됩니다. 라이선스 조건을 지키면 개인·기업·상업용 프로젝트에서 사용할 수 있습니다.</p></details>
      </div>
    </div>
  </section>

<?php $this->stop() ?>
