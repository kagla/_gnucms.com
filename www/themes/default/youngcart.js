(function () {
  'use strict';
  var purchase = document.querySelector('[data-yc-purchase]');
  if (purchase) {
    var option = purchase.querySelector('[data-yc-option]');
    var quantity = purchase.querySelector('[data-yc-quantity]');
    var total = purchase.querySelector('[data-yc-total]');
    var extras = Array.from(purchase.querySelectorAll('[data-yc-extra-price]'));
    var originalMax = Number(quantity.max);
    var productMax = Number(purchase.dataset.buyMax) || 0;
    var stages = purchase.querySelector('[data-yc-option-stages]');
    var steps = Array.from(purchase.querySelectorAll('[data-yc-option-step]'));
    var fallback = purchase.querySelector('[data-yc-option-fallback]');
    var feedback = purchase.querySelector('[data-yc-option-feedback]');
    var selection = purchase.querySelector('[data-yc-option-selection]');
    var selectionList = purchase.querySelector('[data-yc-selections]');
    var selectionTemplate = purchase.querySelector('[data-yc-selection-template]');
    var singleQuantity = purchase.querySelector('[data-yc-single-quantity]');
    var selectedItems = new Map();
    var selectionNotice = '';
    var buyButtons = Array.from(purchase.querySelectorAll('.yc-buy-actions button[type=submit]'));
    var data = null;
    try { data = JSON.parse(purchase.querySelector('[data-yc-options]').dataset.ycOptions).select; } catch (error) { /* Keep the native combination selector available. */ }
    var optionIds = new Set(option ? Array.from(option.options).map(function (entry) { return entry.value; }) : []);
    var sequential = option && stages && fallback && feedback && selection && data && Array.isArray(data.groups) && Array.isArray(data.items) &&
      steps.length > 0 && steps.length === data.groups.length && data.items.length > 0 && data.items.every(function (item) {
        return Array.isArray(item.v) && item.v.length === steps.length && item.v.every(function (value) { return typeof value === 'string'; }) &&
          Number.isInteger(item.id) && optionIds.has(String(item.id)) &&
          Number.isFinite(item.stock) && Number.isFinite(item.price);
      });
    var multiple = sequential && selectionList && selectionTemplate && singleQuantity;
    function money(value) { return (value > 0 ? '+' : '') + value.toLocaleString('ko-KR') + '원'; }
    function matches(item, prefix) { return prefix.every(function (value, index) { return item.v[index] === value; }); }
    function addSelection(item, initialQuantity) {
      var names = item.v.join(' / ');
      if (selectedItems.has(item.id)) {
        selectionNotice = names + ' 옵션은 이미 추가했어요. 아래에서 수량을 변경해 주세요.';
        return;
      }
      if (selectedItems.size >= 100) { selectionNotice = '옵션은 최대 100개까지 선택할 수 있어요.'; return; }
      var row = selectionTemplate.content.firstElementChild.cloneNode(true);
      var input = row.querySelector('[data-yc-line-quantity]');
      row.dataset.ycSelectedOption = String(item.id);
      row.querySelector('[data-yc-line-name]').textContent = names;
      row.querySelector('[data-yc-line-meta]').textContent = '재고 ' + item.stock.toLocaleString('ko-KR') + '개 · ' + (item.price === 0 ? '추가금액 없음' : '옵션 금액 ' + money(item.price));
      input.name = 'selections[' + item.id + ']';
      input.max = String(Math.min(originalMax, item.stock));
      input.value = String(initialQuantity || 1);
      input.setAttribute('aria-label', names + ' 수량');
      var remove = row.querySelector('[data-yc-line-remove]');
      remove.setAttribute('aria-label', names + ' 삭제');
      remove.addEventListener('click', function () {
        selectedItems.delete(item.id); row.remove(); selectionNotice = names + ' 옵션을 삭제했어요.'; update();
        var next = selectionList.querySelector('[data-yc-line-remove]') || steps.slice().reverse().find(function (step) { return !step.disabled; });
        if (next) next.focus();
      });
      [['minus', -1, '줄이기'], ['plus', 1, '늘리기']].forEach(function (control) {
        var button = row.querySelector('[data-yc-line-' + control[0] + ']');
        button.setAttribute('aria-label', names + ' 수량 ' + control[2]);
        button.addEventListener('click', function () {
          input.value = String(Math.min(Number(input.max), Math.max(1, (Number(input.value) || 1) + control[1])));
          selectionNotice = ''; update();
        });
      });
      selectedItems.set(item.id, { item: item, row: row, input: input });
      selectionList.prepend(row);
      selectionNotice = names + ' 옵션을 추가했어요. 다른 옵션도 선택할 수 있어요.';
    }
    function updateSelections() {
      var amount = 0, count = 0, first = null;
      selectedItems.forEach(function (entry) {
        var qty = Math.max(0, Number(entry.input.value) || 0);
        var lineTotal = (Number(purchase.dataset.price) + entry.item.price) * qty;
        count += qty; amount += lineTotal; first = first || entry.input;
        entry.input.setCustomValidity('');
        entry.row.querySelector('[data-yc-line-total]').textContent = lineTotal.toLocaleString('ko-KR') + '원';
        entry.row.querySelector('[data-yc-line-minus]').disabled = qty <= 1;
        entry.row.querySelector('[data-yc-line-plus]').disabled = qty >= Number(entry.input.max);
      });
      var limit = count < Number(quantity.min) ? '상품 수량을 합계 ' + quantity.min + '개 이상 선택해 주세요.' :
        productMax > 0 && count > productMax ? '상품 수량은 합계 ' + productMax + '개까지 선택할 수 있어요.' : '';
      if (first) first.setCustomValidity(limit);
      extras.forEach(function (input) { amount += Number(input.dataset.ycExtraPrice) * Math.max(0, Number(input.value) || 0); });
      total.textContent = selectedItems.size === 0 ? '옵션을 선택해 주세요' : amount.toLocaleString('ko-KR') + '원';
      selection.hidden = true;
      selectionList.hidden = selectedItems.size === 0;
      buyButtons.forEach(function (button) { button.disabled = selectedItems.size === 0; });
      var next = steps.findIndex(function (step) { return step.value === ''; });
      feedback.textContent = (first && limit) || selectionNotice || (selectedItems.size > 0 ? '옵션을 더 선택하거나 아래에서 수량을 조절해 주세요.' : data.groups[Math.max(0, next)] + ' 옵션을 선택해 주세요.');
      feedback.classList.remove('yc-visually-hidden');
    }
    function reset(index) {
      var step = steps[index];
      var prompt = index === 0 ? data.groups[index] + ' 선택' : '먼저 ' + data.groups[index - 1] + ' 옵션을 선택해 주세요';
      step.replaceChildren(new Option(prompt, ''));
      step.disabled = true;
      step.closest('[data-yc-option-stage]').hidden = false;
    }
    function populate(index) {
      var step = steps[index];
      var prefix = steps.slice(0, index).map(function (previous) { return previous.value; });
      var values = new Map();
      data.items.filter(function (item) { return matches(item, prefix); }).forEach(function (item) {
        var value = item.v[index];
        if (!values.has(value)) values.set(value, { stock: 0, price: item.price });
        values.get(value).stock += Math.max(0, item.stock);
      });
      step.replaceChildren(new Option(data.groups[index] + ' 선택', ''));
      values.forEach(function (info, value) {
        var finalStep = index === steps.length - 1;
        var label = value;
        if (info.stock <= 0) label += ' · 품절';
        else if (finalStep) label += ' · 재고 ' + info.stock.toLocaleString('ko-KR') + '개';
        if (finalStep && info.price !== 0) label += ' (' + money(info.price) + ')';
        var entry = new Option(label, value);
        entry.disabled = info.stock <= 0;
        entry.dataset.stock = String(info.stock);
        step.add(entry);
      });
      step.disabled = false;
      step.closest('[data-yc-option-stage]').hidden = false;
    }
    function choose(index) {
      // Reset the pending choice while keeping combinations already added to the purchase list.
      option.value = '';
      selectionNotice = '';
      steps.forEach(function (step, next) { if (next > index) reset(next); });
      var selected = steps[index].selectedOptions[0];
      if (!selected || selected.disabled) steps[index].value = '';
      if (steps[index].value !== '') {
        if (index < steps.length - 1) populate(index + 1);
        else {
          var prefix = steps.map(function (step) { return step.value; });
          var item = data.items.find(function (row) { return row.stock > 0 && matches(row, prefix); });
          if (item) {
            if (multiple) { addSelection(item); steps[index].value = ''; }
            else option.value = String(item.id);
          }
        }
      }
      update();
    }
    function update() {
      if (multiple) { updateSelections(); return; }
      var selected = option && option.options[option.selectedIndex];
      var price = Number(purchase.dataset.price) + (selected ? Number(selected.dataset.price || 0) : 0);
      if (selected && selected.value) quantity.max = String(Math.min(originalMax, Number(selected.dataset.stock)));
      else quantity.max = String(originalMax);
      var amount = price * Math.max(0, Number(quantity.value) || 0);
      extras.forEach(function (input) { amount += Number(input.dataset.ycExtraPrice) * Math.max(0, Number(input.value) || 0); });
      total.textContent = option && !option.value ? '옵션을 선택해 주세요' : amount.toLocaleString('ko-KR') + '원';
      if (sequential) {
        var complete = Boolean(option.value && selected && !selected.disabled);
        buyButtons.forEach(function (button) { button.disabled = !complete; });
        selection.hidden = !complete;
        if (complete) {
          var names = steps.map(function (step) { return step.value; }).join(' / ');
          var stockText = '재고 ' + Number(selected.dataset.stock).toLocaleString('ko-KR') + '개';
          var optionPrice = Number(selected.dataset.price);
          var priceText = optionPrice === 0 ? '추가금액 없음' : '옵션 금액 ' + money(optionPrice);
          selection.querySelector('[data-yc-selection-name]').textContent = names;
          selection.querySelector('[data-yc-selection-stock]').textContent = stockText;
          selection.querySelector('[data-yc-selection-price]').textContent = priceText;
          feedback.textContent = names + ' 선택 완료 · ' + stockText + ' · ' + priceText;
          feedback.classList.add('yc-visually-hidden');
        } else {
          var next = steps.findIndex(function (step) { return step.value === ''; });
          feedback.textContent = next < 0 ? '옵션을 다시 선택해 주세요.' : data.groups[next] + ' 옵션을 선택해 주세요.';
          feedback.classList.remove('yc-visually-hidden');
        }
      }
    }
    if (sequential) {
      // Preserve the existing option_id contract and the no-JavaScript fallback.
      var previous = option.value;
      steps.forEach(function (step, index) { reset(index); step.addEventListener('change', function () { choose(index); }); });
      populate(0);
      var restored = data.items.find(function (item) { return String(item.id) === previous && item.stock > 0; });
      if (restored) {
        steps.forEach(function (step, index) { if (index > 0) populate(index); step.value = restored.v[index]; });
        if (multiple) { addSelection(restored, Number(quantity.value)); steps[steps.length - 1].value = ''; }
      }
      else option.value = '';
      option.required = false;
      fallback.hidden = true;
      stages.hidden = false;
      if (multiple) {
        option.disabled = true;
        quantity.disabled = true;
        singleQuantity.hidden = true;
        steps.forEach(function (step) { step.required = false; });
        var guide = stages.querySelector('.yc-option-guide');
        if (guide) guide.textContent = '마지막 옵션을 선택하면 구매 목록에 추가됩니다. 여러 옵션을 함께 선택할 수 있어요.';
        var help = purchase.querySelector('[data-yc-purchase-help]');
        if (help) help.textContent = '선택한 옵션을 한 번에 장바구니에 담거나 주문할 수 있어요.';
      }
      purchase.addEventListener('submit', function (event) {
        if (multiple) {
          if (selectedItems.size === 0) { event.preventDefault(); update(); steps[0].focus(); }
          return;
        }
        var prefix = steps.map(function (step) { return step.value; });
        var item = data.items.find(function (row) { return row.stock > 0 && matches(row, prefix); });
        if (!item || String(item.id) !== option.value) {
          event.preventDefault();
          option.value = '';
          update();
          var next = steps.find(function (step) { return !step.disabled && step.value === ''; }) || steps[0];
          next.focus();
        }
      });
    }
    purchase.addEventListener('input', function (event) { if (!event.target.matches('[data-yc-option-step]')) update(); });
    purchase.addEventListener('change', update);
    update();
  }
  document.querySelectorAll('[data-yc-cart-quantity]').forEach(function (control) {
    var input = control.querySelector('input[type=number]');
    var minus = control.querySelector('[data-yc-cart-minus]');
    var plus = control.querySelector('[data-yc-cart-plus]');
    if (!input || !minus || !plus) return;
    function updateButtons() {
      minus.disabled = input.disabled || (input.valueAsNumber || 0) <= Number(input.min);
      plus.disabled = input.disabled || input.valueAsNumber >= Number(input.max);
    }
    [[minus, -1], [plus, 1]].forEach(function (entry) {
      entry[0].addEventListener('click', function () {
        var current = Number.isFinite(input.valueAsNumber) ? Math.trunc(input.valueAsNumber) : 0;
        input.value = String(Math.min(Number(input.max), Math.max(Number(input.min), current + entry[1])));
        input.dispatchEvent(new Event('input', { bubbles: true }));
      });
      entry[0].hidden = false;
    });
    input.addEventListener('input', updateButtons);
    input.addEventListener('change', updateButtons);
    updateButtons();
  });
  var gallery = document.querySelector('[data-yc-gallery]');
  if (gallery) {
    var main = gallery.querySelector('[data-yc-main]');
    var thumbs = Array.from(gallery.querySelectorAll('[data-yc-thumb]'));
    thumbs.forEach(function (button, index) {
      if (index === 0) button.setAttribute('aria-current', 'true');
      button.addEventListener('click', function () {
        main.src = button.dataset.ycThumb;
        main.parentNode.href = button.dataset.ycLarge;
        thumbs.forEach(function (thumb) { thumb.removeAttribute('aria-current'); });
        button.setAttribute('aria-current', 'true');
      });
    });
  }
  var copy = document.querySelector('[data-yc-copy-buyer]');
  if (copy) {
    copy.hidden = false;
    copy.addEventListener('click', function () {
      document.getElementById('yc-recipient').value = document.getElementById('yc-buyer_name').value;
      document.getElementById('yc-recipient_phone').value = document.getElementById('yc-phone').value;
      document.getElementById('yc-postcode').focus();
    });
  }
  var errors = document.querySelector('[data-yc-errors]');
  if (errors) errors.focus();
  document.querySelectorAll('.yc-category-dropdown').forEach(function (menu) {
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
    });
    document.addEventListener('click', function (event) { if (menu.open && !menu.contains(event.target)) menu.open = false; });
  });
})();
