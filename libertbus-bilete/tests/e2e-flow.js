// E2E pe o instalare de test: rezervare ca admin cu plata de test, apoi verificarea biletului.
// BASE=http://127.0.0.1:8080 node tests/e2e-flow.js (user/parolă admin/admin)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const login = require('./login');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
let BROWSER = null; // pentru capturile de la eroare (tests/crash.js)
(async () => {
  const browser = BROWSER = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  // Testul nu e despre cookies: vizitatorul și-a ales deja preferința (bannerul e testat în e2e-cookies.js).
  await page.context().addCookies([{ name: 'lbb_cookie_consent', value: 'necessary', url: BASE }]);
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  // login
  await login(page, BASE);
  // route page with preset
  await page.goto(BASE + '/balti-iasi/');
  await page.waitForSelector('[data-lbb="from"]');
  const preset = { from: await page.inputValue('[data-lbb="from"]'), to: await page.$eval('[data-lbb="route"]', s => s.options[s.selectedIndex].text) };
  console.log('from preset:', preset.from, '| route text:', preset.to);
  if (preset.from !== 'Bălți' || preset.to !== 'Iași') console.log('FAIL pagina rutei Bălți → Iași nu preselectează ruta: ' + JSON.stringify(preset));
  const tomorrow = new Date(Date.now() + 86400000 * (1 + Math.floor(Math.random() * 20))).toISOString().slice(0, 10);
  await page.fill('[data-lbb="date"]', tomorrow);
  await page.dispatchEvent('[data-lbb="date"]', 'change');
  await page.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option').length > 1);
  const opts = await page.$$eval('[data-lbb="time"] option', o => o.map(x => x.textContent + (x.disabled ? ' [x]' : '')));
  console.log('times:', opts.join(' | '));
  // Ora cu cele mai multe locuri libere, ca testul să poată rula de multe ori.
  const best = await page.$$eval('[data-lbb="time"] option', o => o.filter(x => x.value && !x.disabled).sort((a, b) => parseInt(b.textContent.split('—')[1]) - parseInt(a.textContent.split('—')[1]))[0].value);
  await page.selectOption('[data-lbb="time"]', best);
  await page.selectOption('[data-lbb="adults"]', '2');
  const names = await page.$$('input[name="lbb_names[]"]');
  console.log('name fields:', names.length);
  if (names.length !== 2) console.log('FAIL pentru 2 adulți trebuie 2 câmpuri de nume, sunt ' + names.length);
  await names[0].fill('Ion Popescu'); await names[1].fill('Maria Popescu');
  await page.fill('input[name="lbb_phone"]', '+37369184111');
  await page.fill('input[name="lbb_email"]', 'client@example.com');
  const summary = (await page.textContent('[data-lbb="summary"]')).replace(/\s+/g, ' ');
  console.log('summary:', summary);
  // Totalul = 2 × prețul pe pasager, cu echivalentul în RON afișat.
  const sTotal = summary.match(/Total de plată: (\d+) MDL \(≈ \d+ RON\)/);
  const sEach = summary.match(/(\d+) MDL \(≈ \d+ RON\) \/ pasager/);
  if (!sTotal || !sEach || +sTotal[1] !== 2 * +sEach[1]) console.log('FAIL rezumatul prețului: ' + summary);
  await page.screenshot({ path: (process.env.OUT || '.') + '/form.png', fullPage: true });
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="pay"]')]);
  console.log('after submit url:', page.url());
  const alert = await page.$('.lbb-alert'); if (alert) console.log('ALERT:', await alert.textContent());
  await page.waitForSelector('#payment', { timeout: 15000 });
  console.log('address field present:', !!(await page.$('#billing_address_1')));
  console.log('prefill first/last/phone:', await page.inputValue('#billing_first_name'), '/', await page.inputValue('#billing_last_name'), '/', await page.inputValue('#billing_phone'));
  const review = (await page.textContent('.woocommerce-checkout-review-order-table')).replace(/\s+/g, ' ');
  console.log('review:', review.slice(0, 300));
  // În comandă: aceleași locuri, pasageri și total ca în formular.
  if (!/Locuri: 2/.test(review) || !/Pasageri: Ion Popescu, Maria Popescu/.test(review) || !sTotal || !new RegExp('Total MDL' + sTotal[1] + '\\.00').test(review)) console.log('FAIL comanda nu are locurile, pasagerii sau totalul din formular: ' + review.slice(0, 300));
  await page.screenshot({ path: (process.env.OUT || '.') + '/checkout.png', fullPage: true });
  await page.check('#payment_method_lbb_test');
  // WooCommerce redesenează blocul de plată după alegerea metodei: căutăm bifa din nou, nu păstrăm elementul vechi.
  await page.waitForLoadState('networkidle');
  if (await page.locator('#terms').count()) await page.locator('#terms').check();
  await Promise.all([page.waitForURL(/order-received/, { timeout: 30000 }), page.click('#place_order')]);
  console.log('thank you url:', page.url());
  const ticket = await page.textContent('.lbb-ticket');
  console.log('ticket:', ticket.replace(/\s+/g, ' '));
  await page.screenshot({ path: (process.env.OUT || '.') + '/thankyou.png', fullPage: true });
  const link = await page.getAttribute('.lbb-ticket a', 'href');
  await page.goto(link);
  const state = (await page.textContent('.state')).trim();
  console.log('ticket page state:', state);
  // Biblioteca desenează pe un canvas ascuns și arată imaginea PNG rezultată.
  const qr = await page.waitForSelector('.lbb-ticket-qr img[src^="data:image/png"]', { state: 'visible', timeout: 5000 }).then(() => true).catch(() => false);
  console.log('qr rendered:', qr);
  await page.screenshot({ path: (process.env.OUT || '.') + '/ticketpage.png', fullPage: true });
  console.log('JS errors:', errors.length ? errors : 'none');
  // Ce vede clientul după plată: biletul plătit cu ambii pasageri, pagina lui „valabil”, cu cod QR, fără erori JS.
  const flat = ticket.replace(/\s+/g, ' ');
  if (!/Achitat online/.test(flat) || !/Ion Popescu, Maria Popescu/.test(flat)) console.log('FAIL biletul de după plată: ' + flat.slice(0, 200));
  if (!/^Bilet valabil/.test(state)) console.log('FAIL pagina biletului nu spune „Bilet valabil”: ' + state);
  if (!qr) console.log('FAIL codul QR nu apare pe pagina biletului');
  if (errors.length) console.log('FAIL erori JS: ' + errors.join(' | '));
  await browser.close();
})().catch(require('./crash')('flow', () => BROWSER));
