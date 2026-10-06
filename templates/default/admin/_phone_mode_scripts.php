<script>
(function(){
  var form=document.querySelector('[data-phone-mode-form]');
  var result=document.getElementById('aligo-result');
  if(!form||!result||!window.fetch||!window.URLSearchParams){return}
  var radios=Array.from(form.querySelectorAll('[name="phone_mode"]'));
  var save=form.querySelector('button[type="submit"]'),busy=false;
  var guidance=document.querySelector('[data-notification-phone-mode]');
  var descriptions={
    disabled:'알림톡과 문자를 보내지 않습니다.',
    sms:'문자만 보냅니다.',
    alimtalk_sms:'알림톡을 먼저 보내고, 실패하거나 사용할 수 없으면 문자로 보냅니다.',
    alimtalk:'알림톡만 보냅니다. 실패해도 문자는 보내지 않습니다.'
  };
  function message(text,kind){
    result.textContent='';var alert=document.createElement('div');
    alert.className='alert '+kind;alert.textContent=text;
    alert.setAttribute('role',kind==='alert-error'?'alert':'status');result.appendChild(alert);
  }
  function render(status){
    var mode=status.phone_mode;
    if(!Object.prototype.hasOwnProperty.call(descriptions,mode)){throw new Error('발송 방식 응답을 확인할 수 없습니다.')}
    radios.forEach(function(radio){radio.checked=radio.value===mode;radio.disabled=radio.value!=='disabled'&&!status.configured});
    if(guidance){guidance.textContent=descriptions[mode]}
    return mode;
  }
  async function submit(){
    if(busy){return}
    var selected=radios.find(function(radio){return radio.checked});if(!selected){return}
    var wanted=selected.value;
    busy=true;result.setAttribute('aria-busy','true');
    var disabled=radios.map(function(radio){return radio.disabled});
    radios.forEach(function(radio){radio.disabled=true});save.disabled=true;
    message('발송 방식을 저장하고 있습니다.','alert-info');var updated=false;
    try{
      var body=new URLSearchParams({
        csrf_token:form.querySelector('[name="csrf_token"]').value,
        phone_mode:wanted,return_to:'settings_messaging'
      });
      var response=await fetch(form.getAttribute('action').split('#')[0],{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        body:body.toString()
      });
      if((response.headers.get('Content-Type')||'').indexOf('application/json')===-1){
        if(response.redirected){window.location.assign(response.url);return}
        throw new Error('저장 응답을 확인하지 못했습니다.');
      }
      var data=await response.json();
      if(!response.ok||!data.ok){throw new Error(typeof data.message==='string'?data.message:'발송 방식을 저장하지 못했습니다.')}
      render(data.status);updated=true;
      message(data.notice&&typeof data.notice.message==='string'?data.notice.message:'발송 방식을 저장했습니다.',
        data.notice&&data.notice.ok===false?'alert-warning':'alert-success');
    }catch(error){
      // 저장 뒤 응답만 끊겼을 수 있으므로 실제 두 스위치의 조합을 다시 읽는다.
      try{
        var check=await fetch(result.dataset.channelStatusUrl,{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
        if(!check.ok){throw new Error('상태 조회 실패')}
        var actual=await check.json();if(!actual.ok){throw new Error('상태 응답 오류')}
        var mode=render(actual.status);updated=true;
        message(mode===wanted?'현재 선택한 발송 방식이 적용되어 있습니다. 예약 취소 결과는 발송 이력에서 확인해 주세요.':
          (error instanceof Error?error.message:'발송 방식을 저장하지 못했습니다.'),mode===wanted?'alert-warning':'alert-error');
      }catch(checkError){message('설정 반영 여부를 확인하지 못했습니다. 새로 고쳐 현재 방식을 확인해 주세요.','alert-error')}
    }finally{
      if(!updated){radios.forEach(function(radio,index){radio.disabled=disabled[index]})}
      save.disabled=false;result.removeAttribute('aria-busy');busy=false;
    }
  }
  radios.forEach(function(radio){radio.addEventListener('change',submit)});
  form.addEventListener('submit',function(event){event.preventDefault();submit()});
})();
</script>
