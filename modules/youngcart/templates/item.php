<?php $this->layout('layout') ?>
<?php $this->start('title') ?><?= $this->e($product['name']) ?> · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>modules/youngcart<?php $this->stop() ?>
<?php $this->start('body_class') ?>yc-page<?php $this->stop() ?>
<?php $this->start('seo_meta') ?>
<link rel="stylesheet" href="<?= $this->asset('youngcart.css') ?>">
<?php if ($preview): ?><meta name="robots" content="noindex,nofollow"><?php endif ?>
<meta property="og:title" content="<?= $this->e($product['name']) ?>">
<?php if ($product['images'] !== []): ?><meta property="og:image" content="<?= $this->e($site_url . $img((int) $product['id'], $product['images'][0]['filename'], 'detail')) ?>"><?php endif ?>
<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="yc-shop yc-item">
  <?php $this->insert('_header') ?>
  <?php $this->insert('_breadcrumb') ?>
  <?php if ($preview): ?><div class="alert alert-warning">판매하지 않는 상품의 관리자 미리보기입니다. <a href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $product['id'] ?>">상품 수정</a></div><?php endif ?>
  <?php if ($product['head_html'] !== ''): ?><div class="yc-html"><?= $this->html($product['head_html']) ?></div><?php endif ?>
  <div class="yc-item-top">
    <div class="yc-gallery" data-yc-gallery>
      <?php if ($product['images'] === []): ?><div class="yc-noimage yc-noimage-large" aria-hidden="true">이미지 없음</div>
      <?php else: ?>
        <a class="yc-gallery-main" href="<?= $this->e($img((int) $product['id'], $product['images'][0]['filename'], 'original')) ?>" target="_blank" rel="noopener"><img src="<?= $this->e($img((int) $product['id'], $product['images'][0]['filename'], 'detail')) ?>" alt="<?= $this->e($product['name']) ?>" data-yc-main></a>
        <?php if (count($product['images']) > 1): ?><div class="yc-thumbs"><?php foreach ($product['images'] as $index => $image): ?>
          <button type="button" class="yc-thumb" data-yc-thumb="<?= $this->e($img((int) $product['id'], $image['filename'], 'detail')) ?>" data-yc-large="<?= $this->e($img((int) $product['id'], $image['filename'], 'original')) ?>" aria-label="<?= $index + 1 ?>번째 이미지"><img src="<?= $this->e($img((int) $product['id'], $image['filename'], 'thumb')) ?>" alt=""></button>
        <?php endforeach ?></div><?php endif ?>
      <?php endif ?>
    </div>
    <div class="yc-item-info">
      <h1 class="yc-item-name"><?= $this->e($product['name']) ?></h1>
      <?php if ($product['summary'] !== ''): ?><div class="yc-item-summary"><?= $this->html($product['summary']) ?></div><?php endif ?>
      <table class="table table-sm yc-item-table"><tbody>
        <?php foreach (['maker' => '제조사', 'origin' => '원산지', 'brand' => '브랜드', 'model' => '모델'] as $field => $label): if ($product[$field] !== ''): ?><tr><th scope="row"><?= $label ?></th><td><?= $this->e($product[$field]) ?></td></tr><?php endif; endforeach ?>
        <?php if ((int) $product['list_price'] > 0 && $display_price !== null): ?><tr><th scope="row">시중가격</th><td><del><?= number_format((int) $product['list_price']) ?>원</del></td></tr><?php endif ?>
        <tr><th scope="row">판매가격</th><td class="yc-item-price"><?php if (!(int) $product['active']): ?>판매중지<?php elseif ($display_price === null): ?>전화문의<?php else: ?><strong><?= number_format($display_price) ?>원</strong><?php if ($settings['show_tax']): ?> <small class="muted"><?= (int) $product['tax_free'] === 1 ? '비과세' : '부가세 포함' ?></small><?php endif ?><?php endif ?></td></tr>
        <?php if ($display_price !== null && (int) $product['point'] > 0): ?><tr><th scope="row">포인트</th><td><?= $this->e($point_label) ?></td></tr><?php endif ?>
        <tr><th scope="row">배송비</th><td><?= [0 => '상점 기본 배송비', 1 => '무료배송', 2 => number_format((int) $product['shipping_free_minimum']) . '원 이상 무료, 미만 ' . number_format((int) $product['shipping_fee']) . '원', 3 => number_format((int) $product['shipping_fee']) . '원', 4 => (int) $product['shipping_per_qty'] . '개마다 ' . number_format((int) $product['shipping_fee']) . '원'][(int) $product['shipping_type']] ?><?= (int) $product['shipping_method'] === 1 ? ' (착불)' : ((int) $product['shipping_method'] === 2 ? ' (선불·착불 선택)' : '') ?></td></tr>
        <?php if ((int) $product['buy_min'] > 0 || (int) $product['buy_max'] > 0): ?><tr><th scope="row">구매수량</th><td><?= (int) $product['buy_min'] > 0 ? '최소 ' . (int) $product['buy_min'] . '개' : '' ?> <?= (int) $product['buy_max'] > 0 ? '최대 ' . (int) $product['buy_max'] . '개' : '' ?></td></tr><?php endif ?>
      </tbody></table>
      <?php if ($sold_out): ?><p class="yc-soldout-notice"><strong>품절</strong>된 상품입니다.<?php if ((int) $product['restock_notify'] === 1): ?> 재입고 알림은 준비 중입니다.<?php endif ?></p>
      <?php elseif ($display_price !== null && (int) $product['active'] === 1): ?><?php $this->insert('_options') ?><?php endif ?>
      <div class="yc-item-actions muted">장바구니와 주문은 다음 단계에서 제공됩니다.</div>
    </div>
  </div>
  <nav class="tabs tabs-border yc-tabs" aria-label="상품 정보 탭">
    <a class="tab tab-active" href="#yc-description">상품정보</a>
    <?php if ($settings['shipping']['content'] !== ''): ?><a class="tab" href="#yc-shipping">배송정보</a><?php endif ?>
    <?php if ($settings['exchange']['content'] !== ''): ?><a class="tab" href="#yc-exchange">교환정보</a><?php endif ?>
  </nav>
  <section id="yc-description" class="yc-section"><div class="editor-content"><?= $this->html($product['description']) ?></div>
    <?php if ($info_articles !== []): ?><h2 class="yc-section-title">상품정보고시 <small class="muted"><?= $this->e($info_label) ?></small></h2>
      <table class="table table-sm yc-info-table"><tbody><?php foreach ($info_articles as $index => $article): ?><tr><th scope="row"><?= $this->e($article) ?></th><td><?= $this->e($product['info'][$index] ?? '') ?></td></tr><?php endforeach ?></tbody></table><?php endif ?>
    <?php $extra = array_filter($product['extra'], static fn ($f) => ($f['label'] ?? '') !== ''); if ($extra !== []): ?><table class="table table-sm"><tbody><?php foreach ($extra as $field): ?><tr><th scope="row"><?= $this->e($field['label']) ?></th><td><?= $this->e($field['value']) ?></td></tr><?php endforeach ?></tbody></table><?php endif ?>
  </section>
  <?php if ($settings['shipping']['content'] !== ''): ?><section id="yc-shipping" class="yc-section"><h2 class="yc-section-title">배송정보</h2><div class="editor-content"><?= $this->html($settings['shipping']['content']) ?></div></section><?php endif ?>
  <?php if ($settings['exchange']['content'] !== ''): ?><section id="yc-exchange" class="yc-section"><h2 class="yc-section-title">교환정보</h2><div class="editor-content"><?= $this->html($settings['exchange']['content']) ?></div></section><?php endif ?>
  <?php if ($related !== []): ?><section class="yc-section"><h2 class="yc-section-title">관련상품</h2><?php $this->insert('_grid', ['items' => $related, 'columns' => $settings['related']['columns'], 'size' => 'related']) ?></section><?php endif ?>
  <nav class="yc-adjacent" aria-label="이전·다음 상품">
    <?php if ($adjacent['prev'] !== null): ?><a rel="prev" href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($adjacent['prev']['code'])) ?>">← 이전 상품: <?= $this->e($adjacent['prev']['name']) ?></a><?php endif ?>
    <?php if ($adjacent['next'] !== null): ?><a rel="next" href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($adjacent['next']['code'])) ?>">다음 상품: <?= $this->e($adjacent['next']['name']) ?> →</a><?php endif ?>
  </nav>
  <?php if ($product['tail_html'] !== ''): ?><div class="yc-html"><?= $this->html($product['tail_html']) ?></div><?php endif ?>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
