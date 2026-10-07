<?php $this->layout('layout') ?>
<?php $this->start('title') ?>GNUCMS · 커뮤니티와 쇼핑몰을 위한 PHP CMS<?php $this->stop() ?>
<?php $this->start('seo_description') ?>GNUCMS는 PHP 8.2 이상과 MySQL/MariaDB에서 동작하는 오픈소스 CMS입니다. 게시판, 회원, 쇼핑몰, 신용카드 결제, 배송과 정산, 알림톡·문자 운영을 제공합니다.<?php $this->stop() ?>
<?php $this->start('meta_description') ?><meta name="description" content="GNUCMS는 PHP 8.2 이상과 MySQL/MariaDB에서 동작하는 오픈소스 CMS입니다. 게시판, 회원, 쇼핑몰, 신용카드 결제, 배송과 정산, 알림톡·문자 운영을 제공합니다."><?php $this->stop() ?>
<?php $this->start('extra_head') ?>
<script type="application/ld+json"><?php echo json_encode([
  '@context' => 'https://schema.org',
  '@type' => 'SoftwareApplication',
  'name' => 'GNUCMS',
  'applicationCategory' => 'ContentManagementSystem',
  'operatingSystem' => 'Web server with PHP 8.2 or later',
  'description' => '게시판, 회원, 쇼핑몰, 신용카드 결제, 배송과 정산, 알림 기능을 제공하는 오픈소스 PHP CMS',
  'url' => 'https://gnucms.com/',
  'downloadUrl' => 'https://github.com/kagla/gnucms/releases/latest',
  'softwareRequirements' => 'PHP 8.2+, PDO MySQL, MySQL or MariaDB',
  'license' => 'https://opensource.org/license/mit',
  'codeRepository' => 'https://github.com/kagla/gnucms',
  'inLanguage' => 'ko-KR',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php $this->stop() ?>
<?php $this->start('nav_section') ?>home<?php $this->stop() ?>
<?php $this->start('body_class') ?>modern-home<?php $this->stop() ?>
<?php
$sourceVersion = 'v' . trim((string) file_get_contents(dirname(__DIR__, 3) . '/version.txt'));
?>
<?php $this->start('body') ?>
      <div class="cb-page cb-body">
        <section class="cb-wrap cb-hero" id="about">
          <div>
            <div class="cb-kicker"><?= $this->icon('code-xml', 18) ?>GNUCMS · OPEN SOURCE CMS</div>
            <h1 class="cb-headline">만드는 일은 가볍게.<br><span>운영은 더 편하게.</span></h1>
            <p class="cb-intro">게시판, 회원, 쇼핑몰과 결제까지.<br>PHP 호스팅에 설치해 직접 운영하는 오픈소스 CMS.</p>
            <div class="cb-actions">
              <a class="cb-action cb-primary cursor-interaction" href="https://github.com/kagla/gnucms/releases/latest" target="_blank" rel="noopener"><?= $this->icon('download', 18) ?><span>GNUCMS <span data-github-download-version><?= $this->e($sourceVersion) ?></span> 다운로드</span></a>
              <a class="cb-action cursor-interaction" href="<?= $this->e($this->base . '/manual') ?>"><?= $this->icon('book-open', 18) ?>매뉴얼 보기</a>
            </div>
            <div class="cb-specs"><span><?= $this->icon('code-xml', 18) ?>PHP 8.2+</span><span><?= $this->icon('database', 18) ?>MySQL · MariaDB</span><span><?= $this->icon('scale', 18) ?>MIT License</span></div>
          </div>
          <aside id="install" class="cb-install" aria-label="배포본과 설치 안내">
            <div class="cb-install-top"><span class="cb-package"><?= $this->icon('package', 18) ?></span><div><strong>GNUCMS 다운로드</strong><small>최신 정식 배포본</small></div><a class="cb-version" href="https://github.com/kagla/gnucms/releases/latest" target="_blank" rel="noopener" data-github-release aria-label="GitHub 최신 릴리스 보기"><span data-github-release-version><?= $this->e($sourceVersion) ?></span></a></div>
            <div class="cb-install-content">
              <h2>내 호스팅에서 시작하기</h2><p class="cb-install-copy">배포본을 올리고 브라우저에서 설치합니다.</p>
              <ol class="cb-steps">
                <li class="cb-step"><span class="cb-step-icon"><?= $this->icon('file-down', 18) ?></span><div><strong>1. 배포 ZIP 받기</strong><small>실행에 필요한 파일이 포함되어 있습니다.</small></div></li>
                <li class="cb-step"><span class="cb-step-icon"><?= $this->icon('folder-up', 18) ?></span><div><strong>2. 호스팅에 업로드</strong><small>PHP와 MySQL을 지원하는 환경에 올립니다.</small></div></li>
                <li class="cb-step"><span class="cb-step-icon"><?= $this->icon('settings-2', 18) ?></span><div><strong>3. 설치 화면에서 설정</strong><small>DB 연결과 관리자 계정을 입력합니다.</small></div></li>
              </ol>
            </div>
            <a class="cb-install-link cursor-interaction" href="<?= $this->e($this->base . '/manual/install') ?>">설치 전 확인할 사항<?= $this->icon('arrow-right', 18) ?></a>
          </aside>
        </section>
        <?php $this->insert('community/_content', ['boards' => $boards, 'on_home' => true]) ?>
        <section class="cb-wrap cb-section" id="features">
          <div class="cb-section-head"><h2>사이트 운영에 필요한 기본 기능</h2><p>게시판부터 주문 관리까지, 하나의 관리자 화면에서.</p></div>
          <div class="cb-feature-grid">
            <article class="cb-feature"><div class="cb-feature-heading"><span class="cb-feature-icon"><?= $this->icon('panels-top-left', 18) ?></span><h3>게시판과 콘텐츠</h3></div><p>목록·갤러리·뉴스·매거진 형태를 고르고,<br>글과 댓글, 첨부파일, 공개 권한을 관리합니다.</p><div class="cb-feature-note"><?= $this->icon('images', 18) ?>콘텐츠에 맞는 네 가지 목록</div></article>
            <article class="cb-feature"><div class="cb-feature-heading"><span class="cb-feature-icon"><?= $this->icon('shopping-bag', 18) ?></span><h3>쇼핑몰과 결제</h3></div><p>상품을 올리고 주문과 재고를 관리합니다.<br>신용카드 결제와 배송, 환불까지 연결합니다.</p><div class="cb-feature-note"><?= $this->icon('credit-card', 18) ?>상품 등록부터 주문 처리까지</div></article>
            <article class="cb-feature"><div class="cb-feature-heading"><span class="cb-feature-icon"><?= $this->icon('users-round', 18) ?></span><h3>회원과 알림</h3></div><p>회원가입과 소셜 로그인을 제공합니다.<br>사이트 알림, 이메일, 알림톡·문자를 설정합니다.</p><div class="cb-feature-note"><?= $this->icon('bell', 18) ?>가입과 운영 소식을 필요한 채널로</div></article>
          </div>
        </section>
        <section class="cb-wrap cb-docs" aria-label="공개 매뉴얼 안내">
          <div class="cb-docs-head"><span class="cb-doc-icon"><?= $this->icon('book-open-text', 18) ?></span><div><h2>설치할 때도, 운영할 때도 매뉴얼과 함께.</h2><p>지금 필요한 작업부터 찾아보세요.</p></div></div>
          <div class="cb-docs-grid">
            <a class="cb-doc-link cursor-interaction" href="<?= $this->e($this->base . '/manual/install') ?>"><?= $this->icon('monitor', 18) ?><div><strong>처음 설치하기</strong><small>서버 환경 · 파일 업로드 · 설치</small></div><?= $this->icon('arrow-up-right', 18, 'cb-arrow') ?></a>
            <a class="cb-doc-link cursor-interaction" href="<?= $this->e($this->base . '/manual/site/settings') ?>"><?= $this->icon('sliders-horizontal', 18) ?><div><strong>사이트 운영하기</strong><small>사이트 기본 설정 · 홈 화면 설정</small></div><?= $this->icon('arrow-up-right', 18, 'cb-arrow') ?></a>
            <a class="cb-doc-link cursor-interaction" href="<?= $this->e($this->base . '/manual/development/templates') ?>"><?= $this->icon('blocks', 18) ?><div><strong>테마와 확장 만들기</strong><small>템플릿 · 모듈 · 플러그인</small></div><?= $this->icon('arrow-up-right', 18, 'cb-arrow') ?></a>
          </div>
        </section>
      </div>




<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('release-version.js') ?>" defer></script><?php $this->stop() ?>
