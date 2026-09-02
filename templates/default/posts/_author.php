<?php
// 목록의 글쓴이 칸. 회원이면 모달을 여는 단추, 비회원이면 글자.
// 비회원은 이름만 남아 동명이인을 가릴 수 없으므로 누를 수 없다.
$compact = $compact ?? false;
$author_name = (string) $post['author_name'];
$shown = $compact ? $this->truncate($author_name, 8) : $author_name;
$author_id = $post['author_id'] ?? null;
$is_member = $author_id !== null && (string) $author_id !== '';
$tone = $is_member ? mb_strlen($author_name) % 6 : 0;
$initial = mb_strtoupper(mb_substr($author_name === '' ? '손' : $author_name, 0, 1));
?>
<?php if ($is_member): ?>
  <button type="button" class="link-author" data-author-id="<?= $this->e((string) $author_id) ?>" data-author-name="<?= $this->e($author_name) ?>" title="<?= $this->e($author_name) ?>">
    <span class="avatar avatar-placeholder avatar-xs" aria-hidden="true"><span class="avatar-inner" data-tone="<?= $this->e($tone) ?>"><?php if (!empty($post['author_avatar_file'])): ?><img src="<?= $this->url('avatar.show', ['file' => $post['author_avatar_file']]) ?>" alt=""><?php else: ?><span><?= $this->e($initial) ?></span><?php endif ?></span></span>
    <span class="post-list-author-name"><?= $this->e($shown) ?></span>
  </button>
<?php else: ?>
  <span class="post-list-author" title="<?= $this->e($author_name) ?>">
    <span class="avatar avatar-placeholder avatar-xs" aria-hidden="true"><span class="avatar-inner" data-tone="0"><span><?= $this->e($initial) ?></span></span></span>
    <span class="post-list-author-name"><?= $this->e($shown) ?></span>
  </span>
<?php endif ?>
