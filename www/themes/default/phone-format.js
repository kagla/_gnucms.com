(function () {
  'use strict';

  function format(value) {
    // 국제번호와 형식을 알 수 없는 값은 사용자가 입력한 그대로 둔다.
    if (/[^0-9 ()-]/.test(value)) return value;
    var digits = value.replace(/\D/g, '');
    var parts = digits.match(/^(01[016789])(\d{3,4})(\d{4})$/) ||
      digits.match(/^(02)(\d{3,4})(\d{4})$/) ||
      digits.match(/^(0[3-6]\d)(\d{3,4})(\d{4})$/) ||
      digits.match(/^(1\d{3})(\d{4})$/);
    if (parts) return parts.slice(1).join('-');

    var first = 0, middle = 0, max = 0;
    if (/^01[016789]/.test(digits)) { first = 3; middle = digits.startsWith('010') ? 4 : 3; max = 11; }
    else if (/^02/.test(digits)) { first = 2; middle = 4; max = 10; }
    else if (/^0[3-6]\d/.test(digits)) { first = 3; middle = 4; max = 11; }
    else if (/^1[5-8]\d{2}/.test(digits)) { first = 4; middle = 4; max = 8; }
    if (!first || digits.length > max || digits.length <= first) return value;

    var formatted = digits.slice(0, first) + '-' + digits.slice(first, first + middle);
    if (digits.length > first + middle) formatted += '-' + digits.slice(first + middle);
    return formatted;
  }

  function formatInput(input) {
    var original = input.value;
    var formatted = format(original);
    if (original === formatted) return;

    var start = input.selectionStart;
    var end = input.selectionEnd;
    var digitsBefore = (original.slice(0, start).match(/\d/g) || []).length;
    var digitsToEnd = (original.slice(0, end).match(/\d/g) || []).length;
    input.value = formatted;

    function position(digits) {
      if (digits === 0) return 0;
      for (var i = 0, seen = 0; i < formatted.length; i++) {
        if (/\d/.test(formatted[i]) && ++seen === digits) return i + 1;
      }
      return formatted.length;
    }
    input.setSelectionRange(start === original.length ? formatted.length : position(digitsBefore),
      end === original.length ? formatted.length : position(digitsToEnd));
  }

  document.addEventListener('beforeinput', function (event) {
    var input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'tel' || input.selectionStart !== input.selectionEnd || /[^0-9 ()-]/.test(input.value)) return;
    var caret = input.selectionStart;
    var from, to;
    if (event.inputType === 'deleteContentBackward' && caret > 1 && input.value[caret - 1] === '-') {
      from = caret - 2; to = caret;
    } else if (event.inputType === 'deleteContentForward' && input.value[caret] === '-') {
      from = caret; to = caret + 2;
    } else return;
    event.preventDefault();
    input.setRangeText('', from, to, 'start');
    formatInput(input);
    input.dispatchEvent(new Event('input', { bubbles: true }));
  });
  document.addEventListener('input', function (event) {
    if (event.isComposing || !(event.target instanceof HTMLInputElement) || event.target.type !== 'tel') return;
    formatInput(event.target);
  });
  document.addEventListener('blur', function (event) {
    if (event.target instanceof HTMLInputElement && event.target.type === 'tel') formatInput(event.target);
  }, true);
})();
