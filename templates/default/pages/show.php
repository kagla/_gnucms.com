<?php $this->layout('layout') ?>
<?php $this->start('title') ?><?= $this->e($page['title']) ?> · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('meta_description') ?><meta name="description" content="<?= $this->e($page['seo_description'] ?: $site['site_tagline']) ?>"><?php $this->stop() ?>
<?php $pagePath = (!empty($page['is_consent']) ? '/terms/' : '/content/') . rawurlencode((string) $page['slug']); $canonical = $site_url . $pagePath; ?>
<?php $this->start('seo_meta') ?>
<link rel="canonical" href="<?= $this->e($canonical) ?>">
<meta property="og:type" content="article"><meta property="og:locale" content="ko_KR">
<meta property="og:site_name" content="<?= $this->e($site['site_name']) ?>">
<meta property="og:title" content="<?= $this->e($page['title']) ?>"><meta property="og:description" content="<?= $this->e($page['seo_description'] ?: $site['site_tagline']) ?>">
<meta property="og:url" content="<?= $this->e($canonical) ?>"><meta name="twitter:card" content="summary">
<?php $this->stop() ?>
<?php $this->start('feed_links') ?><link rel="alternate" type="application/rss+xml" title="<?= $this->e($site['site_name']) ?> 공개 내용 RSS" href="<?= $this->e($site_url) ?>/content/rss.xml"><?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="read-progress" aria-hidden="true"></div>
<article class="card article article-page">
  <?php // 관리자에게만 보이는 편집 길. 보기 화면이든 미리보기든 문구 없이 톱니 하나로 둔다.
        // 미리보기에서 아직 공개 전인 초안이면, 공개된 화면과 헷갈리지 않게 톱니만 경고색으로 둔다.
        // 자세한 설명은 도움말과 화면 낭독기에 남긴다. ?>
  <?php if (!$current_user['is_guest'] && $current_user['is_admin']):
    $page_draft = $preview && $page['status'] !== 'published';
    $page_edit_label = $preview
        ? ($page_draft ? '아직 공개되지 않은 초안 미리보기 · 편집으로 돌아가기' : '공개된 내용 미리보기 · 편집으로 돌아가기')
        : '이 내용 수정';
  ?>
    <a class="btn btn-outline btn-sm btn-square page-edit<?= $page_draft ? ' page-edit-draft' : '' ?>"
       href="<?= $this->url('admin.content.edit', ['id' => $page['id']]) ?>"
       title="<?= $this->e($page_edit_label) ?>" aria-label="<?= $this->e($page_edit_label) ?>"><?= $this->icon('cog', 15) ?></a>
  <?php endif ?>
  <div class="card-body article-head">
    <h1 class="card-title article-title"><?= $this->e($page['title']) ?></h1>
    <?php if ($page['seo_description']): ?><p class="article-summary"><?= $this->e($page['seo_description']) ?></p><?php endif ?>
  </div>
  <div class="divider divider-flush"></div>
  <div class="card-body article-body prose rich-content"><?= $this->html($page['content']) ?></div>
</article>
<?php $this->stop() ?>
