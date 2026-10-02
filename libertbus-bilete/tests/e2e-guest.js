// E2E pe o instalare de test, ca vizitator nelogat:
// formular → coș → plată; plata de test nu e vizibilă; scoaterea din coș eliberează locurile.
// BASE=http://127.0.0.1:8080 node tests/e2e-guest.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  page.on('pageerror', e => fail('pageerror: ' + e.message));
  await page.goto(BASE + '/balti-iasi/');
  await page.waitForSelector('[data-lbb="route"] option:checked', { state: 'attached' });
  const day = new Date(Date.now() + 86400000 * (21 + Math.floor(Math.random() * 20))).toISOString().slice(0, 10);
  await page.fill('[data-lbb="date"]', day);
  await page.dispatchEvent('[data-lbb="date"]', 'change');
  await page.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option:not([disabled])').length > 1);
  const time = await page.$eval('[data-lbb="time"] option:not([disabled]):not([value=""])', o => o.value);
  await page.selectOption('[data-lbb="time"]', time);
  const routeId = await page.inputValue('[data-lbb="route"]');
  const freeBefore = await (await page.request.get(`${BASE}/wp-json/lbb/v1/departures?route_id=${routeId}&date=${day}`)).json();
  const before = freeBefore.departures.find(d => d.time === time).free;

  // Fără nume: butonul e activ, dar serverul trebuie să refuze.
  await page.fill('input[name="lbb_phone"]', '+40712345678');
  await page.fill('input[name="lbb_email"]', 'guest@example.com');
  await page.$eval('input[name="lbb_names[]"]', i => { i.required = false; });
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="pay"]')]);
  const alert = await page.$('.lbb-alert');
  if (!alert) fail('lipsește eroarea pentru numele pasagerului'); else console.log('eroare afișată:', (await alert.textContent()).trim());
  const inView = await page.$eval('.lbb-alert', e => { const r = e.getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; });
  if (!inView) fail('mesajul de eroare nu e vizibil pe ecran după reîncărcare');
  if (!(await page.$eval('.lbb-alert', e => e === document.activeElement))) fail('mesajul de eroare nu primește focus');
  if (await page.inputValue('input[name="lbb_email"]') !== 'guest@example.com') fail('emailul nu s-a păstrat după eroare');

  // Formularul păstrează alegerile; completăm numele și trimitem.
  await page.waitForFunction(() => document.querySelectorAll('input[name="lbb_names[]"]').length > 0);
  await page.fill('input[name="lbb_names[]"]', 'Vasile Guest');
  await page.waitForFunction(() => !document.querySelector('[data-lbb-submit]').disabled);
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="pay"]')]);
  if (!/checkout/.test(page.url())) fail('nu s-a ajuns la plată: ' + page.url());
  await page.waitForSelector('#payment');
  if (await page.$('#payment_method_lbb_test')) fail('plata de test e vizibilă pentru vizitatori');
  if (await page.inputValue('#billing_country') !== 'RO') fail('țara nu s-a dedus din +40');
  console.log('checkout ok, țara:', await page.inputValue('#billing_country'));

  const during = (await (await page.request.get(`${BASE}/wp-json/lbb/v1/departures?route_id=${routeId}&date=${day}`)).json()).departures.find(d => d.time === time).free;
  if (during !== before - 1) fail(`locul nu e ținut (înainte ${before}, acum ${during})`);

  // Scoatem biletul din coș: locul trebuie eliberat.
  await page.goto(BASE + '/cart/');
  await Promise.all([page.waitForLoadState('networkidle'), page.click('.product-remove a.remove')]);
  await page.waitForTimeout(800);
  const after = (await (await page.request.get(`${BASE}/wp-json/lbb/v1/departures?route_id=${routeId}&date=${day}`)).json()).departures.find(d => d.time === time).free;
  if (after !== before) fail(`locul nu s-a eliberat după scoaterea din coș (înainte ${before}, după ${after})`);
  console.log(`locuri: ${before} → ${during} → ${after}`);
  console.log(process.exitCode ? 'GUEST: PROBLEME' : 'GUEST: OK');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
