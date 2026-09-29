// Each real payment template must launch its SDK in Chrome; external SDKs are replaced locally.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer-core');

const root = path.resolve(__dirname, '../..');
const orderId = 'a'.repeat(32);
const providers = ['toss', 'nicepay', 'kcp', 'kcp_legacy'];
const sdk = {
  toss: 'window.TossPayments=key=>({requestPayment:(method,fields)=>{window.sent={key,method,fields};return Promise.resolve();}});',
  nicepay: 'window.AUTHNICE={requestPay:fields=>{window.sent={fields};}};',
  kcp: 'window.KCP_Pay_Execute_Web=form=>{window.sent={fields:Object.fromEntries(new FormData(form))};};',
  kcp_legacy: 'window.KCP_Pay_Execute=form=>{window.sent={fields:Object.fromEntries(new FormData(form))};};',
};

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome',
    headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    for (const provider of providers) {
      const html = execFileSync('php', [path.join(__dirname, 'PaymentWindowsFixture.php'), provider],
        {cwd: root, encoding: 'utf8'});
      const page = await browser.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.setRequestInterception(true);
      page.on('request', request => {
        const url = new URL(request.url());
        if (url.pathname === '/payment-fixture') return request.respond({status: 200, contentType: 'text/html', body: html});
        if (url.pathname === '/themes/default/kcp.js') return request.respond({status: 200, contentType: 'text/javascript',
          body: fs.readFileSync(path.join(root, 'www/themes/default/kcp.js'))});
        if (request.resourceType() === 'script') return request.respond({status: 200, contentType: 'text/javascript', body: sdk[provider]});
        return request.abort();
      });
      await page.goto('https://shop.example.test/payment-fixture');
      await page.waitForFunction(() => Boolean(window.sent), {timeout: 3000});
      const sent = await page.evaluate(() => window.sent);
      if (provider === 'toss') {
        assert.equal(sent.method, '카드');
        assert.equal(sent.fields.orderId, orderId);
        assert.equal(sent.fields.amount, 12000);
      } else if (provider === 'nicepay') {
        assert.equal(sent.fields.orderId, orderId);
        assert.equal(sent.fields.amount, 12000);
      } else {
        assert.equal(sent.fields.ordr_idxx, orderId);
        assert.equal(sent.fields.good_mny, '12000');
        assert.equal(sent.fields.site_cd, 'T0000');
      }
      assert.deepEqual(errors, [], provider);
      await page.close();
    }
    console.log('Payment windows browser checks passed: Toss, Nicepay, KCP REST and KCP legacy launch with matching order and amount.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
