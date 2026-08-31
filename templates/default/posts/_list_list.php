<?php // 목록형: 표. 정보 밀도가 가장 높다. 좁은 화면에서는 카드로 접힌다. ?>
<section class="card">
  <div class="table-wrap">
    <table class="table table-zebra">
      <thead>
        <tr>
          <?php if ($board['use_category']): ?><th>분류</th><?php endif ?>
          <th>제목</th><th>글쓴이</th><th>날짜</th><th class="right">조회</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($list['data'] as $post): ?>
        <tr>
          <?php if ($board['use_category']): ?><td data-label="분류"><?php if ($post['category']): ?><span class="badge badge-ghost badge-sm"><?= $this->e($post['category']) ?></span><?php else: ?><span class="cell-sub">—</span><?php endif ?></td><?php endif ?>
          <td data-label="제목">
            <?php if ($post['is_secret']): ?><span class="post-row-lock" title="비밀글" aria-label="비밀글"><?= $this->icon('lock', 12) ?></span><?php endif ?>
            <a class="cell-title link link-hover" href="<?= $this->url('posts.show', ['id' => $post['id']]) ?>"><?= $this->e($post['title']) ?> <?php $this->insert('posts/_count', ['post' => $post]) ?></a>
            <?php if ($post['file_count'] > 0): ?><span class="post-row-clip" title="첨부파일 있음" aria-label="첨부파일 있음"><?= $this->icon('clip', 12) ?></span><?php endif ?>
          </td>
          <td data-label="글쓴이" class="cell-author"><?= $this->e($post['author_name']) ?></td>
          <td data-label="날짜"><time datetime="<?= $this->e($post['created_at']) ?>"><?= $this->date($post['created_at'], 'Y.m.d') ?></time></td>
          <td data-label="조회" class="right"><?= $this->e($post['view_count']) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</section>
