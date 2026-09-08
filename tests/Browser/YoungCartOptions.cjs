// Render the actual purchase partial and intercept all requests; no running shop or database required.
// PUPPETEER_MODULE=/path/to/puppeteer-core CHROME_BIN=/path/to/chrome node tests/Browser/YoungCartOptions.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer-core');
const root = path.resolve(__dirname, '../..');
const render = count => execFileSync('php', [path.join(__dirname, 'YoungCartOptionsFixture.php'), String(count)], {cwd: root, encoding: 'utf8'});

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    const page = await browser.newPage(), posts = [], errors = [];
    let html = '';
    await page.setRequestInterception(true);
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      const url = new URL(request.url());
      if (request.method() === 'POST') {
        posts.push({path: url.pathname, data: new URLSearchParams(request.postData())});
        return request.respond({status: 200, body: 'submitted'});
      }
      if (url.pathname === '/cms/shop/item') return request.respond({status: 200, contentType: 'text/html', body: html});
      if (['/vendor/daisyui/daisyui.css', '/themes/default/theme.css', '/themes/default/youngcart.css', '/themes/default/youngcart.js'].includes(url.pathname)) {
        return request.respond({status: 200, contentType: url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css', body: fs.readFileSync(path.join(root, 'www', url.pathname))});
      }
      return request.abort();
    });
    const open = async (count = 3) => { html = render(count); await page.goto('https://gnucms.test/cms/shop/item'); };
    const step = index => '[data-yc-option-step="' + index + '"]';
    const select = (index, value) => page.select(step(index), value);
    const value = selector => page.$eval(selector, el => el.value);
    const visible = selector => page.$eval(selector, el => el.getClientRects().length > 0);
    const text = selector => page.$eval(selector, el => el.textContent);
    const disabled = (index, value) => page.$eval(step(index), (el, value) => Array.from(el.options).find(option => option.value === value).disabled, value);
    const fieldDisabled = index => page.$eval(step(index), el => el.disabled);
    const line = id => '[data-yc-selected-option="' + id + '"]';
    const qty = id => line(id) + ' [data-yc-line-quantity]';
    const setQty = (id, value) => page.$eval(qty(id), (el, value) => { el.value = value; el.dispatchEvent(new Event('input', {bubbles: true})); }, value);
    const cart = 'button[value=cart]';
    const submit = async () => { await Promise.all([page.waitForNavigation(), page.click(cart)]); };
    await page.setViewport({width: 1280, height: 960});
    await open();
    assert.equal(await visible(step(0)), true);
    assert.equal(await visible(step(1)), true);
    assert.equal(await visible(step(2)), true);
    assert.equal(await fieldDisabled(0), false);
    assert.equal(await fieldDisabled(1), true);
    assert.equal(await fieldDisabled(2), true);
    assert.equal(await page.$eval(cart, el => el.disabled), true);
    assert.equal(await page.$eval(step(0), el => el.getAttribute('aria-label')), '색상');
    assert.deepEqual(await page.$$eval('[data-yc-option-stage]>span', labels => labels.map(el => el.textContent)), ['색상', '사이즈', '재질']);
    assert.equal(await page.$eval(step(0), el => Array.from(el.options).find(option => option.value === '빨강').textContent), '빨강');
    assert.equal(await page.$eval(step(0), el => Array.from(el.options).find(option => option.value === '파랑').textContent), '파랑');
    assert.doesNotMatch(await text('[data-yc-option-stages]'), /재고|\d단계/);
    assert.match(await text(step(1)), /먼저 색상 옵션/);
    assert.equal(await disabled(0, '품절색'), true);
    await select(0, '빨강');
    assert.equal(await visible(step(1)), true);
    assert.equal(await visible(step(2)), true);
    assert.equal(await fieldDisabled(1), false);
    assert.equal(await fieldDisabled(2), true);
    assert.doesNotMatch(await text(step(1)), /재고/);
    assert.equal(await disabled(1, 'M'), true);
    assert.equal(await page.$eval(step(1), el => Array.from(el.options).some(option => option.value === 'L')), false);
    await select(1, 'S');
    assert.equal(await visible(step(2)), true);
    assert.equal(await fieldDisabled(2), false);
    assert.match(await text(step(2)), /면 · 재고 3개 \(\+500원\)/);
    assert.equal(await disabled(2, '실크'), true);
    // Even a scripted attempt to select a disabled choice must not resolve an option ID.
    await select(2, '실크');
    assert.equal(await value(step(2)), '');
    assert.equal(await value('[name=option_id]'), '');
    await select(2, '면');
    assert.equal(await value(qty(101)), '1');
    assert.equal(await value(step(2)), '');
    assert.equal(await visible('[data-yc-single-quantity]'), false);
    assert.equal(await text('[data-yc-total]'), '10,500원');
    assert.equal(await page.$eval(qty(101), el => el.max), '3');
    assert.equal(await page.$eval(cart, el => el.disabled), false);
    await select(2, '면');
    assert.equal(await page.$$eval('[data-yc-selected-option]', rows => rows.length), 1);
    assert.match(await text('[data-yc-option-feedback]'), /이미 추가/);
    await setQty(101, '4');
    assert.equal(await page.$eval(qty(101), el => el.validity.rangeOverflow), true);
    await setQty(101, '2');
    await page.$eval('[name="extras[201]"]', el => { el.value = '1'; el.dispatchEvent(new Event('input', {bubbles: true})); });
    assert.equal(await text('[data-yc-total]'), '23,000원');
    await select(0, '파랑');
    assert.equal(await value(step(1)), '');
    assert.equal(await visible(step(2)), true);
    assert.equal(await fieldDisabled(1), false);
    assert.equal(await fieldDisabled(2), true);
    assert.equal(await page.$eval(step(2), el => el.options.length), 1);
    assert.doesNotMatch(await text(step(2)), /재고/);
    assert.equal(await value('[name=option_id]'), '');
    assert.equal(await visible(line(101)), true);
    assert.equal(await page.$eval(cart, el => el.disabled), false);
    await select(1, 'L'); await select(2, '실크');
    assert.equal(await text('[data-yc-total]'), '32,000원');
    assert.match(await text(line(104)), /재고 2개.*-1,000원/);
    await select(2, '면');
    assert.equal(await text('[data-yc-total]'), '42,000원');
    await page.click(line(105) + ' [data-yc-line-remove]');
    assert.equal(await text('[data-yc-total]'), '32,000원');
    await submit();
    assert.equal(posts.at(-1).path, '/cms/shop/cart/add');
    for (const [key, expected] of Object.entries({product_id: '10', 'selections[101]': '2', 'selections[104]': '1', 'extras[201]': '1', action: 'cart', csrf_token: 'browser-test-csrf'})) assert.equal(posts.at(-1).data.get(key), expected);
    assert.equal(posts.at(-1).data.has('option_id'), false);
    assert.equal(posts.at(-1).data.has('quantity'), false);
    assert.equal(posts.at(-1).data.has('selections[105]'), false);

    for (const count of [0, 1, 2, 3]) {
      await open(count);
      if (count === 0) {
        assert.equal(await page.$(step(0)), null);
        assert.equal(await value('[name=option_id]'), '0');
      } else {
        await select(0, '0');
        if (count > 1) await select(1, 'S');
        if (count > 2) await select(2, '면');
        assert.equal(await value(qty(count === 3 ? 107 : 101)), '1');
        if (count === 2) {
          await select(1, 'M');
          assert.equal(await value(qty(102)), '1');
          assert.equal(await text('[data-yc-total]'), '21,500원');
          await page.click(line(101) + ' [data-yc-line-plus]');
          assert.equal(await value(qty(101)), '2');
          await page.click(line(101) + ' [data-yc-line-minus]');
          assert.equal(await value(qty(101)), '1');
        }
      }
      assert.equal(await page.$eval(cart, el => el.disabled), false);
    }
    await select(0, '__proto__'); await select(1, 'S'); await select(2, '면');
    assert.equal(await value(qty(108)), '1');
    await select(1, '');
    assert.equal(await fieldDisabled(2), true);
    assert.equal(await value('[name=option_id]'), '');
    await select(0, '');
    assert.equal(await fieldDisabled(1), true);
    assert.equal(await fieldDisabled(2), true);
    assert.equal(await page.$eval(cart, el => el.disabled), false);
    for (const width of [360, 390, 768]) {
      await page.setViewport({width, height: 960});
      for (const theme of ['light', 'dark']) {
        await page.$eval('html', (el, theme) => { el.dataset.theme = theme; }, theme);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.$eval(step(0), el => getComputedStyle(el).appearance), 'auto');
      }
    }
    for (const id of [107, 108]) await page.click(line(id) + ' [data-yc-line-remove]');
    assert.equal(await page.$eval(cart, el => el.disabled), true);
    assert.equal(await visible('[data-yc-selections]'), false);
    // Product minimum/maximum applies to the combined quantity, while each row has its own stock limit.
    html = render(2).replace('data-buy-max="10"', 'data-buy-max="3"').replace('value="1" min="1" max="10"', 'value="2" min="2" max="3"');
    await page.goto('https://gnucms.test/cms/shop/item');
    await select(0, '0'); await select(1, 'S');
    assert.equal(await page.$eval(qty(101), el => el.validity.customError), true);
    await select(1, 'M');
    assert.equal(await page.$eval(qty(101), el => el.validity.valid), true);
    await setQty(101, '3');
    assert.equal(await page.$eval(qty(101), el => el.validity.customError), true);
    await setQty(101, '1');
    assert.equal(await page.$eval(qty(101), el => el.validity.valid), true);
    await page.setJavaScriptEnabled(false); await open();
    assert.equal(await visible('[data-yc-option-stages]'), false);
    assert.equal(await visible('[data-yc-option-fallback]'), true);
    await page.select('[name=option_id]', '101'); await submit();
    assert.equal(posts.at(-1).data.get('option_id'), '101');
    assert.equal(posts.at(-1).data.has('option_step[1]'), false);
    assert.deepEqual(errors, []);
    console.log('YoungCart option browser checks passed: multiple combinations, per-row quantity/removal, stock and combined limits, totals/submission, dependent fields, mobile/dark and no-JS fallback.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
