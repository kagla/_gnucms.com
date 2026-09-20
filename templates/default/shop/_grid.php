<?php if ($items === []): ?>
  <p class="muted yc-empty">등록된 상품이 없습니다.</p>
<?php else: ?>
  <div class="yc-grid" style="--yc-columns: <?= (int) $columns ?>">
    <?php foreach ($items as $item) $this->insert('_product_card', ['item' => $item, 'size' => $size]) ?>
  </div>
<?php endif ?>
