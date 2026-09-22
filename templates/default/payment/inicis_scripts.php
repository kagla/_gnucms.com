<?php if ($payment['kind'] === 'inicis'): ?>
<script src="<?= $this->e($payment['script']) ?>"></script>
<script>(()=>{'use strict';const button=document.getElementById('yc-pay-button'),message=document.getElementById('yc-pay-message');const open=()=>{if(!window.INIStdPay){message.textContent='결제창 연결을 확인해 주세요. 잠시 후 다시 시도해 주세요.';return}window.INIStdPay.pay('yc-pay-form')};button.addEventListener('click',open);window.setTimeout(open,300);})();</script>
<?php else: ?>
<script>(()=>{'use strict';window.setTimeout(()=>{document.getElementById('yc-pay-form').submit();},300);})();</script>
<?php endif ?>
