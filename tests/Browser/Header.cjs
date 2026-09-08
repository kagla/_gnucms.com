// PUPPETEER_MODULE=/path/to/puppeteer-core CHROME_BIN=/path/to/chrome node tests/Browser/Header.cjs
// Use the real public layout and theme assets; no running application or network required.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer-core');
const root = path.resolve(__dirname, '../..');

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    const page = await browser.newPage(), errors = [];
    let html = '';
    page.on('pageerror', error => errors.push(error.message));
    await page.setRequestInterception(true);
    page.on('request', request => {
      const pathname = new URL(request.url()).pathname;
      if (pathname.endsWith('/daisyui.css')) return request.respond({status: 200, contentType: 'text/css', body: fs.readFileSync(path.join(root, 'www/vendor/daisyui/daisyui.css'))});
      if (pathname.endsWith('/theme.css')) return request.respond({status: 200, contentType: 'text/css', body: fs.readFileSync(path.join(root, 'www/themes/default/theme.css'))});
      if (pathname === '/cms/') return request.respond({status: 200, contentType: 'text/html', body: html});
      return request.abort();
    });
    for (const scenario of ['normal', 'overflow']) {
      html = execFileSync('php', [path.join(__dirname, 'HeaderFixture.php'), scenario], {cwd: root, encoding: 'utf8'});
      await page.goto('https://gnucms.test/cms/');
      for (const width of [360, 390, 768, 900, 1280, 1440, 1920]) {
        await page.setViewport({width, height: 960});
        for (const theme of ['light', 'dark']) {
          await page.$eval('html', (el, theme) => { el.dataset.theme = theme; }, theme);
          const layout = await page.evaluate(() => {
            const top = document.querySelector('.navbar.wrap').getBoundingClientRect();
            const row = document.querySelector('.header-tabs .wrap').getBoundingClientRect();
            const nav = document.querySelector('.header-tabs .tabs');
            if (nav.contains(document.activeElement)) document.activeElement.blur();
            nav.scrollLeft = 0;
            const bounds = nav.getBoundingClientRect(), tabs = Array.from(nav.children, el => el.getBoundingClientRect());
            const all = document.querySelector('.gnb-all');
            const gaps = tabs.slice(1).map((rect, index) => rect.left - tabs[index].right);
            return {
              aligned: Math.abs(top.left - row.left) < 1 && Math.abs(top.right - row.right) < 1,
              oneRow: tabs.every(rect => Math.abs(rect.top - tabs[0].top) < 1),
              fits: nav.scrollWidth <= nav.clientWidth + 1,
              filled: Math.abs(tabs.at(-1).right - bounds.right) < 1 && Math.max(...gaps) - Math.min(...gaps) < 1,
              allAligned: !all.getClientRects().length || Math.abs(all.querySelector('svg').getBoundingClientRect().left - document.querySelector('.brand').getBoundingClientRect().left) < 1,
              overflow: document.documentElement.scrollWidth > innerWidth,
            };
          });
          assert.equal(layout.aligned && layout.oneRow && layout.allAligned, true, scenario + ' at ' + width + 'px: ' + JSON.stringify(layout));
          assert.equal(layout.overflow, false, 'Only the menu scrolls horizontally');
          if (layout.fits) assert.equal(layout.filled, true, 'Menu links fill the available width with even spacing');
          else {
            await page.focus('.header-tabs .tab:last-child');
            await page.waitForFunction(() => {
              const el = document.querySelector('.header-tabs .tab:last-child');
              const last = el.getBoundingClientRect(), nav = el.parentElement.getBoundingClientRect();
              return last.left >= nav.left - 1 && last.right <= nav.right + 1;
            }, {timeout: 3000});
          }
        }
      }
    }
    assert.deepEqual(errors, []);
    console.log('Header browser checks passed: matching row widths, full-width spacing, aligned start, single-row overflow, keyboard access, mobile and dark mode.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
