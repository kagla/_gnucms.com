<ol class="yc-steps" aria-label="주문 단계">
  <?php foreach (['cart' => '장바구니', 'checkout' => '주문서 작성', 'order' => '주문 접수'] as $key => $label): ?><li<?= ($step ?? $page) === $key ? ' aria-current="step"' : '' ?>><span aria-hidden="true"><?= ['cart' => '01', 'checkout' => '02', 'order' => '03'][$key] ?></span> <?= $label ?></li><?php endforeach ?>
</ol>
