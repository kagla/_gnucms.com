<form class="yc-filter" method="get" action="<?= $this->e($action) ?>">
  <select class="select select-bordered select-sm" name="field" aria-label="검색 필드"><?php foreach ($fields as $field): ?><option value="<?= $field ?>"<?= $filters['field'] === $field ? ' selected' : '' ?>><?= ['name' => '상품명', 'code' => '코드', 'maker' => '제조사', 'brand' => '브랜드', 'model' => '모델', 'origin' => '원산지', 'seller_email' => '판매자 메일'][$field] ?></option><?php endforeach ?></select>
  <input class="input input-bordered input-sm" type="search" name="q" value="<?= $this->e($filters['q']) ?>" maxlength="100" placeholder="검색어" aria-label="검색어">
  <select class="select select-bordered select-sm" name="ca" aria-label="분류"><option value="">전체 분류</option><?php foreach ($category_codes as $code => $name): ?><option value="<?= $this->e($code) ?>"<?= $filters['ca'] === $code ? ' selected' : '' ?>><?= $this->e(str_repeat('· ', intdiv(strlen($code), 2) - 1) . $name) ?></option><?php endforeach ?></select>
  <button class="btn btn-sm" type="submit">검색</button>
</form>
