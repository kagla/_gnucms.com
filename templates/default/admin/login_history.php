<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>로그인 기록 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>login_history<?php $this->stop() ?>
<?php $this->start('admin_body_class') ?>login-history-body<?php $this->stop() ?>
<?php $this->start('body') ?>
<?php
$method_labels = ['password' => '이메일', 'google' => 'Google', 'naver' => '네이버', 'kakao' => '카카오'];
$page_url = function (int $page) use ($filter): string {
    $params = [];
    if ($filter['q'] !== '') {
        $params[] = 'q=' . rawurlencode($filter['q']);
    }
    if ($filter['member'] !== null) {
        $params[] = 'member=' . (int) $filter['member'];
    }
    if ($filter['ip'] !== null) {
        $params[] = 'ip=' . rawurlencode((string) $filter['ip']);
    }
    if ($page > 1) {
        $params[] = 'page=' . $page;
    }
    return $this->url('admin.login_history') . ($params === [] ? '' : '?' . implode('&', $params));
};
?>
<div class="breadcrumbs"><ul><li><a href="<?= $this->url('admin.index') ?>">사이트 관리</a></li><li aria-current="page">로그인 기록</li></ul></div>
<div class="page-head">
  <div><h1>로그인 기록</h1><p class="page-sub">전체 로그인 성공·실패 기록을 최신순으로 확인합니다. 총 <?= $this->e(number_format($list['total'])) ?>건입니다.</p></div>
</div>

<?php if ($deleted !== null): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>기준일 이전 로그인 기록 <?= $this->e(number_format($deleted)) ?>건을 삭제했습니다.</span></div><?php endif ?>

<form class="inline-search login-history-search" method="get" action="<?= $this->url('admin.login_history') ?>" role="search">
  <label class="input input-bordered">
    <span class="input-icon" aria-hidden="true"><?= $this->icon('search', 16) ?></span>
    <input type="search" name="q" value="<?= $this->e($filter['q']) ?>" placeholder="회원명, 이메일, IP, User-Agent 검색" aria-label="로그인 기록 검색">
  </label>
  <button class="btn btn-primary" type="submit">검색</button>
</form>

<details class="card history-maintenance"<?= isset($errors['before']) ? ' open' : '' ?>>
  <summary class="history-maintenance-summary">
    <span><?= $this->icon('trash', 18) ?> 오래된 기록 삭제</span>
    <span class="history-maintenance-hint">기준일 이전 기록 정리 <?= $this->icon('chevron-down', 15) ?></span>
  </summary>
  <div class="card-body">
    <p class="card-sub">선택한 날짜가 시작되기 전의 기록을 모두 삭제합니다. 삭제한 기록은 복구할 수 없습니다.</p>
    <form class="history-delete-form" method="post" action="<?= $this->url('admin.login_history.delete') ?>" onsubmit="return confirm('선택한 날짜 이전의 로그인 기록을 모두 삭제할까요? 이 작업은 되돌릴 수 없습니다.')">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <label class="fieldset<?= isset($errors['before']) ? ' is-invalid' : '' ?>">
        <span class="fieldset-legend">기준 날짜</span>
        <input class="input input-bordered" type="date" name="before" value="<?= $this->e($before) ?>" max="<?= $this->e(date('Y-m-d')) ?>" required>
        <?php if (isset($errors['before'])): ?><span class="validator-hint"><?= $this->icon('warning', 14) ?> <?= $this->e($errors['before']) ?></span><?php endif ?>
      </label>
      <button class="btn btn-error" type="submit">이전 기록 삭제</button>
    </form>
  </div>
</details>

<?php if ($filter['member'] !== null || $filter['ip'] !== null || $filter['q'] !== ''): ?>
  <div class="filter-note history-filter-note">
    <?= $this->icon('search', 16) ?>
    <span><?php if ($filter['member'] !== null): ?>선택한 회원의 기록<?php elseif ($filter['ip'] !== null): ?>IP <code><?= $this->e($filter['ip']) ?></code>의 기록<?php else: ?>“<?= $this->e($filter['q']) ?>” 검색 결과<?php endif ?>만 표시하고 있습니다.</span>
    <a class="link" href="<?= $this->url('admin.login_history') ?>">전체 기록 보기</a>
  </div>
<?php endif ?>

<section class="card">
  <div class="table-wrap">
    <table class="table table-zebra login-events-table">
      <thead><tr><th>일시</th><th>회원</th><th>방식</th><th>결과</th><th>IP 주소</th><th>접속 환경</th></tr></thead>
      <tbody>
      <?php if ($list['data'] === []): ?>
        <tr class="table-empty"><td colspan="6"><?= $filter['member'] !== null || $filter['ip'] !== null || $filter['q'] !== '' ? '조건에 맞는 로그인 기록이 없습니다.' : '아직 기록된 로그인 기록이 없습니다.' ?></td></tr>
      <?php else: foreach ($list['data'] as $event): ?>
        <tr>
          <td data-label="일시"><time datetime="<?= $this->e($event['created_at']) ?>"><?= $this->date($event['created_at'], 'Y.m.d H:i:s') ?></time></td>
          <td data-label="회원">
            <?php if ($event['user_id'] !== null && $event['display_name'] !== null): ?>
              <a class="link" href="<?= $this->url('admin.login_history', [], ['member' => $event['user_id'], 'q' => $event['display_name']]) ?>" title="이 회원의 기록만 보기"><?= $this->e($event['display_name']) ?></a>
              <div class="cell-sub"><?= $this->e($event['login_identifier'] ?? $event['email'] ?? '') ?></div>
            <?php else: ?>
              <span><?= $this->e($event['login_identifier'] ?? '알 수 없음') ?></span>
            <?php endif ?>
          </td>
          <td data-label="방식"><?= $this->e($method_labels[$event['auth_method']] ?? $event['auth_method']) ?></td>
          <td data-label="결과" class="login-event-result"><span class="badge <?= $event['result'] === 'success' ? 'badge-success' : 'badge-error' ?> badge-soft"><?= $event['result'] === 'success' ? '성공' : '실패' ?></span></td>
          <td data-label="IP 주소"><?php if ($event['client_ip'] !== null): ?><a class="link" href="<?= $this->url('admin.login_history', [], ['ip' => $event['client_ip'], 'q' => $event['client_ip']]) ?>" title="이 IP의 기록만 보기"><code><?= $this->e($event['client_ip']) ?></code></a><?php else: ?>알 수 없음<?php endif ?></td>
          <td data-label="접속 환경" class="login-event-ua" title="<?= $this->e($event['user_agent'] ?? '') ?>"><?= $this->e($event['user_agent'] ?? '알 수 없음') ?></td>
        </tr>
      <?php endforeach; endif ?>
      </tbody>
    </table>
  </div>
</section>
<?php $this->insert('posts/_pager', ['list' => $list, 'page_url' => $page_url]) ?>
<?php $this->stop() ?>
