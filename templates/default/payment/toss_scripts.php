<script src="<?= $this->e($payment['script']) ?>"></script>
<script>(() => {
  'use strict';
  const button = document.getElementById('yc-toss-pay');
  const message = document.getElementById('yc-toss-message');
  const clientKey = <?= json_encode($payment['client_key'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
  const method = <?= json_encode($payment['method'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
  const fields = <?= json_encode($payment['fields'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
  let opening = false;
  button.addEventListener('click', async () => {
    if (opening) return;
    if (typeof window.TossPayments !== 'function') {
      message.textContent = '토스 결제창을 불러오지 못했습니다.'; message.hidden = false; return;
    }
    opening = true; button.disabled = true; message.hidden = true;
    try { await window.TossPayments(clientKey).requestPayment(method, fields); }
    catch (error) {
      message.textContent = typeof error?.message === 'string' ? error.message : '결제창을 열지 못했습니다.';
      message.hidden = false; opening = false; button.disabled = false;
    }
  });
  window.setTimeout(() => button.click(), 300);
})();</script>
