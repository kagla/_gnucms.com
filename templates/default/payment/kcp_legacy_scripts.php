<script>(()=>{
  'use strict';
  const form=document.getElementById('yc-kcp-legacy-form');
  const message=document.getElementById('yc-kcp-legacy-message');
  if(!form)return;
  const show=text=>{
    message.textContent=text;message.hidden=false;
    const notice=document.querySelector('.yc-feedback-success');
    if(notice){
      notice.classList.replace('yc-feedback-success','yc-feedback-error');
      const copy=notice.querySelector('p');
      if(copy)copy.textContent=text;
    }
  };
  const field=(result,name)=>{
    if(result instanceof HTMLFormElement)return result.elements[name]?.value;
    if(typeof result?.value==='function')return result.value(name);
    return result?.[name];
  };
  window.m_Completepayment=(result,closeEvent)=>{
    const code=field(result,'res_cd');
    if(code!=='0000'){
      show(field(result,'res_msg')||'KCP 카드 결제가 완료되지 않았습니다.');
      if(typeof closeEvent==='function')closeEvent();
      return;
    }
    if(typeof window.GetField!=='function'){
      show('KCP 결제 결과를 전달할 수 없습니다. 다시 결제하지 말고 상점에 문의해 주세요.');
      return;
    }
    try{
      window.GetField(form,result);
      if(form.elements.res_cd.value!=='0000'||!form.elements.enc_data.value||!form.elements.enc_info.value){
        show('KCP 결제 결과를 확인할 수 없습니다. 다시 결제하지 말고 상점에 문의해 주세요.');
        return;
      }
      const progress=document.getElementById('yc-pay-progress');
      if(progress)progress.hidden=false;
      form.submit();
    }catch(error){show('KCP 결제 결과를 전달하지 못했습니다. 다시 결제하지 말고 상점에 문의해 주세요.')}
  };
  window.setTimeout(()=>{
    if(typeof window.KCP_Pay_Execute!=='function'){
      show('KCP TCP/IP 결제창 연결을 확인해 주세요.');
      return;
    }
    try{window.KCP_Pay_Execute(form)}catch(error){show('KCP 결제창을 열지 못했습니다.')}
  },300);
})();</script>
