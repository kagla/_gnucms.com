// PUPPETEER_MODULE=/path/to/puppeteer-core CHROME_BIN=/path/to/chrome node tests/Browser/PayProReturn.cjs
// The PG script is replaced with a local stub; no payment or external request is made.
const assert = require('node:assert/strict');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer-core');

const root = path.resolve(__dirname, '../..');
const render = '$payment=["kind"=>"inicis-pro","script"=>"https://paypro.inicis.com/std/payment/js/INIPayPro_v2.js"];'
  + '(function() use ($payment) { include "templates/default/payment/inicis_scripts.php"; })'
  + '->call(new class { public function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, "UTF-8"); }});';
const script = execFileSync('php', ['-r', render], {cwd: root, encoding: 'utf8'});
const callbackUrl = 'https://shop.example.test/shop/pay/callback?order=' + 'a'.repeat(32) + '&state=' + 'b'.repeat(64);
const html = '<!doctype html><form id="yc-pay-form">'
  + '<input name="P_MID" value="INIpayTest"><input name="P_OID" value="' + 'a'.repeat(32) + '">'
  + '<input name="P_AMT" value="200"><input name="P_NEXT_URL" value="' + callbackUrl.replace(/&/g, '&amp;') + '">'
  + '<button id="yc-pay-button" type="button">결제</button></form><p id="yc-pay-message"></p>' + script;

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    const page = await browser.newPage();
    const requests = [];
    await page.setRequestInterception(true);
    page.on('request', request => {
      if (request.url().includes('/INIPayPro_v2.js')) {
        return request.respond({status: 200, contentType: 'text/javascript', body:
          'window.INIPayPro={requestPayment:(options,callback)=>{window.sentOptions=options;setTimeout(()=>callback(location.search.includes("fail=1")?{P_STATUS:"V901",P_RMESG:"authentication failed"}:{P_STATUS:"00",P_MID:options.P_MID,P_OID:options.P_OID,P_AMT:options.P_AMT,P_AUTH_TID:"test-auth",P_IDCNAME:"stg"}),0);return true;}};'});
      }
      if (request.method() === 'POST') {
        requests.push({url: request.url(), body: new URLSearchParams(request.postData())});
        return request.respond({status: 200, contentType: 'text/html', body: 'callback received'});
      }
      if (request.url().startsWith('https://shop.example.test/pay-test')) return request.respond({status: 200, contentType: 'text/html', body: html});
      return request.abort();
    });
    await page.goto('https://shop.example.test/pay-test');
    await page.waitForFunction(() => document.body.textContent.includes('callback received'));
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, callbackUrl);
    assert.equal(requests[0].body.get('P_AUTH_TID'), 'test-auth');
    assert.equal(requests[0].body.get('P_AMT'), '200');
    await page.goto('https://shop.example.test/pay-test?fail=1');
    await page.waitForFunction(() => document.body.textContent.includes('callback received'));
    assert.equal(requests.length, 2);
    assert.equal(requests[1].body.get('P_STATUS'), 'V901');
    assert.equal(requests[1].body.get('P_AUTH_TID'), null);
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
