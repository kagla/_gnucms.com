<form class="yc-sort" method="get" action="<?= $this->e($action) ?>">
  <?php foreach ($hidden as $name => $value): ?><input type="hidden" name="<?= $this->e($name) ?>" value="<?= $this->e($value) ?>"><?php endforeach ?>
  <label class="sr-only" for="yc-sort">정렬</label>
  <select class="select select-bordered select-sm" id="yc-sort" name="sortdir" onchange="var v=this.value.split('_');this.form.sort.value=v[0];this.form.dir.value=v[1];this.form.submit()">
    <option value="_"<?= $sort === '' ? ' selected' : '' ?>>기본순</option>
    <?php foreach ($sort_labels as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $key === $sort . '_' . $dir ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?>
  </select>
  <input type="hidden" name="sort" value="<?= $this->e($sort) ?>"><input type="hidden" name="dir" value="<?= $this->e($dir) ?>">
  <noscript><button class="btn btn-sm" type="submit">정렬</button></noscript>
</form>
