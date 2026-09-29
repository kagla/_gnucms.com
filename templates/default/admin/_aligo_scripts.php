<script>
(function(){
  var radios=document.querySelectorAll('[data-senderkey]');
  var senderkey=document.getElementById('aligo-senderkey'),channelName=document.getElementById('aligo-channel-name');
  if(!radios.length||!senderkey){return}
  radios.forEach(function(radio){
    radio.addEventListener('change',function(){
      senderkey.value=radio.dataset.senderkey||'';
      if(channelName){channelName.value=radio.dataset.name||''}
    });
  });
})();
</script>
<script>
(function(){
  if(!window.fetch||!window.URLSearchParams){return}
  var forms=Array.from(document.querySelectorAll('form[data-channel-toggle]'));
  var result=document.getElementById('aligo-result');
  if(!forms.length||!result){return}
  var busy=false;
  function message(text,kind){
    result.textContent='';
    var alert=document.createElement('div'),label=document.createElement('span');
    alert.className='alert '+kind;alert.setAttribute('role',kind==='alert-error'?'alert':'status');
    label.textContent=text;alert.appendChild(label);result.appendChild(alert);
  }
  function renderStatus(status){
    [['sms','sms_switch_on','문자 발송'],['at','alimtalk_switch_on','알림톡 발송']].forEach(function(item){
      var on=status[item[1]]===true;
      var badge=document.querySelector('[data-aligo-status="'+item[0]+'"]');
      if(badge){
        badge.textContent=item[2]+' '+(on?'켜짐':'꺼짐');
        badge.classList.toggle('badge-success',on);
        badge.classList.toggle('badge-soft',on);
        badge.classList.toggle('badge-ghost',!on);
      }
      forms.forEach(function(form){
        if(form.querySelector('input[name="channel"]').value!==item[0]){return}
        form.querySelectorAll('button[data-action]').forEach(function(button){
          var action=button.dataset.action,current=(action==='enable')===on;
          button.type=current?'button':'submit';button.name='action';button.value=action;
          button.setAttribute('aria-pressed',current?'true':'false');
          button.classList.toggle('btn-success',current&&action==='enable');
          button.classList.toggle('btn-neutral',current&&action==='disable');
          button.classList.toggle('btn-outline',!current);
          button.disabled=!current&&action==='enable'&&!status.configured;
        });
      });
    });
    var summary=document.querySelector('[data-aligo-summary]');
    if(summary){summary.textContent=(status.configured?'계정 연결됨':'계정 연결 필요')+
      ' · 문자 '+(status.sms_switch_on?'켜짐':'꺼짐')+' · 알림톡 '+(status.alimtalk_switch_on?'켜짐':'꺼짐')}
    var pending=document.querySelector('[data-aligo-pending]');
    if(pending&&typeof status.pending==='number'){
      var count=Math.max(0,Number(status.pending)||0);
      pending.textContent=count+'건 대기 중';pending.hidden=count===0;
    }
    var warning=document.querySelector('[data-notify-channel-warning]');
    if(warning){
      warning.hidden=status.sms_enabled&&status.alimtalk_enabled;
      var label=warning.querySelector('[data-notify-channel-warning-text]');
      if(label){label.textContent=!status.sms_enabled&&!status.alimtalk_enabled
        ?'알림톡 발송과 문자 발송이 모두 꺼져 있습니다.'
        :(!status.alimtalk_enabled?'알림톡 발송이 꺼져 있습니다.':'문자 발송이 꺼져 있습니다.')}
    }
  }
  forms.forEach(function(form){
    form.addEventListener('submit',async function(event){
      event.preventDefault();if(busy){return}
      var button=event.submitter||form.querySelector('button[type="submit"]');
      if(!button||!button.dataset.action){return}
      var wantOn=button.dataset.action==='enable';
      busy=true;
      result.setAttribute('aria-busy','true');
      message('발송 설정을 변경하고 있습니다.','alert-info');
      var buttons=Array.from(document.querySelectorAll('form[data-channel-toggle] button[data-action]'));
      var oldDisabled=buttons.map(function(b){return b.disabled});
      buttons.forEach(function(b){b.disabled=true});
      var updated=false;
      try{
        var body=new URLSearchParams();
        body.set('csrf_token',form.querySelector('input[name="csrf_token"]').value);
        body.set('channel',form.querySelector('input[name="channel"]').value);
        body.set('action',button.dataset.action);
        // name="action" 인 제출 버튼은 브라우저에서 form.action 속성을 가릴 수 있다.
        // 폼의 HTML 속성을 직접 읽어야 URL 대신 버튼 객체가 넘어가는 일을 막는다.
        var response=await fetch(form.getAttribute('action').split('#')[0],{
          method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
          body:body.toString()
        });
        var type=response.headers.get('Content-Type')||'';
        if(type.indexOf('application/json')===-1){
          if(response.redirected){window.location.assign(response.url);return}
          throw new Error('서버가 JSON으로 응답하지 않았습니다. (HTTP '+response.status+')');
        }
        var data;
        try{data=await response.json()}catch(error){
          throw new Error('서버 응답을 읽지 못했습니다. (HTTP '+response.status+')');
        }
        if(!response.ok||!data.ok){
          var error=data.error&&data.error.message;
          throw new Error(typeof data.message==='string'?data.message:
            (typeof error==='string'?error:'발송 설정을 바꾸지 못했습니다. 다시 시도해 주세요.'));
        }
        if(data.status&&typeof data.status.sms_switch_on!=='boolean'&&typeof data.status.sms_enabled==='boolean'){
          data.status.sms_switch_on=data.status.sms_enabled;
          data.status.alimtalk_switch_on=data.status.alimtalk_enabled;
        }
        if(!data.status||typeof data.status.sms_switch_on!=='boolean'||typeof data.status.alimtalk_switch_on!=='boolean'){
          throw new Error('서버가 채널 상태를 보내지 않았습니다. (HTTP '+response.status+')');
        }
        renderStatus(data.status);updated=true;
        message(data.notice&&typeof data.notice.message==='string'?data.notice.message:'설정을 저장했습니다.',
          data.notice&&data.notice.ok===false?'alert-warning':'alert-success');
        var url=new URL(window.location.href);
        ['saved','aligo_saved','cancel_ok','cancel_failed','cancel_unknown'].forEach(function(key){url.searchParams.delete(key)});
        try{window.history.replaceState(null,'',url.pathname+url.search+url.hash)}catch(error){}
      }catch(error){
        // 요청이 서버에 도착한 뒤 응답만 끊길 수도 있다. 이전 배지를 그대로 둔 채
        // 실패라고 단정하지 말고, 저장된 스위치를 다시 읽어 화면을 맞춘다.
        try{
          var check=await fetch(result.dataset.channelStatusUrl,{
            method:'GET',credentials:'same-origin',cache:'no-store',
            headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
          });
          if(!check.ok){throw new Error('상태 조회 실패')}
          var actual=await check.json();
          if(!actual.ok||!actual.status||typeof actual.status.sms_switch_on!=='boolean'||
             typeof actual.status.alimtalk_switch_on!=='boolean'){
            throw new Error('상태 응답 오류');
          }
          renderStatus(actual.status);updated=true;
          var channel=form.querySelector('input[name="channel"]').value;
          var on=channel==='sms'?actual.status.sms_switch_on:actual.status.alimtalk_switch_on;
          if(on===wantOn){
            message(wantOn?'현재 발송이 켜져 있습니다.':
              '현재 발송이 꺼져 있습니다. 예약 발송 취소 결과는 발송 이력에서 확인해 주세요.',
              wantOn?'alert-success':'alert-warning');
          }else{
            message(error instanceof Error&&error.message.indexOf('(HTTP ')===-1
              ?error.message:'설정이 변경되지 않았습니다. 다시 시도해 주세요.','alert-error');
          }
        }catch(checkError){
          message('설정 반영 여부를 확인하지 못했습니다. 새로 고쳐 현재 상태를 확인해 주세요.','alert-error');
        }
      }
      finally{
        if(!updated){buttons.forEach(function(b,i){b.disabled=oldDisabled[i]})}
        result.removeAttribute('aria-busy');
        busy=false;
      }
    });
  });
})();
</script>
<script>
(function(){
  // 가린 칸 보기·숨기기. 값은 칸에 그대로 있으므로 입력상자의 종류만 바꾼다.
  document.querySelectorAll('[data-mask-toggle]').forEach(function(btn){
    var box=btn.closest('label'),field=box?box.querySelector('input'):null;if(!field){return}
    var name=btn.dataset.maskToggle;
    btn.addEventListener('click',function(){
      var show=field.type!=='text',label=name+(show?' 숨기기':' 표시');
      field.type=show?'text':'password';btn.setAttribute('aria-pressed',show?'true':'false');
      btn.setAttribute('aria-label',label);btn.title=label;field.focus();
    });
  });
})();
</script>
<script>
(function(){
  // 저장된 API 키 보기. 페이지에는 키가 없고(소스 보기에 나오면 안 된다), 눈 버튼을 눌렀을
  // 때만 POST 로 받아 채운다. 받아 온 뒤에는 api_key_loaded 를 1 로 두어 그 상태에서 칸을
  // 비우고 저장하면 삭제가 된다. 손대지 않은 채 다시 가리면 값을 비우고 표시도 0 으로
  // 되돌린다 — 그 빈 칸은 "기존 키 유지"라서 저장해도 바뀌지 않고, 화면에 원본이 남지도 않는다.
  var btn=document.querySelector('[data-aligo-key-toggle]');if(!btn){return}
  var box=btn.closest('label'),field=box?box.querySelector('input'):null;
  var loaded=document.getElementById('aligo-api-key-loaded');if(!field||!loaded){return}
  var revealedStored=false,loading=false;
  function state(show){
    var label=show?'API 키 숨기기':'API 키 표시';
    field.type=show?'text':'password';btn.setAttribute('aria-pressed',show?'true':'false');
    btn.setAttribute('aria-label',label);btn.title=label;
  }
  field.addEventListener('input',function(){revealedStored=false});
  btn.addEventListener('click',async function(){
    if(field.type==='text'){
      state(false);if(revealedStored){field.value='';loaded.value='0';revealedStored=false}field.focus();return;
    }
    if(field.value!==''||btn.dataset.keySet!=='1'){state(true);field.focus();return}
    if(loading){return}loading=true;btn.disabled=true;
    try{
      var body=new URLSearchParams({csrf_token:btn.dataset.csrf});
      var res=await fetch(btn.dataset.keyUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
      if(!res.ok){throw new Error('request failed')}
      var data=await res.json();field.value=typeof data.api_key==='string'?data.api_key:'';
      loaded.value='1';revealedStored=true;state(true);field.focus();field.setSelectionRange(field.value.length,field.value.length);
    }catch(e){window.alert('API 키를 불러오지 못했습니다. 다시 로그인한 뒤 시도해 주세요.')}
    finally{loading=false;btn.disabled=false}
  });
})();
</script>
