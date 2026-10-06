(function(){
  'use strict';
  var root=document.querySelector('[data-manual-root]');if(!root)return;
  var navigation=root.querySelector('.manual-navigation');
  if(window.matchMedia('(max-width:680px)').matches)navigation.open=false;
  root.querySelector('[data-manual-print]')?.addEventListener('click',function(){window.print()});
  root.querySelectorAll('[data-manual-copy]').forEach(function(button){button.addEventListener('click',async function(){
    try{await navigator.clipboard.writeText(button.closest('.manual-code').querySelector('code').textContent);button.textContent='복사됨'}catch(e){button.textContent='본문을 선택해 복사하세요'}
  })});
  var form=root.querySelector('.manual-search'),input=form.querySelector('input'),results=root.querySelector('.manual-search-results'),indexPromise=null,revision=0;
  function element(tag,text){var node=document.createElement(tag);node.textContent=text;return node}
  form.addEventListener('submit',async function(event){event.preventDefault();var query=input.value.trim(),current=++revision;
    results.replaceChildren();results.hidden=!query;if(!query)return;results.append(element('p','검색 중입니다…'));
    try{
      if(!indexPromise)indexPromise=fetch(root.dataset.searchUrl,{credentials:'omit'}).then(function(response){if(!response.ok)throw new Error('search');return response.json()}).catch(function(error){indexPromise=null;throw error});
      var documents=await indexPromise;if(current!==revision)return;
      var words=query.toLocaleLowerCase().split(/\s+/),matches=documents.filter(function(doc){var text=(doc.title+' '+doc.summary+' '+doc.text).toLocaleLowerCase();return words.every(function(word){return text.includes(word)})});
      matches.sort(function(a,b){return Number(b.title.toLocaleLowerCase().includes(query.toLocaleLowerCase()))-Number(a.title.toLocaleLowerCase().includes(query.toLocaleLowerCase()))});
      results.replaceChildren();var header=element('header','');header.append(element('strong','검색 결과 '+matches.length+'건'));var close=element('button','닫기');close.type='button';close.addEventListener('click',function(){results.hidden=true;input.focus()});header.append(close);results.append(header);
      matches.slice(0,30).forEach(function(doc){var link=element('a','');link.href=root.dataset.manualUrl+'/'+doc.slug;link.append(element('strong',doc.title),element('p',doc.summary));results.append(link)});
      if(!matches.length)results.append(element('p','일치하는 문서가 없습니다. 짧은 단어로 다시 검색하거나 전체 목차를 확인해 주세요.'));
      if(matches.length>30)results.append(element('p','상위 30건을 표시했습니다. 검색어를 추가해 범위를 좁힐 수 있습니다.'));
    }catch(e){if(current===revision){results.replaceChildren(element('p','검색 자료를 불러오지 못했습니다. 전체 목차에서 문서를 찾거나 다시 검색해 주세요.'))}}
  });
})();
