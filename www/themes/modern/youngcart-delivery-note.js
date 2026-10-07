(function () {
  'use strict';
  var root = document.querySelector('[data-yc-delivery-note]');
  if (!root) return;
  var choices = root.querySelector('[data-yc-delivery-note-choices]');
  var select = root.querySelector('[data-yc-delivery-note-choice]');
  var custom = root.querySelector('[data-yc-delivery-note-custom]');
  var input = root.querySelector('[name="delivery_note"]');
  if (!choices || !select || !custom || !input) return;
  var customValue = '';
  var presets = Array.from(select.options).map(function (option) { return option.value; })
    .filter(function (value) { return value !== '' && value !== '__custom'; });

  function syncFromInput() {
    var value = input.value;
    if (value === '') select.value = '';
    else if (presets.indexOf(value) !== -1) select.value = value;
    else { select.value = '__custom'; customValue = value; }
    custom.hidden = select.value !== '__custom';
  }

  select.addEventListener('change', function () {
    if (select.value === '__custom') {
      input.value = customValue;
      custom.hidden = false;
      input.focus();
    } else {
      input.value = select.value;
      custom.hidden = true;
    }
    // 기존 주문서와 같은 delivery_note 값만 제출한다. 선택기 자체는 제출하지 않는다.
    input.dispatchEvent(new Event('input', { bubbles: true }));
    // 직접 입력을 고른 직후 빈 입력칸이 사라지지 않도록 change를 재발생시키지 않는다.
  });
  input.addEventListener('input', function () {
    if (!custom.hidden && select.value === '__custom') customValue = input.value;
    else syncFromInput();
  });
  // 이전 배송지 스크립트도 input/change를 발생시키므로 저장된 문구를 그대로 반영한다.
  input.addEventListener('change', syncFromInput);
  syncFromInput();
  choices.hidden = false;
})();
