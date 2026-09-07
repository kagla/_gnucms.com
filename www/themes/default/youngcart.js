(function(){
  'use strict';
  var root=document.querySelector('[data-yc-options]');
  if(root){setupOptions(root);}
  var gallery=document.querySelector('[data-yc-gallery]');
  if(gallery){setupGallery(gallery);}

  function setupOptions(root){
    var data;try{data=JSON.parse(root.getAttribute('data-yc-options'));}catch(e){return;}
    var selects=[].slice.call(root.querySelectorAll('[data-yc-select]'));
    var extras=[].slice.call(root.querySelectorAll('[data-yc-extra]'));
    var list=root.querySelector('[data-yc-selected]');
    var total=root.querySelector('[data-yc-total]');
    var qty=root.querySelector('[data-yc-quantity]');
    var chosen=[];
    function key(values){return values.join('');}
    function fill(level){
      var select=selects[level];if(!select){return;}
      var prefix=[];for(var i=0;i<level;i++){prefix.push(selects[i].value);}
      var seen={},options=[];
      data.select.items.forEach(function(item){
        for(var i=0;i<level;i++){if(item.v[i]!==prefix[i]){return;}}
        var value=item.v[level];if(seen[value]){return;}seen[value]=true;
        var last=level===data.select.groups.length-1;
        options.push({value:value,label:value+(last?(item.price?(' ('+(item.price>0?'+':'')+item.price.toLocaleString()+'원)'):''):'')+(last&&item.stock<1?' [품절]':''),disabled:last&&item.stock<1});
      });
      select.innerHTML='';var head=document.createElement('option');head.value='';head.textContent=data.select.groups[level]+' 선택';select.appendChild(head);
      options.forEach(function(o){var el=document.createElement('option');el.value=o.value;el.textContent=o.label;el.disabled=o.disabled;select.appendChild(el);});
      select.disabled=false;
      for(var j=level+1;j<selects.length;j++){selects[j].innerHTML='';selects[j].disabled=true;}
    }
    selects.forEach(function(select,level){
      select.addEventListener('change',function(){
        if(!select.value){for(var j=level+1;j<selects.length;j++){selects[j].innerHTML='';selects[j].disabled=true;}return;}
        if(level<selects.length-1){fill(level+1);return;}
        var values=selects.map(function(s){return s.value;});
        var item=data.select.items.filter(function(it){return key(it.v)===key(values);})[0];
        if(!item||item.stock<1){return;}
        addLine('select',values.join(' / '),data.price+item.price,item.stock);
        selects.forEach(function(s,i){if(i===0){s.value='';}});fill(0);
      });
    });
    if(selects.length){fill(0);}
    extras.forEach(function(select){
      select.addEventListener('change',function(){
        var option=select.options[select.selectedIndex];if(!option||!option.value){return;}
        addLine('extra',select.getAttribute('data-yc-extra')+': '+option.value,parseInt(option.getAttribute('data-price'),10)||0,parseInt(option.getAttribute('data-stock'),10)||0);
        select.value='';
      });
    });
    if(qty){qty.addEventListener('input',render);}
    function addLine(kind,label,price,stock){
      var existing=chosen.filter(function(c){return c.label===label;})[0];
      if(existing){existing.qty=Math.min(stock,existing.qty+1);}else{chosen.push({kind:kind,label:label,price:price,stock:stock,qty:1});}
      render();
    }
    function render(){
      if(list){list.innerHTML='';chosen.forEach(function(line,index){
        var li=document.createElement('li');
        var span=document.createElement('span');span.textContent=line.label+' ';li.appendChild(span);
        var input=document.createElement('input');input.type='number';input.min='1';input.max=String(Math.min(9999,line.stock));input.value=String(line.qty);input.className='input input-bordered input-xs';
        input.addEventListener('input',function(){var v=parseInt(input.value,10)||1;line.qty=Math.max(1,Math.min(line.stock,v));input.value=String(line.qty);render();});
        li.appendChild(input);
        var remove=document.createElement('button');remove.type='button';remove.className='btn btn-xs';remove.textContent='삭제';
        remove.addEventListener('click',function(){chosen.splice(index,1);render();});
        li.appendChild(remove);
        var sum=document.createElement('strong');sum.textContent=(line.price*line.qty).toLocaleString()+'원';li.appendChild(sum);
        list.appendChild(li);
      });}
      var amount=0;
      if(chosen.length){chosen.forEach(function(line){amount+=line.price*line.qty;});}
      else if(qty){amount=data.price*Math.max(1,Math.min(9999,parseInt(qty.value,10)||1));}
      else{amount=data.price;}
      if(total){total.textContent=amount.toLocaleString()+'원';}
    }
    render();
  }

  function setupGallery(gallery){
    var main=gallery.querySelector('[data-yc-main]');var link=main&&main.parentNode;
    [].slice.call(gallery.querySelectorAll('[data-yc-thumb]')).forEach(function(button,index){
      if(index===0){button.setAttribute('aria-current','true');}
      button.addEventListener('click',function(){
        if(main){main.src=button.getAttribute('data-yc-thumb');}
        if(link&&link.tagName==='A'){link.href=button.getAttribute('data-yc-large');}
        [].slice.call(gallery.querySelectorAll('[data-yc-thumb]')).forEach(function(b){b.removeAttribute('aria-current');});
        button.setAttribute('aria-current','true');
      });
    });
  }
})();
