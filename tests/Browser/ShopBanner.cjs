// PUPPETEER_MODULE=/path/to/puppeteer-core node tests/Browser/ShopBanner.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer-core');
const root = path.resolve(__dirname, '../..');
const render = (...args) => execFileSync('php', [path.join(__dirname, 'ShopBannerFixture.php'), ...args], {cwd: root, encoding: 'utf8'});

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    const page = await browser.newPage(), errors = [];
    let html = render('admin');
    page.on('pageerror', error => errors.push(error.message));
    await page.setRequestInterception(true);
    page.on('request', request => {
      const url = new URL(request.url());
      if (url.hostname === 'cdn.jsdelivr.net' && url.pathname.endsWith('/daisyui.css')) return request.respond({status: 200, contentType: 'text/css', body: fs.readFileSync(path.join(root, 'www/vendor/daisyui/daisyui.css'))});
      if (url.pathname === '/cms/admin/shop/settings' || url.pathname === '/cms/shop') return request.respond({status: 200, contentType: 'text/html', body: html});
      const asset = url.pathname.replace(/^\/cms/, '');
      if (/^\/(themes\/default|vendor\/daisyui)\/[\w.-]+\.(css|js)$/.test(asset)) {
        const file = path.join(root, 'www', asset);
        if (fs.existsSync(file)) return request.respond({status: 200, contentType: asset.endsWith('.js') ? 'text/javascript' : 'text/css', body: fs.readFileSync(file)});
      }
      return request.abort();
    });
    await page.setViewport({width: 1280, height: 960});
    await page.goto('https://gnucms.test/cms/admin/shop/settings');
    const visible = selector => page.$eval(selector, el => el.getClientRects().length > 0);
    const panel = mode => '[data-yc-banner-panel="' + mode + '"]';
    assert.equal(await visible(panel('product')), false);
    assert.equal(await visible(panel('upload')), false);
    await page.select('[name=banner_mode]', 'random');
    assert.equal(await page.$eval('[name=banner_mode]', el => el.value), 'random');
    assert.equal(await visible(panel('product')), false);
    assert.equal(await visible(panel('upload')), false);
    await page.select('[name=banner_mode]', 'product');
    assert.equal(await visible(panel('product')), true);
    await page.select('[name=banner_product_id]', '1');
    await page.select('[name=banner_mode]', 'upload');
    assert.equal(await visible(panel('product')), false);
    assert.equal(await visible(panel('upload')), true);
    await page.type('[name=banner_image_caption]', '새로운 가을');
    await page.select('[name=banner_mode]', 'auto');
    await page.select('[name=banner_mode]', 'upload');
    assert.equal(await page.$eval('[name=banner_image_caption]', el => el.value), '새로운 가을');
    assert.equal(await page.$eval('.yc-edit-form', el => el.enctype), 'multipart/form-data');
    assert.match(await page.$eval('[data-yc-save-status]', el => el.textContent), /저장하지 않은/);
    for (const width of [360, 768, 1280]) {
      await page.setViewport({width, height: 960});
      for (const theme of ['light', 'dark']) {
        await page.$eval('html', (el, theme) => { el.dataset.theme = theme; }, theme);
        assert.equal(await page.evaluate(() => { window.scrollTo(10000, window.scrollY); return window.scrollX === 0; }), true, `admin ${width} ${theme}`);
      }
    }
    await page.$eval('html', el => { el.dataset.theme = 'light'; });
    await page.$eval('#settings-banner', el => el.scrollIntoView());
    await page.screenshot({path: '/tmp/gnucms-banner-admin.png'});
    await page.setJavaScriptEnabled(false);
    await page.reload();
    assert.equal(await visible(panel('product')), true);
    assert.equal(await visible(panel('upload')), true);
    await page.select('[name=banner_mode]', 'product');
    await page.select('[name=banner_product_id]', '1');
    assert.deepEqual(await page.$eval('.yc-edit-form', el => [...el.elements].filter(field => !field.checkValidity()).map(field => field.name)), []);
    html = render('home');
    await page.goto('https://gnucms.test/cms/shop');
    for (const width of [360, 768, 1280]) {
      await page.setViewport({width, height: 960});
      for (const theme of ['light', 'dark']) {
        await page.$eval('html', (el, theme) => { el.dataset.theme = theme; }, theme);
        assert.equal(await page.evaluate(() => { window.scrollTo(10000, window.scrollY); return window.scrollX === 0; }), true, `home ${width} ${theme}`);
      }
    }
    await page.screenshot({path: '/tmp/gnucms-banner-home.png', fullPage: true});
    html = render('home', 'hidden');
    await page.reload();
    assert.equal(await page.$('.yc-promo-grid'), null);
    assert.deepEqual(errors, []);
    console.log('Shop banner browser checks passed: mode switching, retained fields, mobile/dark layouts, no-JS editing and hidden banner.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
