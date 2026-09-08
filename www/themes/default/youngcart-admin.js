(function(){
  'use strict';
  var root=document.querySelector('.yc-admin-page');
  if(!root){return;}
  var shell=root.closest('.admin-content'),siteLink=shell&&shell.querySelector('.navbar-end>a');
  if(siteLink){siteLink.title=siteLink.textContent.trim();}
  var checkAll=document.querySelector('[data-yc-check-all]');
  if(checkAll){
    var boxes=[].slice.call(root.querySelectorAll('input[name="ids[]"]'));
    var counter=root.querySelector('[data-yc-selection-count]'),deleteButton=root.querySelector('[data-yc-delete-selected]');
    function syncSelection(){
      var count=boxes.filter(function(box){return box.checked;}).length;
      checkAll.checked=boxes.length>0&&count===boxes.length;checkAll.indeterminate=count>0&&count<boxes.length;
      if(counter){counter.textContent=count+'개 선택';}
      if(deleteButton){deleteButton.disabled=count===0;}
    }
    checkAll.addEventListener('change',function(){boxes.forEach(function(box){box.checked=checkAll.checked;});syncSelection();});
    boxes.forEach(function(box){box.addEventListener('change',syncSelection);});syncSelection();
  }
  // Anchors and form submissions still work without this progressive enhancement.
  function reveal(target){
    for(var parent=target;parent&&parent!==root;parent=parent.parentElement){if(parent.tagName==='DETAILS'){parent.open=true;}}
  }
  var formNav=root.querySelector('[data-yc-form-nav]');
  if(formNav){
    var links=[].slice.call(formNav.querySelectorAll('a[href^="#"]'));
    links.forEach(function(link){link.addEventListener('click',function(){var section=document.getElementById(link.hash.slice(1));if(section){reveal(section);}});});
    if(location.hash){var initial=document.getElementById(location.hash.slice(1));if(initial){reveal(initial);initial.scrollIntoView();}}
    if('IntersectionObserver' in window){
      var visible=new Set();
      var observer=new IntersectionObserver(function(entries){
        entries.forEach(function(entry){if(entry.isIntersecting){visible.add(entry.target.id);}else{visible.delete(entry.target.id);}});
        var active=links.find(function(link){return visible.has(link.hash.slice(1));});
        if(active){links.forEach(function(link){if(link===active){link.setAttribute('aria-current','location');}else{link.removeAttribute('aria-current');}});}
      },{rootMargin:'-140px 0px -50% 0px'});
      links.forEach(function(link){var section=document.getElementById(link.hash.slice(1));if(section){observer.observe(section);}});
    }
  }
  root.querySelectorAll('.yc-edit-form').forEach(function(editForm){
    var saveStatus=editForm.querySelector('[data-yc-save-status]');
    function dirty(){if(saveStatus){saveStatus.textContent='저장하지 않은 변경사항이 있습니다.';saveStatus.dataset.dirty='true';}}
    editForm.addEventListener('input',dirty);editForm.addEventListener('change',dirty);
    editForm.addEventListener('click',function(event){if(event.target.closest('[data-yc-copy-down],[data-yc-move],[data-yc-remove-relation],[data-yc-add-extra]')){dirty();}});
    editForm.addEventListener('invalid',function(event){reveal(event.target);},true);
  });
  // Give existing compact fieldsets and tables unambiguous accessible input names.
  function labelFields(container){
    container.querySelectorAll('input:not([type=hidden]),select,textarea').forEach(function(input){
      if(input.labels.length||input.hasAttribute('aria-label')||input.hasAttribute('aria-labelledby')){return;}
      var fieldset=input.closest('fieldset'),legend=fieldset&&fieldset.querySelector('legend');
      if(legend){input.setAttribute('aria-label',legend.textContent.trim()+(input.placeholder?' · '+input.placeholder:''));return;}
      var cell=input.closest('td'),row=cell&&cell.parentElement,table=cell&&cell.closest('table');
      if(table&&table.tHead){var header=table.tHead.rows[0].cells[cell.cellIndex];if(header){input.setAttribute('aria-label',row.cells[0].textContent.trim()+' '+header.textContent.trim());}}
    });
  }
  labelFields(root);
  var errors=root.querySelector('[data-yc-errors]');
  if(errors){
    errors.querySelectorAll('[data-yc-error-field]').forEach(function(message){
      var name=message.dataset.ycErrorField;
      var input=[].slice.call(root.querySelectorAll('input:not([type=hidden]),select,textarea')).find(function(field){return field.name===name;});
      if(input){input.setAttribute('aria-invalid','true');input.setAttribute('aria-describedby','yc-errors');reveal(input);}
    });
    errors.focus();
  }
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
      labelFields(body);
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
      labelFields(infoFields);
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
  function syncRelations(){if(!relations||!ids){return;}ids.value=[].slice.call(relations.querySelectorAll('[data-yc-relation]')).map(function(el){return el.getAttribute('data-yc-relation');}).join(',');ids.dispatchEvent(new Event('input',{bubbles:true}));}
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
