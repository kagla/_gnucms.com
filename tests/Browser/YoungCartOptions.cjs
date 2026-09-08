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
    const cart = 'button[value=cart]';
    const submit = async () => { await Promise.all([page.waitForNavigation(), page.click(cart)]); };
    await page.setViewport({width: 1280, height: 960});
    await open();
    assert.equal(await visible(step(0)), true);
    assert.equal(await visible(step(1)), false);
    assert.equal(await visible(step(2)), false);
    assert.equal(await page.$eval(cart, el => el.disabled), true);
    assert.equal(await page.$eval(step(0), el => el.getAttribute('aria-label')), '1단계 색상');
    assert.match(await text(step(0)), /빨강 · 합산 재고 3개/);
    assert.match(await text(step(0)), /파랑 · 합산 재고 6개/);
    assert.equal(await disabled(0, '품절색'), true);
    await select(0, '빨강');
    assert.equal(await visible(step(1)), true);
    assert.equal(await visible(step(2)), false);
    assert.equal(await disabled(1, 'M'), true);
    assert.equal(await page.$eval(step(1), el => Array.from(el.options).some(option => option.value === 'L')), false);
    await select(1, 'S');
    assert.equal(await visible(step(2)), true);
    assert.equal(await disabled(2, '실크'), true);
    // Even a scripted attempt to select a disabled choice must not resolve an option ID.
    await select(2, '실크');
    assert.equal(await value(step(2)), '');
    assert.equal(await value('[name=option_id]'), '');
    await select(2, '면');
    assert.equal(await value('[name=option_id]'), '101');
    assert.equal(await text('[data-yc-total]'), '10,500원');
    assert.equal(await page.$eval('[name=quantity]', el => el.max), '3');
    assert.equal(await page.$eval(cart, el => el.disabled), false);
    await page.$eval('[name=quantity]', el => { el.value = '4'; el.dispatchEvent(new Event('input', {bubbles: true})); });
    assert.equal(await page.$eval('[name=quantity]', el => el.validity.rangeOverflow), true);
    await page.$eval('[name=quantity]', el => { el.value = '2'; el.dispatchEvent(new Event('input', {bubbles: true})); });
    await page.$eval('[name="extras[201]"]', el => { el.value = '1'; el.dispatchEvent(new Event('input', {bubbles: true})); });
    assert.equal(await text('[data-yc-total]'), '23,000원');
    await select(0, '파랑');
    assert.equal(await value(step(1)), '');
    assert.equal(await visible(step(2)), false);
    assert.equal(await value('[name=option_id]'), '');
    assert.equal(await visible('[data-yc-option-selection]'), false);
    assert.equal(await page.$eval(cart, el => el.disabled), true);
    await select(1, 'L'); await select(2, '실크');
    assert.equal(await text('[data-yc-total]'), '20,000원');
    assert.match(await text('[data-yc-option-selection]'), /재고 2개.*-1,000원/);
    await submit();
    assert.equal(posts.at(-1).path, '/cms/shop/cart/add');
    for (const [key, expected] of Object.entries({product_id: '10', option_id: '104', quantity: '2', 'extras[201]': '1', action: 'cart', csrf_token: 'browser-test-csrf'})) assert.equal(posts.at(-1).data.get(key), expected);

    for (const count of [0, 1, 2, 3]) {
      await open(count);
      if (count === 0) {
        assert.equal(await page.$(step(0)), null);
        assert.equal(await value('[name=option_id]'), '0');
      } else {
        await select(0, '0');
        if (count > 1) await select(1, 'S');
        if (count > 2) await select(2, '면');
        assert.equal(await value('[name=option_id]'), count === 3 ? '107' : '101');
      }
      assert.equal(await page.$eval(cart, el => el.disabled), false);
    }
    await select(0, '__proto__'); await select(1, 'S'); await select(2, '면');
    assert.equal(await value('[name=option_id]'), '108');
    for (const width of [360, 390, 768]) {
      await page.setViewport({width, height: 960});
      for (const theme of ['light', 'dark']) {
        await page.$eval('html', (el, theme) => { el.dataset.theme = theme; }, theme);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.$eval(step(0), el => getComputedStyle(el).appearance), 'auto');
      }
    }
    await page.setJavaScriptEnabled(false); await open();
    assert.equal(await visible('[data-yc-option-stages]'), false);
    assert.equal(await visible('[data-yc-option-fallback]'), true);
    await page.select('[name=option_id]', '101'); await submit();
    assert.equal(posts.at(-1).data.get('option_id'), '101');
    assert.equal(posts.at(-1).data.has('option_step[1]'), false);
    assert.deepEqual(errors, []);
    console.log('YoungCart option browser checks passed: cascading stock/price, reset, canonical submission, 0–3 groups, mobile/dark and no-JS fallback.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
