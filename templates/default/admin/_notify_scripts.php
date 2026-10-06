<script>
(function(){
  // 두 채널의 입력은 한 폼에 둔다. 숨긴 패널도 입력을 유지하고 함께 저장한다.
  document.querySelectorAll('[data-notify-editor]').forEach(function(form){
    var tabs=form.querySelector('[data-notify-editor-tabs]');
    var panels=Array.from(form.querySelectorAll('[data-notify-editor-panel]'));
    var buttons=Array.from(form.querySelectorAll('[data-notify-editor-tab]'));
    var field=form.querySelector('[data-notify-editor-channel]');
    var preview=form.querySelector('[data-notify-editor-preview]');
    var empty=form.querySelector('[data-notify-editor-empty]');
    var mail=form.querySelector('[name="mail"]');
    var phone=form.querySelector('[name="phone"]');
    var active=field.value;
    var available=[];
    var fallback=form.querySelector('[data-notify-preview-fallback]');
    if(fallback){fallback.hidden=true;}
    panels.forEach(function(panel){panel.setAttribute('role','tabpanel');});

    function refresh(requested,focus){
      available=panels.map(function(panel){return panel.getAttribute('data-notify-editor-panel');}).filter(function(channel){
        var checkbox=channel==='mail'?mail:phone;
        var panel=panels.find(function(item){return item.getAttribute('data-notify-editor-panel')===channel;});
        return (checkbox&&checkbox.checked)||panel.hasAttribute('data-notify-panel-error');
      });
      active=available.includes(requested)?requested:(available.includes(active)?active:(available[0]||''));
      field.value=active;
      tabs.hidden=available.length<2;
      empty.hidden=available.length!==0;
      buttons.forEach(function(button){
        var channel=button.getAttribute('data-notify-editor-tab');
        var selected=channel===active;
        button.hidden=!available.includes(channel);
        button.classList.toggle('tab-active',selected);
        button.setAttribute('aria-selected',selected?'true':'false');
        button.tabIndex=selected?0:-1;
        if(focus&&selected){button.focus();}
      });
      panels.forEach(function(panel){
        panel.hidden=panel.getAttribute('data-notify-editor-panel')!==active;
        panel.querySelector('[data-notify-editor-heading]').hidden=available.length>1;
      });
      preview.disabled=available.length===0;
      preview.setAttribute('formaction',preview.getAttribute(active==='phone'?'data-phone-preview-url':'data-mail-preview-url'));
      preview.setAttribute('aria-label',active==='phone'?'문자 미리보기':'메일 미리보기');
    }
    buttons.forEach(function(button){
      button.addEventListener('click',function(){refresh(button.getAttribute('data-notify-editor-tab'),false);});
      button.addEventListener('keydown',function(event){
        var index=available.indexOf(active),next;
        if(event.key==='ArrowRight'){next=available[(index+1)%available.length];}
        else if(event.key==='ArrowLeft'){next=available[(index+available.length-1)%available.length];}
        else if(event.key==='Home'){next=available[0];}
        else if(event.key==='End'){next=available[available.length-1];}
        else{return;}
        event.preventDefault();refresh(next,true);
      });
    });
    [mail,phone].forEach(function(checkbox){
      if(checkbox){checkbox.addEventListener('change',function(){refresh(active,false);});}
    });
    refresh(active,false);
  });

  // 변수 기본값은 서버가 자동 연결한다. 선택한 템플릿의 예외 연결만 제출한다.
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

  document.querySelectorAll('[data-notify-insert]').forEach(function(button){
    button.addEventListener('click',function(){
      var textarea=button.closest('form').querySelector('[data-notify-sms-body]');
      if(!textarea){return;}
      var start=textarea.selectionStart,end=textarea.selectionEnd,token='#{'+button.getAttribute('data-notify-insert')+'}';
      if(textarea.value.length-(end-start)+token.length>textarea.maxLength){return;}
      textarea.setRangeText(token,start,end,'end');
      textarea.dispatchEvent(new Event('input',{bubbles:true}));textarea.focus();
    });
  });
  document.querySelectorAll('[data-notify-restore]').forEach(function(button){
    button.addEventListener('click',function(){
      var form=button.closest('form'),textarea=form.querySelector('[data-notify-sms-body]'),title=form.querySelector('[data-notify-sms-title]');
      textarea.value=button.getAttribute('data-body')||'';title.value=button.getAttribute('data-title')||'';
      textarea.dispatchEvent(new Event('input',{bubbles:true}));textarea.focus();
    });
  });
  document.querySelectorAll('[data-notify-sms-body],[data-notify-sms-title]').forEach(function(field){
    field.addEventListener('input',function(){var preview=field.closest('form').querySelector('[data-notify-preview]');if(preview){preview.hidden=true;}});
  });
  var search=document.querySelector('[data-notify-search]'),phoneFilter=document.querySelector('[data-notify-phone-filter]');
  document.querySelectorAll('[data-notify-mail-editor]').forEach(function(editor){
    var subject=editor.querySelector('[data-notify-mail-subject]'),body=editor.querySelector('[data-notify-mail-body]'),active=body;
    editor.querySelectorAll('[data-notify-mail-subject],[data-notify-mail-body],[data-notify-mail-sample]').forEach(function(field){
      field.addEventListener('input',function(){var preview=editor.querySelector('[data-notify-mail-preview]');if(preview){preview.hidden=true;}});
      if(field===subject||field===body){field.addEventListener('focus',function(){active=field;});}
    });
    editor.querySelectorAll('[data-notify-mail-insert]').forEach(function(button){
      button.addEventListener('click',function(){
        var token='#{'+button.getAttribute('data-notify-mail-insert')+'}',start=active.selectionStart,end=active.selectionEnd;
        if(active.value.length-(end-start)+token.length>active.maxLength){return;}
        active.setRangeText(token,start,end,'end');active.dispatchEvent(new Event('input',{bubbles:true}));active.focus();
      });
    });
    editor.querySelectorAll('[data-notify-mail-restore]').forEach(function(button){
      button.addEventListener('click',function(){
        subject.value=button.getAttribute('data-subject')||'';body.value=button.getAttribute('data-body')||'';
        body.dispatchEvent(new Event('input',{bubbles:true}));body.focus();
      });
    });
  });
  function filterEvents(){
    var terms=search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean),visible=0;
    document.querySelectorAll('[data-notify-event]').forEach(function(item){
      var text=(item.querySelector('summary').textContent+' '+(item.getAttribute('data-notify-search-keywords')||'')).toLocaleLowerCase().replace(/\s+/g,'');
      item.hidden=(phoneFilter.checked&&item.getAttribute('data-phone')!=='1')||!terms.every(function(term){return text.includes(term);});
      if(!item.hidden){visible++;}
    });
    document.querySelector('[data-notify-empty]').hidden=visible!==0;
  }
  if(search&&phoneFilter){search.addEventListener('input',filterEvents);phoneFilter.addEventListener('change',filterEvents);}

  // 폼을 옮겨 같은 입력과 검증 결과를 모달에서 보여 준다. dialog 를 지원하지 않거나
  // 자바스크립트가 꺼진 환경에서는 원래 details 안의 폼을 그대로 쓸 수 있다.
  var modal=document.querySelector('[data-notify-modal]');
  if (!modal || typeof modal.showModal!=='function') { return; }
  var title=modal.querySelector('#notify-settings-modal-title');
  var body=modal.querySelector('[data-notify-modal-body]');
  var activeEvent=null;
  var activeForm=null;
  var previewModal=document.querySelector('[data-notify-result-modal]');
  var previewBody=previewModal&&previewModal.querySelector('[data-notify-result-body]');
  var previewTitle=previewModal&&previewModal.querySelector('#notify-result-title');
  var activePreview=null;
  var previewSlot=null;

  function openPreview(form){
    var preview=Array.from(form.querySelectorAll('[data-notify-preview],[data-notify-mail-preview]')).find(function(result){return !result.hidden;});
    if(!preview||!previewModal||typeof previewModal.showModal!=='function'){return;}
    previewSlot=document.createElement('span');
    previewSlot.hidden=true;
    preview.before(previewSlot);
    activePreview=preview;
    previewTitle.textContent=activeEvent.querySelector('.notify-event-name').textContent+(preview.hasAttribute('data-notify-mail-preview')?' 메일':' 문자')+' 미리보기';
    previewBody.appendChild(preview);
    previewModal.showModal();
    previewTitle.focus();
  }
  if(previewModal){
    previewModal.querySelector('[data-notify-result-close]').addEventListener('click',function(){previewModal.close()});
    previewModal.addEventListener('close',function(){
      if(activePreview&&previewSlot){
        activePreview.hidden=true;
        previewSlot.replaceWith(activePreview);
      }
      activePreview=null;
      previewSlot=null;
      var button=activeForm&&activeForm.querySelector('[data-notify-editor-preview]');
      if(button){button.focus();}
    });
  }

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
    openPreview(form);
  }

  document.querySelectorAll('[data-notify-event]').forEach(function(item){
    item.querySelector('summary').addEventListener('click',function(event){
      event.preventDefault();
      // 문구 영역은 스크롤·선택·복사를 할 수 있도록 편집 창을 열지 않는다.
      if(event.target.closest('[data-notify-body-preview]')){return;}
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
