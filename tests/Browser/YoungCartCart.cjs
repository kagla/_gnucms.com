// PUPPETEER_MODULE=/path/to/puppeteer-core CHROME_BIN=/path/to/chrome node tests/Browser/YoungCartCart.cjs
// Render the actual cart template; requests and submissions stay inside this browser fixture.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer-core');
const root = path.resolve(__dirname, '../..');
const html = execFileSync('php', [path.join(__dirname, 'YoungCartCartFixture.php')], {cwd: root, encoding: 'utf8'});

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    const page = await browser.newPage(), posts = [], errors = [];
    await page.setRequestInterception(true);
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      const url = new URL(request.url());
      if (request.method() === 'POST') {
        posts.push({path: url.pathname, data: new URLSearchParams(request.postData())});
        return request.respond({status: 200, body: 'submitted'});
      }
      if (url.pathname === '/cms/shop/cart') return request.respond({status: 200, contentType: 'text/html', body: html});
      const asset = url.hostname === 'cdn.jsdelivr.net' && url.pathname.endsWith('/daisyui.css')
        ? '/vendor/daisyui/daisyui.css' : url.pathname.replace(/^\/cms/, '');
      if (['/vendor/daisyui/daisyui.css', '/themes/default/theme.css', '/themes/default/youngcart.css', '/themes/default/youngcart.js'].includes(asset)) {
        return request.respond({status: 200, contentType: asset.endsWith('.js') ? 'text/javascript' : 'text/css', body: fs.readFileSync(path.join(root, 'www', asset))});
      }
      return request.abort();
    });
    const open = () => page.goto('https://gnucms.test/cms/shop/cart');
    const control = id => '[data-yc-cart-quantity]:has([name="quantities[10:' + id + ']"])';
    const input = id => control(id) + ' input';
    const minus = id => control(id) + ' [data-yc-cart-minus]';
    const plus = id => control(id) + ' [data-yc-cart-plus]';
    const value = id => page.$eval(input(id), el => el.value);
    const setQty = (id, quantity) => page.$eval(input(id), (el, quantity) => { el.value = quantity; el.dispatchEvent(new Event('input', {bubbles: true})); }, quantity);
    const save = '.yc-cart-toolbar button';
    const submit = async selector => { await Promise.all([page.waitForNavigation(), page.click(selector)]); };
    await page.setViewport({width: 1280, height: 960});
    await open();
    assert.equal(await page.$eval(minus(101), el => el.disabled), true);
    await page.click(plus(101));
    assert.equal(await value(101), '2');
    assert.equal(await value(102), '1');
    await page.click(minus(101));
    assert.equal(await value(101), '1');
    assert.equal(await page.$eval(minus(101), el => el.disabled), true);
    await setQty(101, '9999');
    assert.equal(await page.$eval(plus(101), el => el.disabled), true);
    await page.click(minus(101));
    assert.equal(await value(101), '9998');
    assert.equal(await page.$eval(plus(101), el => el.disabled), false);
    await setQty(101, ''); await page.click(plus(101));
    assert.equal(await value(101), '1');
    for (const invalid of ['0', '-1', '1.5', '10000']) {
      await setQty(101, invalid);
      assert.equal(await page.$eval(input(101), el => el.checkValidity()), false);
    }
    await setQty(101, '3');
    await page.focus(plus(102)); await page.keyboard.press('Space');
    assert.equal(await value(102), '2');
    assert.equal(posts.length, 0, 'Quantity buttons must not submit the cart');
    for (const width of [360, 390, 768, 1280]) {
      await page.setViewport({width, height: 960});
      for (const theme of ['light', 'dark']) {
        await page.$eval('html', (el, theme) => { el.dataset.theme = theme; }, theme);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.$$eval('[data-yc-cart-quantity]', controls => controls.every(control => {
          const [minus, input, plus] = Array.from(control.children, el => el.getBoundingClientRect());
          return minus.right <= input.left + 1 && input.right <= plus.left + 1 && Math.abs(minus.top - plus.top) < 1 && Math.abs(minus.top + minus.height / 2 - input.top - input.height / 2) < 1;
        })), true, 'Minus, quantity and plus stay aligned in that order');
      }
    }
    await submit(save);
    assert.equal(posts.at(-1).path, '/cms/shop/cart');
    assert.equal(posts.at(-1).data.get('quantities[10:101]'), '3');
    assert.equal(posts.at(-1).data.get('quantities[10:102]'), '2');
    assert.equal(posts.at(-1).data.get('csrf_token'), 'browser-test-csrf');
    await open(); await setQty(101, '');
    await submit('.yc-remove[value="10:101"]');
    assert.equal(posts.at(-1).data.get('remove'), '10:101', 'Delete works even when quantity is invalid');
    await page.setJavaScriptEnabled(false); await open();
    assert.equal(await page.$eval(plus(101), el => el.getClientRects().length), 0);
    await page.focus(input(101)); await page.keyboard.down('Control'); await page.keyboard.press('A'); await page.keyboard.up('Control');
    await page.keyboard.press('Backspace'); await page.type(input(101), '4');
    await submit(save);
    assert.equal(posts.at(-1).data.get('quantities[10:101]'), '4');
    assert.deepEqual(errors, []);
    console.log('YoungCart cart browser checks passed: quantity buttons, independent rows, limits, keyboard/direct input, save/delete, mobile/dark alignment and no-JS fallback.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
