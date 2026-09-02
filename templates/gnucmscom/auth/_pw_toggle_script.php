<script>
(function(){
  /* 비밀번호를 잠깐 눈으로 확인하는 단추.
     스크립트가 없으면 단추 자체가 나오지 않고 칸은 평소대로 가려진 채 남는다. */
  var btns=document.querySelectorAll('[data-pw-toggle]');
  for(var i=0;i<btns.length;i++){
    (function(btn){
      var box=btn.closest('label'),field=box?box.querySelector('input'):null;
      if(!field){return}
      btn.hidden=false;
      btn.addEventListener('click',function(){
        var show=field.type==='password',name=btn.dataset.pwLabel||'비밀번호';
        var label=name+(show?' 숨기기':' 표시');
        field.type=show?'text':'password';
        btn.setAttribute('aria-pressed',show?'true':'false');
        btn.setAttribute('aria-label',label);
        btn.title=label;
        /* 눌러도 커서는 입력칸에 남는다. 끝으로 보내 이어서 칠 수 있게 한다. */
        var end=field.value.length;
        field.focus();
        try{field.setSelectionRange(end,end)}catch(e){}
      });
    })(btns[i]);
  }
})();
</script>
