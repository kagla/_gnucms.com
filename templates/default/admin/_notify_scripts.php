<script>
(function(){
  // 템플릿을 바꾸면 그 템플릿의 변수 연결 칸만 보이고 제출된다. 자바스크립트가 없어도
  // 화면은 그대로 쓸 수 있다 — 다른 템플릿을 고르고 저장하면 저장이 "변수를 모두 골라
  // 주세요"로 거절하고, 그 되보여주기에서 새 템플릿의 칸이 나온다.
  document.querySelectorAll('[data-notify-tpl]').forEach(function(select){
    var event=select.getAttribute('data-notify-tpl');
    var blocks=document.querySelectorAll('[data-notify-tpl-for="'+event+'"]');
    select.addEventListener('change',function(){
      blocks.forEach(function(block){
        var on=block.getAttribute('data-tpl-code')===select.value;
        block.hidden=!on;
        block.querySelectorAll('select').forEach(function(field){field.disabled=!on});
      });
    });
  });

  // 저장된 본문이 없거나 직접 고친 뒤에도 이벤트별 기본 문구로 바로 되돌릴 수 있다.
  // EUC-KR 문자 한 글자는 2바이트, ASCII는 1바이트이므로 기본 문구를 넣은 직후 화면의
  // SMS/LMS 안내도 함께 고친다. 최종 검증은 저장할 때 서버가 다시 수행한다.
  document.querySelectorAll('[data-notify-sms-default]').forEach(function(button){
    button.addEventListener('click',function(){
      var fieldset=button.closest('fieldset');
      var textarea=fieldset&&fieldset.querySelector('[data-notify-sms-body]');
      if(!textarea){return;}
      textarea.value=button.getAttribute('data-default-body')||'';
      textarea.dispatchEvent(new Event('input',{bubbles:true}));
      textarea.focus();
    });
  });
  document.querySelectorAll('[data-notify-sms-body]').forEach(function(textarea){
    textarea.addEventListener('input',function(){
      var fieldset=textarea.closest('fieldset');
      var count=fieldset&&fieldset.querySelector('[data-notify-sms-count]');
      if(!count){return;}
      var bytes=Array.from(textarea.value).reduce(function(total,char){
        return total+(char.codePointAt(0)<=127?1:2);
      },0);
      var bytesNode=count.querySelector('[data-notify-sms-bytes]');
      if(bytesNode){bytesNode.textContent=bytes.toLocaleString('ko-KR')}
    });
  });

  // 폼을 옮겨 같은 입력과 검증 결과를 모달에서 보여 준다. dialog 를 지원하지 않거나
  // 자바스크립트가 꺼진 환경에서는 원래 details 안의 폼을 그대로 쓸 수 있다.
  var modal=document.querySelector('[data-notify-modal]');
  if (!modal || typeof modal.showModal!=='function') { return; }
  var title=modal.querySelector('#notify-settings-modal-title');
  var body=modal.querySelector('[data-notify-modal-body]');
  var activeEvent=null;
  var activeForm=null;

  function openEvent(item){
    if (modal.open) { return; }
    var form=item.querySelector('form');
    if (!form) { return; }
    item.open=false;
    title.textContent=item.querySelector('.notify-event-name').textContent+' 설정';
    body.appendChild(form);
    activeEvent=item;
    activeForm=form;
    modal.showModal();
    title.focus();
  }

  document.querySelectorAll('[data-notify-event]').forEach(function(item){
    item.querySelector('summary').addEventListener('click',function(event){
      event.preventDefault();
      openEvent(item);
    });
  });

  modal.querySelector('[data-notify-modal-close]').addEventListener('click',function(){modal.close()});
  modal.addEventListener('close',function(){
    if (!activeEvent || !activeForm) { return; }
    activeEvent.appendChild(activeForm);
    activeEvent.open=false;
    activeEvent.querySelector('summary').focus();
    activeEvent=null;
    activeForm=null;
  });

  var failed=document.querySelector('[data-notify-reopen]');
  if (failed) { openEvent(failed); }
})();
</script>
