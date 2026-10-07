(()=>{'use strict';
const form=document.getElementById('yc-kcp-form');
const message=document.getElementById('yc-kcp-message');
if(!form)return;
const show=text=>{message.textContent=text;message.hidden=false};
const callback=(fields)=>{
  const target=form.elements.Ret_URL?.value;
  if(!target){show('결제 결과를 전달할 주소를 확인해 주세요.');return}
  const response=document.createElement('form');response.method='post';response.action=target;response.hidden=true;
  ['site_cd','ordr_idxx','res_cd','res_msg','tran_cd','enc_data','enc_info'].forEach(name=>{
    const value=fields[name];if(typeof value!=='string')return;
    const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;response.appendChild(input)
  });document.body.appendChild(response);response.submit();
};
const value=(data,name)=>{
  if(data instanceof HTMLFormElement)return data.elements[name]?.value;
  if(typeof data?.value==='function')return data.value(name);
  return data?.[name];
};
if(form.elements.PayUrl){
  window.setTimeout(()=>form.submit(),300);
  return;
}
window.m_Completepayment=(result)=>{
  if(!result||typeof result!=='object'){show('KCP 결제 결과를 확인하지 못했습니다.');return}
  const values={};
  ['site_cd','ordr_idxx','res_cd','res_msg','tran_cd','enc_data','enc_info'].forEach(name=>{
    const field=value(result,name);if(typeof field==='string')values[name]=field
  });
  callback(values)
};
window.setTimeout(()=>{
  if(typeof window.KCP_Pay_Execute_Web!=='function'){show('KCP 결제창 연결을 확인해 주세요.');return}
  try{window.KCP_Pay_Execute_Web(form)}catch(error){show('결제창을 열지 못했습니다.')}
},300);
})();
