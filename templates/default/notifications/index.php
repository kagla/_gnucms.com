<?php $this->layout('layout') ?>
<?php $this->start('title') ?>알림<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="page-head">
  <div>
    <h1>알림</h1>
    <p class="page-sub">댓글·답글, 주문 진행과 계정 알림을 모아 보여 줍니다.</p>
  </div>
  <?php if ($unread_notifications > 0): ?>
    <form method="post" action="<?= $this->url('notifications.read_all') ?>">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>">
      <button class="btn btn-outline btn-sm" type="submit">모두 읽음으로</button>
    </form>
  <?php endif ?>
</div>

<?php if (empty($notifications['items'])): ?>
  <div class="card empty-card">
    <div class="card-body">
      <h2 class="card-title"><?= $this->icon('bell-off', 22) ?> 아직 알림이 없습니다</h2>
      <p>댓글·답글, 주문 진행과 계정 알림이 이곳에 쌓입니다.</p>
    </div>
  </div>
<?php else: ?>
  <ul class="list card notice-list">
    <?php foreach ($notifications['items'] as $item): ?>
      <li class="list-row notice-row<?php if (!$item['is_read']): ?> is-unread<?php endif ?>">
        <a class="notice-link" href="<?= $this->url('notifications.open', ['id' => $item['id']]) ?>">
          <span class="notice-ico" aria-hidden="true"><?= $this->icon($item['post_id'] === null ? 'bell' : 'comment', 18) ?></span>
          <span class="notice-body">
            <span class="notice-text">
              <?php if ($item['kind'] === 'welcome'): ?>
                가입이 완료되었습니다. 환영합니다.
              <?php elseif ($item['kind'] === 'password_changed'): ?>
                계정의 비밀번호가 변경되었습니다.
              <?php elseif ($item['kind'] === 'inquiry_replied'): ?>
                상품문의에 답변이 등록되었습니다.
              <?php elseif ($item['order_id'] !== null): ?>
                <?= $this->e(match ($item['kind']) {
                    'order_pending' => '주문이 접수되었습니다.',
                    'order_paid' => '결제가 확인되었습니다.',
                    'order_cancelled' => '주문이 취소되었습니다.',
                    'order_refunded' => '환불이 처리되었습니다.',
                    'order_confirmed' => '주문하신 상품을 준비 중입니다.',
                    'order_shipped' => '주문하신 상품이 배송 중입니다.',
                    'order_completed' => '주문하신 상품의 배송이 완료되었습니다.',
                    default => '주문 진행 상태가 변경되었습니다.',
                }) ?>
              <?php else: ?>
                <strong><?= $this->e($item['actor_name']) ?></strong>님이
                <?php if ($item['kind'] === 'reply'): ?>내 댓글에 답글을 달았습니다.<?php else: ?>내 글에 댓글을 달았습니다.<?php endif ?>
              <?php endif ?>
            </span>
            <span class="notice-subject"><?php if ($item['order_id'] !== null): ?>주문번호 <?php endif ?><?= $this->e($item['subject']) ?></span>
          </span>
          <time class="notice-time" datetime="<?= $this->e($item['created_at']) ?>"><?= $this->date($item['created_at']) ?></time>
        </a>
      </li>
    <?php endforeach ?>
  </ul>

  <?php if ($notifications['total_pages'] > 1): ?>
    <nav class="pager" aria-label="알림 페이지 이동">
      <div class="join">
        <?php for ($p = 1; $p <= $notifications['total_pages']; $p++): ?>
          <?php if ($p === $notifications['page']): ?>
            <span class="join-item btn btn-sm btn-active" aria-current="page"><?= $this->e($p) ?></span>
          <?php else: ?>
            <a class="join-item btn btn-sm" href="<?= $this->url('notifications.index') ?>?page=<?= $this->e($p) ?>" aria-label="<?= $this->e($p) ?> 페이지"><?= $this->e($p) ?></a>
          <?php endif ?>
        <?php endfor ?>
      </div>
    </nav>
  <?php endif ?>
<?php endif ?>
<?php $this->stop() ?>
