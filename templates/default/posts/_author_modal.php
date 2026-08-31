<?php // 목록 페이지에 하나만 둔다. 눌린 이름의 회원 번호를 스크립트가 링크에 채운다. ?>
<dialog class="modal author-modal" id="author-modal">
  <div class="modal-box">
    <h3 class="author-modal-title" data-author-modal-name></h3>
    <p class="author-modal-sub">이 회원이 남긴 것을 모아 봅니다.</p>
    <div class="author-modal-links">
      <a class="btn btn-outline btn-block" href="#" data-author-modal-posts><?= $this->icon('board', 16) ?> 이 사람의 글</a>
      <a class="btn btn-outline btn-block" href="#" data-author-modal-comments><?= $this->icon('comment', 16) ?> 이 사람의 댓글</a>
    </div>
    <form method="dialog" class="modal-action"><button class="btn btn-ghost">닫기</button></form>
  </div>
  <form method="dialog" class="modal-backdrop"><button aria-label="닫기">닫기</button></form>
</dialog>
<script>
(function () {
  var modal = document.getElementById('author-modal');
  if (!modal || typeof modal.showModal !== 'function') { return; }
  var title = modal.querySelector('[data-author-modal-name]');
  var postsLink = modal.querySelector('[data-author-modal-posts]');
  var commentsLink = modal.querySelector('[data-author-modal-comments]');
  var postsBase = <?= $this->json($this->url('posts.all')) ?>;
  var commentsBase = <?= $this->json($this->url('comments.byAuthor')) ?>;
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.link-author');
    if (!button) { return; }
    var id = button.dataset.authorId;
    if (!id) { return; }
    title.textContent = button.dataset.authorName || '';
    postsLink.href = postsBase + '?author=' + encodeURIComponent(id);
    commentsLink.href = commentsBase + '?author=' + encodeURIComponent(id);
    modal.showModal();
  });
})();
</script>
