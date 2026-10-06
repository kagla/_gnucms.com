<?php $this->layout('layout') ?>
<?php $this->start('title') ?><?= $this->e($product['name']) ?> · 쇼핑몰 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('nav_section') ?>shop<?php $this->stop() ?>
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
  <div class="yc-item-toolbar">
    <?php $this->insert('_breadcrumb') ?>
    <?php if ($admin): ?><a class="yc-button yc-button-small yc-product-edit" href="<?= $this->e($admin_url) ?>/products/edit?id=<?= (int) $product['id'] ?>"><?= $this->icon('cog', 16) ?> 상품 관리</a><?php endif ?>
  </div>
  <?php if ($preview): ?><div class="alert alert-warning">판매하지 않는 상품의 관리자 미리보기입니다.</div><?php endif ?>
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
        <?php if ((int) $product['list_price'] > 0 && $display_price !== null): ?><tr><th scope="row">시중가격</th><td><del><?= number_format((int) $product['list_price']) ?>원</del></td></tr><?php endif ?>
        <tr><th scope="row">판매가격</th><td class="yc-item-price"><?php if (!(int) $product['active']): ?>판매중지<?php elseif ($display_price === null): ?>전화문의<?php else: ?><strong><?= number_format($display_price) ?>원</strong><?php if ($settings['show_tax']): ?> <small class="muted"><?= (int) $product['tax_free'] === 1 ? '면세' : '부가세 포함' ?></small><?php endif ?><?php endif ?></td></tr>
        <tr><th scope="row">배송비</th><td><?= [0 => ((int) $settings['shipping']['fee'] === 0 ? '무료배송' : number_format((int) $settings['shipping']['fee']) . '원' . ((int) $settings['shipping']['free_minimum'] > 0 ? ' · 기본배송 상품 ' . number_format((int) $settings['shipping']['free_minimum']) . '원 이상 무료' : '')), 1 => '무료배송', 2 => number_format((int) $product['shipping_free_minimum']) . '원 이상 무료, 미만 ' . number_format((int) $product['shipping_fee']) . '원', 3 => number_format((int) $product['shipping_fee']) . '원', 4 => (int) $product['shipping_per_qty'] . '개마다 ' . number_format((int) $product['shipping_fee']) . '원'][(int) $product['shipping_type']] ?><?= (int) $product['shipping_method'] === 1 ? ' (착불)' : ((int) $product['shipping_method'] === 2 ? ' (선불·착불 선택)' : '') ?></td></tr>
        <?php if ((int) $product['buy_min'] > 0 || (int) $product['buy_max'] > 0): ?><tr><th scope="row">구매수량</th><td><?= (int) $product['buy_min'] > 0 ? '최소 ' . (int) $product['buy_min'] . '개' : '' ?> <?= (int) $product['buy_max'] > 0 ? '최대 ' . (int) $product['buy_max'] . '개' : '' ?></td></tr><?php endif ?>
      </tbody></table>
      <?php if ($sold_out): ?><p class="yc-soldout-notice"><strong>품절</strong>된 상품입니다.</p>
      <?php elseif ($display_price !== null && !$preview): ?><?php $this->insert('_options') ?><?php endif ?>

    </div>
  </div>
  <nav class="yc-tabs yc-item-nav" aria-label="상품 상세 정보" data-yc-item-tabs>
    <a class="yc-item-nav-link is-active" href="#yc-description" aria-current="location">상품정보</a>
    <a class="yc-item-nav-link" href="#yc-reviews">사용후기 <span class="yc-tab-count"><?= number_format($reviews['total']) ?></span></a>
    <a class="yc-item-nav-link" href="#yc-inquiries">상품문의 <span class="yc-tab-count"><?= number_format($inquiries['total']) ?></span></a>
    <a class="yc-item-nav-link" href="#yc-delivery">배송/교환</a>
  </nav>
  <section id="yc-description" class="yc-section" data-yc-item-panel aria-label="상품정보"><h2 class="yc-section-title">상품정보</h2><div class="editor-content"><?= $this->html($product['description']) ?></div>
    <?php if ($info_articles !== []): ?><h2 class="yc-section-title">상품정보고시 <small class="muted"><?= $this->e($info_label) ?></small></h2>
      <table class="table table-sm yc-info-table"><tbody><?php foreach ($info_articles as $index => $article): ?><tr><th scope="row"><?= $this->e($article) ?></th><td><?= $this->e($product['info'][$index] ?? '') ?></td></tr><?php endforeach ?></tbody></table><?php endif ?>
    <?php $extra = array_filter($product['extra'], static fn ($f) => ($f['label'] ?? '') !== ''); if ($extra !== []): ?><table class="table table-sm"><tbody><?php foreach ($extra as $field): ?><tr><th scope="row"><?= $this->e($field['label']) ?></th><td><?= $this->e($field['value']) ?></td></tr><?php endforeach ?></tbody></table><?php endif ?>
  </section>
  <section id="yc-reviews" class="yc-section" data-yc-item-panel aria-label="사용후기">
    <div class="yc-feedback-heading"><div><h2 class="yc-section-title">사용후기</h2><p>구매한 회원이 남긴 후기입니다.</p></div><strong><?= number_format($reviews['total']) ?>건<?= $reviews['total'] > 0 ? ' · 평균 ' . number_format((float) $product['review_avg'], 1) . '/5' : '' ?></strong></div>
    <?php if ($feedback_kind === 'review' && $feedback_errors !== []): ?><div class="alert alert-error" role="alert"><?php foreach ($feedback_errors as $message): ?><p><?= $this->e($message) ?></p><?php endforeach ?></div><?php endif ?>
    <?php if (($query['saved'] ?? '') === 'review'): ?><div class="alert alert-success" role="status">사용후기를 등록했습니다.</div><?php endif ?>
    <?php if ($reviews['items'] === []): ?><div class="yc-feedback-empty">아직 등록된 사용후기가 없습니다.</div><?php endif ?>
    <div class="yc-feedback-list"><?php foreach ($reviews['items'] as $row): ?>
      <article class="yc-feedback-card"><div class="yc-feedback-card-head"><strong><?= $this->e($row['title']) ?></strong><time datetime="<?= date('c', (int) $row['created_at']) ?>"><?= $this->date((int) $row['created_at']) ?></time></div><p class="yc-feedback-meta"><?= $this->e($row['author']) ?> · 평점 <?= (int) $row['rating'] ?>/5</p><p class="yc-feedback-text"><?= nl2br($this->e($row['content'])) ?></p></article>
    <?php endforeach ?></div>
    <?php if ($reviews['pages'] > 1): ?><nav class="yc-feedback-pager" aria-label="사용후기 페이지"><?php for ($page = 1; $page <= $reviews['pages']; $page++): ?><a href="<?= $this->e($url) ?>/item?<?= $this->e(http_build_query(['id' => $product['code'], 'review_page' => $page, 'inquiry_page' => $inquiries['page']])) ?>#yc-reviews"<?= $page === $reviews['page'] ? ' aria-current="page"' : '' ?>><?= $page ?></a><?php endfor ?></nav><?php endif ?>
    <?php if ($can_review && !$preview): ?><details class="yc-feedback-compose"<?= $feedback_kind === 'review' && $feedback_errors !== [] ? ' open' : '' ?>><summary>사용후기 작성</summary><form method="post" action="<?= $this->e($url) ?>/item/feedback" class="yc-feedback-form"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="code" value="<?= $this->e($product['code']) ?>"><input type="hidden" name="kind" value="review"><label>평점<select name="rating" class="select select-bordered" required><option value="">평점을 선택하세요</option><?php for ($rating = 5; $rating >= 1; $rating--): ?><option value="<?= $rating ?>"<?= (string) ($feedback_input['rating'] ?? '') === (string) $rating ? ' selected' : '' ?>><?= $rating ?>점</option><?php endfor ?></select></label><label>제목<input class="input input-bordered" name="title" maxlength="150" required value="<?= $this->e($feedback_input['title'] ?? '') ?>"></label><label>후기<textarea class="textarea textarea-bordered" name="content" maxlength="3000" rows="5" required><?= $this->e($feedback_input['content'] ?? '') ?></textarea></label><button class="yc-button yc-button-primary" type="submit">후기 등록</button></form></details><?php elseif ($viewer_id === null): ?><p class="yc-help">사용후기는 구매한 회원이 로그인 후 작성할 수 있습니다.</p><?php elseif (!$preview): ?><p class="yc-help">결제 완료된 주문의 상품에 한 번만 후기를 작성할 수 있습니다.</p><?php endif ?>
  </section>
  <section id="yc-inquiries" class="yc-section" data-yc-item-panel aria-label="상품문의">
    <div class="yc-feedback-heading"><div><h2 class="yc-section-title">상품문의</h2><p>상품에 대해 궁금한 점을 남겨 주세요.</p></div><strong><?= number_format($inquiries['total']) ?>건</strong></div>
    <?php if ($feedback_kind === 'inquiry' && $feedback_errors !== []): ?><div class="alert alert-error" role="alert"><?php foreach ($feedback_errors as $message): ?><p><?= $this->e($message) ?></p><?php endforeach ?></div><?php endif ?>
    <?php if (($query['saved'] ?? '') === 'inquiry'): ?><div class="alert alert-success" role="status">상품문의를 등록했습니다.</div><?php endif ?>
    <?php if ($inquiries['items'] === []): ?><div class="yc-feedback-empty">아직 등록된 상품문의가 없습니다.</div><?php endif ?>
    <div class="yc-feedback-list"><?php foreach ($inquiries['items'] as $row): ?>
      <article class="yc-feedback-card" id="yc-inquiry-<?= (int) $row['id'] ?>"><div class="yc-feedback-card-head"><strong><?= $this->e($row['title']) ?><?php if ((int) $row['is_private'] === 1): ?> <span class="yc-feedback-private">비공개</span><?php endif ?></strong><time datetime="<?= date('c', (int) $row['created_at']) ?>"><?= $this->date((int) $row['created_at']) ?></time></div>
        <?php if ($row['restricted']): ?><p class="yc-feedback-meta">작성자와 관리자만 볼 수 있습니다.</p><?php else: ?><p class="yc-feedback-meta"><?= $this->e($row['author']) ?> · <?= $row['reply'] === '' ? '답변 대기' : '답변 완료' ?></p><p class="yc-feedback-text"><?= nl2br($this->e($row['content'])) ?></p><?php if ($row['reply'] !== ''): ?><div class="yc-feedback-answer"><strong>판매자 답변</strong><p><?= nl2br($this->e($row['reply'])) ?></p></div><?php endif ?><?php endif ?>
      </article>
    <?php endforeach ?></div>
    <?php if ($inquiries['pages'] > 1): ?><nav class="yc-feedback-pager" aria-label="상품문의 페이지"><?php for ($page = 1; $page <= $inquiries['pages']; $page++): ?><a href="<?= $this->e($url) ?>/item?<?= $this->e(http_build_query(['id' => $product['code'], 'review_page' => $reviews['page'], 'inquiry_page' => $page])) ?>#yc-inquiries"<?= $page === $inquiries['page'] ? ' aria-current="page"' : '' ?>><?= $page ?></a><?php endfor ?></nav><?php endif ?>
    <?php if ($viewer_id !== null && !$preview): ?><details class="yc-feedback-compose"<?= $feedback_kind === 'inquiry' && $feedback_errors !== [] ? ' open' : '' ?>><summary>상품문의 작성</summary><form method="post" action="<?= $this->e($url) ?>/item/feedback" class="yc-feedback-form"><input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="code" value="<?= $this->e($product['code']) ?>"><input type="hidden" name="kind" value="inquiry"><label>제목<input class="input input-bordered" name="title" maxlength="150" required value="<?= $this->e($feedback_input['title'] ?? '') ?>"></label><label>문의 내용<textarea class="textarea textarea-bordered" name="content" maxlength="3000" rows="5" required><?= $this->e($feedback_input['content'] ?? '') ?></textarea></label><label class="yc-feedback-private-choice"><input type="checkbox" name="is_private" value="1"<?= ($feedback_input['is_private'] ?? '') === '1' ? ' checked' : '' ?>> 비공개 문의</label><button class="yc-button yc-button-primary" type="submit">문의 등록</button></form></details><?php elseif ($viewer_id === null): ?><p class="yc-help"><a href="<?= $this->e($feedback_login_url) ?>">로그인</a> 후 상품문의를 작성할 수 있습니다.</p><?php endif ?>
  </section>
  <section id="yc-delivery" class="yc-section" data-yc-item-panel aria-label="배송 및 교환 안내">
    <h2 class="yc-section-title">배송/교환</h2>
    <h3 id="yc-shipping" class="yc-section-title">배송 안내</h3>
    <?php if ($settings['shipping']['content'] !== ''): ?><div class="editor-content"><?= $this->html($settings['shipping']['content']) ?></div><?php else: ?><p class="yc-feedback-empty">등록된 배송 안내가 없습니다.</p><?php endif ?>
    <h3 id="yc-exchange" class="yc-section-title">교환·반품 안내</h3>
    <?php if ($settings['exchange']['content'] !== ''): ?><div class="editor-content"><?= $this->html($settings['exchange']['content']) ?></div><?php else: ?><p class="yc-feedback-empty">등록된 교환·반품 안내가 없습니다.</p><?php endif ?>
  </section>
  <nav class="yc-adjacent" aria-label="이전·다음 상품">
    <?php if ($adjacent['prev'] !== null): ?><a rel="prev" href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($adjacent['prev']['code'])) ?>">← 이전 상품: <?= $this->e($adjacent['prev']['name']) ?></a><?php endif ?>
    <?php if ($adjacent['next'] !== null): ?><a rel="next" href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($adjacent['next']['code'])) ?>">다음 상품: <?= $this->e($adjacent['next']['name']) ?> →</a><?php endif ?>
  </nav>
</div>
<?php $this->stop() ?>
<?php $this->start('scripts') ?><script src="<?= $this->asset('youngcart.js') ?>" defer></script><?php $this->stop() ?>
