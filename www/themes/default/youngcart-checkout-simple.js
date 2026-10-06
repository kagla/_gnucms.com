(()=>{'use strict';const form=document.querySelector('[data-yc-checkout]'),same=form?.querySelector('[data-yc-recipient-same]');if(!same)return;
const source=[form.querySelector('#yc-buyer_name'),form.querySelector('#yc-phone')],target=[form.querySelector('#yc-recipient'),form.querySelector('#yc-recipient_phone')];
const digits=v=>v.replace(/\D/g,'');
let alternate=target.map(field=>field?.value||'');
function sync(){target.forEach((field,i)=>{if(!field)return;field.readOnly=same.checked;if(same.checked){field.value=source[i]?.value||'';}});}
same.addEventListener('change',()=>{if(same.checked){alternate=target.map(field=>field?.value||'');}else{target.forEach((field,i)=>{if(field)field.value=alternate[i];});}sync();});source.forEach(field=>field?.addEventListener('input',sync));
target.forEach(field=>field?.addEventListener('change',()=>{if(same.checked&&(target[0].value!==(source[0]?.value||'')||digits(target[1].value)!==digits(source[1]?.value||''))){same.checked=false;sync();}if(!same.checked)alternate=target.map(field=>field?.value||'');}));sync();
form.addEventListener('submit',sync);
})();
