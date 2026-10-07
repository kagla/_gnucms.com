<?php
$this->layout('layout');
$manualHome = $this->base . $manual_prefix;
$manualTitle = $manual_article['title'] ?? 'GNUCMS 매뉴얼';
$manualSummary = $manual_article['summary'] ?? '설치부터 사이트 운영, 쇼핑몰, 알림 발송과 개발까지 단계별로 안내합니다.';
$manualLink = fn (string $slug): string => $manualHome . ($slug === '' ? '' : '/' . $slug);
?>
<?php $this->start('title') ?><?= $this->e($manualTitle) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>modules/manual<?php $this->stop() ?>
<?php $this->start('body_class') ?>manual-page<?php $this->stop() ?>
<?php $this->start('meta_description') ?><meta name="description" content="<?= $this->e($manualSummary) ?>"><?php $this->stop() ?>
<?php $this->start('seo_description') ?><?= $this->e($manualSummary) ?><?php $this->stop() ?>
<?php $this->start('seo_meta') ?><link rel="stylesheet" href="<?= $this->asset('manual.css') ?>"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="manual" data-manual-root data-search-url="<?= $this->e($manualLink('search')) ?>" data-manual-url="<?= $this->e($manualHome) ?>">
  <header class="manual-heading">
    <div><a class="manual-eyebrow" href="<?= $this->e($manualHome) ?>"><?= $this->icon('book-open', 18) ?>GNUCMS / MANUAL</a><h1><?= $this->e($manualTitle) ?></h1><p><?= $this->e($manualSummary) ?></p></div>
    <form class="manual-search" role="search"><label for="manual-search">매뉴얼 검색</label><div><input id="manual-search" type="search" placeholder="예: 반품, SMTP, 테마" autocomplete="off" maxlength="100" aria-controls="manual-search-results"><button type="submit">검색</button></div><noscript><p>브라우저 찾기 또는 아래 목차를 이용해 주세요.</p></noscript></form>
  </header>
  <section id="manual-search-results" class="manual-search-results" aria-live="polite" hidden></section>
  <div class="manual-grid<?= $manual_article ? ' has-article' : '' ?>">
    <aside class="manual-sidebar">
      <details class="manual-navigation" open><summary>전체 목차</summary><nav aria-label="매뉴얼 목차"><a class="manual-home-link" href="<?= $this->e($manualHome) ?>"<?= !$manual_article ? ' aria-current="page"' : '' ?>>매뉴얼 처음</a>
      <?php foreach ($manual_catalog['groups'] as $group): ?>
        <details class="manual-nav-group"<?= !$manual_article || $manual_article['group'] === $group['id'] ? ' open' : '' ?>><summary><?= $this->e($group['title']) ?></summary><ul>
        <?php foreach ($manual_catalog['articles'] as $item): if ($item['group'] !== $group['id']) continue; ?>
          <li><a href="<?= $this->e($manualLink($item['slug'])) ?>"<?= ($manual_article['slug'] ?? null) === $item['slug'] ? ' aria-current="page"' : '' ?>><?= $this->e($item['title']) ?></a></li>
        <?php endforeach ?></ul></details>
      <?php endforeach ?></nav></details>
    </aside>
    <div class="manual-content" id="manual-content">
    <?php if (!$manual_article): ?>
      <div class="manual-start"><p class="manual-eyebrow">처음 운영한다면</p><h2>필요한 순서대로 시작하세요</h2><ol><?php foreach ($manual_catalog['start'] as $slug): foreach ($manual_catalog['articles'] as $item): if ($item['slug'] !== $slug) continue; ?><li><a href="<?= $this->e($manualLink($slug)) ?>"><?= $this->e($item['title']) ?></a><p><?= $this->e($item['summary']) ?></p></li><?php endforeach; endforeach ?></ol></div>
      <?php foreach ($manual_catalog['groups'] as $group): ?>
      <section class="manual-topic"><p class="manual-eyebrow"><?= $this->e($group['audience']) ?></p><h2><?= $this->e($group['title']) ?></h2><p><?= $this->e($group['description']) ?></p><div class="manual-cards">
        <?php foreach ($manual_catalog['articles'] as $item): if ($item['group'] !== $group['id']) continue; ?><a class="manual-card" href="<?= $this->e($manualLink($item['slug'])) ?>"><strong><?= $this->e($item['title']) ?></strong><span><?= $this->e($item['summary']) ?></span></a><?php endforeach ?>
      </div></section><?php endforeach ?>
      <p class="manual-note">설명은 현재 GNUCMS 소스의 동작을 기준으로 작성했습니다. 설치한 버전과 선택한 테마에 따라 화면의 배치가 다를 수 있습니다. 문서에 나오는 회원·주문·연락처는 예시입니다.</p>
    <?php else: ?>
      <article>
        <div class="manual-meta"><span><?= $this->e($manual_article['audience']) ?></span><?php if (!empty($manual_article['screen'])): ?><code><?= $this->e($manual_article['screen']) ?></code><?php endif ?><button type="button" data-manual-print>인쇄 / PDF 저장</button></div>
        <nav class="manual-on-this-page" aria-label="이 문서의 목차"><strong>이 문서에서</strong><ul><?php foreach ($manual_article['sections'] as $i => $section): ?><li><a href="#section-<?= $i + 1 ?>"><?= $this->e($section['title']) ?></a></li><?php endforeach ?></ul></nav>
        <?php foreach ($manual_article['sections'] as $i => $section): ?>
        <section class="manual-section" id="section-<?= $i + 1 ?>"><h2><a href="#section-<?= $i + 1 ?>"><?= $this->e($section['title']) ?></a></h2>
        <?php foreach ($section['blocks'] as $block): ?>
          <?php if ($block['type'] === 'p'): ?><p><?= $this->e($block['text']) ?></p>
          <?php elseif ($block['type'] === 'note'): ?><aside class="manual-note"><strong><?= $this->e($block['title']) ?></strong><p><?= $this->e($block['text']) ?></p></aside>
          <?php elseif ($block['type'] === 'list' || $block['type'] === 'steps'): $tag = $block['type'] === 'steps' ? 'ol' : 'ul'; ?><<?= $tag ?> class="manual-<?= $block['type'] ?>"><?php foreach ($block['items'] as $line): ?><li><?= $this->e($line) ?></li><?php endforeach ?></<?= $tag ?>>
          <?php elseif ($block['type'] === 'table'): ?><table class="manual-table"><thead><tr><?php foreach ($block['headers'] as $header): ?><th scope="col"><?= $this->e($header) ?></th><?php endforeach ?></tr></thead><tbody><?php foreach ($block['rows'] as $row): ?><tr><?php foreach ($row as $n => $cell): ?><td data-label="<?= $this->e($block['headers'][$n]) ?>"><?= $this->e($cell) ?></td><?php endforeach ?></tr><?php endforeach ?></tbody></table>
          <?php elseif ($block['type'] === 'code'): ?><div class="manual-code"><div><span><?= $this->e($block['language'] ?? '예시') ?></span><button type="button" data-manual-copy>복사</button></div><pre><code><?= $this->e($block['text']) ?></code></pre></div>
          <?php elseif ($block['type'] === 'gallery'): ?><div class="manual-gallery"><?php foreach ($block['images'] as $picture): ?><figure><a href="<?= $this->asset($picture['file']) ?>" data-manual-image data-image-title="<?= $this->e($picture['title']) ?>" aria-label="<?= $this->e($picture['title'] . ' 크게 보기') ?>"><img src="<?= $this->asset($picture['file']) ?>" alt="<?= $this->e($picture['alt']) ?>" loading="lazy" decoding="async"><span>크게 보기</span></a><figcaption><strong><?= $this->e($picture['title']) ?></strong><p><?= $this->e($picture['caption']) ?></p></figcaption></figure><?php endforeach ?></div>
          <?php endif ?>
        <?php endforeach ?></section><?php endforeach ?>
        <?php if (!empty($manual_article['related'])): ?><section class="manual-related"><h2>함께 읽기</h2><ul><?php foreach ($manual_article['related'] as $slug): foreach ($manual_catalog['articles'] as $item): if ($item['slug'] !== $slug) continue; ?><li><a href="<?= $this->e($manualLink($slug)) ?>"><?= $this->e($item['title']) ?></a></li><?php endforeach; endforeach ?></ul></section><?php endif ?>
        <?php if (!empty($manual_article['sources'])): ?><details class="manual-sources"><summary>개발자 참고 소스</summary><ul><?php foreach ($manual_article['sources'] as $source): ?><li><a href="<?= $this->e('https://github.com/kagla/gnucms/blob/main/' . $source) ?>" target="_blank" rel="noopener"><?= $this->e($source) ?></a></li><?php endforeach ?></ul></details><?php endif ?>
        <p class="manual-updated">문서 파일 갱신 <?= $this->date(gmdate('Y-m-d H:i:s', $manual_article['updated_at'])) ?></p>
        <nav class="manual-prev-next" aria-label="이전과 다음 문서"><?php foreach (['previous' => '이전 문서', 'next' => '다음 문서'] as $key => $label): $item = $manual_article[$key]; ?><div><?php if ($item): ?><span><?= $this->e($label) ?></span><a href="<?= $this->e($manualLink($item['slug'])) ?>"><?= $this->e($item['title']) ?></a><?php endif ?></div><?php endforeach ?></nav>
      </article>
    <?php endif ?>
    </div>
  </div>
  <dialog class="manual-image-dialog" data-manual-image-dialog aria-labelledby="manual-image-title"><header><h2 id="manual-image-title"></h2><button type="button" data-manual-image-close aria-label="큰 이미지 닫기">닫기</button></header><div class="manual-image-body"><img alt=""></div><footer><a data-manual-image-original target="_blank" rel="noopener">원본 이미지 열기</a></footer></dialog>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('manual.js') ?>" defer></script><?php $this->stop() ?>
