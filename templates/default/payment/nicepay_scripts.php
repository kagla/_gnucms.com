<script src="<?= $this->e($payment['script']) ?>"></script>
<script>(() => {
  'use strict';
  const button = document.getElementById('yc-nicepay-pay');
  const message = document.getElementById('yc-nicepay-message');
  const fields = <?= json_encode($payment['fields'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
  let opening = false;
  button.addEventListener('click', () => {
    if (opening) return;
    if (!window.AUTHNICE || typeof window.AUTHNICE.requestPay !== 'function') {
      message.textContent = '나이스페이 결제창을 불러오지 못했습니다.'; message.hidden = false; return;
    }
    opening = true; button.disabled = true; message.hidden = true;
    try { window.AUTHNICE.requestPay({...fields, fnError: result => {
      message.textContent = typeof result?.errorMsg === 'string' ? result.errorMsg : '결제를 진행하지 못했습니다.';
      message.hidden = false; opening = false; button.disabled = false;
    }}); }
    catch (error) {
      message.textContent = '결제창을 열지 못했습니다.'; message.hidden = false;
      opening = false; button.disabled = false;
    }
  });
  window.setTimeout(() => button.click(), 300);
})();</script>
