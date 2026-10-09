<script>
(function(){
  var p=document.querySelector('[data-mail-provider]');if(!p){return}
  var host=document.querySelector('[data-mail-host]'),port=document.querySelector('[data-mail-port]'),enc=document.querySelector('[data-mail-encryption]'),help=document.querySelector('[data-mail-help]');
  var presets={
    gmail:{host:'smtp.gmail.com',port:'465',encryption:'ssl',help:'Google 계정의 2단계 인증을 켠 뒤 16자리 앱 비밀번호를 발급하세요.'},
    naver:{host:'smtp.naver.com',port:'587',encryption:'tls',help:'네이버 메일에서 IMAP/SMTP 사용을 켜고 애플리케이션 비밀번호를 발급하세요.'},
    kakao:{host:'smtp.kakao.com',port:'465',encryption:'ssl',help:'카카오계정에서 2단계 인증을 켜고 앱 비밀번호를 발급한 뒤, 카카오메일 설정 → IMAP/POP3 → IMAP에서 IMAP/SMTP 사용을 켜세요. SMTP 사용자 이름은 @kakao.com 앞의 아이디, 발신 이메일은 해당 아이디의 전체 카카오메일 주소를 입력하세요.'},
    daum:{host:'smtp.daum.net',port:'465',encryption:'ssl',help:'다음 계정에서 앱 비밀번호를 발급하고 SMTP 사용을 켜세요. 발신 이메일은 로그인한 다음 메일 주소를 사용해야 합니다.'}
  };
  function sync(change){
    var s=presets[p.value],custom=!s;
    host.readOnly=!custom;port.readOnly=!custom;enc.disabled=!custom;
    if(change&&s){host.value=s.host;port.value=s.port;enc.value=s.encryption}
    help.textContent=s?s.help:'SMTP 제공업체가 안내한 서버, 포트와 보안 연결을 입력하세요.';
  }
  p.addEventListener('change',function(){sync(true)});
  sync(false);
})();
</script>
<script>
(function(){
  var modes=Array.from(document.querySelectorAll('[data-mail-modes] input[name="mode"]'));
  var box=document.querySelector('[data-mail-smtp-settings]');if(!modes.length||!box){return}
  var requiredNames=['host','port','username','from_email','from_name'];
  function syncMode(){
    var selected=modes.find(function(input){return input.checked});
    var smtp=selected&&selected.value==='smtp';
    box.hidden=!smtp;
    Array.from(box.querySelectorAll('input,select,button')).forEach(function(control){
      control.disabled=!smtp;
      if(requiredNames.indexOf(control.name)!==-1){control.required=!!smtp}
    });
    if(smtp){
      var provider=box.querySelector('[data-mail-provider]');
      var custom=provider&&provider.value==='custom';
      var host=box.querySelector('[data-mail-host]'),port=box.querySelector('[data-mail-port]'),enc=box.querySelector('[data-mail-encryption]');
      if(host){host.readOnly=!custom}if(port){port.readOnly=!custom}if(enc){enc.disabled=!custom}
    }
  }
  modes.forEach(function(input){input.addEventListener('change',syncMode)});
  syncMode();
})();
</script>
<script>
(function(){
  var btn=document.querySelector('[data-mail-password-toggle]');if(!btn){return}
  var box=btn.closest('label'),field=box?box.querySelector('input'):null;if(!field){return}
  var revealedStored=false,loading=false;
  function state(show){
    var label=show?'앱 비밀번호 숨기기':'앱 비밀번호 표시';
    field.type=show?'text':'password';btn.setAttribute('aria-pressed',show?'true':'false');
    btn.setAttribute('aria-label',label);btn.title=label;
  }
  field.addEventListener('input',function(){revealedStored=false});
  btn.addEventListener('click',async function(){
    if(field.type==='text'){
      state(false);if(revealedStored){field.value='';revealedStored=false}field.focus();return;
    }
    if(field.value!==''||btn.dataset.passwordSet!=='1'){state(true);field.focus();return}
    if(loading){return}loading=true;btn.disabled=true;
    try{
      var body=new URLSearchParams({csrf_token:btn.dataset.csrf});
      var res=await fetch(btn.dataset.passwordUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
      if(!res.ok){throw new Error('request failed')}
      var data=await res.json();field.value=typeof data.password==='string'?data.password:'';
      revealedStored=true;state(true);field.focus();field.setSelectionRange(field.value.length,field.value.length);
    }catch(e){window.alert('앱 비밀번호를 불러오지 못했습니다. 다시 로그인한 뒤 시도해 주세요.')}
    finally{loading=false;btn.disabled=false}
  });
})();
</script>
