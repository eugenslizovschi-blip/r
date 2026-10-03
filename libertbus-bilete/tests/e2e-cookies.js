// E2E: bannerul de cookies și blocarea Google Analytics până la acord.
// setup-local.sh adaugă un „Google Analytics” de test (același cod ca pe libertbus.md) și paginile legale.
// BASE=http://127.0.0.1:8080 node tests/e2e-cookies.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const OUT = process.env.OUT || '.';
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };
(async () => {
  const browser = await chromium.launch();
  for (const vp of [{ width: 375, height: 667, name: 'iphone-se' }, { width: 1280, height: 800, name: 'desktop' }]) {
    const ctx = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    const page = await ctx.newPage();
    page.on('pageerror', e => fail(vp.name + ' pageerror: ' + e.message));
    let ga = 0;
    page.on('request', r => { if (/googletagmanager\.com|google-analytics\.com/.test(r.url())) ga++; });
    const trackers = async () => (await ctx.cookies()).filter(c => /^(_ga|_gcl|sbjs_)/.test(c.name)).map(c => c.name);
    const consent = async () => ((await ctx.cookies()).find(c => c.name === 'lbb_cookie_consent') || {}).value;

    // Prima vizită: bannerul apare, nimic de statistică nu pornește.
    await page.goto(BASE + '/balti-iasi/');
    await page.waitForSelector('#lbb-cc:not([hidden])');
    const box = await page.$eval('#lbb-cc', e => { const r = e.getBoundingClientRect(); return { top: r.top, bottom: r.bottom, left: r.left, right: r.right, h: r.height }; });
    if (box.left < 0 || box.right > vp.width || box.bottom > vp.height || box.top < 0) fail(vp.name + ': bannerul iese din ecran ' + JSON.stringify(box));
    if (box.h > vp.height * 0.4) fail(vp.name + ': bannerul acoperă prea mult din ecran (' + Math.round(box.h) + 'px)');
    const btns = await page.$$eval('#lbb-cc button', bs => bs.map(b => ({ t: b.textContent.trim(), h: b.getBoundingClientRect().height, w: b.getBoundingClientRect().width })));
    if (btns.length !== 2 || btns.some(b => b.h < 44)) fail(vp.name + ': butoane greșite ' + JSON.stringify(btns));
    if (Math.abs(btns[0].w - btns[1].w) > 2) fail(vp.name + ': „Doar necesare” trebuie să fie la fel de mare ca „Accept toate” ' + JSON.stringify(btns));
    if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)) fail(vp.name + ': scroll orizontal cu bannerul');
    // La capătul paginii, ultimul conținut rămâne deasupra bannerului (ex. butonul de plată).
    const covered = await page.evaluate(() => {
      window.scrollTo(0, document.documentElement.scrollHeight);
      const cc = document.getElementById('lbb-cc').getBoundingClientRect();
      const pad = parseFloat(getComputedStyle(document.body).paddingBottom);
      return pad < cc.height ? 'padding ' + pad + ' < ' + Math.round(cc.height) : '';
    });
    if (covered) fail(vp.name + ': bannerul acoperă sfârșitul paginii: ' + covered);
    if (!(await page.$('#lbb-cc a[href*="politica-de-cookies"]'))) fail(vp.name + ': bannerul nu are link spre Politica de cookies');
    await page.screenshot({ path: OUT + '/cookies-' + vp.name + '.png' });
    if (ga) fail(vp.name + ': Google Analytics s-a încărcat fără acord');
    if ((await trackers()).length) fail(vp.name + ': cookies de statistică fără acord: ' + (await trackers()).join(','));
    if (await page.evaluate(() => typeof window.dataLayer !== 'undefined')) fail(vp.name + ': codul gtag a rulat fără acord');
    if (await page.evaluate(() => window.lbbTestInline !== 1)) fail(vp.name + ': un script obișnuit a fost blocat');
    if (!(await page.$('script[type="application/ld+json"]'))) fail(vp.name + ': JSON-LD a fost modificat');

    // „Doar necesare”: rămâne blocat și după reîncărcare.
    await page.click('#lbb-cc [data-lbb-cc="necessary"]');
    if (await page.isVisible('#lbb-cc')) fail(vp.name + ': bannerul nu se închide la „Doar necesare”');
    if (await page.evaluate(() => document.body.style.paddingBottom !== '')) fail(vp.name + ': spațiul de sub pagină rămâne după închiderea bannerului');
    if (await consent() !== 'necessary') fail(vp.name + ': alegerea nu s-a salvat');
    await page.reload();
    await page.waitForTimeout(800);
    if (await page.isVisible('#lbb-cc')) fail(vp.name + ': bannerul reapare după „Doar necesare”');
    if (ga || (await trackers()).length) fail(vp.name + ': statistica pornește după „Doar necesare”');
    // Subsolul are linkurile legale și „Setări cookies”, care redeschide bannerul.
    const foot = await page.evaluate(() => {
      const n = document.getElementById('lbb-legal-links');
      return n ? { inFooter: !!n.closest('footer'), text: n.textContent.replace(/\s+/g, ' ').trim() } : null;
    });
    if (!foot || !foot.inFooter || !/Politica de confidențialitate/.test(foot.text) || !/Politica de cookies/.test(foot.text) || !/Setări cookies/.test(foot.text)) fail(vp.name + ': linkurile din subsol lipsesc ' + JSON.stringify(foot));
    await page.click('#lbb-legal-links a[href="#lbb-cookies"]');
    if (!(await page.isVisible('#lbb-cc'))) fail(vp.name + ': „Setări cookies” din subsol nu redeschide bannerul');
    await page.click('#lbb-cc [data-lbb-cc="necessary"]');

    // Din Politica de cookies: „Schimbă preferințele” → „Accept toate” pornește statistica.
    await page.goto(BASE + '/politica-de-cookies/');
    const text = await page.textContent('body');
    if (!/_ga/.test(text) || !/lbb_cookie_consent/.test(text) || !/Doar|necesare/.test(text)) fail(vp.name + ': Politica de cookies nu descrie cookies-urile');
    if (/completați în LibertBus/.test(text)) fail(vp.name + ': Politica de cookies are câmpuri necompletate');
    await page.click('a.lbb-cc-open');
    await page.waitForSelector('#lbb-cc:not([hidden])');
    await page.click('#lbb-cc [data-lbb-cc="all"]');
    await page.waitForFunction(() => typeof window.dataLayer !== 'undefined');
    await page.waitForTimeout(800);
    if (!ga) fail(vp.name + ': Google Analytics nu pornește după „Accept toate”');
    if (await consent() !== 'all') fail(vp.name + ': acordul nu s-a salvat');
    if (!(await trackers()).some(n => /^sbjs_/.test(n))) fail(vp.name + ': sursa vizitei (WooCommerce) nu pornește după acord');

    // După acord, la reîncărcare statistica pornește direct, fără banner.
    ga = 0;
    await page.goto(BASE + '/balti-iasi/');
    await page.waitForTimeout(800);
    if (await page.isVisible('#lbb-cc')) fail(vp.name + ': bannerul reapare după „Accept toate”');
    if (!ga) fail(vp.name + ': Google Analytics nu pornește la vizita următoare');

    // Retragerea acordului șterge cookies de statistică.
    await ctx.addCookies([{ name: '_ga', value: 'GA1.1.1.1', url: BASE }]);
    await page.goto(BASE + '/politica-de-cookies/#lbb-cookies');
    await page.waitForSelector('#lbb-cc:not([hidden])');
    await Promise.all([page.waitForNavigation(), page.click('#lbb-cc [data-lbb-cc="necessary"]')]);
    if ((await trackers()).length) fail(vp.name + ': cookies de statistică rămân după retragerea acordului: ' + (await trackers()).join(','));
    if (await consent() !== 'necessary') fail(vp.name + ': retragerea nu s-a salvat');
    console.log(vp.name + ': ok');
    await ctx.close();
  }

  // Politica de confidențialitate e publicată și legată de WordPress/WooCommerce.
  const p = await (await browser.newContext()).newPage();
  const r = await p.goto(BASE + '/politica-de-confidentialitate/');
  const body = await p.textContent('body');
  if (r.status() !== 200 || !/Google Analytics/.test(body) || !/Politica de cookies/.test(body)) fail('Politica de confidențialitate lipsește sau nu pomenește cookies');
  if (/completați în LibertBus/.test(body)) fail('Politica de confidențialitate are câmpuri necompletate');
  console.log(process.exitCode ? 'COOKIES: PROBLEME' : 'COOKIES: OK');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
