(function () {
  'use strict';
  var copyOrderNumber = document.querySelector('[data-copy-order-number]');
  if (copyOrderNumber) copyOrderNumber.addEventListener('click', function () {
    var number = document.querySelector('[data-order-number]');
    if (!number) return;
    var value = number.textContent.trim();
    var copied = false;
    function fallbackCopy() {
      var field = document.createElement('textarea');
      field.value = value; field.setAttribute('readonly', '');
      field.style.position = 'fixed'; field.style.opacity = '0';
      document.body.appendChild(field); field.select();
      try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
      field.remove(); finish();
    }
    function finish() {
      copyOrderNumber.textContent = copied ? '복사됨' : '복사 실패';
      window.setTimeout(function () { copyOrderNumber.textContent = '복사'; }, 1800);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(value).then(function () { copied = true; finish(); }).catch(fallbackCopy);
    } else fallbackCopy();
  });
  function showCartStockFeedback(input) {
    var info = input.closest('.yc-cart-item-info');
    if (!info) return;
    var feedback = info.querySelector('[data-yc-stock-attempt]');
    if (!feedback) {
      feedback = document.createElement('p');
      feedback.className = 'yc-inline-error';
      feedback.setAttribute('data-yc-stock-attempt', '');
      feedback.setAttribute('role', 'alert');
      var bottom = info.querySelector('.yc-cart-item-bottom');
      info.insertBefore(feedback, bottom);
    }
    feedback.textContent = '재고가 부족합니다. 구매 가능 수량: ' + Number(input.max).toLocaleString('ko-KR') + '개';
  }
  function clearCartStockFeedback(input) {
    var feedback = input.closest('.yc-cart-item-info')?.querySelector('[data-yc-stock-attempt]');
    if (feedback) feedback.remove();
  }
  function placePurchaseQuantityMessage(row, message) {
    var controls = row.querySelector('.yc-selected-option-bottom,.yc-option-quantity-controls');
    if (controls) row.insertBefore(message, controls);
    else row.appendChild(message);
  }
  function showPurchaseQuantityFeedback(input) {
    var row = input.closest('.yc-extra-row,[data-yc-selected-option],[data-yc-single-quantity]');
    if (!row) return;
    var feedback = row.querySelector('[data-yc-quantity-feedback]');
    if (!feedback) {
      feedback = document.createElement('span');
      feedback.className = 'yc-quantity-feedback';
      feedback.setAttribute('data-yc-quantity-feedback', '');
      feedback.setAttribute('role', 'alert');
      placePurchaseQuantityMessage(row, feedback);
    }
    var maximum = Number(input.max), stock = Number(input.dataset.ycStock);
    feedback.textContent = stock <= maximum ? '재고가 부족합니다. 구매 가능 수량: ' + stock.toLocaleString('ko-KR') + '개'
      : '최대 구매 수량: ' + maximum.toLocaleString('ko-KR') + '개';
  }
  function clearPurchaseQuantityFeedback(input) {
    var feedback = input.closest('.yc-extra-row,[data-yc-selected-option],[data-yc-single-quantity]')?.querySelector('[data-yc-quantity-feedback]');
    if (feedback) feedback.remove();
  }
  var cartForm = document.querySelector('[data-yc-cart-form]');
  if (cartForm) {
    var selectAll = cartForm.querySelector('[data-yc-cart-select-all]');
    var productChecks = Array.from(cartForm.querySelectorAll('[data-yc-cart-select]'));
    var deleteSelected = cartForm.querySelector('[data-yc-cart-delete-selected]');
    var checkoutSelected = cartForm.querySelector('[data-yc-cart-checkout]');
    var selectionValid = checkoutSelected ? checkoutSelected.dataset.ycCartValid !== 'false' : false;
    var lastSelection = null;
    var selectedCount = cartForm.querySelector('[data-yc-cart-selected-count]');
    var saveStatus = cartForm.querySelector('[data-yc-cart-save-status]');
    var cartTotal = function (name) { return cartForm.querySelector('[data-yc-cart-total="' + name + '"]'); };
    var previewRequest = 0;
    var previewTimer = null;
    function updateCartTotals(data) {
      var subtotal = cartTotal('subtotal');
      var shipping = cartTotal('shipping');
      var cod = cartTotal('cod');
      var total = cartTotal('total');
      if (subtotal) subtotal.textContent = new Intl.NumberFormat('ko-KR').format(data.subtotal) + '원';
      if (shipping) shipping.textContent = data.shipping_fee === 0 ? '무료' : new Intl.NumberFormat('ko-KR').format(data.shipping_fee) + '원';
      if (cod) cod.textContent = new Intl.NumberFormat('ko-KR').format(data.cod_fee) + '원';
      if (total && total.firstChild) total.firstChild.nodeValue = new Intl.NumberFormat('ko-KR').format(data.total);
      if (typeof data.valid === 'boolean') selectionValid = data.valid;
      if (checkoutSelected) checkoutSelected.disabled = !selectionValid || !productChecks.some(function (checkbox) { return checkbox.checked; });
    }
    function requestCartPreview(saveQuantities) {
      var request = ++previewRequest;
      selectionValid = false;
      if (checkoutSelected) checkoutSelected.disabled = true;
      var inputs = Array.from(cartForm.querySelectorAll('.yc-cart-item input[type="number"]'));
      if (inputs.some(function (input) { return !input.validity.valid && !input.validity.rangeOverflow; })) {
        if (saveQuantities && saveStatus) { saveStatus.hidden = false; saveStatus.textContent = '수량을 확인해 주세요.'; }
        return;
      }
      var body = new URLSearchParams(new FormData(cartForm));
      body.set('cart_action', saveQuantities ? 'update_quantities' : 'preview_selection');
      body.delete('remove');
      if (saveQuantities && saveStatus) { saveStatus.hidden = true; saveStatus.textContent = ''; }
      fetch(cartForm.action, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() })
        .then(function (response) { if (!response.ok) throw new Error('cart preview'); return response.json(); })
        .then(function (data) {
          if (request !== previewRequest) return;
          if (data.saved) applySavedCartLines(data);
          updateCartTotals(data);
          if (data.saved && saveStatus) saveStatus.hidden = true;
        })
        .catch(function () { if (saveQuantities && saveStatus) { saveStatus.hidden = false; saveStatus.textContent = '수량 저장에 실패했습니다. 다시 시도해 주세요.'; } });
    }
    function updateCartSelection() {
      var selected = productChecks.filter(function (checkbox) { return checkbox.checked; }).length;
      if (selectAll) {
        selectAll.checked = productChecks.length > 0 && selected === productChecks.length;
        selectAll.indeterminate = selected > 0 && selected < productChecks.length;
      }
      if (deleteSelected) deleteSelected.disabled = selected === 0;
      if (checkoutSelected) checkoutSelected.disabled = selected === 0;
      if (selectedCount) selectedCount.textContent = String(selected);
      var selectionKey = productChecks.filter(function (checkbox) { return checkbox.checked; }).map(function (checkbox) { return checkbox.value; }).join(',');
      if (selectionKey !== lastSelection) { lastSelection = selectionKey; selectionValid = false; }
      if (checkoutSelected) checkoutSelected.disabled = selected === 0 || !selectionValid;
      if (selected === 0) {
        previewRequest++;
        updateCartTotals({ subtotal: 0, shipping_fee: 0, cod_fee: 0, total: 0 });
        return;
      }
      requestCartPreview(false);
    }
    function applySavedCartLines(data) {
      if (!Array.isArray(data.lines)) return;
      var totals = new Map(data.lines.map(function (line) { return [line.key, line]; }));
      var removed = false;
      Array.from(cartForm.querySelectorAll('[data-yc-cart-line]')).forEach(function (line) {
        var key = line.dataset.ycCartLine;
        if (!totals.has(key)) { line.remove(); removed = true; return; }
        var current = totals.get(key);
        var amount = line.querySelector('[data-yc-cart-line-total]');
        if (amount) amount.textContent = new Intl.NumberFormat('ko-KR').format(current.total) + '원';
        var input = line.querySelector('input[type="number"]');
        if (input) {
          input.max = String(Math.max(Number(input.min) || 0, current.available));
          input.setAttribute('aria-invalid', current.error ? 'true' : 'false');
        }
        var error = line.querySelector('[data-yc-cart-error]');
        if (current.error && error) error.textContent = current.error;
        else if (current.error) {
          error = document.createElement('p');
          error.className = 'yc-inline-error';
          error.setAttribute('data-yc-cart-error', '');
          error.setAttribute('role', 'alert');
          error.textContent = current.error;
          line.querySelector('.yc-cart-item-info').insertBefore(error, line.querySelector('.yc-cart-item-bottom'));
        } else if (error) error.remove();
      });
      Array.from(cartForm.querySelectorAll('[data-yc-cart-product]')).forEach(function (product) {
        var options = product.querySelectorAll('.yc-cart-options .yc-cart-item').length;
        if (options === 0) { product.remove(); removed = true; return; }
        var title = product.querySelector('.yc-cart-product-info>span');
        if (title) title.textContent = options + '개 선택 구성';
        var extras = product.querySelector('[data-yc-cart-extras]');
        if (extras && extras.querySelectorAll('.yc-cart-item').length === 0) extras.remove();
      });
      if (removed) refreshCartCounts(data);
    }
    function refreshCartCounts(data) {
      productChecks = Array.from(cartForm.querySelectorAll('[data-yc-cart-select]'));
      var products = Array.from(cartForm.querySelectorAll('[data-yc-cart-product]'));
      var titleCount = document.querySelector('.yc-page-heading .yc-title span');
      if (titleCount) titleCount.textContent = String(products.length);
      var badge = document.querySelector('.yc-cart-link .yc-count');
      if (data.cart_count > 0 && badge) {
        badge.textContent = String(Math.min(99, data.cart_count)) + (data.cart_count > 99 ? '+' : '');
        badge.setAttribute('aria-label', '담은 상품 ' + data.cart_count + '종');
      } else if (data.cart_count > 0) {
        var link = document.querySelector('.yc-cart-link');
        if (link) {
          badge = document.createElement('b');
          badge.className = 'yc-count';
          badge.textContent = String(Math.min(99, data.cart_count)) + (data.cart_count > 99 ? '+' : '');
          badge.setAttribute('aria-label', '담은 상품 ' + data.cart_count + '종');
          link.appendChild(badge);
        }
      } else if (badge) badge.remove();
      if (products.length === 0) { window.location.reload(); return; }
      updateCartSelection();
    }
    function postCartAction(action, removeKey) {
      var body = new URLSearchParams(new FormData(cartForm));
      body.set('cart_action', action);
      if (removeKey) body.set('remove', removeKey);
      return fetch(cartForm.action, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString() })
        .then(function (response) { if (!response.ok) throw new Error('cart update'); return response.json(); });
    }
    cartForm.addEventListener('input', function (event) {
      if (!event.target.matches('.yc-cart-item input[type="number"]')) return;
      if (event.target.validity.rangeOverflow) {
        showCartStockFeedback(event.target);
        event.target.value = event.target.max;
      } else clearCartStockFeedback(event.target);
      window.clearTimeout(previewTimer);
      previewTimer = window.setTimeout(function () { requestCartPreview(true); }, 250);
    });
    cartForm.addEventListener('change', function (event) {
      if (!event.target.matches('.yc-cart-item input[type="number"]')) return;
      window.clearTimeout(previewTimer);
      requestCartPreview(true);
    });
    cartForm.addEventListener('click', function (event) {
      var removeButton = event.target.closest('[data-yc-cart-remove]');
      if (removeButton) {
        event.preventDefault();
        var section = removeButton.closest('[data-yc-cart-product]');
        var line = removeButton.closest('[data-yc-cart-line]');
        postCartAction('remove_line', removeButton.value).then(function (data) {
          if (line.closest('.yc-cart-options')) {
            line.remove();
            if (section.querySelectorAll('.yc-cart-options .yc-cart-item').length === 0) section.remove();
            else {
              var title = section.querySelector('.yc-cart-product-info>span');
              if (title) title.textContent = section.querySelectorAll('.yc-cart-options .yc-cart-item').length + '개 선택 구성';
            }
          } else {
            var extras = line.closest('[data-yc-cart-extras]');
            line.remove();
            if (extras && extras.querySelectorAll('.yc-cart-item').length === 0) extras.remove();
          }
          updateCartTotals(data);
          refreshCartCounts(data);
        }).catch(function () { window.location.reload(); });
      }
      var deleteButton = event.target.closest('[data-yc-cart-delete-selected]');
      if (deleteButton) {
        event.preventDefault();
        var selectedIds = productChecks.filter(function (checkbox) { return checkbox.checked; }).map(function (checkbox) { return checkbox.value; });
        postCartAction('delete_selected').then(function (data) {
          selectedIds.forEach(function (id) {
            var product = cartForm.querySelector('[data-yc-cart-product="' + CSS.escape(id) + '"]');
            if (product) product.remove();
          });
          updateCartTotals(data);
          refreshCartCounts(data);
        }).catch(function () { window.location.reload(); });
      }
    });
    if (selectAll) selectAll.addEventListener('change', function () {
      productChecks.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
      updateCartSelection();
    });
    productChecks.forEach(function (checkbox) { checkbox.addEventListener('change', updateCartSelection); });
    updateCartSelection();
  }
  var checkoutForm = document.querySelector('[data-yc-checkout]');
  if (checkoutForm) {
    var previousAddress = checkoutForm.querySelector('[data-yc-previous-address]');
    var previousDialog = checkoutForm.querySelector('[data-yc-previous-dialog]');
    var openPrevious = checkoutForm.querySelector('[data-yc-open-previous]');
    if (previousAddress && previousDialog && openPrevious) {
      var previousList = previousDialog.querySelector('[data-yc-previous-list]');
      var previousSearch = previousDialog.querySelector('[data-yc-previous-search]');
      var previousCount = previousDialog.querySelector('[data-yc-previous-count]');
      var previousPageLabel = previousDialog.querySelector('[data-yc-previous-page]');
      var previousBack = previousDialog.querySelector('[data-yc-previous-prev]');
      var previousForward = previousDialog.querySelector('[data-yc-previous-next]');
      var previousPage = 1;
      var previousTotalPages = 1;
      var previousRequest = 0;
      var previousSearchTimer = null;
      function renderPreviousAddresses(data) {
        previousList.replaceChildren();
        data.items.forEach(function (item) {
          var button = document.createElement('button');
          button.type = 'button'; button.className = 'yc-previous-option';
          var heading = document.createElement('span'); heading.className = 'yc-previous-option-heading';
          if (Number(item.default_address) === 1) {
            var badge = document.createElement('strong'); badge.className = 'yc-previous-default'; badge.textContent = '기본 배송지'; heading.appendChild(badge);
          }
          var recipient = document.createElement('strong'); recipient.textContent = item.recipient; heading.appendChild(recipient);
          var date = document.createElement('small'); date.textContent = item.created_label; heading.appendChild(date);
          var addressLine = document.createElement('span'); addressLine.className = 'yc-previous-option-address';
          addressLine.textContent = '(' + item.postcode + ') ' + item.address + (item.address_detail ? ' ' + item.address_detail : '');
          button.append(heading, addressLine);
          button.addEventListener('click', function () { applyPreviousAddress(item); });
          previousList.appendChild(button);
        });
        previousCount.textContent = data.total ? data.total + '개 주소' : (previousSearch.value ? '검색 결과가 없습니다.' : '불러올 주소가 없습니다.');
        previousPage = data.page;
        previousTotalPages = data.total_pages;
        previousPageLabel.textContent = data.total ? previousPage + ' / ' + previousTotalPages : '0 / 0';
        previousBack.disabled = previousPage <= 1;
        previousForward.disabled = previousPage >= previousTotalPages;
      }
      function loadPreviousAddresses(page) {
        var request = ++previousRequest;
        var body = new URLSearchParams({
          csrf_token: checkoutForm.querySelector('[name="csrf_token"]').value,
          page: String(page), q: previousSearch.value.trim()
        });
        previousCount.textContent = '주소를 불러오는 중입니다.';
        previousList.replaceChildren();
        previousBack.disabled = true; previousForward.disabled = true;
        fetch(previousDialog.dataset.endpoint, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: body.toString(), credentials: 'same-origin' })
          .then(function (response) { if (!response.ok) throw new Error('주소를 불러오지 못했습니다.'); return response.json(); })
          .then(function (data) { if (request === previousRequest) renderPreviousAddresses(data); })
          .catch(function () {
            if (request !== previousRequest) return;
            previousCount.textContent = '주소를 불러오지 못했습니다. 다시 시도해 주세요.';
            previousPageLabel.textContent = '0 / 0';
          });
      }
      function applyPreviousAddress(item) {
        var value = item === 'new' ? 'new' : String(item.id);
        var option = Array.from(previousAddress.options).find(function (entry) { return entry.value === value; });
        if (!option && item !== 'new') {
          option = document.createElement('option'); option.value = value;
          option.dataset.buyerName = item.buyer_name; option.dataset.buyerPhone = item.phone; option.dataset.email = item.email;
          option.dataset.recipient = item.recipient; option.dataset.recipientPhone = item.recipient_phone;
          option.dataset.postcode = item.postcode; option.dataset.address = item.address;
          option.dataset.addressDetail = item.address_detail; option.dataset.deliveryNote = item.delivery_note;
          option.textContent = item.recipient + ' · (' + item.postcode + ') ' + item.address + ' ' + item.address_detail;
          previousAddress.appendChild(option);
        }
        previousAddress.value = value;
        previousAddress.dispatchEvent(new Event('change', { bubbles: true }));
        previousDialog.close();
        openPrevious.focus();
      }
      openPrevious.addEventListener('click', function () {
        previousDialog.showModal();
        loadPreviousAddresses(1);
        previousSearch.focus();
      });
      previousDialog.querySelector('[data-yc-previous-new]').addEventListener('click', function () { applyPreviousAddress('new'); });
      previousDialog.querySelector('[data-yc-close-previous]').addEventListener('click', function () { previousDialog.close(); openPrevious.focus(); });
      previousSearch.addEventListener('input', function () {
        previousRequest++;
        window.clearTimeout(previousSearchTimer);
        previousSearchTimer = window.setTimeout(function () { loadPreviousAddresses(1); }, 250);
      });
      previousBack.addEventListener('click', function () { if (previousPage > 1) loadPreviousAddresses(previousPage - 1); });
      previousForward.addEventListener('click', function () { if (previousPage < previousTotalPages) loadPreviousAddresses(previousPage + 1); });
      previousDialog.addEventListener('click', function (event) { if (event.target === previousDialog) previousDialog.close(); });
    }
    var copyBuyerName = checkoutForm.querySelector('[data-yc-copy-buyer-name]');
    if (copyBuyerName) copyBuyerName.addEventListener('click', function () {
      var buyerName = checkoutForm.querySelector('[name="buyer_name"]');
      var buyerPhone = checkoutForm.querySelector('[name="phone"]');
      var recipient = checkoutForm.querySelector('[name="recipient"]');
      if (!buyerName || !recipient) return;
      recipient.value = buyerName.value;
      recipient.dispatchEvent(new Event('input', { bubbles: true }));
      recipient.dispatchEvent(new Event('change', { bubbles: true }));
      var recipientPhone = checkoutForm.querySelector('[name="recipient_phone"]');
      if (buyerPhone && recipientPhone) {
        recipientPhone.value = buyerPhone.value;
        recipientPhone.dispatchEvent(new Event('input', { bubbles: true }));
        recipientPhone.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    var manualTransfer = checkoutForm.querySelector('[data-yc-manual-transfer]');
    if (manualTransfer) {
      var depositor = manualTransfer.querySelector('[name="depositor"]');
      function updateManualTransfer() {
        var selected = checkoutForm.querySelector('[name="payment_method"]:checked');
        var show = selected && selected.value === 'manual_transfer';
        manualTransfer.hidden = !show;
        if (depositor) depositor.disabled = !show;
      }
      checkoutForm.addEventListener('change', function (event) {
        if (event.target.matches('[name="payment_method"]')) updateManualTransfer();
      });
      updateManualTransfer();
    }
  }
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
      input.dataset.ycStock = String(item.stock);
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
          if (control[1] > 0 && (Number(input.value) || 0) >= Number(input.max)) {
            showPurchaseQuantityFeedback(input); return;
          }
          input.value = String(Math.min(Number(input.max), Math.max(1, (Number(input.value) || 1) + control[1])));
          clearPurchaseQuantityFeedback(input);
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
        entry.row.querySelector('[data-yc-line-plus]').setAttribute('aria-disabled', String(qty >= Number(entry.input.max)));
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
      var previousMaximum = quantity.max, previousStock = quantity.dataset.ycStock;
      if (selected && selected.value) {
        quantity.max = String(Math.min(originalMax, Number(selected.dataset.stock)));
        quantity.dataset.ycStock = selected.dataset.stock;
      } else quantity.max = String(originalMax);
      if (quantity.max !== previousMaximum || quantity.dataset.ycStock !== previousStock) clearPurchaseQuantityFeedback(quantity);
      quantity.dispatchEvent(new Event('yc:quantity-limits'));
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
    var purchaseFeedback = purchase.querySelector('[data-yc-purchase-feedback]');
    if (purchaseFeedback && window.fetch) {
      var pendingPurchase = false;
      function showPurchaseErrors(messages) {
        var list = purchaseFeedback.querySelector('[data-yc-purchase-errors]');
        list.replaceChildren();
        messages.forEach(function (message) { var li = document.createElement('li'); li.textContent = message; list.appendChild(li); });
        purchaseFeedback.hidden = false;
        purchaseFeedback.focus({preventScroll: true});
        purchaseFeedback.scrollIntoView({block: 'center', behavior: 'instant'});
      }
      function refreshStock(availability) {
        if (!availability || String(availability.product_id) !== purchase.elements.namedItem('product_id').value || !availability.items) return;
        var items = availability.items;
        function cell(id) { return items[id] || {stock: 0, in_cart: 0}; }
        function refreshInput(input, id, maximum) {
          var current = cell(id), previousMax = Number(input.max);
          clearPurchaseQuantityFeedback(input);
          input.max = String(Math.min(maximum, current.stock));
          input.dataset.ycStock = String(current.stock);
          var row = input.closest('.yc-extra-row,[data-yc-selected-option],[data-yc-single-quantity]');
          if (!row) return;
          var note = row.querySelector('[data-yc-stock-note]');
          var remaining = Math.max(0, Number(input.max) - current.in_cart);
          if (!note && previousMax === Number(input.max) && (input.valueAsNumber || 0) <= remaining) {
            input.dispatchEvent(new Event('change', {bubbles: true})); return;
          }
          if (!note) {
            note = document.createElement('span'); note.className = 'yc-stock-note'; note.dataset.ycStockNote = '';
            note.id = 'yc-stock-note-' + id; placePurchaseQuantityMessage(row, note);
            input.setAttribute('aria-describedby', ((input.getAttribute('aria-describedby') || '') + ' ' + note.id).trim());
          }
          note.textContent = '현재 담을 수 있는 수량: ' + remaining + '개' + (current.in_cart > 0 ? ' (장바구니에 ' + current.in_cart + '개 담김)' : '');
          input.setAttribute('aria-invalid', String(input.valueAsNumber > Number(input.max)));
          input.dispatchEvent(new Event('change', {bubbles: true}));
          if (input.valueAsNumber > 0) { var details = input.closest('details'); if (details) details.open = true; }
        }
        if (option) Array.from(option.options).forEach(function (entry) {
          if (!entry.value) return;
          entry.dataset.stock = String(cell(entry.value).stock); entry.disabled = cell(entry.value).stock < 1;
        });
        if (sequential) {
          data.items.forEach(function (item) { item.stock = cell(item.id).stock; });
          // Keep the selected rows; update only the available choices and their current stock.
          steps.forEach(function (step, index) {
            var value = step.value;
            if (index === 0 || steps[index - 1].value !== '') { populate(index); step.value = value; }
            else reset(index);
          });
        }
        if (multiple) selectedItems.forEach(function (entry, id) {
          entry.row.querySelector('[data-yc-line-meta]').textContent = '현재 재고 ' + entry.item.stock.toLocaleString('ko-KR') + '개 · ' + (entry.item.price === 0 ? '추가금액 없음' : '옵션 금액 ' + money(entry.item.price));
          refreshInput(entry.input, id, originalMax);
        });
        else {
          if (!option) originalMax = Math.min(originalMax, cell(0).stock);
          refreshInput(quantity, option ? option.value : 0, originalMax);
        }
        extras.forEach(function (input) { var id = /^extras\[(\d+)\]$/.exec(input.name); if (id) refreshInput(input, id[1], 9999); });
        update();
      }
      purchase.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (pendingPurchase) return;
        var submitter = event.submitter || buyButtons[0], submitLabel = submitter.textContent;
        var body = new URLSearchParams(new FormData(purchase));
        body.set('action', submitter.value === 'buy' ? 'buy' : 'cart');
        var locked = Array.from(purchase.querySelectorAll('input,select,button')).filter(function (input) { return !input.disabled; });
        locked.forEach(function (input) { input.disabled = true; });
        pendingPurchase = true; purchase.setAttribute('aria-busy', 'true'); purchaseFeedback.hidden = true; submitter.textContent = '재고 확인 중…';
        function unlock() {
          locked.forEach(function (input) { input.disabled = false; });
          pendingPurchase = false; purchase.removeAttribute('aria-busy'); submitter.textContent = submitLabel; update();
          extras.forEach(function (input) { input.dispatchEvent(new Event('change', {bubbles: true})); });
        }
        fetch(purchase.getAttribute('action'), {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json'}, body: body})
          .then(function (response) { return response.json().then(function (result) { return {ok: response.ok, data: result}; }); })
          .then(function (result) {
            if (!result.ok) {
              unlock(); refreshStock(result.data.availability);
              var error = result.data.error || {}, messages = Object.values(error.details || {}).filter(function (message) { return typeof message === 'string'; });
              showPurchaseErrors(messages.length ? messages : [error.message || '구매 내용을 확인하고 다시 시도해 주세요.']);
              return;
            }
            if (typeof result.data.redirect !== 'string') throw new Error('Unexpected purchase response');
            var next = new URL(result.data.redirect, window.location.href);
            if (next.origin !== window.location.origin) throw new Error('Unexpected purchase destination');
            window.location.assign(next.href);
          })
          .catch(function () {
            unlock();
            showPurchaseErrors(['처리 결과를 확인하지 못했습니다. 장바구니를 확인한 뒤 다시 시도해 주세요. 입력한 내용은 유지됩니다.']);
          });
      });
      purchase.addEventListener('input', function (event) {
        if (event.target.hasAttribute('aria-invalid')) event.target.setAttribute('aria-invalid', String(!event.target.validity.valid));
      });
    }
    purchase.addEventListener('input', function (event) {
      var input = event.target;
      if (!(input instanceof HTMLInputElement) || !input.matches('[data-yc-quantity],[data-yc-line-quantity],[data-yc-extra-price]')) return;
      if (input.validity.rangeOverflow) {
        showPurchaseQuantityFeedback(input);
        if (Number(input.max) >= Number(input.min)) input.value = input.max;
      } else clearPurchaseQuantityFeedback(input);
    }, true);
    purchase.addEventListener('input', function (event) { if (!event.target.matches('[data-yc-option-step]')) update(); });
    purchase.addEventListener('change', update);
    update();
  }
  document.querySelectorAll('[data-yc-quantity-controls],[data-yc-cart-quantity]').forEach(function (control) {
    var input = control.querySelector('input[type=number]');
    var minus = control.querySelector('[data-yc-quantity-minus],[data-yc-cart-minus]');
    var plus = control.querySelector('[data-yc-quantity-plus],[data-yc-cart-plus]');
    if (!input || !minus || !plus) return;
    function updateButtons() {
      minus.disabled = input.disabled || (input.valueAsNumber || 0) <= Number(input.min);
      plus.disabled = input.disabled;
      if (input.valueAsNumber >= Number(input.max)) plus.setAttribute('aria-disabled', 'true');
      else plus.removeAttribute('aria-disabled');
    }
    [[minus, -1], [plus, 1]].forEach(function (entry) {
      entry[0].addEventListener('click', function () {
        var current = Number.isFinite(input.valueAsNumber) ? Math.trunc(input.valueAsNumber) : 0;
        if (entry[1] > 0 && current >= Number(input.max)) {
          if (control.matches('[data-yc-cart-quantity]')) showCartStockFeedback(input);
          else showPurchaseQuantityFeedback(input);
          return;
        }
        input.value = String(Math.min(Number(input.max), Math.max(Number(input.min), current + entry[1])));
        if (control.matches('[data-yc-cart-quantity]')) clearCartStockFeedback(input);
        else clearPurchaseQuantityFeedback(input);
        input.dispatchEvent(new Event('input', { bubbles: true }));
      });
      entry[0].hidden = false;
    });
    input.addEventListener('input', updateButtons);
    input.addEventListener('change', updateButtons);
    input.addEventListener('yc:quantity-limits', updateButtons);
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
  var errors = document.querySelector('[data-yc-errors]');
  if (errors) errors.focus();
  document.querySelectorAll('.yc-category-dropdown, [data-yc-search-menu]').forEach(function (menu) {
    if (menu.matches('[data-yc-search-menu]')) {
      menu.addEventListener('toggle', function () {
        if (menu.open) menu.querySelector('input[type=search]').focus();
      });
    }
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
    });
    document.addEventListener('click', function (event) { if (menu.open && !menu.contains(event.target)) menu.open = false; });
  });
})();
