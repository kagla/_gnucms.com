(function(){
  'use strict';
  var root=document.querySelector('.yc-admin-page');
  if(!root){return;}
  var shell=root.closest('.admin-content'),siteLink=shell&&shell.querySelector('.navbar-end>a');
  if(siteLink){siteLink.title=siteLink.textContent.trim();}
  // 누른 제출 단추의 동작. event.submitter 가 없는 엔진(Safari 15.4 이전)에서는 초점과 폼 안을 뒤지고, 그래도 모르면 빈 문자열이다.
  function submitAction(event,form){
    var button=event.submitter
      ||(document.activeElement&&document.activeElement.form===form&&document.activeElement.type==='submit'?document.activeElement:null)
      ||form.querySelector('button[type=submit][name=action]');
    return button?button.value:'';
  }
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
  // 선택 상품 일괄 작업: 누른 단추가 동작을 정한다(삭제는 확인, 분류 넣기·빼기는 분류를 먼저 고르게 한다).
  var selection=root.querySelector('[data-yc-selection-form]');
  if(selection){
    selection.addEventListener('submit',function(event){
      // 분류 선택 상자는 폼 밖에 있고 form 속성으로 이어져 있다 — elements 로 찾는다.
      var action=submitAction(event,selection),category=selection.elements.category;
      if(action==='categorize'||action==='uncategorize'){
        if(category&&!category.value){event.preventDefault();alert('분류를 먼저 고르세요.');}
        return;
      }
      // 어떤 단추인지 알아내지 못하면 삭제로 보고 묻는다 — 묻지 않고 지우는 것보다 낫다.
      if(!confirm('선택한 상품을 삭제할까요? 이미지·옵션도 함께 지워집니다.')){event.preventDefault();}
    });
  }
  // 한 줄짜리 삭제 폼의 확인 문구는 data-yc-confirm 속성에서 온다 — 분류명 같은 데이터를 JS 문자열에 끼워 넣지 않는다.
  root.addEventListener('submit',function(event){
    var message=event.target.getAttribute('data-yc-confirm');
    if(message&&!confirm(message)){event.preventDefault();}
  });
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
    editForm.addEventListener('click',function(event){if(event.target.closest('[data-yc-copy-down],[data-yc-move],[data-yc-remove-relation],[data-yc-add-extra],[data-yc-add-category],[data-yc-remove-category],[data-yc-add-main-category],[data-yc-remove-main-category]')){dirty();}});
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
  var bannerMode=root.querySelector('[data-yc-banner-mode]');
  if(bannerMode){
    function showBannerFields(){root.querySelectorAll('[data-yc-banner-panel]').forEach(function(panel){panel.hidden=panel.dataset.ycBannerPanel!==bannerMode.value;});}
    bannerMode.addEventListener('change',showBannerFields);showBannerFields();
  }
  // 묶음의 기준(자동/분류 선택)이 자동일 때는 기준 분류 선택을 꺼서 제출되지 않게 한다.
  root.querySelectorAll('select[name$="_source"]').forEach(function(source){
    var form=source.form,target=form&&form.elements[source.name.replace(/_source$/,'_source_category_id')];
    if(!target){return;}
    function sync(){target.disabled=source.value!=='category';}
    source.addEventListener('change',sync);sync();
  });
  var errors=root.querySelector('[data-yc-errors]');
  if(errors){
    errors.querySelectorAll('[data-yc-error-field]').forEach(function(message){
      var name=message.dataset.ycErrorField;
      var input=[].slice.call(root.querySelectorAll('input:not([type=hidden]),select,textarea')).find(function(field){return field.name===name;});
      if(input){input.setAttribute('aria-invalid','true');input.setAttribute('aria-describedby','yc-errors');reveal(input);}
    });
    errors.focus();
  }
  // 설정 화면의 메인 분류 블록 줄. 틀을 복제해 name 속성의 줄 번호만 바꾼다(데이터를 문자열로 잇지 않는다).
  function mainCategoryTarget(event,selector){return event.target&&event.target.closest?event.target.closest(selector):null;}
  function renumberMainCategories(list){
    [].slice.call(list.children).forEach(function(row,index){
      [].slice.call(row.querySelectorAll('[name]')).forEach(function(field){field.name=field.name.replace(/^main_categories\[[^\]]*\]/,'main_categories['+index+']');});
    });
  }
  document.addEventListener('click',function(event){
    if(mainCategoryTarget(event,'[data-yc-add-main-category]')){
      var rows=document.querySelector('[data-yc-main-categories]'),template=document.querySelector('template[data-yc-main-category-row]');
      if(rows&&template){
        rows.appendChild(template.content.firstElementChild.cloneNode(true));
        renumberMainCategories(rows);
        var select=rows.lastElementChild.querySelector('select');if(select){select.focus();}
      }
    }
    var remove=mainCategoryTarget(event,'[data-yc-remove-main-category]');
    if(remove){
      var item=remove.closest('[data-yc-main-category-item]'),list=item&&item.parentElement;
      if(item){item.remove();}
      if(list){renumberMainCategories(list);}
    }
  });
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
    var addCategory=event.target.closest('[data-yc-add-category]');
    if(addCategory){
      var categoryRows=form.querySelector('[data-yc-categories]'),categoryTemplate=form.querySelector('template[data-yc-category-row]');
      if(categoryRows&&categoryTemplate){categoryRows.appendChild(categoryTemplate.content.firstElementChild.cloneNode(true));categoryRows.lastElementChild.querySelector('select').focus();}
    }
    var removeCategory=event.target.closest('[data-yc-remove-category]');
    if(removeCategory){removeCategory.closest('[data-yc-category-row-item]').remove();}
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
