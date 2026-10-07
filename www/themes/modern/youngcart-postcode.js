(function () {
  'use strict';
  var root = document.querySelector('[data-yc-postcode]');
  if (!root) return;
  var search = root.querySelector('[data-yc-postcode-search]');
  var panel = root.querySelector('[data-yc-postcode-panel]');
  var host = root.querySelector('[data-yc-postcode-host]');
  var status = root.querySelector('[data-yc-postcode-status]');
  var closeButton = root.querySelector('[data-yc-postcode-close]');
  var postcode = document.getElementById('yc-postcode');
  var address = document.getElementById('yc-address');
  var detail = document.getElementById('yc-address_detail');
  if (!search || !panel || !host || !status || !closeButton || !postcode || !address || !detail) return;
  var loading = null, attempt = 0;
  function constructor() { return (window.kakao && window.kakao.Postcode) || (window.daum && window.daum.Postcode); }
  function load() {
    if (typeof constructor() === 'function') return Promise.resolve(constructor());
    if (loading) return loading;
    loading = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      var timer = setTimeout(fail, 15000);
      function fail() {
        clearTimeout(timer); script.onload = script.onerror = null; script.remove(); loading = null;
        reject(new Error('Postcode service unavailable'));
      }
      script.src = 'https://t1.kakaocdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
      script.async = true;
      script.onerror = fail;
      script.onload = function () {
        if (typeof constructor() !== 'function') { fail(); return; }
        clearTimeout(timer); resolve(constructor());
      };
      document.head.appendChild(script);
    });
    return loading;
  }
  function message(text) { status.textContent = text; status.hidden = text === ''; }
  function close(focus) {
    attempt++;
    panel.hidden = true; panel.removeAttribute('aria-busy'); host.replaceChildren();
    search.setAttribute('aria-expanded', 'false'); search.disabled = false;
    message('');
    if (focus) search.focus();
  }
  function changed(field) {
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.dispatchEvent(new Event('change', { bubbles: true }));
  }
  // 검색 스크립트를 사용할 수 있을 때만 직접 입력을 잠근다.
  postcode.readOnly = true; address.readOnly = true;
  search.hidden = false;
  var previous = document.querySelector('[data-yc-previous-address]');
  var recipient = document.getElementById('yc-recipient');
  var phone = document.getElementById('yc-recipient_phone');
  var deliveryNote = document.getElementById('yc-delivery_note');
  if (previous && recipient && phone && deliveryNote) previous.addEventListener('change', function () {
    var selected = previous.selectedOptions[0];
    if (!selected || !selected.value) return;
    var fresh = selected.value === 'new';
    [[recipient, selected.dataset.recipient], [phone, selected.dataset.recipientPhone],
      [postcode, selected.dataset.postcode], [address, selected.dataset.address],
      [detail, selected.dataset.addressDetail], [deliveryNote, selected.dataset.deliveryNote]].forEach(function (entry) {
      if (!entry[0]) return;
      entry[0].value = fresh ? '' : (entry[1] || '');
      changed(entry[0]);
    });
    close(false);
    if (fresh) recipient.focus();
  });
  search.addEventListener('click', function () {
    var current = ++attempt;
    panel.hidden = false; panel.setAttribute('aria-busy', 'true'); host.replaceChildren();
    search.setAttribute('aria-expanded', 'true'); search.disabled = true;
    message('주소 검색을 불러오는 중입니다.');
    load().then(function (Postcode) {
      if (current !== attempt) return;
      new Postcode({
        width: '100%', height: '100%', minWidth: 240,
        onresize: function (size) {
          if (current === attempt && Number.isFinite(size.height) && size.height > 0) host.style.height = size.height + 'px';
        },
        oncomplete: function (data) {
          if (current !== attempt) return;
          var selected = data.userSelectedType === 'R' ? data.roadAddress : data.jibunAddress;
          selected = selected || data.address;
          if (typeof selected !== 'string' || !selected.trim() || !/^[0-9]{5}$/.test(data.zonecode)) {
            message('선택한 주소를 확인할 수 없습니다. 다시 검색해 주세요.'); return;
          }
          var extra = [];
          if (data.userSelectedType === 'R') {
            if (typeof data.bname === 'string' && /[동로가]$/.test(data.bname)) extra.push(data.bname);
            if (data.apartment === 'Y' && data.buildingName && !extra.includes(data.buildingName)) extra.push(data.buildingName);
          }
          selected = selected.trim() + (extra.length ? ' (' + extra.join(', ') + ')' : '');
          if (postcode.value !== data.zonecode || address.value !== selected) { detail.value = ''; changed(detail); }
          postcode.value = data.zonecode; address.value = selected;
          postcode.readOnly = true; address.readOnly = true;
          changed(postcode); changed(address);
          close(false);
          detail.focus();
        }
      // This page removes the search frame on selection or cancellation.
      }).embed(host, { autoClose: false });
      panel.removeAttribute('aria-busy'); search.disabled = false; message('');
    }).catch(function () {
      if (current !== attempt) return;
      close(false);
      postcode.readOnly = false; address.readOnly = false;
      message('주소 검색을 불러오지 못했습니다. 다시 시도하거나 우편번호와 주소를 직접 입력해 주세요.');
      search.focus();
    });
  });
  closeButton.addEventListener('click', function () { close(true); });
  root.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); close(true); }
  });
})();
