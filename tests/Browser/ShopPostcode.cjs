// PUPPETEER_MODULE=/path/to/puppeteer-core CHROME_BIN=/path/to/chrome node tests/Browser/ShopPostcode.cjs
// Use the real partial/assets and mock only Kakao's external service; never submit a live order.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer-core');
const root = path.resolve(__dirname, '../..');
const html = execFileSync('php', [path.join(__dirname, 'ShopPostcodeFixture.php')], {cwd: root, encoding: 'utf8'});
const sdk = 'https://t1.kakaocdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
const stub = `window.kakao={Postcode:function(options){this.embed=function(host,settings={}){window.embeds=(window.embeds||0)+1;const frame=document.createElement('div');frame.textContent='카카오 주소 검색';host.appendChild(frame);window.postcodeCallbacks={...options,oncomplete(data){options.oncomplete(data);if(settings.autoClose!==false)host.removeChild(frame);}};options.onresize({height:520});}}};`;

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    const page = await browser.newPage(), errors = [], posts = [];
    let requests = 0, mode = 'success', release;
    await page.setRequestInterception(true);
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      if (request.url() === sdk) {
        requests++;
        if (mode === 'fail') return request.abort();
        const respond = () => request.respond({status: 200, contentType: 'text/javascript', body: stub});
        if (mode === 'hold') { release = respond; return; }
        return respond();
      }
      const url = new URL(request.url());
      if (request.method() === 'POST') { posts.push(new URLSearchParams(request.postData())); return request.respond({status: 200, body: 'submitted'}); }
      if (url.pathname === '/cms/shop/checkout') return request.respond({status: 200, contentType: 'text/html', body: html});
      if (['/vendor/daisyui/daisyui.css', '/themes/default/theme.css', '/themes/default/youngcart.css', '/themes/default/youngcart-postcode.js'].includes(url.pathname)) {
        return request.respond({status: 200, contentType: url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css', body: fs.readFileSync(path.join(root, 'www', url.pathname))});
      }
      return request.abort();
    });
    const search = '[data-yc-postcode-search]', panel = '[data-yc-postcode-panel]';
    const open = () => page.goto('https://gnucms.test/cms/shop/checkout');
    const value = selector => page.$eval(selector, el => el.value);
    const visible = selector => page.$eval(selector, el => el.getClientRects().length > 0);
    const choose = data => page.evaluate(data => window.postcodeCallbacks.oncomplete(data), data);
    const searchReady = async () => { await page.click(search); await page.waitForFunction(() => !!window.postcodeCallbacks && !document.querySelector('[data-yc-postcode-search]').disabled); };
    await open();
    assert.equal(requests, 0, 'Load the external SDK only after the user requests address search');
    for (const width of [360, 390, 1280]) {
      await page.setViewport({width, height: 950});
      for (const theme of ['light', 'dark']) {
        await page.$eval('html', (el, theme) => { el.dataset.theme = theme; }, theme);
        const aligned = await page.evaluate(() => {
          const input = document.getElementById('yc-postcode').getBoundingClientRect();
          const button = document.querySelector('[data-yc-postcode-search]').getBoundingClientRect();
          return button.left >= input.right && Math.abs(button.top - input.top) < 2;
        });
        assert.equal(aligned, true, 'Search stays to the right of the postcode input');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
      }
    }
    await searchReady(); assert.equal(requests, 1); assert.equal(await visible(panel), true);
    assert.equal(await page.$eval('[data-yc-postcode-host]', el => el.style.height), '520px');
    const road = {zonecode: '04524', userSelectedType: 'R', roadAddress: '서울 중구 세종대로 110', jibunAddress: '서울 중구 태평로1가 31', bname: '태평로1가', apartment: 'N', buildingName: '서울시청'};
    await choose(road);
    assert.equal(await value('#yc-postcode'), '04524');
    assert.equal(await value('#yc-address'), '서울 중구 세종대로 110 (태평로1가)');
    assert.equal(await value('#yc-address_detail'), '');
    assert.equal(await value('#yc-recipient'), '받는사람');
    assert.equal(await page.evaluate(() => document.activeElement.id), 'yc-address_detail');
    assert.equal(await visible(panel), false);
    await page.type('#yc-address_detail', '202호'); await searchReady(); await choose(road);
    assert.equal(await value('#yc-address_detail'), '202호', 'Selecting the same address preserves the unit number');
    await searchReady(); await choose({...road, userSelectedType: 'J'});
    assert.equal(await value('#yc-address'), '서울 중구 태평로1가 31');
    assert.equal(await value('#yc-address_detail'), '');
    await searchReady(); await choose({...road, zonecode: '123'});
    assert.equal(await visible(panel), true); assert.equal(await value('#yc-postcode'), '04524');
    await page.click('[data-yc-postcode-close]');
    assert.equal(await visible(panel), false);
    assert.equal(await page.$eval(search, el => el === document.activeElement), true);
    assert.equal(requests, 1, 'Reuse the loaded SDK for subsequent searches');

    mode = 'fail'; await open(); await page.click(search);
    await page.waitForFunction(() => document.querySelector('[data-yc-postcode-status]').textContent.includes('불러오지 못했습니다'));
    assert.equal(await value('#yc-address'), '이전 주소'); assert.equal(await value('#yc-address_detail'), '101호');
    assert.equal(await page.$eval(search, el => el.disabled), false);
    mode = 'success'; await searchReady(); assert.equal(await visible(panel), true);

    mode = 'hold'; await open(); await page.click(search);
    await page.waitForFunction(() => document.querySelector('[data-yc-postcode-search]').disabled);
    await page.click('[data-yc-postcode-close]'); await release();
    await page.waitForFunction(() => !!window.kakao);
    assert.equal(await visible(panel), false); assert.equal(await page.evaluate(() => window.embeds || 0), 0);
    mode = 'success'; await searchReady(); assert.equal(await page.evaluate(() => window.embeds), 1);

    await page.setJavaScriptEnabled(false); await open();
    assert.equal(await visible(search), false);
    assert.equal(await page.$eval('#yc-postcode', el => el.disabled || el.readOnly), false);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    assert.equal(posts.at(-1).get('postcode'), '12345'); assert.equal(posts.at(-1).get('address'), '이전 주소');
    assert.deepEqual(errors, []);
    console.log('Shop postcode browser checks passed: button alignment, lazy SDK, road/jibun selection, detail focus, cancel/retry, mobile/dark and manual no-JS submission.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
