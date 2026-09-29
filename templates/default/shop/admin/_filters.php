<form class="yc-filter" method="get" action="<?= $this->e($action) ?>">
  <label>검색 기준<select class="select select-bordered select-sm" name="field"><?php foreach ($fields as $field): ?><option value="<?= $field ?>"<?= $filters['field'] === $field ? ' selected' : '' ?>><?= ['name' => '상품명', 'code' => '상품 코드'][$field] ?></option><?php endforeach ?></select></label>
  <label class="yc-filter-query">검색어<input class="input input-bordered input-sm" type="search" name="q" value="<?= $this->e($filters['q']) ?>" maxlength="100" placeholder="찾으려는 상품을 입력하세요"></label>
  <label>분류<select class="select select-bordered select-sm" name="ca"><option value="">전체 분류</option><?php foreach ($categories as $id => $label): ?><option value="<?= (int) $id ?>"<?= $filters['ca'] === (string) $id ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?></select></label>
  <button class="btn btn-sm btn-primary" type="submit"><?= $this->icon('search', 16) ?> 검색</button>
  <?php if ($filters['q'] !== '' || $filters['ca'] !== ''): ?><a class="btn btn-sm" href="<?= $this->e($action) ?>">초기화</a><?php endif ?>
</form>
