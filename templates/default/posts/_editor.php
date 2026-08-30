<?php
/*
  게시글·댓글 본문 편집기 (CKEditor 4).
  관리자 전용인 admin/_editor.php 와 달리 게시판 권한으로 업로드를 허용한다.

  넘겨야 하는 값
    editor_id     textarea 의 id
    upload_url    이미지 업로드 주소 (csrf_token, image_key 포함)
    discard_url   편집 중 버린 이미지 정리 주소
    editor_mini   true 면 굵게·링크·이미지만 있는 최소 도구모음
*/
// GNUCMS_ID|capitalize: 첫 글자만 대문자, 나머지는 소문자.
$gnucmsCap = mb_strtoupper(mb_substr(GNUCMS_ID, 0, 1)) . mb_strtolower(mb_substr(GNUCMS_ID, 1));
?>
<script src="<?= $this->e($this->base) ?>/vendor/ckeditor4/ckeditor.js"></script>
<script>
(function(){
  var textarea=document.getElementById(<?= $this->json($editor_id) ?>);
  if(!textarea||!window.CKEDITOR){return}
  var root=document.documentElement,media=window.matchMedia('(prefers-color-scheme: dark)');
  var uploadUrl=<?= $this->json($upload_url) ?>,discardUrl=<?= $this->json($discard_url) ?>;
  var uploadedInput=textarea.form&&textarea.form.querySelector('[data-uploaded-images]'),uploaded={},committed=false,discarded=false;

  (uploadedInput&&uploadedInput.value?uploadedInput.value.split(','):[]).forEach(function(f){
    if(/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/.test(f)){uploaded[f]=true}
  });
  function remember(url){
    var m=String(url||'').match(/\/media\/editor\/[a-f0-9]{32}\/([a-f0-9]{32}\.(?:jpg|png|gif|webp))(?:[?#]|$)/i);
    if(!m){return}
    uploaded[m[1].toLowerCase()]=true;
    if(uploadedInput){uploadedInput.value=Object.keys(uploaded).join(',')}
  }
  /* 글을 저장하지 않고 떠나면 그때까지 올린 이미지는 쓰레기가 된다. 떠날 때 정리한다. */
  function discard(){
    if(committed||discarded){return}
    var files=Object.keys(uploaded);
    if(!files.length){return}
    discarded=true;
    var body=new URLSearchParams();
    files.forEach(function(f){body.append('files[]',f)});
    if(navigator.sendBeacon&&navigator.sendBeacon(discardUrl,body)){return}
    fetch(discardUrl,{method:'POST',body:body,credentials:'same-origin',keepalive:true,
      headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}}).catch(function(){});
  }
  function isDark(){return root.dataset.theme==='dark'||(!root.dataset.theme&&media.matches)}
  function syncTheme(editor){
    if(!editor.document){return}
    var body=editor.document.getBody(),html=editor.document.getDocumentElement();
    [body,html].forEach(function(el){
      el.removeClass('<?= $this->e(GNUCMS_ID) ?>-editor-light');el.removeClass('<?= $this->e(GNUCMS_ID) ?>-editor-dark');
      el.addClass(isDark()?'<?= $this->e(GNUCMS_ID) ?>-editor-dark':'<?= $this->e(GNUCMS_ID) ?>-editor-light');
    });
  }

  /* 표준 Image 다이얼로그는 URL 을 직접 넣는 자리다. 사진을 고르는 흐름이 아니라
     파일 선택창에서 여러 장을 골라 바로 본문에 넣는 방식을 쓴다.
     관리자 내용 편집기(admin/_editor.php)와 같은 동작이다. */
  var uploading=0;
  function insertImage(editor,url,name){
    var paragraph=new CKEDITOR.dom.element('p'),image=new CKEDITOR.dom.element('img');
    image.setAttributes({src:url,alt:name});paragraph.append(image);editor.insertElement(paragraph);
  }
  function chooseImages(editor){
    var input=document.createElement('input');
    input.type='file';input.accept='image/jpeg,image/png,image/gif,image/webp';input.multiple=true;input.hidden=true;
    input.addEventListener('change',async function(){
      var files=Array.prototype.slice.call(input.files||[]);input.remove();
      if(!files.length){return}
      var valid=[],rejected=[];
      files.forEach(function(file){
        if(file.size>5*1024*1024){rejected.push(file.name+' (5MB 초과)')}
        else if(file.type&&!/^image\/(?:jpeg|png|gif|webp)$/.test(file.type)){rejected.push(file.name+' (지원하지 않는 형식)')}
        else{valid.push(file)}
      });
      if(!valid.length){editor.showNotification(rejected.join('<br>'),'warning');return}
      uploading++;
      var notice=editor.showNotification('사진 '+valid.length+'장을 올리는 중입니다.','progress',0),failed=[];
      for(var i=0;i<valid.length;i++){
        try{
          var formData=new FormData();formData.append('upload',valid[i],valid[i].name);
          var response=await fetch(uploadUrl,{method:'POST',body:formData,credentials:'same-origin',headers:{Accept:'application/json'}});
          var data=await response.json();
          if(!response.ok||!data.uploaded||!data.url){throw new Error(data.error&&data.error.message?data.error.message:'업로드에 실패했습니다.')}
          remember(data.url);
          editor.focus();insertImage(editor,data.url,valid[i].name);
        }catch(error){failed.push(valid[i].name+': '+(error.message||'업로드 실패'))}
        notice.update({message:'사진 '+valid.length+'장 중 '+(i+1)+'장 처리 중',progress:(i+1)/valid.length});
      }
      uploading--;
      if(rejected.length){failed=failed.concat(rejected)}
      if(failed.length){notice.update({message:'일부 사진을 올리지 못했습니다.<br>'+failed.map(CKEDITOR.tools.htmlEncode).join('<br>'),type:'warning',duration:7000})}
      else{notice.update({message:'사진 '+valid.length+'장을 본문에 넣었습니다.',type:'success',duration:3000})}
    });
    document.body.appendChild(input);input.click();
  }
  if(!CKEDITOR.plugins.get('<?= $this->e(GNUCMS_ID) ?>postimages')){
    CKEDITOR.plugins.add('<?= $this->e(GNUCMS_ID) ?>postimages',{init:function(editor){
      editor.addCommand('<?= $this->e(GNUCMS_ID) ?>PostImages',{exec:function(){chooseImages(editor)}});
      editor.ui.addButton('<?= $this->e($gnucmsCap) ?>Images',{label:'사진 올리기',command:'<?= $this->e(GNUCMS_ID) ?>PostImages',toolbar:'insert,5'});
    }});
  }

  var mini=<?= ($editor_mini ?? false) ? 'true' : 'false' ?>;
  var narrow=window.matchMedia('(max-width: 640px)').matches;
  function build(){
  return CKEDITOR.replace(textarea.id,{
    language:'ko',
    height:mini?160:360,
    versionCheck:false,
    resize_minWidth:0,
    contentsCss:[<?= $this->json($this->base . '/vendor/ckeditor4/contents.css') ?>,<?= $this->json($this->base . '/assets/editor-content.css') ?>],
    bodyClass:'<?= $this->e(GNUCMS_ID) ?>-editor-content',
    uploadUrl:uploadUrl,
    filebrowserImageUploadUrl:uploadUrl+'&responseType=json',
    extraPlugins:'uploadimage,notification,<?= $this->e(GNUCMS_ID) ?>postimages',
    removePlugins:'exportpdf,flash,forms,iframe,newpage,preview,print,save,scayt,sourcearea,templates',
    removeDialogTabs:'image:advanced;link:advanced',
    format_tags:'p;h2;h3;h4;pre',
    /* 엔터가 문단을 만들면 줄 사이가 크게 벌어진다. 글쓰기와 댓글 모두
       엔터를 shift+엔터처럼 줄바꿈(<br>)으로 다룬다. 문단이 필요하면 엔터를 두 번 친다. */
    enterMode: CKEDITOR.ENTER_BR,
    shiftEnterMode: CKEDITOR.ENTER_BR,
    autoParagraph: false,
    /* 댓글은 글자 꾸미기와 사진만. 링크 버튼은 빼고 주소는 그대로 적게 둔다.
       글쓰기는 문단·목록·링크까지 쓰되 '서식 지우기'처럼 헷갈리는 것은 뺐다.
       좁은 화면에서는 도구가 세 줄로 넘쳐 본문이 밀리므로 자주 쓰는 것만 남긴다. */
    toolbar: mini
      ? [
          {name:'basicstyles',items:['Bold','Italic','Underline','Strike']},
          {name:'colors',items:['TextColor','BGColor']},
          {name:'insert',items:['<?= $this->e($gnucmsCap) ?>Images']}
        ]
      : (narrow
        ? [
            {name:'styles',items:['Format']},
            {name:'basicstyles',items:['Bold','Italic','Underline','Strike']},
            {name:'colors',items:['TextColor','BGColor']},
            {name:'insert',items:['<?= $this->e($gnucmsCap) ?>Images']},
            {name:'tools',items:['Maximize']}
          ]
        : [
            {name:'styles',items:['Format']},
            {name:'basicstyles',items:['Bold','Italic','Underline','Strike']},
            {name:'colors',items:['TextColor','BGColor']},
            {name:'paragraph',items:['NumberedList','BulletedList','Blockquote']},
            {name:'links',items:['Link','Unlink']},
            {name:'insert',items:['<?= $this->e($gnucmsCap) ?>Images','HorizontalRule']},
            {name:'history',items:['Undo','Redo']},
            {name:'tools',items:['Maximize']}
          ]),
    /* 기본 팔레트는 60가지라 고르기 어렵다. 게시판에서 쓸 만한 색만 남긴다. */
    colorButton_colors:'000000,434343,666666,999999,CCCCCC,FFFFFF,'
      + 'B91C1C,EA580C,CA8A04,15803D,0369A1,4338CA,7E22CE,BE185D',
    colorButton_enableMore:true
  });
  }
  function wire(e){
    e.on('instanceReady',function(){syncTheme(e)});
    e.on('fileUploadResponse',function(event){
      try{
        var data=JSON.parse(event.data.fileLoader.xhr.responseText||'{}');
        if(data.uploaded&&data.url){remember(data.url)}
      }catch(error){}
    });

    return e;
  }
  var editor=wire(build());

  /* 답글을 쓸 때 폼을 해당 댓글 아래로 옮긴다. CKEditor 는 iframe 이라
     그냥 옮기면 내용이 날아간다. 그래서 껐다가 옮기고 다시 켠다.
     destroy() 가 textarea 에 내용을 되돌려 주므로 쓰던 글은 그대로 남는다. */
  window.<?= $this->e(GNUCMS_ID) ?>Editor=window.<?= $this->e(GNUCMS_ID) ?>Editor||{};
  window.<?= $this->e(GNUCMS_ID) ?>Editor[textarea.id]={
    remount:function(move){
      editor.destroy();
      if(typeof move==='function'){move()}
      editor=wire(build());
    },
    focus:function(){try{editor.focus()}catch(error){}}
  };
  new MutationObserver(function(){syncTheme(editor)}).observe(root,{attributes:true,attributeFilter:['data-theme']});
  if(media.addEventListener){media.addEventListener('change',function(){syncTheme(editor)})}
  window.addEventListener('pagehide',discard);
  if(textarea.form){
    textarea.form.addEventListener('submit',function(event){
      if(uploading){
        event.preventDefault();
        editor.showNotification('사진 업로드가 끝난 뒤 저장해 주세요.','warning');

        return;
      }
      editor.updateElement();
      /* CKEditor 가 textarea 를 숨기므로 브라우저의 "필수" 검사가 동작하지 않는다.
         그래서 required 대신 data-required 를 두고 여기서 직접 확인한다. */
      if(textarea.getAttribute('data-required')){
        var text=textarea.value.replace(/<[^>]*>/g,'').replace(/&nbsp;|\s/g,'');
        if(text===''){
          event.preventDefault();
          window.alert('내용을 입력해 주세요.');
          editor.focus();

          return;
        }
      }
      committed=true;
    });
  }
})();
</script>
