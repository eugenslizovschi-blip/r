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
  // Sumele ca pe bilet: „1.200 MDL” (punct la mii).
  const num = x => +String(x).replace(/\./g, '');
  const sTotal = summary.match(/Total de plată: ([\d.]+) MDL \(≈ [\d.]+ RON\)/);
  const sEach = summary.match(/([\d.]+) MDL \(≈ [\d.]+ RON\) \/ pasager/);
  if (!sTotal || !sEach || num(sTotal[1]) !== 2 * num(sEach[1])) console.log('FAIL rezumatul prețului: ' + summary);
  // Data din rezumat cu ziua săptămânii, ca pe bilet: „21.10.2026 (miercuri) 08:45”.
  const sumDay = ['duminică', 'luni', 'marți', 'miercuri', 'joi', 'vineri', 'sâmbătă'][new Date(tomorrow + 'T12:00:00Z').getUTCDay()];
  if (summary.indexOf(tomorrow.split('-').reverse().join('.') + ' (' + sumDay + ') ' + best) < 0) console.log('FAIL rezumatul nu are ziua săptămânii (' + sumDay + '): ' + summary);
  await page.screenshot({ path: (process.env.OUT || '.') + '/form.png', fullPage: true });
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="pay"]')]);
  console.log('after submit url:', page.url());
  const alert = await page.$('.lbb-alert'); if (alert) console.log('ALERT:', await alert.textContent());
  await page.waitForSelector('#payment', { timeout: 15000 });
  // Clientul se răzgândește la plată: „Înapoi”, altă oră în aceeași zi, „Achit online” din nou. În comandă rămâne
  // un singur bilet, cel nou (nu plătește și cursa greșită).
  await page.goBack();
  await page.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option:not([disabled]):not([value=""])').length > 0 && !document.querySelector('[data-lbb-submit][value="pay"]').disabled, null, { timeout: 15000 });
  const other = await page.$$eval('[data-lbb="time"] option', (o, cur) => (o.find(x => x.value && !x.disabled && x.value !== cur) || {}).value || cur, best);
  await page.selectOption('[data-lbb="time"]', other);
  await page.waitForFunction(() => !document.querySelector('[data-lbb-submit][value="pay"]').disabled);
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="pay"]')]);
  await page.waitForSelector('#payment', { timeout: 15000 });
  // Doar biletele zilei alese (alte zile, ex. tur-retur, rămân în coș intenționat).
  const dayRo = tomorrow.split('-').reverse().join('.');
  const again = await page.$$eval('.woocommerce-checkout-review-order-table .cart_item', (rs, d) => rs.map(r => r.textContent.replace(/\s+/g, ' ')).filter(t => t.includes(d)), dayRo);
  if (again.length !== 1 || !again[0].includes(other)) console.log('FAIL după „Înapoi” și altă oră, la plată trebuie un singur bilet, la ' + other + ': ' + JSON.stringify(again));
  console.log('address field present:', !!(await page.$('#billing_address_1')));
  console.log('prefill first/last/phone:', await page.inputValue('#billing_first_name'), '/', await page.inputValue('#billing_last_name'), '/', await page.inputValue('#billing_phone'));
  const review = (await page.textContent('.woocommerce-checkout-review-order-table')).replace(/\s+/g, ' ');
  console.log('review:', review.slice(0, 300));
  // În comandă: aceleași locuri, pasageri și total ca în formular.
  if (!/Locuri: 2/.test(review) || !/Pasageri: Ion Popescu, Maria Popescu/.test(review) || !sTotal || !new RegExp('Total MDL' + num(sTotal[1]) + '\\.00').test(review)) console.log('FAIL comanda nu are locurile, pasagerii sau totalul din formular: ' + review.slice(0, 300));
  await page.screenshot({ path: (process.env.OUT || '.') + '/checkout.png', fullPage: true });
  await page.check('#payment_method_lbb_test');
  // WooCommerce redesenează blocul de plată după alegerea metodei: căutăm bifa din nou, nu păstrăm elementul vechi.
  await page.waitForLoadState('networkidle');
  if (await page.locator('#terms').count()) await page.locator('#terms').check();
  await Promise.all([page.waitForURL(/order-received/, { timeout: 30000 }), page.click('#place_order')]);
  const thankUrl = page.url();
  console.log('thank you url:', thankUrl);
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
  // Contul meu → Comenzi → Vezi comanda: clientul logat își găsește biletul și mai târziu, o singură dată.
  {
    const orderId = (thankUrl.match(/order-received\/(\d+)/) || [])[1];
    const code = (ticket.match(/LB-[A-Z0-9]{6}/) || [])[0];
    await page.goto(BASE + '/my-account/view-order/' + orderId + '/');
    const mine = await page.$$eval('.lbb-ticket', els => els.map(e => e.textContent.replace(/\s+/g, ' ')));
    if (!orderId || !code || mine.length !== 1 || mine[0].indexOf(code) < 0) console.log('FAIL comanda din Contul meu nu arată biletul (o dată): ' + JSON.stringify({ orderId, code, mine: mine.map(t => t.slice(0, 60)) }));
    // „Comandă din nou” ar duce la un coș gol („Biletele se cumpără din formularul de rezervare”): nu apare la bilete.
    const status = await page.textContent('.woocommerce-MyAccount-content mark.order-status').catch(() => '');
    if (await page.$('.order-again')) console.log('FAIL comanda cu bilet din Contul meu are „Comandă din nou” (duce la un coș gol); starea: ' + status);
    else console.log('fără „Comandă din nou” la comanda cu bilet; starea: ' + status);
  }
  console.log('JS errors:', errors.length ? errors : 'none');
  // Ce vede clientul după plată: biletul plătit cu ambii pasageri, pagina lui „valabil”, cu cod QR, fără erori JS.
  const flat = ticket.replace(/\s+/g, ' ');
  if (!/Achitat online/.test(flat) || !/Ion Popescu, Maria Popescu/.test(flat)) console.log('FAIL biletul de după plată: ' + flat.slice(0, 200));
  if (!/^Bilet valabil/.test(state)) console.log('FAIL pagina biletului nu spune „Bilet valabil”: ' + state);
  if (!qr) console.log('FAIL codul QR nu apare pe pagina biletului');
  if (errors.length) console.log('FAIL erori JS: ' + errors.join(' | '));

  // Finalizarea comenzii cu blocuri („Checkout” din editorul de blocuri) plătește prin Store API, nu prin formularul clasic:
  // rezervarea trebuie legată de comandă și acolo, altfel plata trece și clientul rămâne fără bilet.
  {
    const bDay = new Date(Date.now() + 86400000 * (1 + Math.floor(Math.random() * 20))).toISOString().slice(0, 10);
    // Rezervarea cu „Achit online”; întoarce ora aleasă (aceeași de fiecare dată: prima cu locuri).
    const bookPay = async () => {
      await page.goto(BASE + '/balti-iasi/');
      await page.waitForSelector('[data-lbb="from"]');
      await page.fill('[data-lbb="date"]', bDay);
      await page.dispatchEvent('[data-lbb="date"]', 'change');
      await page.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option:not([disabled]):not([value=""])').length > 0);
      const time = await page.$$eval('[data-lbb="time"] option', o => o.find(x => x.value && !x.disabled).value);
      await page.selectOption('[data-lbb="time"]', time);
      await page.fill('input[name="lbb_names[]"]', 'Blocuri Test');
      await page.fill('input[name="lbb_phone"]', '+37369184111');
      await page.fill('input[name="lbb_email"]', 'blocks@example.com');
      await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="pay"]')]);
      return time;
    };
    const routeId = await (async () => { await page.goto(BASE + '/balti-iasi/'); await page.waitForSelector('[data-lbb="route"] option:checked', { state: 'attached' }); return page.inputValue('[data-lbb="route"]'); })();
    const free = async time => ((await (await page.request.get(`${BASE}/wp-json/lbb/v1/departures?route_id=${routeId}&date=${bDay}`)).json()).departures.find(d => d.time === time) || {}).free;
    // Store API: cheia REST a utilizatorului logat și cheia „Nonce” a coșului, ca în blocul Checkout.
    const rest = (await (await page.request.get(BASE + '/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    // „Șterge” din coșul cu blocuri merge tot prin Store API: locul ținut se eliberează imediat, nu abia la expirare.
    const rTime = await bookPay();
    const fDuring = await free(rTime);
    const rCart = await page.request.get(BASE + '/wp-json/wc/store/v1/cart', { headers: { 'X-WP-Nonce': rest } });
    const rKey = (((await rCart.json()).items || [])[0] || {}).key;
    const rRes = await page.request.post(BASE + '/wp-json/wc/store/v1/cart/remove-item', { headers: { 'X-WP-Nonce': rest, Nonce: rCart.headers()['nonce'] || '' }, data: { key: rKey } });
    const fAfter = await free(rTime);
    console.log(`blocks remove: ${rRes.status()} locuri ${fDuring} → ${fAfter}`);
    if (!rKey || rRes.status() !== 200 || !(fAfter === fDuring + 1)) console.log('FAIL scos din coșul cu blocuri (Store API): locul nu se eliberează: ' + JSON.stringify({ rKey, status: rRes.status(), fDuring, fAfter }));
    await bookPay();
    const cartRes = await page.request.get(BASE + '/wp-json/wc/store/v1/cart', { headers: { 'X-WP-Nonce': rest } });
    const cart = await cartRes.json();
    // Coșul cu blocuri arată cursa (ruta, data, pasagerii) și nu lasă schimbată cantitatea (un bilet = o rezervare).
    const bItem = (cart.items || [])[0] || {};
    const bData = (bItem.item_data || []).map(d => d.key + ': ' + d.value).join(' | ');
    if ((cart.items || []).length !== 1 || !/Ruta: Bălți → Iași/.test(bData) || !/Pasageri: Blocuri Test/.test(bData) || !bItem.quantity_limits || bItem.quantity_limits.editable !== false || bItem.permalink) console.log('FAIL coșul cu blocuri (Store API): cursa nu apare sau cantitatea se poate schimba: ' + JSON.stringify({ n: (cart.items || []).length, bData, q: bItem.quantity_limits, link: bItem.permalink }));
    const res = await page.request.post(BASE + '/wp-json/wc/store/v1/checkout', {
      headers: { 'X-WP-Nonce': rest, Nonce: cartRes.headers()['nonce'] || '' },
      data: { billing_address: cart.billing_address, shipping_address: cart.shipping_address, payment_method: 'lbb_test' },
    });
    const done = await res.json().catch(() => ({}));
    const back = done.payment_result && done.payment_result.redirect_url;
    let bTicket = '';
    if (back) {
      await page.goto(back);
      bTicket = (await page.$('.lbb-ticket')) ? (await page.textContent('.lbb-ticket')).replace(/\s+/g, ' ') : '';
    }
    console.log('blocks checkout:', res.status(), done.status || '', '| ticket:', bTicket.slice(0, 120));
    if (res.status() !== 200 || !/Blocuri Test/.test(bTicket) || !/Achitat online/.test(bTicket)) console.log('FAIL plata prin finalizarea cu blocuri (Store API) nu dă biletul: ' + res.status() + ' ' + JSON.stringify(done).slice(0, 200) + ' | ' + bTicket.slice(0, 200));
  }
  await browser.close();
  // Ultimul rând: QA știe că scriptul a ajuns la capăt (un „ticket:” de la început nu ajunge).
  console.log('FLOW: DONE');
})().catch(require('./crash')('flow', () => BROWSER));
