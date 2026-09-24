<?php if ($payment['kind'] === 'inicis-pro'): ?>
<script src="<?= $this->e($payment['script']) ?>" charset="UTF-8"></script>
<script>(()=>{
    'use strict';
    const form = document.getElementById('yc-pay-form');
    const message = document.getElementById('yc-pay-message');
    const showMessage = text => { message.textContent = text; message.hidden = false; };
    let opening = false;
    let hadModal = false;
    let received = false;
    if ('MutationObserver' in window) {
        new MutationObserver(() => {
            if (document.getElementById('inicisModalDiv')) { hadModal = true; return; }
            if (hadModal) {
                hadModal = false; opening = false;
                if (!received) showMessage('결제창이 닫혔습니다. 결제 내역을 확인해 주세요.');
            }
        }).observe(document.body, {childList: true, subtree: true});
    }
    const receive = result => {
        if (received) return;
        opening = false;
        received = true;
        if (!result || typeof result.P_STATUS !== 'string'
                || (result.P_STATUS === '00' && (result.P_MID !== form.elements.P_MID.value
                || result.P_OID !== form.elements.P_OID.value
                || result.P_AMT !== form.elements.P_AMT.value
                || typeof result.P_AUTH_TID !== 'string' || typeof result.P_IDCNAME !== 'string'))) {
            showMessage('결제 결과를 확인하지 못했습니다. 다시 결제하지 말고 상점에 문의해 주세요.');
            return;
        }
        const response = document.createElement('form');
        response.method = 'post';
        response.action = form.elements.P_NEXT_URL.value;
        response.hidden = true;
        ['P_STATUS', 'P_MID', 'P_OID', 'P_AMT', 'P_AUTH_TID', 'P_IDCNAME'].forEach(name => {
            if (typeof result[name] !== 'string') return;
            const field = document.createElement('input');
            field.type = 'hidden'; field.name = name; field.value = result[name];
            response.appendChild(field);
        });
        document.body.appendChild(response);
        response.submit();
    };
    const open = () => {
        if (opening) return;
        if (!window.INIPayPro || typeof window.INIPayPro.requestPayment !== 'function') {
            showMessage('결제창 연결을 확인해 주세요.');
            return;
        }
        opening = true;
        received = false;
        message.textContent = ''; message.hidden = true;
        try {
            const result = window.INIPayPro.requestPayment(Object.fromEntries(new FormData(form)), receive);
            if (result === false) opening = false;
        } catch (error) {
            opening = false;
            showMessage('결제창을 열지 못했습니다.');
        }
    };
    window.setTimeout(open, 300);
})();</script>
<?php elseif ($payment['kind'] === 'inicis'): ?>
<script src="<?= $this->e($payment['script']) ?>"></script>
<script>(()=>{'use strict';const message=document.getElementById('yc-pay-message');const showMessage=text=>{message.textContent=text;message.hidden=false};const open=()=>{if(!window.INIStdPay){showMessage('결제창 연결을 확인해 주세요.');return}window.INIStdPay.pay('yc-pay-form')};window.setTimeout(open,300);})();</script>
<?php else: ?>
<script>(()=>{'use strict';window.setTimeout(()=>{document.getElementById('yc-pay-form').submit();},300);})();</script>
<?php endif ?>
