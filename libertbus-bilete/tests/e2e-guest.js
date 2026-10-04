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
  // Prima cerere pentru locuri primește pagina HTML a protecției hostingului: formularul trebuie să reîncerce.
  let blocked = 0, gate = null, release = null;
  await page.route('**/lbb/v1/departures**', async route => {
    if (blocked++ === 0) return route.fulfill({ status: 200, contentType: 'text/html', body: '<!DOCTYPE html><title>One moment, please...</title>' });
    if (gate) await gate; // orele „pe internet slab”: le eliberează testul
    return route.continue();
  });
  await page.goto(BASE + '/balti-iasi/');
  await page.waitForSelector('[data-lbb="route"] option:checked', { state: 'attached' });
  // Safari pe iPhone lasă date în afara min/max: mesajul trebuie să spună intervalul, nu „nu sunt plecări”.
  for (const bad of [new Date(Date.now() - 86400000 * 3), new Date(Date.now() + 86400000 * 400)]) {
    await page.fill('[data-lbb="date"]', bad.toISOString().slice(0, 10));
    await page.dispatchEvent('[data-lbb="date"]', 'change');
    const st = await page.textContent('[data-lbb="status"]');
    const timeOff = await page.$eval('[data-lbb="time"]', s => s.disabled);
    if (!/Online se poate rezerva de azi până pe \d\d\.\d\d\.\d{4}/.test(st) || !timeOff) fail('data în afara intervalului: „' + st + '”');
  }
  const day = new Date(Date.now() + 86400000 * (21 + Math.floor(Math.random() * 20))).toISOString().slice(0, 10);
  await page.fill('[data-lbb="date"]', day);
  await page.dispatchEvent('[data-lbb="date"]', 'change');
  await page.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option:not([disabled])').length > 1);
  if (blocked < 2) fail('formularul nu a reîncercat după răspunsul HTML');
  const time = await page.$eval('[data-lbb="time"] option:not([disabled]):not([value=""])', o => o.value);
  await page.selectOption('[data-lbb="time"]', time);
  const routeId = await page.inputValue('[data-lbb="route"]');
  const freeBefore = await (await page.request.get(`${BASE}/wp-json/lbb/v1/departures?route_id=${routeId}&date=${day}`)).json();
  const before = freeBefore.departures.find(d => d.time === time).free;

  // Fără nume: butonul e activ, dar serverul trebuie să refuze.
  await page.fill('input[name="lbb_phone"]', '+40712345678');
  await page.fill('input[name="lbb_email"]', 'guest@example.com');
  await page.$eval('input[name="lbb_names[]"]', i => { i.required = false; });
  gate = new Promise(r => { release = r; }); // după reîncărcare orele întârzie până le eliberăm mai jos
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="pay"]')]);
  const alert = await page.$('.lbb-alert');
  if (!alert) fail('lipsește eroarea pentru numele pasagerului'); else console.log('eroare afișată:', (await alert.textContent()).trim());
  const inView = await page.$eval('.lbb-alert', e => { const r = e.getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; });
  if (!inView) fail('mesajul de eroare nu e vizibil pe ecran după reîncărcare');
  if (!(await page.$eval('.lbb-alert', e => e === document.activeElement))) fail('mesajul de eroare nu primește focus');
  if (await page.inputValue('input[name="lbb_email"]') !== 'guest@example.com') fail('emailul nu s-a păstrat după eroare');
  // Câmpul cu problema e marcat (contur roșu, aria-invalid legat de mesaj), iar marcajul dispare când clientul scrie.
  await page.waitForFunction(() => document.querySelectorAll('input[name="lbb_names[]"]').length > 0);
  const mark = await page.$eval('input[name="lbb_names[]"]', i => ({ inv: i.getAttribute('aria-invalid'), desc: i.getAttribute('aria-describedby'), alertId: document.querySelector('.lbb-alert').id, border: getComputedStyle(i).borderTopColor }));
  if (mark.inv !== 'true' || !mark.alertId || mark.desc !== mark.alertId) fail('numele lipsă nu e marcat ca greșit: ' + JSON.stringify(mark));
  if (!/rgb\(19[0-9], 4[0-9], 4[0-9]\)/.test(mark.border)) fail('câmpul greșit nu are contur roșu: ' + mark.border);
  if (await page.$eval('input[name="lbb_phone"]', i => i.hasAttribute('aria-invalid'))) fail('telefonul corect e marcat greșit');
  await page.focus('input[name="lbb_names[]"]');
  await page.keyboard.type('Io');
  if (await page.$eval('input[name="lbb_names[]"]', i => i.hasAttribute('aria-invalid'))) fail('marcajul rămâne după ce clientul scrie');
  // Orele sosesc cât clientul scrie: câmpul de nume nu se reface sub degete (cursorul și textul rămân).
  gate = null; release();
  await page.waitForFunction(() => !document.querySelector('[data-lbb="time"]').disabled, null, { timeout: 15000 });
  await page.waitForTimeout(300);
  const typing = await page.evaluate(() => { const a = document.activeElement; return { name: a && a.name, value: a && a.value }; });
  if (typing.name !== 'lbb_names[]' || typing.value !== 'Io') fail('câmpul de nume s-a refăcut cât clientul scria: ' + JSON.stringify(typing));
  await page.fill('input[name="lbb_names[]"]', '');

  // Formularul păstrează alegerile; completăm numele și trimitem.
  await page.waitForFunction(() => document.querySelectorAll('input[name="lbb_names[]"]').length > 0);
  // Un nume fără litere e oprit chiar în browser, fără reîncărcare.
  await page.fill('input[name="lbb_names[]"]', ' - ');
  // Formularul trimite la aceeași adresă: un semn pus pe pagină dispare dacă pagina se reîncarcă.
  await page.evaluate(() => { window.__lbbNoReload = 1; });
  await page.click('[data-lbb-submit][value="pay"]');
  await page.waitForTimeout(800);
  const bad = await page.$eval('input[name="lbb_names[]"]', i => ({ ok: i.checkValidity(), msg: i.validationMessage, focus: i === document.activeElement, stayed: window.__lbbNoReload === 1 }));
  if (bad.ok || !/două litere/.test(bad.msg) || !bad.stayed || !bad.focus) fail('numele „-” nu e oprit în browser: ' + JSON.stringify(bad));
  await page.fill('input[name="lbb_names[]"]', 'Ли');
  if (!(await page.$eval('input[name="lbb_names[]"]', i => i.checkValidity()))) fail('numele scurt în chirilică e respins în browser');
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

  // Adulți și copii: când scade numărul adulților, numele copilului rămâne la copil (nu trece un adult pe locul de copil).
  {
    const kid = await browser.newPage({ viewport: { width: 390, height: 844 } });
    await kid.context().addCookies([{ name: 'lbb_cookie_consent', value: 'necessary', url: BASE }]);
    await kid.goto(BASE + '/balti-iasi/');
    await kid.waitForSelector('[data-lbb="route"] option:checked', { state: 'attached' });
    // O rută cu preț pentru copii (Chișinău → Iași în datele de test).
    const fromCity = await kid.$$eval('[data-lbb="from"] option', os => (os.find(o => /Chi.in.u/.test(o.textContent)) || {}).value);
    await kid.selectOption('[data-lbb="from"]', fromCity);
    const childRoute = await kid.$$eval('[data-lbb="route"] option', os => (os.find(o => /Ia.i/.test(o.textContent)) || {}).value);
    await kid.selectOption('[data-lbb="route"]', childRoute);
    for (let d = 16; d < 24; d++) {
      await kid.fill('[data-lbb="date"]', new Date(Date.now() + 86400000 * d).toISOString().slice(0, 10));
      await kid.dispatchEvent('[data-lbb="date"]', 'change');
      await kid.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option').length > 1 || /nu sunt/.test(document.querySelector('[data-lbb="status"]').textContent), null, { timeout: 10000 }).catch(() => {});
      if (await kid.$('[data-lbb="time"] option:not([disabled]):not([value=""])')) break;
    }
    await kid.selectOption('[data-lbb="time"]', await kid.$eval('[data-lbb="time"] option:not([disabled]):not([value=""])', o => o.value));
    if (!(await kid.isVisible('[data-lbb="children"]'))) fail('ruta Chișinău → Iași ar trebui să aibă preț pentru copii');
    const names = () => kid.$$eval('[data-lbb="names"] label.lbb-field', ls => ls.map(l => l.querySelector('span').textContent.trim() + '=' + l.querySelector('input').value).join(' | '));
    await kid.fill('input[name="lbb_names[]"]', 'Ion Popescu');
    await kid.selectOption('[data-lbb="adults"]', '2');
    await kid.locator('input[name="lbb_names[]"]').nth(1).fill('Maria Popescu');
    await kid.selectOption('[data-lbb="children"]', '1');
    await kid.locator('input[name="lbb_names[]"]').nth(2).fill('Ana Popescu');
    await kid.selectOption('[data-lbb="adults"]', '1');
    const n1 = await names();
    if (n1 !== 'Pasager 1=Ion Popescu | Pasager 2 (copil)=Ana Popescu') fail('după 2→1 adulți numele s-au mutat greșit: ' + n1);
    await kid.selectOption('[data-lbb="adults"]', '2');
    const n2 = await names();
    if (n2 !== 'Pasager 1=Ion Popescu | Pasager 2= | Pasager 3 (copil)=Ana Popescu') fail('după 1→2 adulți copilul nu a rămas copil: ' + n2);
    console.log('adulți/copii:', n1, '→', n2);
    await kid.close();
  }
  console.log(process.exitCode ? 'GUEST: PROBLEME' : 'GUEST: OK');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
