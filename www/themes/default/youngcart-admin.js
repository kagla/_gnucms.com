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
    editForm.addEventListener('click',function(event){if(event.target.closest('[data-yc-copy-down],[data-yc-move],[data-yc-remove-relation],[data-yc-add-extra],[data-yc-remove-extra],[data-yc-add-category],[data-yc-remove-category],[data-yc-add-main-category],[data-yc-remove-main-category]')){dirty();}});
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
  // 영카트처럼 서버가 만든 옵션 목록만 바꾼다. 상품 본문·이미지는 전송하지 않는다.
  var combine=form.querySelector('[data-yc-combine]'),combinations=form.querySelector('[data-yc-combinations]'),combineStatus=form.querySelector('[data-yc-combine-status]');
  if(combine&&combinations&&combineStatus&&window.fetch){
    var combining=false;
    function combinationMessage(message,error){
      combineStatus.textContent=message;combineStatus.className=error?'text-error':'muted';combineStatus.hidden=false;
    }
    function syncOptionStock(){
      var hasOptions=!!combinations.querySelector('[data-yc-combos] tbody tr'),note=form.querySelector('[data-yc-option-stock-note]');
      ['stock','stock_alert'].forEach(function(name){
        var input=form.elements[name];if(!input){return;}
        input.readOnly=hasOptions;
        if(hasOptions){input.title='선택옵션이 있는 상품은 조합별 재고를 씁니다';}else{input.removeAttribute('title');}
      });
      if(note){note.hidden=!hasOptions;}
    }
    // JS가 없으면 기존 POST를 쓰고, JS가 있으면 편집기 등의 폼 제출 처리도 실행하지 않는다.
    combine.type='button';
    form.addEventListener('submit',function(event){if(combining){event.preventDefault();}});
    combine.addEventListener('click',function(){
      if(combining){return;}
      // 많은 조합도 PHP의 max_input_vars에 잘리지 않도록 JSON으로 보낸다.
      var body={action:'combine',option_group:{},option_values:{},options:{}};
      [].slice.call(form.elements).forEach(function(input){
        if(input.disabled||(/^(checkbox|radio)$/.test(input.type)&&!input.checked)){return;}
        if(input.name==='csrf_token'||input.name==='id'){body[input.name]=input.value;return;}
        var group=/^(option_group|option_values)\[(\d+)\]$/.exec(input.name),row=/^options\[(\d+)\]\[(value[123]|price|stock|stock_alert|active)\]$/.exec(input.name);
        if(group){body[group[1]][group[2]]=input.value;}
        if(row){if(!body.options[row[1]]){body.options[row[1]]={};}body.options[row[1]][row[2]]=input.value;}
      });
      // 요청 중 옵션 편집·중복 생성·저장이 서로 덮어쓰지 않게 잠시 막는다.
      var locked=[].slice.call(form.querySelectorAll('#section-options input,#section-options button,button[type=submit]')).filter(function(input){return !input.disabled;});
      locked.forEach(function(input){input.disabled=true;});
      combining=true;combinations.setAttribute('aria-busy','true');combinationMessage('조합을 생성하고 있습니다.',false);
      // name="action" 입력 요소가 form.action을 가리므로 HTML 속성에서 주소를 읽는다.
      fetch(form.getAttribute('action'),{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify(body)})
        .then(function(response){
          return response.json().then(function(data){
            if(!response.ok){throw new Error(data.error&&data.error.message||'조합을 생성하지 못했습니다. 다시 시도해 주세요.');}
            if(typeof data.html!=='string'||typeof data.message!=='string'){throw new Error('조합 응답을 확인할 수 없습니다. 다시 시도해 주세요.');}
            return data;
          });
        })
        .then(function(data){
          combinations.innerHTML=data.html;labelFields(combinations);syncOptionStock();
          combinationMessage(data.message,false);
          form.dispatchEvent(new Event('input',{bubbles:true}));
        })
        .catch(function(error){combinationMessage(error instanceof SyntaxError||error instanceof TypeError?'조합을 생성하지 못했습니다. 연결 상태를 확인하고 다시 시도해 주세요.':error.message,true);})
        .finally(function(){locked.forEach(function(input){input.disabled=false;});combining=false;combinations.removeAttribute('aria-busy');});
    });
  }
  function renumberExtras(body){
    [].slice.call(body.rows).forEach(function(row,index){row.querySelectorAll('input[name]').forEach(function(input){input.name=input.name.replace(/extras\[\d+\]/,'extras['+index+']');});});
  }
  // 손잡이에서만 정렬을 시작하고, 실제 입력 행을 옮겨 작성 중인 값을 유지한다.
  var extraBody=form.querySelector('[data-yc-extras] tbody'),extraDrag=null;
  if(extraBody){
    if(window.Sortable){
      new Sortable(extraBody,{
        draggable:'tr',handle:'[data-yc-extra-drag]',direction:'vertical',
        animation:window.matchMedia('(prefers-reduced-motion: reduce)').matches?0:180,
        easing:'cubic-bezier(.2,0,0,1)',forceFallback:true,fallbackTolerance:4,
        ghostClass:'yc-extra-placeholder',chosenClass:'yc-extra-chosen',fallbackClass:'yc-extra-floating',
        scrollSensitivity:60,scrollSpeed:10,
        onChoose:function(event){
          extraDrag={order:[].slice.call(extraBody.rows),cancel:false};
          event.item.querySelector('[data-yc-extra-drag]').focus({preventScroll:true});
        },
        onStart:function(event){
          // 떠 있는 복제 행은 표의 열 너비를 유지하되 폼 제출·접근성 트리에서 제외한다.
          var ghost=Sortable.ghost;
          ghost.setAttribute('aria-hidden','true');ghost.style.opacity='1';
          [].slice.call(event.item.cells).forEach(function(cell,index){ghost.cells[index].style.width=cell.getBoundingClientRect().width+'px';});
          ghost.querySelectorAll('input,button').forEach(function(input){input.disabled=true;input.removeAttribute('name');});
          ghost.querySelectorAll('[id]').forEach(function(element){element.removeAttribute('id');});
        },
        onMove:function(){if(extraDrag&&extraDrag.cancel){return false;}},
        onEnd:function(event){
          var drag=extraDrag;extraDrag=null;
          if(drag.cancel||/cancel$/.test(event.originalEvent&&event.originalEvent.type)){
            drag.order.forEach(function(row){extraBody.appendChild(row);});
          }
          renumberExtras(extraBody);
          event.item.querySelector('[data-yc-extra-drag]').focus({preventScroll:true});
          if(drag.order.some(function(row,index){return extraBody.rows[index]!==row;})){
            form.dispatchEvent(new Event('input',{bubbles:true}));
          }
        },
        onUnchoose:function(){if(!Sortable.active){extraDrag=null;}}
      });
      // DOM 이동 중 포커스가 풀려도 Escape를 받을 수 있도록 문서에서 처리한다.
      document.addEventListener('keydown',function(event){
        if(extraDrag&&event.key==='Escape'){event.preventDefault();extraDrag.cancel=true;}
      });
    }
    extraBody.addEventListener('keydown',function(event){
      if(extraDrag){return;}
      var handle=event.target.closest('[data-yc-extra-drag]');
      if(!handle||!['ArrowUp','ArrowDown'].includes(event.key)){return;}
      event.preventDefault();
      var row=handle.closest('tr'),target=event.key==='ArrowUp'?row.previousElementSibling:row.nextElementSibling;
      if(!target){return;}
      extraBody.insertBefore(row,event.key==='ArrowUp'?target:target.nextElementSibling);
      renumberExtras(extraBody);handle.focus();form.dispatchEvent(new Event('input',{bubbles:true}));
    });
  }
  // 선택·추가옵션 표: 같은 열의 아래 행에 값 복사
  form.addEventListener('click',function(event){
    var button=event.target.closest('[data-yc-copy-down]');
    if(button){
      var field=button.getAttribute('data-yc-copy-down'),row=button.closest('tr'),selector='input:not([type=hidden])[name$="['+field+']"]',source=row.querySelector(selector),next=row.nextElementSibling;
      var property=source.type==='checkbox'?'checked':'value';
      while(next){var input=next.querySelector(selector);if(input){input[property]=source[property];}next=next.nextElementSibling;}
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
      var body=form.querySelector('[data-yc-extras] tbody'),extraTemplate=form.querySelector('template[data-yc-extra-row]'),source=extraTemplate?extraTemplate.content.firstElementChild:body.lastElementChild;
      if(source){
        var index=body.rows.length,newRow=source.cloneNode(true);
        newRow.querySelectorAll('input').forEach(function(input){input.name=input.name.replace(/extras\[\d+\]/,'extras['+index+']');if(input.type==='text'){input.value='';}});
        body.appendChild(newRow);labelFields(newRow);newRow.querySelector('input:not([type=hidden])').focus();
      }
    }
    var removeExtra=event.target.closest('[data-yc-remove-extra]');
    if(removeExtra){
      var row=removeExtra.closest('tr'),body=row.parentElement,next=row.nextElementSibling||row.previousElementSibling;
      row.remove();
      renumberExtras(body);
      var focus=next?next.querySelector('input:not([type=hidden])'):form.querySelector('[data-yc-add-extra]');if(focus){focus.focus();}
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
