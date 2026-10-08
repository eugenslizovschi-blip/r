// Matrice de dispozitive (telefoane, tablete, laptop, desktop) pe pagina de previzualizare, DOAR CITIRE.
// BASE=https://libertbus.md WP_USER=... WP_PASS=... PARALLEL=2 node tests/e2e-devices.js
// Rulează mai multe dispozitive în paralel (implicit 5) cu o singură autentificare comună.
const { chromium, devices } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const login = require('./login');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const PAGE = process.env.PAGE || '/previzualizare-bilete/';
const OUT = process.env.OUT || 'devices';
const LIST = [
  ['iPhone SE', devices['iPhone SE']],
  ['iPhone 14 Pro Max', devices['iPhone 14 Pro Max']],
  ['Galaxy S9+ (360px)', devices['Galaxy S9+']],
  ['Pixel 7', devices['Pixel 7']],
  ['iPad Mini', devices['iPad Mini']],
  ['iPad landscape', devices['iPad (gen 7) landscape']],
  ['Laptop 1366', { viewport: { width: 1366, height: 768 } }],
  ['Desktop 1920', { viewport: { width: 1920, height: 1080 } }],
];
const proxy = BASE.startsWith('https') && process.env.HTTPS_PROXY ? { proxy: { server: process.env.HTTPS_PROXY } } : {};

