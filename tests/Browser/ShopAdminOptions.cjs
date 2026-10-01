// PUPPETEER_MODULE=/path/to/puppeteer-core node tests/Browser/ShopAdminOptions.cjs
// Exercise the actual product form, option service and shared partial without a live shop.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const puppeteer = require(process.env.PUPPETEER_MODULE || 'puppeteer-core');
const root = path.resolve(__dirname, '../..');
const fixture = (mode, base, input = '') => execFileSync('php', [path.join(__dirname, 'ShopAdminOptionsFixture.php'), mode, base], {cwd: root, input, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024});

(async () => {
  const browser = await puppeteer.launch({executablePath: process.env.CHROME_BIN || '/usr/bin/google-chrome', headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    for (const [base, mode] of [['', 'new'], ['/cms', 'new'], ['', 'edit'], ['/cms', 'edit']]) {
      const page = await browser.newPage(), errors = [], posts = [], submissions = [];
      await page.exposeFunction('captureFormData', entries => { submissions.push(new Map(entries)); });
      const html = fixture(mode, base), url = 'https://gnucms.test' + base + '/admin/shop/products/' + mode;
      let failure = '', held = null, holdNext = false, navigations = 0;
      page.on('pageerror', error => errors.push(error.message));
      page.on('framenavigated', frame => { if (frame === page.mainFrame()) navigations++; });
      await page.setRequestInterception(true);
      page.on('request', async request => {
        const target = new URL(request.url());
        if (request.method() === 'POST') {
          posts.push(request);
          if (request.url() !== url) return request.respond({status: 404, contentType: 'application/json', body: JSON.stringify({error: {message: '요청하신 주소에 해당하는 것이 없습니다.'}})});
          if (request.headers().accept === 'application/json') {
            if (holdNext) { holdNext = false; held = request; return; }
            if (failure === 'network') return request.abort('failed');
            if (failure === 'html') return request.respond({status: 502, contentType: 'text/html', body: '<html>Bad gateway</html>'});
            if (failure === 'session') return request.respond({status: 401, contentType: 'application/json', body: JSON.stringify({error: {message: '로그인이 필요합니다.'}})});
            const result = JSON.parse(fixture('combine', base, request.postData()));
            return request.respond({status: result.status, contentType: 'application/json', body: JSON.stringify(result.body)});
          }
          return request.respond({status: 200, contentType: 'text/html', body: 'submitted'});
        }
        if (request.url() === url) return request.respond({status: 200, contentType: 'text/html', body: html});
        if (target.hostname === 'cdn.jsdelivr.net' && target.pathname.endsWith('/daisyui.css')) return request.respond({status: 200, contentType: 'text/css', body: fs.readFileSync(path.join(root, 'www/vendor/daisyui/daisyui.css'))});
        const asset = target.pathname.slice(base.length), file = path.resolve(root, 'www', '.' + asset);
        if (target.hostname === 'gnucms.test' && file.startsWith(root + '/www/') && fs.existsSync(file) && fs.statSync(file).isFile()) {
          const types = {'.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png', '.svg': 'image/svg+xml'};
          return request.respond({status: 200, contentType: types[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file)});
        }
        return request.abort();
      });
      const field = name => '[name="' + name + '"]';
      const set = (name, value) => page.$eval(field(name), (input, value) => { input.value = value; input.dispatchEvent(new Event('input', {bubbles: true})); }, value);
      const value = name => page.$eval(field(name), input => input.value);
      const count = () => page.$$eval('[data-yc-combos] tbody tr', rows => rows.length);
      const status = () => page.$eval('[data-yc-combine-status]', el => el.textContent);
      const generate = async () => {
        const before = posts.length;
        await page.click('[data-yc-combine]');
        await page.waitForFunction(() => !document.querySelector('[data-yc-combine]').disabled);
        assert.equal(posts.length, before + 1);
        assert.equal(posts.at(-1).url(), url, 'generation must POST to the product form action');
        assert.equal(navigations, 1, 'generation must not navigate');
      };
      await page.setViewport({width: 1280, height: 960});
      assert.equal(html.includes('Warning:'), false, 'product form fixture must render without PHP warnings');
      await page.goto(url);
      const viewLink = '.yc-save-bar a[target="_blank"]';
      assert.equal(await page.$$eval(viewLink, links => links.length), mode === 'edit' ? 1 : 0);
      if (mode === 'edit') {
        assert.equal(await page.$eval(viewLink, link => link.getAttribute('href')), base + '/shop/item?id=BROWSER1');
        assert.equal(await page.$eval(viewLink, link => link.rel), 'noopener');
        assert.match(await page.$eval(viewLink, link => link.textContent), /상품 보기/);
      }
      const extraCount = () => page.$$eval('[data-yc-extras] tbody tr', rows => rows.length);
      const extraNames = () => page.$$eval('[data-yc-extras] input[name$="[value2]"]', inputs => inputs.map(input => input.value));
      const copyExtra = (row, name) => page.$eval('[data-yc-extras] tbody', (body, {row, name}) => body.rows[row].querySelector('[data-yc-copy-down="' + name + '"]').click(), {row, name});
      const removeExtra = row => page.$eval('[data-yc-extras] tbody', (body, row) => body.rows[row].querySelector('[data-yc-remove-extra]').click(), row);
      assert.equal(await page.$$eval('[data-yc-extras] input[type=text][name$="[value1]"]', inputs => inputs.length), 0, 'group names are not editable');
      assert.equal(await extraCount(), 1, 'start with one empty extra option');
      await removeExtra(0);
      assert.equal(await extraCount(), 0, 'the last extra option can be removed');
      assert.equal(await page.$eval('[data-yc-product-form]', form => Array.from(new FormData(form).keys()).some(name => name.startsWith('extras['))), false);
      assert.match(await page.$eval('[data-yc-save-status]', el => el.textContent), /저장하지 않은/);
      await page.click('[data-yc-add-extra]');
      assert.equal(await extraCount(), 1, 'adding after removing all rows still works');
      assert.equal(await value('extras[0][price]'), '0');
      assert.equal(await page.$eval('input[type=checkbox][name="extras[0][active]"]', input => input.checked), true);
      await set('extras[0][value2]', '선물 포장'); await set('extras[0][price]', '700');
      await page.click('[data-yc-add-extra]');
      assert.equal(await extraCount(), 2);
      assert.equal(await value('extras[1][value1]'), '');
      assert.equal(await value('extras[1][value2]'), '');
      await page.click('[data-yc-add-extra]');
      assert.equal(await extraCount(), 3);
      assert.equal(await value('extras[2][value1]'), '');
      assert.equal(await value('extras[0][value2]'), '선물 포장');
      assert.equal(await value('extras[0][price]'), '700');
      await set('extras[1][value2]', '삭제할 포장');
      await set('extras[2][value2]', '추가 포장'); await set('extras[2][price]', '900');
      await removeExtra(1);
      assert.equal(await extraCount(), 2);
      assert.equal(await value('extras[0][value2]'), '선물 포장');
      assert.equal(await value('extras[1][value2]'), '추가 포장');
      assert.equal(await value('extras[1][price]'), '900');
      await page.click('[data-yc-add-extra]');
      assert.equal(await extraCount(), 3);
      assert.equal(await value('extras[2][value2]'), '');
      assert.equal(await value('extras[2][price]'), '0');
      assert.equal(await page.$$eval('[data-yc-extras] input[name="extras[1][value2]"]', inputs => inputs.length), 1, 'adding after a middle deletion must not duplicate field names');
      await removeExtra(2);
      assert.equal(posts.length, 0, 'deleting only edits the draft until the product is saved');
      await page.click('[data-yc-add-extra]'); await set('extras[2][value2]', '카드');
      for (const [name, copyValue, original] of [['price', '900', '700'], ['stock', '7', '9999'], ['stock_alert', '0', '100']]) {
        await set('extras[1][' + name + ']', copyValue); await copyExtra(1, name);
        assert.equal(await value('extras[2][' + name + ']'), copyValue);
        assert.equal(await value('extras[0][' + name + ']'), original);
      }
      const extraStates = () => page.$$eval('[data-yc-extras] input[type=checkbox]', inputs => inputs.map(input => input.checked));
      await page.$eval('input[type=checkbox][name="extras[1][active]"]', input => { input.checked = false; });
      await copyExtra(1, 'active'); assert.deepEqual(await extraStates(), [true, false, false]);
      await page.$eval('input[type=checkbox][name="extras[1][active]"]', input => { input.checked = true; });
      await copyExtra(1, 'active'); assert.deepEqual(await extraStates(), [true, true, true]);
      const settleExtraRows = () => page.waitForFunction(() => [...document.querySelector('[data-yc-extras] tbody').rows].every(row => !row.animated));
      const dragPoints = async (from, to) => {
        await settleExtraRows();
        await page.$eval('[data-yc-extras]', table => table.scrollIntoView({block: 'center', behavior: 'instant'}));
        return page.$eval('[data-yc-extras] tbody', (body, {from, to}) => {
          const handle = body.rows[from].querySelector('[data-yc-extra-drag]').getBoundingClientRect(), target = body.rows[to].getBoundingClientRect();
          return {x: handle.x + handle.width / 2, start: handle.y + handle.height / 2, end: from < to ? target.bottom - 5 : target.top + 5};
        }, {from, to});
      };
      const dragMouse = async (from, to, cancel = false) => {
        const point = await dragPoints(from, to);
        await page.mouse.move(point.x, point.start); await page.mouse.down();
        await page.mouse.move(point.x, point.end, {steps: 12});
        await page.waitForSelector('.yc-extra-floating');
        assert.ok(await page.$eval('.yc-extra-floating', row => row.getAttribute('aria-hidden') === 'true' && [...row.querySelectorAll('input')].every(input => input.disabled && !input.name)), 'drag preview is excluded from accessibility and form submission');
        if (base === '' && mode === 'new' && from === 0 && !cancel) await page.screenshot({path: '/tmp/gnucms-extra-options-drag.png'});
        // Sortable polls the last pointer position every 50 ms before animating the drop target.
        await new Promise(resolve => setTimeout(resolve, 120));
        if (cancel) await page.keyboard.press('Escape');
        await page.mouse.up();
      };
      await dragMouse(0, 2);
      assert.deepEqual(await extraNames(), ['추가 포장', '카드', '선물 포장']);
      assert.equal(await value('extras[2][price]'), '700', 'dragging moves all values together');
      await dragMouse(2, 0);
      assert.deepEqual(await extraNames(), ['선물 포장', '추가 포장', '카드']);
      await dragMouse(0, 2, true);
      assert.deepEqual(await extraNames(), ['선물 포장', '추가 포장', '카드'], 'Escape cancels a drag');
      await page.focus('[data-yc-extra-drag]'); await page.keyboard.press('ArrowDown');
      assert.deepEqual(await extraNames(), ['추가 포장', '선물 포장', '카드']);
      await page.keyboard.press('ArrowUp');
      assert.deepEqual(await extraNames(), ['선물 포장', '추가 포장', '카드']);
      if (base === '' && mode === 'new') {
        await page.setViewport({width: 390, height: 960});
        const client = await page.createCDPSession();
        await client.send('Emulation.setTouchEmulationEnabled', {enabled: true});
        for (const [from, to] of [[0, 2], [2, 0]]) {
          const point = await dragPoints(from, to);
          await client.send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{x: point.x, y: point.start}]});
          for (let step = 1; step <= 10; step++) await client.send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [{x: point.x, y: point.start + (point.end - point.start) * step / 10}]});
          await new Promise(resolve => setTimeout(resolve, 120));
          await client.send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
          assert.deepEqual(await extraNames(), from === 0 ? ['추가 포장', '카드', '선물 포장'] : ['선물 포장', '추가 포장', '카드']);
        }
        await client.send('Emulation.setTouchEmulationEnabled', {enabled: false}); await client.detach();
        assert.ok(await page.$eval('.yc-extra-name', input => input.getBoundingClientRect().width >= 150), 'item names remain readable on mobile');
        await page.$eval('html', html => { html.dataset.theme = 'dark'; });
        await page.screenshot({path: '/tmp/gnucms-extra-options-mobile.png'});
        await page.$eval('html', html => { html.dataset.theme = 'light'; });
        await page.setViewport({width: 1280, height: 960});
        await page.$eval('#section-extras', section => section.scrollIntoView({block: 'center', behavior: 'instant'}));
        await page.screenshot({path: '/tmp/gnucms-extra-options.png'});
      }
      if (base === '' && mode === 'new') {
        // Long lists must scroll the page while the dragged row remains attached to the pointer.
        await page.$eval('[data-yc-add-extra]', button => { for (let i = 0; i < 18; i++) button.click(); });
        await settleExtraRows();
        await page.$eval('[data-yc-extra-drag]', handle => handle.scrollIntoView({block: 'center', behavior: 'instant'}));
        const handle = await page.$eval('[data-yc-extra-drag]', el => { const r = el.getBoundingClientRect(); return {x: r.x + r.width / 2, y: r.y + r.height / 2}; });
        const scrollBefore = await page.evaluate(() => window.scrollY);
        await page.mouse.move(handle.x, handle.y); await page.mouse.down();
        await page.mouse.move(handle.x, 950, {steps: 20});
        await page.waitForFunction(before => window.scrollY > before + 40, {timeout: 5000}, scrollBefore);
        await page.keyboard.press('Escape'); await page.mouse.up();
        await settleExtraRows();
        assert.deepEqual((await extraNames()).slice(0, 3), ['선물 포장', '추가 포장', '카드']);
        await page.$eval('[data-yc-extras] tbody', body => { while (body.rows.length > 3) body.lastElementChild.querySelector('[data-yc-remove-extra]').click(); });
      }
      await removeExtra(2);
      await set('summary', '입력 중인 상품 설명');
      await set('description', '<p>작성 중인 상세 설명</p>');
      await page.waitForFunction(() => window.CKEDITOR && CKEDITOR.instances['yc-description']?.status === 'ready');
      await page.evaluate(() => new Promise(resolve => {
        window.originalEditor = CKEDITOR.instances['yc-description'];
        window.originalEditor.setData('<p>작성 중인 상세 설명</p>', {callback: resolve});
      }));
      await page.$eval(field('images[]'), input => {
        const transfer = new DataTransfer(); transfer.items.add(new File(['draft'], 'draft.png', {type: 'image/png'})); input.files = transfer.files;
      });
      await page.evaluate(() => { window.originalDescription = document.querySelector('[name=description]'); });
      await set('option_group[1]', '색상'); await set('option_values[1]', '빨강,파랑');
      await set('option_group[2]', '크기'); await set('option_values[2]', 'S,0');
      await generate(); // Required name/price fields are deliberately still empty.
      assert.equal(await count(), 4);
      assert.match(await status(), /4개 조합/);
      assert.equal(await value('options[3][value2]'), '0');
      assert.equal(await page.$eval(field('stock'), input => input.readOnly), true);
      assert.equal(await page.$eval(field('images[]'), input => input.files[0].name), 'draft.png');
      assert.equal(await value('summary'), '입력 중인 상품 설명');
      assert.equal(await value('description'), '<p>작성 중인 상세 설명</p>');
      assert.equal(await page.evaluate(() => window.originalDescription === document.querySelector('[name=description]')), true);
      assert.equal(await page.evaluate(() => window.originalEditor === CKEDITOR.instances['yc-description']), true);
      assert.match(await page.evaluate(() => window.originalEditor.getData()), /작성 중인 상세 설명/);
      const sent = JSON.parse(posts.at(-1).postData());
      assert.equal(sent.csrf_token, 'browser-test-csrf');
      assert.equal(sent.action, 'combine');
      assert.deepEqual(Object.keys(sent).sort(), ['action', 'csrf_token', ...(mode === 'edit' ? ['id'] : []), 'option_group', 'option_values', 'options']);
      if (mode === 'edit') assert.equal(sent.id, '10', 'editing must send the product ID');

      await set('options[0][price]', '-500'); await set('options[0][stock]', '7'); await set('options[0][stock_alert]', '3');
      await page.$eval('input[type=checkbox][name="options[0][active]"]', input => { input.checked = false; });
      await set('option_values[1]', '빨강,파랑,초록');
      await generate();
      assert.equal(await count(), 6);
      assert.equal(await value('options[0][price]'), '-500'); assert.equal(await value('options[0][stock]'), '7');
      assert.equal(await value('options[0][stock_alert]'), '3');
      assert.equal(await page.$eval('input[type=checkbox][name="options[0][active]"]', input => input.checked), false);
      assert.equal(await value('options[4][stock]'), '9999');
      await page.click('[data-yc-copy-down=stock]');
      assert.equal(await value('options[5][stock]'), '7');
      const copyDown = (row, name) => page.$eval('[data-yc-combos] tbody', (body, {row, name}) => body.rows[row].querySelector('[data-yc-copy-down="' + name + '"]').click(), {row, name});
      const states = () => page.$$eval('[data-yc-combos] input[type=checkbox]', inputs => inputs.map(input => input.checked));
      await set('options[1][stock_alert]', '0'); await copyDown(1, 'stock_alert');
      assert.deepEqual(await page.$$eval('[data-yc-combos] input[name$="[stock_alert]"]', inputs => inputs.map(input => input.value)), ['3', '0', '0', '0', '0', '0']);
      await page.$eval('input[type=checkbox][name="options[2][active]"]', input => { input.checked = false; });
      await copyDown(2, 'active');
      assert.deepEqual(await states(), [false, true, false, false, false, false]);
      await generate();
      assert.deepEqual(await states(), [false, true, false, false, false, false], 'unchecked copies survive regeneration');
      assert.equal(await value('options[5][stock_alert]'), '0');
      await copyDown(1, 'active');
      assert.deepEqual(await states(), [false, true, true, true, true, true]);
      assert.deepEqual(await page.$$eval('[data-yc-combos] input[type=hidden][name$="[active]"]', inputs => inputs.map(input => input.value)), Array(6).fill('0'));
      assert.deepEqual(await page.$$eval('[data-yc-combos] input[type=checkbox]', inputs => inputs.map(input => input.value)), Array(6).fill('1'));
      await copyDown(5, 'active'); // The last row has no following rows.
      await generate();
      assert.deepEqual(await states(), [false, true, true, true, true, true], 'checked copies survive regeneration');
      assert.match(await page.$eval('[data-yc-save-status]', el => el.textContent), /저장하지 않은/);

      await set('option_values[1]', ''); await generate();
      assert.match(await status(), /함께 입력/); assert.equal(await count(), 6);
      await set('option_values[1]', '빨강,파랑,초록');
      for (failure of ['network', 'html', 'session']) {
        await generate(); assert.equal(await count(), 6); assert.equal(await value('options[0][price]'), '-500');
        assert.match(await status(), failure === 'session' ? /로그인/ : /다시 시도/);
      }
      failure = ''; holdNext = true;
      const pendingRequest = page.waitForRequest(request => request.method() === 'POST');
      await page.click('[data-yc-combine]');
      await pendingRequest;
      await page.waitForFunction(() => document.querySelector('[data-yc-combine]').disabled);
      assert.equal(await page.$eval('button[value=save]', button => button.disabled), true);
      assert.equal(await page.$eval(field('option_values[1]'), input => input.disabled), true);
      assert.equal(await page.$eval('[data-yc-product-form]', form => form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}))), false);
      assert.ok(held);
      const result = JSON.parse(fixture('combine', base, held.postData()));
      await held.respond({status: result.status, contentType: 'application/json', body: JSON.stringify(result.body)});
      await page.waitForFunction(() => !document.querySelector('[data-yc-combine]').disabled);
      assert.equal(await page.$eval('button[value=save]', button => button.disabled), false);

      for (const width of [390, 1280]) {
        await page.setViewport({width, height: 960});
        for (const theme of ['light', 'dark']) {
          await page.$eval('html', (el, theme) => { el.dataset.theme = theme; }, theme);
          assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, width + ' ' + theme);
        }
      }
      if (base === '' && mode === 'new') {
        const csv = Array.from({length: 10}, (_, i) => String(i)).join(',');
        await set('option_group[3]', '소재');
        for (let i = 1; i <= 3; i++) await set('option_values[' + i + ']', csv);
        await generate(); assert.equal(await count(), 1000);
        await set('options[999][stock]', '13'); await generate();
        assert.equal(await value('options[999][stock]'), '13', 'large drafts must not truncate their last row');
        await set('option_group[3]', ''); await set('option_values[3]', '');
      }
      await set('option_group[1]', ''); await set('option_values[1]', '');
      await set('option_group[2]', ''); await set('option_values[2]', ''); await generate();
      assert.equal(await count(), 0);
      assert.equal(await page.$eval(field('stock'), input => input.readOnly), false);
      assert.equal(await value('stock'), '4');
      await set('option_group[1]', '색상'); await set('option_values[1]', '빨강'); await generate();
      await set('name', '저장할 상품'); await set('price', '10000'); await page.select(field('category_id'), '1');
      await page.$eval('[data-yc-product-form]', form => form.addEventListener('formdata', event => {
        window.captureFormData(Array.from(event.formData, ([key, value]) => [key, value instanceof File ? value.name : value]));
      }));
      await Promise.all([page.waitForNavigation(), page.click('button[value=save]')]);
      assert.notEqual(posts.at(-1).headers().accept, 'application/json', 'saving remains a normal product submission');
      assert.equal(submissions.at(-1).get('action'), 'save');
      assert.equal(submissions.at(-1).get('options[0][value1]'), '빨강');
      assert.equal(submissions.at(-1).get('images[]'), 'draft.png');
      assert.equal(submissions.at(-1).get('extras[0][value2]'), '선물 포장');
      assert.equal(submissions.at(-1).get('extras[0][price]'), '700');
      assert.equal(submissions.at(-1).get('extras[1][value2]'), '추가 포장');
      assert.equal(submissions.at(-1).get('extras[1][price]'), '900');
      assert.equal(submissions.at(-1).has('extras[2][value2]'), false);
      assert.equal(Array.from(submissions.at(-1).values()).includes('삭제할 포장'), false);
      assert.match(submissions.at(-1).get('description'), /작성 중인 상세 설명/);

      await page.setJavaScriptEnabled(false); await page.goto(url);
      await page.type(field('option_group[1]'), '색상'); await page.type(field('option_values[1]'), '빨강');
      await Promise.all([page.waitForNavigation(), page.click('[data-yc-combine]')]);
      assert.match(posts.at(-1).postData(), /name="action"\r\n\r\ncombine/);
      assert.deepEqual(errors, []);
      await page.close();
    }
    console.log('Shop admin options passed: AJAX-only updates, draft/file preservation, regeneration, copy-down, validation/network/session errors, pending requests, stock state, save, mouse/touch sorting, drag preview, auto-scroll, Escape/keyboard ordering, mobile/dark, base paths and no-JS fallback.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
