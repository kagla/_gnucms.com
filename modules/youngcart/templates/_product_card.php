<?php $price = \GnuCms\Modules\YoungCart\Catalog\Pricing::display($item); $image = $img((int) $item['id'], $item['image'], $size); ?>
<article class="yc-card<?= $item['sold_out'] ? ' yc-card-soldout' : '' ?>">
  <a class="yc-card-image" href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($item['code'])) ?>">
    <?php if ($image !== null): ?><img src="<?= $this->e($image) ?>" alt="<?= $this->e($item['name']) ?>" loading="lazy"><?php else: ?><span class="yc-noimage" aria-hidden="true">이미지 없음</span><?php endif ?>
    <?php if ($item['sold_out']): ?><span class="yc-soldout">SOLD OUT</span><?php endif ?>
  </a>
  <div class="yc-card-body">
    <a class="yc-card-name" href="<?= $this->e($url) ?>/item?id=<?= $this->e(rawurlencode($item['code'])) ?>"><?= $this->e($item['name']) ?></a>
    <?php if ($item['summary'] !== ''): ?><div class="yc-card-summary"><?= $this->html($item['summary']) ?></div><?php endif ?>
    <div class="yc-card-price">
      <?php if ($price === null): ?><span class="yc-inquiry">전화문의</span>
      <?php else: ?>
        <?php if ((int) $item['list_price'] > 0): ?><del class="yc-list-price"><?= $this->e(number_format((int) $item['list_price'])) ?>원</del><?php endif ?>
        <strong><?= $this->e(number_format($price)) ?>원</strong>
      <?php endif ?>
    </div>
    <div class="yc-card-icons">
      <?php foreach (['is_hit' => '히트', 'is_recommended' => '추천', 'is_new' => '최신', 'is_popular' => '인기', 'is_discount' => '할인'] as $flag => $label): if ((int) $item[$flag] === 1): ?><span class="badge badge-sm"><?= $label ?></span><?php endif; endforeach ?>
      <?php if ((int) $item['review_count'] > 0): ?><span class="yc-stars" aria-label="평점 <?= $this->e((string) $item['review_avg']) ?>">★ <?= $this->e((string) $item['review_avg']) ?> (<?= (int) $item['review_count'] ?>)</span><?php endif ?>
    </div>
  </div>
</article>
