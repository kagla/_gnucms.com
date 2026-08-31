<?php
// 회원이 가입할 때 남긴 동의 기록. 고칠 수 없는 기록이라 읽기만 한다.
// 테마마다 두지 않고 default 로 폴백시켜 한 곳에서 관리한다.
?>
<?php if ($member_consents !== []): ?>
<section class="card">
  <div class="card-body">
    <h2 class="card-title">가입 동의 내역</h2>
    <p class="card-sub">어떤 항목에 동의했는지와, 그때 본 문서가 어느 판이었는지 남긴 기록입니다.</p>
    <div class="table-wrap">
      <table class="table table-zebra">
        <thead><tr><th>항목</th><th>자리</th><th>동의</th><th>동의 시각</th><th>그때 본 문서</th></tr></thead>
        <tbody>
        <?php foreach ($member_consents as $row): ?>
          <tr>
            <td data-label="항목"><span class="cell-title"><?= $this->e($this->def($row['content_title'] ?? null, $row['consent_type'])) ?></span></td>
            <td data-label="자리"><code class="kbd kbd-sm"><?= $this->e($row['scope']) ?></code></td>
            <td data-label="동의"><span class="badge badge-sm badge-soft <?= $row['agreed'] ? 'badge-success' : 'badge-ghost' ?>"><?= $row['agreed'] ? '동의' : '안 함' ?></span></td>
            <td data-label="동의 시각"><?= $this->date($row['agreed_at'], 'Y.m.d H:i') ?></td>
            <td data-label="그때 본 문서">
              <?php if ($row['content_slug']): ?>
                <a class="link" href="<?= $this->url('terms.show', ['slug' => $row['content_slug']]) ?>" target="_blank" rel="noopener"><?= $this->date($row['content_updated_at'], 'Y.m.d H:i') ?> 판</a>
                <?php if ($row['content_current_updated_at'] > $row['content_updated_at']): ?>
                  <span class="badge badge-warning badge-soft badge-xs">그 뒤 바뀜</span>
                <?php endif ?>
              <?php else: ?>
                <span class="cell-sub">지워진 문서</span>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif ?>
