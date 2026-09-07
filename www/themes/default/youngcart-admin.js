(function(){
  'use strict';
  var checkAll=document.querySelector('[data-yc-check-all]');
  if(checkAll){checkAll.addEventListener('change',function(){[].slice.call(document.querySelectorAll('input[name="ids[]"]')).forEach(function(box){box.checked=checkAll.checked;});});}
  var form=document.querySelector('[data-yc-product-form]');
  if(!form){return;}
  // 조합 표: 같은 열의 아래 행에 값 복사
  form.addEventListener('click',function(event){
    var button=event.target.closest('[data-yc-copy-down]');
    if(button){
      var field=button.getAttribute('data-yc-copy-down'),row=button.closest('tr'),value=row.querySelector('input[name$="['+field+']"]').value,next=row.nextElementSibling;
      while(next){var input=next.querySelector('input[name$="['+field+']"]');if(input){input.value=value;}next=next.nextElementSibling;}
    }
    var move=event.target.closest('[data-yc-move]');
    if(move){
      var li=move.closest('li'),list=li.parentNode;
      if(move.getAttribute('data-yc-move')==='up'&&li.previousElementSibling){list.insertBefore(li,li.previousElementSibling);}
      if(move.getAttribute('data-yc-move')==='down'&&li.nextElementSibling){list.insertBefore(li.nextElementSibling,li);}
      var order=[].slice.call(list.querySelectorAll('[data-yc-image]')).map(function(el){return el.getAttribute('data-yc-image');});
      var orderInput=form.querySelector('[data-yc-image-order]');if(orderInput){orderInput.value=order.join(',');}
    }
    var removeRelation=event.target.closest('[data-yc-remove-relation]');
    if(removeRelation){removeRelation.closest('li').remove();syncRelations();}
    var addExtra=event.target.closest('[data-yc-add-extra]');
    if(addExtra){
      var body=form.querySelector('[data-yc-extras] tbody'),rows=body.querySelectorAll('tr'),index=rows.length,template=rows[rows.length-1].cloneNode(true);
      [].slice.call(template.querySelectorAll('input')).forEach(function(input){input.name=input.name.replace(/extras\[\d+\]/,'extras['+index+']');if(input.type==='text'){input.value='';}});
      body.appendChild(template);
    }
  });
  // 상품정보고시 군 전환
  var infoSelect=form.querySelector('[data-yc-info-select]'),infoFields=form.querySelector('[data-yc-info-fields]');
  if(infoSelect&&infoFields){
    var groups={};try{groups=JSON.parse(infoFields.getAttribute('data-yc-info-groups'));}catch(e){}
    infoSelect.addEventListener('change',function(){
      var articles=groups[infoSelect.value]||[];infoFields.innerHTML='';
      articles.forEach(function(article,index){
        var fieldset=document.createElement('fieldset');fieldset.className='fieldset';
        var legend=document.createElement('legend');legend.className='fieldset-legend';legend.textContent=article;fieldset.appendChild(legend);
        var input=document.createElement('input');input.className='input input-bordered input-sm';input.type='text';input.name='info['+index+']';input.maxLength=500;input.placeholder='상품페이지 참고';fieldset.appendChild(input);
        infoFields.appendChild(fieldset);
      });
    });
  }
  // 배송비 유형에 따라 입력칸 표시
  var shippingType=form.querySelector('[data-yc-shipping-type]');
  function toggleShipping(){
    if(!shippingType){return;}var type=shippingType.value;
    var fee=form.querySelector('[name=shipping_fee]'),min=form.querySelector('[name=shipping_free_minimum]'),qty=form.querySelector('[name=shipping_per_qty]');
    if(fee){fee.closest('fieldset').hidden=type==='0'||type==='1';}
    if(min){min.closest('fieldset').hidden=type!=='2';}
    if(qty){qty.closest('fieldset').hidden=type!=='4';}
  }
  if(shippingType){shippingType.addEventListener('change',toggleShipping);toggleShipping();}
  // 관련상품 검색
  var search=form.querySelector('[data-yc-relation-search]'),results=form.querySelector('[data-yc-relation-results]'),relations=form.querySelector('[data-yc-relations]'),ids=form.querySelector('[data-yc-relation-ids]');
  function syncRelations(){if(!relations||!ids){return;}ids.value=[].slice.call(relations.querySelectorAll('[data-yc-relation]')).map(function(el){return el.getAttribute('data-yc-relation');}).join(',');}
  if(search&&results){
    var timer=null;
    search.addEventListener('input',function(){
      clearTimeout(timer);var q=search.value.trim();if(q.length<1){results.innerHTML='';return;}
      timer=setTimeout(function(){
        fetch(search.getAttribute('data-yc-search-url')+'?q='+encodeURIComponent(q)+'&exclude='+encodeURIComponent(search.getAttribute('data-yc-exclude')||''),{credentials:'same-origin',headers:{'Accept':'application/json'}})
          .then(function(r){return r.json();}).then(function(data){
            results.innerHTML='';
            (data.items||[]).forEach(function(item){
              var li=document.createElement('li');var button=document.createElement('button');button.type='button';button.className='btn btn-xs btn-ghost';button.textContent=item.code+' '+item.name+' ('+item.category_name+')';
              button.addEventListener('click',function(){
                if(relations.querySelector('[data-yc-relation="'+item.id+'"]')){return;}
                var row=document.createElement('li');row.setAttribute('data-yc-relation',String(item.id));
                row.innerHTML='<code></code> <span></span> <button class="btn btn-xs" type="button" data-yc-remove-relation>제거</button>';
                row.querySelector('code').textContent=item.code;row.querySelector('span').textContent=item.name;
                relations.appendChild(row);syncRelations();results.innerHTML='';search.value='';
              });
              li.appendChild(button);results.appendChild(li);
            });
          }).catch(function(){results.innerHTML='';});
      },250);
    });
  }
})();
