<?php $this->layout('admin/extension') ?>
<?php $this->start('title') ?>후기·문의 관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>shop<?php $this->stop() ?>
<?php $this->start('extension_body') ?>
<?php $this->insert('admin/_extension_header', ['section' => 'shop', 'heading' => '후기·문의 관리', 'description' => '상품 후기를 확인하고 상품문의에 답변하세요.']) ?>
<?php $this->insert('admin/_nav') ?>
<?php $this->insert('admin/_errors') ?>
<nav class="tabs tabs-sm yc-subtabs" aria-label="후기·문의 필터">
  <?php foreach (['' => '전체', 'review' => '사용후기', 'inquiry' => '상품문의'] as $value => $label): ?><a class="tab<?= $kind === $value ? ' tab-active' : '' ?>" href="<?= $this->e($admin_url) ?>/feedback<?= $value === '' ? '' : '?kind=' . $value ?>"<?= $kind === $value ? ' aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach ?>
</nav>
<section class="yc-list-panel">
  <div class="yc-list-heading"><h2>후기·문의 <span><?= number_format($list['total']) ?></span></h2></div>
  <?php if ($list['items'] === []): ?><div class="yc-admin-empty"><strong>등록된 후기·문의가 없습니다.</strong></div><?php endif ?>
  <div class="yc-feedback-admin-list">
    <?php foreach ($list['items'] as $row): ?>
      <article class="yc-feedback-card" id="yc-feedback-<?= (int) $row['id'] ?>">
        <div class="yc-feedback-card-head"><div><span class="yc-feedback-kind"><?= $row['kind'] === 'review' ? '사용후기' : '상품문의' ?></span> <a href="<?= $this->e($public_url) ?>/item?id=<?= $this->e(rawurlencode($row['product_code'])) ?>#<?= $row['kind'] === 'review' ? 'yc-reviews' : 'yc-inquiries' ?>"><?= $this->e($row['product_name']) ?></a></div><time datetime="<?= date('c', (int) $row['created_at']) ?>"><?= date('Y.m.d H:i', (int) $row['created_at']) ?></time></div>
        <h3><?= $this->e($row['title']) ?><?php if ((int) $row['is_private'] === 1): ?> <span class="yc-feedback-private">비공개</span><?php endif ?></h3>
        <p class="yc-feedback-meta"><?= $this->e($row['author']) ?><?php if ($row['kind'] === 'review'): ?> · 평점 <?= (int) $row['rating'] ?>/5<?php endif ?></p>
        <p class="yc-feedback-text"><?= nl2br($this->e($row['content'])) ?></p>
        <?php if ($row['kind'] === 'inquiry'): ?>
          <form method="post" action="<?= $this->e($admin_url) ?>/feedback" class="yc-feedback-reply-form">
            <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="kind" value="<?= $this->e($kind) ?>"><input type="hidden" name="action" value="reply">
            <label for="yc-feedback-reply-<?= (int) $row['id'] ?>">답변</label><textarea id="yc-feedback-reply-<?= (int) $row['id'] ?>" name="reply" class="textarea textarea-bordered" maxlength="3000" rows="3"><?= $this->e($row['reply']) ?></textarea>
            <button class="btn btn-sm btn-primary" type="submit">답변 저장</button>
            <a class="btn btn-outline btn-sm" href="<?= $this->url('admin.settings.notifications') ?>#events">답변 알림·문자 문구 설정</a>
            <a class="btn btn-ghost btn-sm" href="<?= $this->url('admin.messages.send') ?>?inquiry=<?= (int) $row['id'] ?>&amp;preset=inquiry_replied">문의 회원에게 문자 보내기</a>
          </form>
        <?php endif ?>
        <form method="post" action="<?= $this->e($admin_url) ?>/feedback" class="yc-feedback-delete-form" onsubmit="return confirm('이 <?= $row['kind'] === 'review' ? '후기' : '문의' ?>를 삭제할까요?');">
          <input type="hidden" name="csrf_token" value="<?= $this->e($csrf_token) ?>"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="kind" value="<?= $this->e($kind) ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-error btn-outline" type="submit">삭제</button>
        </form>
      </article>
    <?php endforeach ?>
  </div>
</section>
<?php $this->insert('_pager', ['list' => ['total_pages' => $list['pages'], 'page' => $list['page']], 'page_url' => fn (int $page): string => $admin_url . '/feedback?' . http_build_query(['kind' => $kind, 'page' => $page])]) ?>
<?php $this->stop() ?>