async function check(browser, state, [name, dev]) {
  const ctx = await browser.newContext({ ...dev, storageState: state, defaultBrowserType: undefined });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message.slice(0, 90)));
  const r = { device: name, issues: [] };
  try {
    await p.goto(BASE + PAGE, { waitUntil: 'domcontentloaded', timeout: 150000 });
    await p.waitForSelector('.lbb-booking [data-lbb="from"] option[value="Iași"]', { state: 'attached', timeout: 150000 });
    await p.waitForTimeout(5000);
    let f = null; for (const x of await p.$$('.lbb-booking')) if (await x.isVisible()) { f = x; break; }
    if (!f) { r.issues.push('niciun formular vizibil'); return r; }
    await f.scrollIntoViewIfNeeded();
    await (await f.$('[data-lbb="from"]')).selectOption('Iași'); await p.waitForTimeout(300);
    await (await f.$('[data-lbb="route"]')).selectOption({ label: 'Cluj-Napoca' });
    await p.waitForFunction(el => el.querySelectorAll('[data-lbb="time"] option:not([disabled])').length > 1, f, { timeout: 40000 });
    await f.$eval('[data-lbb="time"]', s => { const o = [...s.options].find(x => x.value && !x.disabled); s.value = o.value; s.dispatchEvent(new Event('change')); });
    await (await f.$('[data-lbb="adults"]')).selectOption('5');
    await p.waitForTimeout(400);
    const m1 = await p.evaluate(root => {
      const box = root.getBoundingClientRect();
      const small = [...root.querySelectorAll('select, input:not([type=hidden]), button')].filter(e => e.offsetParent && e.getBoundingClientRect().height < 40).map(e => e.name || e.dataset.lbb || e.textContent.trim().slice(0, 15));
      const tinyFont = [...root.querySelectorAll('select, input:not([type=hidden])')].filter(e => e.offsetParent && parseFloat(getComputedStyle(e).fontSize) < 16).map(e => e.name);
      const time = root.querySelector('[data-lbb="time"]');
      const opt = time.options[time.selectedIndex].textContent;
      const c = document.createElement('canvas').getContext('2d'); const cs = getComputedStyle(time);
      c.font = cs.fontSize + ' ' + cs.fontFamily;
      const textW = c.measureText(opt).width, availW = time.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) - 18;
      const tot = root.querySelector('.lbb-summary-total'); const tcs = getComputedStyle(tot);
      const cramped = parseFloat(tcs.lineHeight) < parseFloat(tcs.fontSize) * 1.15;
      return { cramped, hScroll: document.documentElement.scrollWidth > innerWidth + 1, boxW: Math.round(box.width), boxH: Math.round(box.height), offRight: box.right > innerWidth + 1, offLeft: box.left < -1, small, tinyFont, timeTruncated: textW > availW, opt };
    }, f);
    if (m1.hScroll) r.issues.push('pagina are scroll orizontal');
    if (m1.cramped) r.issues.push('rândurile din rezumat se suprapun (line-height prea mic)');
    if (m1.offRight || m1.offLeft) r.issues.push('formularul iese din ecran');
    if (m1.small.length) r.issues.push('elemente sub 40px înălțime: ' + m1.small.join(','));
    if (m1.tinyFont.length) r.issues.push('font sub 16px (zoom pe iPhone): ' + m1.tinyFont.join(','));
    if (m1.timeTruncated) r.issues.push('ora tăiată în listă: „' + m1.opt + '”');
    r.step1 = m1.boxW + '×' + m1.boxH;
    await f.screenshot({ path: `${OUT}/${name.replace(/\W+/g, '_')}-1.png` });
    await (await f.$('[data-lbb="next"]')).click(); await p.waitForTimeout(700);
    const m2 = await p.evaluate(() => {
      const ov = document.querySelector('.lbb-overlay'); if (!ov) return null;
      const bs = [...ov.querySelectorAll('[data-lbb-submit]')].map(b => { const q = b.getBoundingClientRect(); return { t: b.textContent.trim(), on: q.top >= 0 && q.bottom <= innerHeight, w: Math.round(q.width), h: Math.round(q.height), oneLine: q.height < 70 }; });
      const panel = ov.querySelector('.lbb-booking').getBoundingClientRect();
      const back = ov.querySelector('.lbb-back'); const pay = ov.querySelector('[data-lbb-submit]');
      const backColor = back ? getComputedStyle(back).color : '', accent = pay ? getComputedStyle(pay).borderTopColor : '';
      // Telefonul și sumele nu se rup pe două rânduri.
      const phone = ov.querySelector('a.lbb-phone');
      const phoneLines = phone ? phone.getClientRects().length : 0;
      const tot = ov.querySelector('.lbb-summary-total');
      const totalSplit = tot ? /\d [A-Z]{3}/.test(tot.textContent) : false;
      return { bs, panelW: Math.round(panel.width), hScroll: ov.scrollWidth > ov.clientWidth + 1, backOk: !back || backColor === accent, backColor, phoneLines, totalSplit };
    });
    if (!m2) r.issues.push('fereastra pasului 2 nu s-a deschis');
    else {
      m2.bs.forEach(b => { if (!b.on) r.issues.push('buton ascuns: ' + b.t); if (!b.oneLine) r.issues.push('buton pe 2+ rânduri: ' + b.t); });
      if (m2.hScroll) r.issues.push('fereastra are scroll orizontal');
      if (!m2.backOk) r.issues.push('„Schimbă cursa” are altă culoare: ' + m2.backColor);
      if (m2.phoneLines !== 1) r.issues.push('telefonul de la final e rupt pe ' + m2.phoneLines + ' rânduri (sau lipsește)');
      if (m2.totalSplit) r.issues.push('suma și moneda se pot despărți pe rânduri diferite');
      r.step2 = 'panou ' + m2.panelW + 'px, butoane ' + m2.bs.map(b => b.w + '×' + b.h).join(' / ');
    }
    await p.screenshot({ path: `${OUT}/${name.replace(/\W+/g, '_')}-2.png` });
  } catch (e) { r.issues.push('EROARE: ' + e.message.split('\n')[0]); }
  if (errs.length) r.issues.push('erori JS: ' + errs.join(' | '));
  await ctx.close();
  return r;
}

(async () => {
  const browser = await chromium.launch(proxy);
  // O singură autentificare, sesiunea e împărțită de toate dispozitivele.
  const lctx = await browser.newContext(); const lp = await lctx.newPage();
  await login(lp, BASE, { timeout: 150000 });
  const state = await lctx.storageState(); await lctx.close();
  const results = []; const queue = LIST.slice(); const N = parseInt(process.env.PARALLEL || '5', 10);
  await Promise.all(Array.from({ length: N }, async () => { while (queue.length) results.push(await check(browser, state, queue.shift())); }));
  results.sort((a, b) => LIST.findIndex(x => x[0] === a.device) - LIST.findIndex(x => x[0] === b.device));
  for (const r of results) console.log(`${r.issues.length ? '✗' : '✓'} ${r.device.padEnd(20)} pas1 ${r.step1 || '-'} | ${r.step2 || '-'}${r.issues.length ? '\n    - ' + r.issues.join('\n    - ') : ''}`);
  await browser.close();
})().catch(e => { console.error('FAIL', e.message); process.exit(1); });
