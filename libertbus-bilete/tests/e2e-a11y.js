// E2E: accesibilitate cu axe-core (etichete, contrast, roluri ARIA, text alternativ) pe telefon:
// formularul de pe pagina rutei, bannerul de cookies cu „Setări” deschis, pagina biletului și paginile legale (confidențialitate, cookies); apoi conținutul
// paginilor LibertBus din admin (Panou, Rute și orar, o rută, Pasageri, Rezervări, Setări).
// AXE_JS=/cale/axe.min.js BASE=http://127.0.0.1:8080 node tests/e2e-a11y.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const login = require('./login');
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
  // Id-uri duplicate în zona verificată (axe nu le mai caută din versiunea 4.7): o etichetă sau un aria-describedby
  // ar nimeri alt câmp, ex. cu două formulare pe aceeași pagină. Câmpurile ascunse „_wpnonce” ale WordPress nu contează.
  const dup = await page.evaluate((inc) => {
    const c = {};
    inc.forEach(sel => document.querySelectorAll(sel[0]).forEach(root => [root, ...root.querySelectorAll('[id]')].forEach(e => {
      if (e.id && e.type !== 'hidden') c[e.id] = (c[e.id] || 0) + 1;
    })));
    return Object.keys(c).filter(k => c[k] > 1);
  }, include);
  if (dup.length) found.push('id-uri duplicate: ' + dup.join(', '));
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
  // Cu numărul maxim de pasageri: nota pentru grupuri (text estompat + link de apel), data în cuvinte și rezumatul.
  const maxAdults = await page.$eval('[data-lbb="adults"]', s => s.options[s.options.length - 1].value);
  await page.selectOption('[data-lbb="adults"]', maxAdults);
  await page.waitForSelector('[data-lbb="groupNote"]:not([hidden])');
  // Telefonul din notă se atinge ușor (zonă de cel puțin 28px) și zona lui nu ajunge peste alt câmp sau buton.
  const tap = await page.evaluate(() => {
    const a = document.querySelector('[data-lbb="groupNote"] a.lbb-phone'); if (!a) return null;
    const r = a.getBoundingClientRect();
    const over = [...document.querySelectorAll('.lbb-booking a, .lbb-booking button, .lbb-booking input, .lbb-booking select')].filter(e => e !== a && e.offsetParent).filter(e => { const q = e.getBoundingClientRect(); return q.left < r.right && q.right > r.left && q.top < r.bottom && q.bottom > r.top; }).map(e => e.name || e.dataset.lbb || e.tagName);
    return { h: Math.round(r.height), over };
  });
  if (!tap || tap.h < 28 || tap.over.length) fail('telefonul din nota pentru grupuri e greu de atins sau acoperă alt element: ' + JSON.stringify(tap));
  await scan(page, 'formular cu nota pentru grupuri', [['.lbb-booking']]);
  await page.selectOption('[data-lbb="adults"]', '1');
  // Eroare de la server (telefon „123”): mesajul de alertă și câmpul marcat greșit, verificate și ele.
  await page.fill('input[name="lbb_names[]"]', 'Test Accesibil');
  await page.fill('input[name="lbb_phone"]', '123');
  await page.fill('input[name="lbb_email"]', 'a11y@example.com');
  // Focus vizibil pe butoane de la tastatură, și cu o temă care scoate conturul (button:focus { outline: none }).
  await page.addStyleTag({ content: 'button:focus,button:focus-visible{outline:none!important}' });
  await page.focus('input[name="lbb_email"]');
  let kbFocus = null;
  for (let i = 0; i < 6 && !kbFocus; i++) {
    await page.keyboard.press('Tab');
    kbFocus = await page.evaluate(() => { const a = document.activeElement; if (!a || !a.matches('[data-lbb-submit]')) return null; const s = getComputedStyle(a); return { style: s.outlineStyle, width: parseFloat(s.outlineWidth) }; });
  }
  if (!kbFocus || kbFocus.style === 'none' || kbFocus.width < 2) fail('butonul de trimitere nu are focus vizibil de la tastatură: ' + JSON.stringify(kbFocus));
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="reserve"]')]);
  await page.waitForSelector('.lbb-alert');
  await page.waitForSelector('input[name="lbb_phone"][aria-invalid="true"]', { state: 'attached' }).catch(() => fail('după eroare telefonul nu e marcat aria-invalid'));
  await scan(page, 'formular cu eroare de la server', [['.lbb-booking']]);
  await page.waitForFunction(() => document.querySelectorAll('[data-lbb="time"] option:not([disabled]):not([value=""])').length > 0);
  if (!(await page.inputValue('[data-lbb="time"]'))) await page.selectOption('[data-lbb="time"]', await page.$eval('[data-lbb="time"] option:not([disabled]):not([value=""])', o => o.value));
  await page.fill('input[name="lbb_names[]"]', 'Test Accesibil');
  await page.fill('input[name="lbb_phone"]', '+3736' + String(Math.floor(1e7 + Math.random() * 8e7)));
  await page.fill('input[name="lbb_email"]', 'a11y@example.com');
  // Ca pe un telefon care poate partaja: pe bilet apare și „Trimite biletul”, verificat și el.
  await page.addInitScript(() => { navigator.share = () => Promise.resolve(); });
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb-submit][value="reserve"]')]);
  if (!/lbb_bilet=/.test(page.url())) fail('rezervarea nu a dus la pagina biletului: ' + page.url());
  const ticketUrl = page.url();
  await page.waitForSelector('.lbb-ticket-qr img, .lbb-ticket-qr canvas', { state: 'attached' });
  // Pagina biletului e în întregime a plugin-ului: o verificăm toată (repere, titlu), nu doar biletul.
  if (!(await page.isVisible('.actions .share'))) fail('„Trimite biletul” nu apare pe biletul verificat');
  await scan(page, 'pagina biletului', [['body']]);
  // Linkul stricat (cheie greșită): pagina „Biletul nu a fost găsit” are aceleași repere și linkul spre telefon.
  await page.goto(page.url().replace(/([?&]k=)[^&]+/, '$1' + '0'.repeat(20)));
  await scan(page, 'bilet negăsit', [['body']]);
  // Paginile legale scrise de plugin (titluri, liste, linkul „Setări cookies”): doar conținutul, nu tema.
  for (const slug of ['politica-de-confidentialitate', 'politica-de-cookies']) {
    const r = await page.goto(BASE + '/' + slug + '/');
    if (!r || r.status() !== 200) { fail(slug + ': pagina legală lipsește (' + (r && r.status()) + ')'); continue; }
    await scan(page, slug, [['.entry-content']]);
  }

  // Formularul compact (pagina de previzualizare, cu admin): fereastra pasului 2 e un dialog peste pagină.
  {
    const mctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await mctx.addCookies([{ name: 'lbb_cookie_consent', value: 'necessary', url: BASE }]);
    const mp = await mctx.newPage();
    await login(mp, BASE);
    await mp.goto(BASE + '/previzualizare-bilete/');
    const f = (await mp.$$('.lbb-booking'))[1];
    await mp.waitForFunction(el => el.querySelectorAll('[data-lbb="time"] option:not([disabled]):not([value=""])').length > 0, f);
    await f.$eval('[data-lbb="time"]', t => { t.value = [...t.options].find(o => o.value && !o.disabled).value; t.dispatchEvent(new Event('change')); });
    await (await f.$('[data-lbb="next"]')).click();
    await mp.waitForSelector('.lbb-overlay .lbb-booking');
    // Eticheta ferestrei e textul plugin-ului: fereastra are limba formularului, nu doar a paginii.
    const ovLang = await mp.$eval('.lbb-overlay', o => [o.getAttribute('lang'), o.querySelector('.lbb-booking').getAttribute('lang')]);
    if (!ovLang[0] || ovLang[0] !== ovLang[1]) fail('fereastra formularului fără limba textului: ' + JSON.stringify(ovLang));
    // „← Schimbă cursa”: săgeata e decor, numele butonului pentru cititorul de ecran e doar „Schimbă cursa”.
    if (await mp.getByRole('button', { name: 'Schimbă cursa', exact: true }).count() !== 1) fail('butonul „← Schimbă cursa” are săgeata în numele citit de cititorul de ecran');
    await scan(mp, 'formular compact, pasul 2 (dialog)', [['.lbb-overlay']]);
    await mctx.close();
  }

  // Contrast ridicat (Windows, forced-colors): moneda aleasă se vede altfel decât cealaltă (fundalul colorat dispare).
  {
    const fctx = await browser.newContext({ viewport: { width: 390, height: 844 }, forcedColors: 'active' });
    await fctx.addCookies([{ name: 'lbb_cookie_consent', value: 'necessary', url: BASE }]);
    const fp = await fctx.newPage();
    await fp.goto(BASE + '/balti-iasi/');
    // Fundalul văzut: unul transparent arată culoarea primului părinte cu fundal (altfel „alb” și „transparent pe alb”
    // ar părea diferite, deși pe ecran sunt la fel).
    const chips = await fp.$$eval('.lbb-booking .lbb-chip', cs => cs.filter(c => c.offsetParent).map(c => {
      const span = c.querySelector('span'), s = getComputedStyle(span);
      let bg = 'rgb(255, 255, 255)';
      for (let e = span; e; e = e.parentElement) { const b = getComputedStyle(e).backgroundColor; if (!/rgba\(.*, 0\)$|transparent/.test(b)) { bg = b; break; } }
      return { on: c.querySelector('input').checked, look: bg + '|' + s.color + '|' + s.borderTopWidth + '|' + s.borderTopColor };
    }));
    const on = chips.filter(c => c.on), off = chips.filter(c => !c.on);
    if (chips.length < 2) console.log('contrast ridicat: o singură monedă, sărit');
    else if (on.length !== 1 || off.some(c => c.look === on[0].look)) fail('contrast ridicat: moneda aleasă arată la fel ca cealaltă ' + JSON.stringify(chips));
    else console.log('contrast ridicat (monede): ok');
    await fctx.close();
  }

  // Spațierea textului mărită (WCAG 1.4.12: rânduri 1,5, litere 0,12em, cuvinte 0,16em, paragrafe 2em), pe telefonul
  // cel mai îngust: nimic din formular sau din banner nu se taie și pagina nu capătă scroll orizontal.
  {
    const SPACING = '*{line-height:1.5!important;letter-spacing:.12em!important;word-spacing:.16em!important}p{margin-bottom:2em!important}';
    const clipped = () => {
      const bad = [];
      document.querySelectorAll('.lbb-booking *, #lbb-cc *, .lbb-ticket *, .wrap .actions *').forEach(e => {
        if (e.closest('.lbb-hp') || ['SELECT', 'INPUT', 'TEXTAREA'].includes(e.tagName) || (!e.offsetParent && getComputedStyle(e).position !== 'fixed')) return;
        const cs = getComputedStyle(e);
        if ((e.scrollWidth > e.clientWidth + 1 && /hidden|clip/.test(cs.overflowX)) || (e.scrollHeight > e.clientHeight + 1 && /hidden|clip/.test(cs.overflowY))) bad.push(e.tagName + '.' + e.className);
      });
      if (document.documentElement.scrollWidth > innerWidth + 1) bad.push('scroll orizontal');
      return bad;
    };
    const sctx = await browser.newContext({ viewport: { width: 320, height: 640 } });
    await sctx.route(/googletagmanager\.com|google-analytics\.com/, r => r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
    const sp = await sctx.newPage();
    await sp.goto(BASE + '/balti-iasi/');
    await sp.waitForSelector('#lbb-cc:not([hidden])');
    await sp.addStyleTag({ content: SPACING });
    const c1 = await sp.evaluate(clipped);
    if (c1.length) fail('spațierea textului mărită (formular + banner, 320px) taie: ' + c1.join(', '));
    await login(sp, BASE);
    await sp.goto(BASE + '/previzualizare-bilete/');
    await sp.addStyleTag({ content: SPACING });
    const sf = (await sp.$$('.lbb-booking'))[1];
    await sp.waitForFunction(el => el.querySelectorAll('[data-lbb="time"] option:not([disabled]):not([value=""])').length > 0, sf);
    await sf.$eval('[data-lbb="time"]', t => { t.value = [...t.options].find(o => o.value && !o.disabled).value; t.dispatchEvent(new Event('change')); });
    await (await sf.$('[data-lbb="next"]')).click();
    await sp.waitForSelector('.lbb-overlay .lbb-booking');
    const c2 = await sp.evaluate(clipped);
    if (c2.length) fail('spațierea textului mărită (pasul 2, 320px) taie: ' + c2.join(', '));
    // Și biletul (îl arată șoferului de pe telefon): tabelul cu datele și butoanele de jos.
    await sp.goto(ticketUrl);
    await sp.addStyleTag({ content: SPACING });
    const c3 = await sp.evaluate(clipped);
    if (c3.length) fail('spațierea textului mărită (pagina biletului, 320px) taie: ' + c3.join(', '));
    console.log('spațierea textului (320px): ' + (c1.length + c2.length + c3.length ? 'probleme' : 'ok'));
    await sctx.close();
  }

  // Admin: doar conținutul nostru (.wrap), nu meniurile WordPress.
  const admin = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await login(admin, BASE);
  await admin.goto(BASE + '/wp-admin/admin.php?page=lbb-routes');
  const editUrl = await admin.$eval('a[href*="page=lbb-routes&edit="]', a => a.href);
  for (const u of ['lbb', 'lbb-routes', editUrl, 'lbb-manifest&date=' + day, 'lbb-bookings', 'lbb-settings']) {
    await admin.goto(u.startsWith('http') ? u : BASE + '/wp-admin/admin.php?page=' + u);
    await scan(admin, 'admin ' + u.replace(/^.*page=/, ''), [['#wpbody-content .wrap']]);
  }

  console.log(process.exitCode ? 'A11Y: PROBLEME' : 'A11Y: OK');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
