(function(){
  'use strict';
  var checkAll=document.querySelector('[data-yc-check-all]');
  if(checkAll){checkAll.addEventListener('change',function(){[].slice.call(document.querySelectorAll('input[name="ids[]"]')).forEach(function(box){box.checked=checkAll.checked;});});}
})();
