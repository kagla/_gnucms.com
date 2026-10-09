<footer class="cb-wrap cb-footer">
  <span>GNUCMS · 직접 설치하고 운영하는 오픈소스 CMS</span>
  <nav class="cb-footer-links" aria-label="하단 메뉴">
    <a href="https://github.com/kagla/gnucms" target="_blank" rel="noopener"><?= $this->icon('code-xml', 14) ?>GitHub</a>
    <a href="<?= $this->url('posts.index', ['key' => 'gallery']) ?>"><?= $this->icon('images', 14) ?>사이트 갤러리</a>
    <a href="<?= $this->e($this->base . '/community') ?>">커뮤니티</a>
    <a href="<?= $this->e($this->base . '/manual') ?>">매뉴얼</a>
    <?php foreach ($legal_pages as $doc): ?><a href="<?= $this->url('terms.show', ['slug' => $doc['slug']]) ?>"><?= $this->e($doc['title']) ?></a><?php endforeach ?>
    <?php $this->insert('_privacy_links') ?>
    <a href="https://github.com/kagla/gnucms/blob/main/LICENSE" target="_blank" rel="noopener">MIT License</a>
  </nav>
</footer>
