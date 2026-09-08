(function () {
  'use strict';
  var purchase = document.querySelector('[data-yc-purchase]');
  if (purchase) {
    var option = purchase.querySelector('[data-yc-option]');
    var quantity = purchase.querySelector('[data-yc-quantity]');
    var total = purchase.querySelector('[data-yc-total]');
    var extras = Array.from(purchase.querySelectorAll('[data-yc-extra-price]'));
    var originalMax = Number(quantity.max);
    function update() {
      var selected = option && option.options[option.selectedIndex];
      var price = Number(purchase.dataset.price) + (selected ? Number(selected.dataset.price || 0) : 0);
      if (selected && selected.value) quantity.max = String(Math.min(originalMax, Number(selected.dataset.stock)));
      else quantity.max = String(originalMax);
      var amount = price * Math.max(0, Number(quantity.value) || 0);
      extras.forEach(function (input) { amount += Number(input.dataset.ycExtraPrice) * Math.max(0, Number(input.value) || 0); });
      total.textContent = option && !option.value ? '옵션을 선택해 주세요' : amount.toLocaleString('ko-KR') + '원';
    }
    purchase.addEventListener('input', update);
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
