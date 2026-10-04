// E2E: accesibilitate cu axe-core (etichete, contrast, roluri ARIA, text alternativ) pe telefon:
// formularul de pe pagina rutei, bannerul de cookies cu „Setări” deschis și pagina biletului; apoi conținutul
// paginilor LibertBus din admin (Panou, Rute și orar, o rută, Pasageri, Rezervări, Setări).
// AXE_JS=/cale/axe.min.js BASE=http://127.0.0.1:8080 node tests/e2e-a11y.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };
const axe = fs.readFileSync(process.env.AXE_JS, 'utf8');

async function scan(page, label, include) {
  await page.addScriptTag({ content: axe });
  const found = await page.evaluate(async (inc) => {
    const r = await window.axe.run({ include: inc }, { resultTypes: ['violations'] });
    return r.violations.map(v => v.id + ' (' + v.impact + '): ' + v.nodes.slice(0, 3).map(n => n.target.join(' ')).join(', '));
  }, include);
  found.forEach(v => fail(label + ': ' + v));
  console.log(label + ': ' + (found.length ? found.length + ' probleme' : 'ok'));
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  await ctx.route(/googletagmanager\.com|google-analytics\.com/, r => r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
  const page = await ctx.newPage();
  page.setDefaultTimeout(15000);

  // Pagina rutei: formularul complet și bannerul cu categoriile deschise.
  await page.goto(BASE + '/balti-iasi/');
  await page.waitForSelector('#lbb-cc:not([hidden])');
  await page.click('#lbb-cc [data-lbb-cc="settings"]');
  await page.waitForSelector('[data-lbb="route"] option:checked', { state: 'attached' });
  await scan(page, 'formular + banner', [['.lbb-booking'], ['#lbb-cc']]);
  await page.click('#lbb-cc [data-lbb-cc="necessary"]');

  // O rezervare cu plata la urcare, apoi pagina biletului (codul QR, tabelul cu datele).
  const day = new Date(Date.now() + 86400000 * (2 + Math.floor(Math.random() * 25))).toISOString().slice(0, 10);
  await page.fill('[data-lbb="date"]', day);
  await page.dispatchEvent('[data-lbb="date"]', 'change');
  await page.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option:not([disabled])').length > 1);
  await page.selectOption('[data-lbb="time"]', await page.$eval('[data-lbb="time"] option:not([disabled]):not([value=""])', o => o.value));
  await page.fill('input[name="lbb_names[]"]', 'Test Accesibil');
  await page.fill('input[name="lbb_phone"]', '+3736' + String(Math.floor(1e7 + Math.random() * 8e7)));
  await page.fill('input[name="lbb_email"]', 'a11y@example.com');
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="reserve"]')]);
  if (!/lbb_bilet=/.test(page.url())) fail('rezervarea nu a dus la pagina biletului: ' + page.url());
  await page.waitForSelector('.lbb-ticket-qr img, .lbb-ticket-qr canvas', { state: 'attached' });
  await scan(page, 'pagina biletului', [['.lbb-ticket']]);

  // Admin: doar conținutul nostru (.wrap), nu meniurile WordPress.
  const admin = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await admin.goto(BASE + '/wp-login.php');
  await admin.fill('#user_login', 'admin'); await admin.fill('#user_pass', 'admin');
  await Promise.all([admin.waitForNavigation(), admin.click('#wp-submit')]);
  if (/wp-login\.php/.test(admin.url())) { console.error('FAIL login admin eșuat'); process.exit(1); }
  await admin.goto(BASE + '/wp-admin/admin.php?page=lbb-routes');
  const editUrl = await admin.$eval('a[href*="page=lbb-routes&edit="]', a => a.href);
  for (const u of ['lbb', 'lbb-routes', editUrl, 'lbb-manifest&date=' + day, 'lbb-bookings', 'lbb-settings']) {
    await admin.goto(u.startsWith('http') ? u : BASE + '/wp-admin/admin.php?page=' + u);
    await scan(admin, 'admin ' + u.replace(/^.*page=/, ''), [['#wpbody-content .wrap']]);
  }

  console.log(process.exitCode ? 'A11Y: PROBLEME' : 'A11Y: OK');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
