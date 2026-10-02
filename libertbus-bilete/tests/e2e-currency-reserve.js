// E2E pe o instalare de test:
// 1) admin alege RON, plătește cu plata de test → comanda e în RON, biletul arată suma în RON;
// 2) vizitator apasă „Rezerv, achit la urcare” → pagina rezervării, fără plată.
// BASE=http://127.0.0.1:8080 node tests/e2e-currency-reserve.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };

async function fillForm(page, phone) {
  await page.goto(BASE + '/balti-iasi/');
  await page.waitForSelector('[data-lbb="route"] option:checked', { state: 'attached' });
  const day = new Date(Date.now() + 86400000 * (2 + Math.floor(Math.random() * 25))).toISOString().slice(0, 10);
  await page.fill('[data-lbb="date"]', day);
  await page.dispatchEvent('[data-lbb="date"]', 'change');
  await page.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option:not([disabled])').length > 1);
  await page.selectOption('[data-lbb="time"]', await page.$eval('[data-lbb="time"] option:not([disabled]):not([value=""])', o => o.value));
  await page.fill('input[name="lbb_names[]"]', 'Test Pasager');
  await page.fill('input[name="lbb_phone"]', phone);
  await page.fill('input[name="lbb_email"]', 'test@example.com');
}

(async () => {
  const browser = await browser_launch();
  // 1) Plată în RON ca admin.
  const admin = await browser.newPage({ viewport: { width: 390, height: 844 } });
  admin.on('pageerror', e => fail('pageerror: ' + e.message));
  await admin.goto(BASE + '/wp-login.php');
  await admin.fill('#user_login', 'admin'); await admin.fill('#user_pass', 'admin');
  await Promise.all([admin.waitForNavigation(), admin.click('#wp-submit')]);
  await fillForm(admin, '+37369111111');
  const def = await admin.$eval('[data-lbb="currency"]:checked', r => r.value);
  if (def !== 'MDL') fail('moneda implicită pentru Bălți→Iași ar trebui să fie MDL, e ' + def);
  await admin.click('.lbb-chip:has(input[value="RON"])');
  const summary = (await admin.textContent('[data-lbb="summary"]')).replace(/\s+/g, ' ');
  if (!/RON/.test(summary)) fail('sumarul nu e în RON: ' + summary);
  console.log('sumar RON:', summary);
  await admin.screenshot({ path: (process.env.OUT || '.') + '/form-ron.png', fullPage: true });
  await Promise.all([admin.waitForNavigation(), admin.click('[data-lbb-submit][value="pay"]')]);
  await admin.waitForSelector('#payment');
  const total = (await admin.textContent('.order-total')).replace(/\s+/g, ' ');
  if (!/RON|lei/i.test(total)) fail('totalul de la plată nu e în RON: ' + total);
  console.log('checkout total:', total);
  await admin.waitForLoadState('networkidle');
  await admin.waitForSelector('.blockUI', { state: 'detached' }).catch(() => {});
  await admin.locator('#payment_method_lbb_test').check();
  if (await admin.locator('#terms').count()) await admin.locator('#terms').check();
  await Promise.all([admin.waitForURL(/order-received/, { timeout: 30000 }), admin.click('#place_order')]);
  const ticket = (await admin.textContent('.lbb-ticket')).replace(/\s+/g, ' ');
  if (!/Achitat online .*RON/.test(ticket)) fail('biletul nu arată plata în RON: ' + ticket);
  console.log('bilet:', ticket);

  // 2) Rezervare fără plată ca vizitator.
  const guestCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const guest = await guestCtx.newPage();
  guest.on('pageerror', e => fail('pageerror: ' + e.message));
  await fillForm(guest, '+3736' + String(Math.floor(1e7 + Math.random() * 8e7)));
  await guest.screenshot({ path: (process.env.OUT || '.') + '/form-buttons.png', fullPage: true });
  await Promise.all([guest.waitForNavigation(), guest.click('[data-lbb-submit][value="reserve"]')]);
  if (!/lbb_bilet=/.test(guest.url())) fail('rezervarea nu duce la pagina rezervării: ' + guest.url());
  const state = (await guest.textContent('.state')).trim();
  if (!/achitați la urcare/.test(state)) fail('starea rezervării e greșită: ' + state);
  const rticket = (await guest.textContent('.lbb-ticket')).replace(/\s+/g, ' ');
  if (!/De achitat la urcare .*MDL/.test(rticket)) fail('rezervarea nu arată suma la urcare: ' + rticket);
  console.log('rezervare:', state, '|', rticket);
  await guest.waitForTimeout(500);
  await guest.screenshot({ path: (process.env.OUT || '.') + '/reservation.png', fullPage: true });
  console.log(process.exitCode ? 'CURRENCY+RESERVE: PROBLEME' : 'CURRENCY+RESERVE: OK');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });

function browser_launch() { return chromium.launch(); }
