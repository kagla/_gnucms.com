<?php // 목록형: 표. 정보 밀도가 가장 높다. 좁은 화면에서는 카드로 접힌다. ?>
<?php $this->insert('posts/_table', [
  'list' => $list,
  'notices' => $list['notices'] ?? [],
  'show_category' => (bool) $board['use_category'],
  'compact' => true,
  'current_post_id' => $current_post_id ?? null,
]) ?>
