(function () {
  'use strict';
  var purchase = document.querySelector('[data-yc-purchase]');
  if (purchase) {
    var option = purchase.querySelector('[data-yc-option]');
    var quantity = purchase.querySelector('[data-yc-quantity]');
    var total = purchase.querySelector('[data-yc-total]');
    var extras = Array.from(purchase.querySelectorAll('[data-yc-extra-price]'));
    var originalMax = Number(quantity.max);
    var stages = purchase.querySelector('[data-yc-option-stages]');
    var steps = Array.from(purchase.querySelectorAll('[data-yc-option-step]'));
    var fallback = purchase.querySelector('[data-yc-option-fallback]');
    var feedback = purchase.querySelector('[data-yc-option-feedback]');
    var selection = purchase.querySelector('[data-yc-option-selection]');
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
    function money(value) { return (value > 0 ? '+' : '') + value.toLocaleString('ko-KR') + '원'; }
    function matches(item, prefix) { return prefix.every(function (value, index) { return item.v[index] === value; }); }
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
        var stockLabel = finalStep ? '재고 ' : '합산 재고 ';
        var label = value + (info.stock > 0 ? ' · ' + stockLabel + info.stock.toLocaleString('ko-KR') + '개' : ' · 품절');
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
      // Clear every dependent field and the submitted combination before resolving a new choice.
      option.value = '';
      steps.slice(index + 1).forEach(function (step) {
        step.value = '';
        step.disabled = true;
        step.closest('[data-yc-option-stage]').hidden = true;
      });
      var selected = steps[index].selectedOptions[0];
      if (!selected || selected.disabled) steps[index].value = '';
      if (steps[index].value !== '') {
        if (index < steps.length - 1) populate(index + 1);
        else {
          var prefix = steps.map(function (step) { return step.value; });
          var item = data.items.find(function (row) { return row.stock > 0 && matches(row, prefix); });
          if (item) option.value = String(item.id);
        }
      }
      update();
    }
    function update() {
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
          feedback.textContent = next < 0 ? '옵션을 다시 선택해 주세요.' : (next + 1) + '단계 ' + data.groups[next] + ' 옵션을 선택해 주세요.';
          feedback.classList.remove('yc-visually-hidden');
        }
      }
    }
    if (sequential) {
      // Preserve the existing option_id contract and the no-JavaScript fallback.
      var previous = option.value;
      steps.forEach(function (step, index) { step.addEventListener('change', function () { choose(index); }); });
      populate(0);
      var restored = data.items.find(function (item) { return String(item.id) === previous && item.stock > 0; });
      if (restored) steps.forEach(function (step, index) { if (index > 0) populate(index); step.value = restored.v[index]; });
      else option.value = '';
      option.required = false;
      fallback.hidden = true;
      stages.hidden = false;
      purchase.addEventListener('submit', function (event) {
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
