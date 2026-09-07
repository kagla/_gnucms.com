<form class="yc-sort" method="get" action="<?= $this->e($action) ?>">
  <?php foreach ($hidden as $name => $value): ?><input type="hidden" name="<?= $this->e($name) ?>" value="<?= $this->e($value) ?>"><?php endforeach ?>
  <label class="sr-only" for="yc-sort">정렬</label>
  <select class="select select-bordered select-sm" id="yc-sort" name="sortdir" onchange="this.form.submit()">
    <option value="_"<?= $sort === '' ? ' selected' : '' ?>>기본순</option>
    <?php foreach ($sort_labels as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $key === $sort . '_' . $dir ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?>
  </select>
  <noscript><button class="btn btn-sm" type="submit">정렬</button></noscript>
</form>
