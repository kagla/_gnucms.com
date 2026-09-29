// Render the current cart template and exercise browser behavior without a running shop.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer-core');
const root = path.resolve(__dirname, '../..');
const html = execFileSync('php', [path.join(__dirname, 'ShopCartFixture.php')], {cwd: root, encoding: 'utf8'});

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    const page = await browser.newPage();
    const posts = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.setRequestInterception(true);
    page.on('request', request => {
      const url = new URL(request.url());
      if (request.method() === 'POST') {
        const data = new URLSearchParams(request.postData());
        posts.push({path: url.pathname, data});
        if (request.headers().accept === 'application/json') {
          const selected = data.getAll('selected_products[]');
          const total = selected.reduce((sum, id) => sum + (id === '10' ? 82000 : 25000), 0);
          return request.respond({status: 200, contentType: 'application/json', body: JSON.stringify({
            subtotal: total, shipping_fee: 0, cod_fee: 0, total, valid: true, saved: false
          })});
        }
        return request.respond({status: 200, contentType: 'text/plain', body: 'submitted'});
      }
      if (url.pathname === '/cms/shop/cart') return request.respond({status: 200, contentType: 'text/html', body: html});
      const asset = url.hostname === 'cdn.jsdelivr.net' && url.pathname.endsWith('/daisyui.css')
        ? '/vendor/daisyui/daisyui.css' : url.pathname.replace(/^\/cms/, '');
      if (['/vendor/daisyui/daisyui.css', '/themes/default/theme.css', '/themes/default/youngcart.css',
           '/themes/default/youngcart.js', '/themes/default/phone-format.js'].includes(asset)) {
        return request.respond({status: 200, contentType: asset.endsWith('.js') ? 'text/javascript' : 'text/css', body: fs.readFileSync(path.join(root, 'www', asset))});
      }
      return request.abort();
    });

    await page.setViewport({width: 1280, height: 960});
    await page.goto('https://gnucms.test/cms/shop/cart');
    assert.equal(await page.$eval('.yc-page-heading .yc-title span', el => el.textContent), '2');
    assert.equal(await page.$$eval('[data-yc-cart-product]', els => els.length), 2);
    assert.equal(await page.$$eval('[data-yc-cart-product="10"] .yc-cart-options .yc-cart-item', els => els.length), 2);
    assert.equal(await page.$$eval('[data-yc-cart-product="10"] [data-yc-cart-extras] .yc-cart-item', els => els.length), 1);
    assert.equal(await page.$eval('[data-yc-cart-line="20:202"]', el => el.closest('[data-yc-cart-product]').dataset.ycCartProduct), '20');
    assert.equal(await page.$eval('[data-yc-cart-total="total"]', el => el.textContent.trim()), '107,000원');
    assert.equal(await page.$eval('[name="quantities[10:101]"]', el => el.max), '5');

    await page.click('[data-yc-cart-product="20"] [data-yc-cart-select]');
    await page.waitForFunction(() => document.querySelector('[data-yc-cart-selected-count]').textContent === '1');
    await page.waitForFunction(() => !document.querySelector('[data-yc-cart-checkout]').disabled);
    assert.equal(await page.$eval('[data-yc-cart-total="total"]', el => el.textContent.trim()), '82,000원');
    assert.equal(await page.$eval('[data-yc-cart-select-all]', el => el.indeterminate), true);
    await page.click('[data-yc-cart-select-all]');
    await page.waitForFunction(() => !document.querySelector('[data-yc-cart-checkout]').disabled);
    await page.click('[data-yc-cart-select-all]');
    assert.equal(await page.$eval('[data-yc-cart-checkout]', el => el.disabled), true);
    await page.click('[data-yc-cart-select-all]');
    await page.waitForFunction(() => !document.querySelector('[data-yc-cart-checkout]').disabled);

    await page.click('[data-yc-cart-line="10:101"] [data-yc-cart-plus]');
    assert.equal(await page.$eval('[name="quantities[10:101]"]', el => el.value), '2');
    await new Promise(resolve => setTimeout(resolve, 400));
    await page.waitForFunction(() => document.querySelector('[data-yc-cart-save-status]').hidden);
    assert.equal(posts.some(post => post.data.get('cart_action') === 'update_quantities'
      && post.data.get('quantities[10:101]') === '2'), true);
    await page.$eval('[name="quantities[10:101]"]', el => {
      el.value = '99'; el.dispatchEvent(new Event('input', {bubbles: true}));
    });
    assert.equal(await page.$eval('[name="quantities[10:101]"]', el => el.value), '5');

    for (const width of [360, 1280]) {
      await page.setViewport({width, height: 960});
      assert.equal(await page.$eval('[data-yc-cart-checkout]', el => el.getBoundingClientRect().width > 0), true);
    }
    await page.setJavaScriptEnabled(false);
    await page.goto('https://gnucms.test/cms/shop/cart');
    assert.equal(await page.$('[name="cart_action"][value="update"]') !== null, true);
    await Promise.all([page.waitForNavigation(), page.click('[name="cart_action"][value="update"]')]);
    assert.equal(posts.at(-1).path, '/cms/shop/cart');
    assert.equal(posts.at(-1).data.get('cart_action'), 'update');
    assert.deepEqual(errors, []);
    console.log('Shop cart browser checks passed: grouping, selection totals, quantity saves, stock cap, responsive checkout and no-JS form.');
  } finally {
    await browser.close();
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
