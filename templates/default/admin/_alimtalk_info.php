<section class="card settings-card landing" id="alimtalk-info">
  <div class="card-body">
    <h2 class="card-title"><?= $this->icon('bell', 19) ?> 알림톡 발신 정보</h2>
    <p class="card-sub">카카오 알림톡에 표시할 서비스명, 홈페이지 주소, 문의처를 입력합니다. 비워 둔 항목은 사이트 기본 정보를 사용합니다.</p>
    <?php if ($info_saved): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>알림톡 발신 정보를 저장했습니다.</span></div><?php endif ?>
    <form method="post" action="<?= $this->url('admin.aligo.info') ?>#alimtalk-info">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <?php foreach (['name' => ['서비스명', 'text', 100, '사이트명'], 'url' => ['홈페이지 주소', 'url', 1000, '사이트주소'], 'contact' => ['문의처', 'text', 500, '문의처']] as $field => [$label, $type, $limit, $variable]): ?>
        <fieldset class="fieldset<?= array_key_exists($field, $info_errors) ? ' is-invalid' : '' ?>">
          <legend class="fieldset-legend"><?= $this->e($label) ?></legend>
          <input class="input input-bordered input-block" type="<?= $this->e($type) ?>" name="<?= $this->e($field) ?>" value="<?= $this->e(is_string($info_values[$field] ?? null) ? $info_values[$field] : '') ?>" placeholder="<?= $this->e($info_defaults[$variable] ?? '') ?>" maxlength="<?= $limit ?>">
          <p class="fieldset-label">템플릿의 #{<?= $this->e($variable) ?>}에 넣습니다. 비워두면 <?= $this->e($info_defaults[$variable] ?? '') ?>을 사용합니다.</p>
          <?php if (array_key_exists($field, $info_errors)): ?><p class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($info_errors[$field]) ?></p><?php endif ?>
        </fieldset>
      <?php endforeach ?>
      <p class="fieldset-label">문의처에는 전화번호, 이메일 또는 문의 페이지 주소를 입력할 수 있습니다. 변경한 정보는 다음 알림톡 발송부터 사용합니다.</p>
      <div class="card-actions form-actions"><button class="btn btn-primary" type="submit">알림톡 발신 정보 저장</button></div>
    </form>
  </div>
</section>
