// E2E: checkout-ul cu blocul „Checkout” (nu shortcode-ul clasic), cu „Plată simplificată” și doar bilete în coș:
// clientul vede numele, emailul și telefonul, dar nu strada, orașul, județul sau codul poștal; rezumatul arată biletul (ora, pasagerii).
// qa.sh pune blocurile pe paginile de finalizare și de coș doar cât rulează testul.
// BASE=http://127.0.0.1:8080 node tests/e2e-blocks.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await page.context().addCookies([{ name: 'lbb_cookie_consent', value: 'necessary', url: BASE }]);
  page.setDefaultTimeout(20000);
  await page.goto(BASE + '/balti-iasi/');
  const day = new Date(Date.now() + 86400000 * (3 + Math.floor(Math.random() * 20))).toISOString().slice(0, 10);
  await page.fill('[data-lbb="date"]', day);
  await page.dispatchEvent('[data-lbb="date"]', 'change');
  await page.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option:not([disabled]):not([value=""])').length > 0);
  await page.selectOption('[data-lbb="time"]', await page.$eval('[data-lbb="time"] option:not([disabled]):not([value=""])', o => o.value));
  await page.fill('input[name="lbb_names[]"]', 'Ion Bloc');
  await page.fill('input[name="lbb_phone"]', '+3736' + String(Math.floor(1e7 + Math.random() * 8e7)));
  await page.fill('input[name="lbb_email"]', 'bloc@example.com');
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="pay"]')]);
  await page.waitForSelector('.wc-block-checkout #billing-first_name').catch(() => fail('checkout-ul cu blocuri nu s-a încărcat: ' + page.url()));
  const shown = await page.$$eval('.wc-block-checkout input, .wc-block-checkout select', els => els.filter(e => e.offsetParent).map(e => e.id));
  ['billing-address_1', 'billing-city', 'billing-postcode', 'billing-state', 'billing-company'].forEach(id => {
    if (shown.includes(id)) fail('checkout cu blocuri: clientul trebuie să completeze ' + id + ' pentru un bilet');
  });
  ['email', 'billing-first_name', 'billing-last_name', 'billing-phone'].forEach(id => {
    if (!shown.includes(id)) fail('checkout cu blocuri: lipsește ' + id);
  });
  // Numele și telefonul din formular sunt deja completate (clientul nu le scrie din nou).
  const pre = await page.$eval('#billing-first_name', i => i.value).catch(() => '');
  if (pre !== 'Ion') fail('checkout cu blocuri: numele din formular nu e precompletat: ' + JSON.stringify(pre));
  // Rezumatul comenzii (pe telefon se deschide cu un clic) arată ruta, ora și pasagerii, fără nota internă a produsului.
  await page.click('.wc-block-components-order-summary__button-text').catch(() => {});
  const item = await page.$eval('.wc-block-components-order-summary-item', e => e.innerText.replace(/\s+/g, ' ')).catch(() => '');
  if (!/Ora plecării/.test(item) || !/Pasageri: Ion Bloc/.test(item) || /Se cumpără din formularul/.test(item)) fail('checkout cu blocuri: rezumatul biletului e greșit: ' + JSON.stringify(item));
  // Coșul cu blocul „Cart”: biletul arată ruta, ora și pasagerii, fără selector de cantitate (locurile se aleg în
  // formular) și fără link spre pagina produsului.
  await page.goto(BASE + '/cart/');
  await page.waitForSelector('.wc-block-cart-items__row').catch(() => fail('coșul cu blocuri nu s-a încărcat'));
  const row = await page.$eval('.wc-block-cart-items__row', r => ({ text: r.innerText.replace(/\s+/g, ' '), qty: !!r.querySelector('.wc-block-components-quantity-selector'), links: r.querySelectorAll('a[href]').length })).catch(() => null);
  if (!row || !/Ora plecării/.test(row.text) || !/Pasageri: Ion Bloc/.test(row.text) || row.qty || row.links) fail('coș cu blocuri: biletul e afișat greșit ' + JSON.stringify(row));
  console.log('câmpuri vizibile: ' + shown.join(', '));
  console.log(process.exitCode ? 'BLOCKS: PROBLEME' : 'BLOCKS: OK');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
