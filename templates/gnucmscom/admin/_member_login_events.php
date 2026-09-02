<section class="card member-login-events-card">
  <div class="card-body">
    <h2 class="card-title"><?= $this->icon('shield', 18) ?> 최근 로그인 이력</h2>
    <p class="card-sub">성공과 실패를 지우지 않고 기록합니다. 최근 20건을 표시합니다.</p>
    <?php if ($member_login_events === []): ?>
      <p class="empty-copy">아직 기록된 로그인 이력이 없습니다.</p>
    <?php else: ?>
      <?php $method_labels = ['password' => '이메일', 'google' => 'Google', 'naver' => '네이버', 'kakao' => '카카오']; ?>
      <div class="overflow-x-auto"><table class="table table-sm login-events-table">
        <thead><tr><th>시각</th><th>방식</th><th>결과</th><th>IP</th><th>환경</th></tr></thead>
        <tbody><?php foreach ($member_login_events as $event): ?><tr>
          <td><?= $this->date($event['created_at'], 'Y.m.d H:i:s') ?></td>
          <td><?= $this->e($method_labels[$event['auth_method']] ?? $event['auth_method']) ?></td>
          <td><span class="badge <?= $event['result'] === 'success' ? 'badge-success' : 'badge-error' ?> badge-soft"><?= $event['result'] === 'success' ? '성공' : '실패' ?></span></td>
          <td><code><?= $this->e($event['client_ip'] ?? '알 수 없음') ?></code></td>
          <td class="login-event-ua" title="<?= $this->e($event['user_agent'] ?? '') ?>"><?= $this->e($event['user_agent'] ?? '알 수 없음') ?></td>
        </tr><?php endforeach ?></tbody>
      </table></div>
    <?php endif ?>
  </div>
</section>
