<?php $this->layout('admin/layout') ?>
<?php $this->start('title') ?>관리 · <?= $this->e($site['site_name']) ?><?php $this->stop() ?>
<?php $this->start('admin_section') ?>dashboard<?php $this->stop() ?>
<?php $this->start('body') ?>
<div class="admin-hello">
  <div>
    <h1><?= $this->e($current_user['display_name']) ?>님, 오늘도 반가워요</h1>
    <p><?= $this->e($site['site_name']) ?> 의 게시판과 내용, 회원을 이곳에서 관리합니다.</p>
  </div>
  <div class="admin-hello-actions">
    <a class="btn btn-outline" href="<?= $this->url('boards.index') ?>"><?= $this->icon('external', 15) ?> 사이트 보기</a>
    <a class="btn btn-primary" href="<?= $this->url('admin.content.create') ?>"><?= $this->icon('plus', 16) ?> 내용 만들기</a>
  </div>
</div>

<?php if (!$mail_configured): ?><div class="alert alert-warning alert-soft mail-warning"><span aria-hidden="true"><?= $this->icon('warning', 18) ?></span><span><strong>메일 설정이 없습니다.</strong> 가입 인증·비밀번호 변경 알림이 서버 기본 메일로만 나가 도착하지 않을 수 있습니다.</span><a class="btn btn-sm btn-warning" href="<?= $this->url('admin.mail') ?>">메일 설정</a></div><?php endif ?>
<?php if (($query['saved'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>변경사항을 저장했습니다.</span></div><?php endif ?>
<?php if (($query['deleted'] ?? '') === '1'): ?><div class="alert alert-success"><span aria-hidden="true"><?= $this->icon('check-circle', 18) ?></span><span>게시판을 삭제했습니다.</span></div><?php endif ?>

<div class="stats stats-grid">
  <a class="stat" href="<?= $this->url('admin.boards') ?>">
    <div class="stat-figure" data-tone="0" aria-hidden="true"><?= $this->icon('board', 20) ?></div>
    <div class="stat-title">게시판</div>
    <div class="stat-value"><?= $this->e($board_count) ?></div>
    <div class="stat-desc">관리하기 <?= $this->icon('arrow-right', 12) ?></div>
  </a>
  <a class="stat" href="<?= $this->url('admin.content') ?>">
    <div class="stat-figure" data-tone="2" aria-hidden="true"><?= $this->icon('document', 20) ?></div>
    <div class="stat-title">내용</div>
    <div class="stat-value"><?= $this->e($page_count) ?></div>
    <div class="stat-desc">관리하기 <?= $this->icon('arrow-right', 12) ?></div>
  </a>
  <a class="stat" href="<?= $this->url('admin.posts') ?>">
    <div class="stat-figure" data-tone="3" aria-hidden="true"><?= $this->icon('comment', 20) ?></div>
    <div class="stat-title">게시글</div>
    <div class="stat-value"><?= $this->e($post_count) ?></div>
    <div class="stat-desc">전체 글 보기 <?= $this->icon('arrow-right', 12) ?></div>
  </a>
  <a class="stat" href="<?= $this->url('admin.members') ?>">
    <div class="stat-figure" data-tone="4" aria-hidden="true"><?= $this->icon('users', 20) ?></div>
    <div class="stat-title">회원</div>
    <div class="stat-value"><?= $this->e($user_count) ?></div>
    <div class="stat-desc">관리하기 <?= $this->icon('arrow-right', 12) ?></div>
  </a>
</div>

<section class="card">
  <div class="card-body card-body-flush">
    <div class="card-head-row">
      <h2 class="card-title">게시판 바로가기</h2>
      <a class="link link-hover" href="<?= $this->url('admin.boards') ?>">전체 관리 <?= $this->icon('arrow-right', 13) ?></a>
    </div>
    <?php if ($boards === []): ?>
      <div class="empty-inline">
        <span class="empty-icon" aria-hidden="true"><?= $this->icon('board', 22) ?></span>
        <p>아직 게시판이 없습니다</p>
        <a class="btn btn-primary btn-sm" href="<?= $this->url('admin.boards.create') ?>"><?= $this->icon('plus', 15) ?> 첫 게시판 만들기</a>
      </div>
    <?php else: ?>
      <ul class="list">
        <?php $i = 0; foreach ($boards as $board): ?>
          <li class="list-row">
            <span class="list-icon" data-tone="<?= $this->e($i % 6) ?>" aria-hidden="true"><?= $this->icon('board', 17) ?></span>
            <div class="list-copy">
              <a class="list-title link link-hover" href="<?= $this->url('admin.boards.edit', ['key' => $board['board_key']]) ?>"><?= $this->e($board['name']) ?></a>
              <div class="list-sub">/boards/<?= $this->e($board['board_key']) ?></div>
            </div>
            <a class="btn btn-ghost btn-square btn-sm" href="<?= $this->url('admin.boards.edit', ['key' => $board['board_key']]) ?>" aria-label="<?= $this->e($board['name']) ?> 설정"><?= $this->icon('chevron-right', 16) ?></a>
          </li>
          <?php $i++; endforeach ?>
      </ul>
    <?php endif ?>
  </div>
</section>
<?php $this->stop() ?>
