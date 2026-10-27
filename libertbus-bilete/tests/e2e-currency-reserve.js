// E2E pe o instalare de test:
// 1) admin alege RON, plătește cu plata de test → comanda e în RON, biletul arată suma în RON;
// 2) vizitator apasă „Rezerv, achit la urcare” → pagina rezervării, fără plată.
// BASE=http://127.0.0.1:8080 node tests/e2e-currency-reserve.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const login = require('./login');
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

let BROWSER = null; // pentru capturile de la eroare (tests/crash.js)
(async () => {
  const browser = BROWSER = await browser_launch();
  // 1) Plată în RON ca admin.
  const admin = await browser.newPage({ viewport: { width: 390, height: 844 } });
  // Testul nu e despre cookies: vizitatorul și-a ales deja preferința (bannerul e testat în e2e-cookies.js).
  await admin.context().addCookies([{ name: 'lbb_cookie_consent', value: 'necessary', url: BASE }]);
  admin.on('pageerror', e => fail('pageerror: ' + e.message));
  await login(admin, BASE);
  await fillForm(admin, '+37369111111');
  const def = await admin.$eval('[data-lbb="currency"]:checked', r => r.value);
  if (def !== 'MDL') fail('moneda implicită pentru Bălți→Iași ar trebui să fie MDL, e ' + def);
  const mdlSummary = (await admin.textContent('[data-lbb="summary"]')).replace(/\s+/g, ' ');
  if (!/MDL.*≈ [\d,]+ RON/.test(mdlSummary)) fail('lipsește echivalentul în RON: ' + mdlSummary);
  console.log('sumar MDL:', mdlSummary);
  const ronEnabled = !!(await admin.$('[data-lbb="currency"][value="RON"]'));
  const PAY = ronEnabled ? 'RON' : 'MDL';
  if (ronEnabled) {
    await admin.click('.lbb-chip:has(input[value="RON"])');
    const summary = (await admin.textContent('[data-lbb="summary"]')).replace(/\s+/g, ' ');
    if (!/Total de plată: [\d,]+ RON/.test(summary)) fail('sumarul nu e în RON: ' + summary);
    console.log('sumar RON:', summary);
  } else {
    console.log('(plata în RON e oprită în setări: se plătește în MDL)');
  }
  await admin.screenshot({ path: (process.env.OUT || '.') + '/form-ron.png', fullPage: true });
  await Promise.all([admin.waitForNavigation(), admin.click('[data-lbb-submit][value="pay"]')]);
  await admin.waitForSelector('#payment');
  const total = (await admin.textContent('.order-total')).replace(/\s+/g, ' ');
  if (PAY === 'RON' ? !/RON|lei/i.test(total) : !/MDL/.test(total)) fail('totalul de la plată nu e în ' + PAY + ': ' + total);
  console.log('checkout total:', total);
  await admin.waitForLoadState('networkidle');
  await admin.waitForSelector('.blockUI', { state: 'detached' }).catch(() => {});
  await admin.locator('#payment_method_lbb_test').check();
  if (await admin.locator('#terms').count()) await admin.locator('#terms').check();
  await Promise.all([admin.waitForURL(/order-received/, { timeout: 30000 }), admin.click('#place_order')]);
  const ticket = (await admin.textContent('.lbb-ticket')).replace(/\s+/g, ' ');
  if (!new RegExp('Achitat online .*' + PAY).test(ticket)) fail('biletul nu arată plata în ' + PAY + ': ' + ticket);
  console.log('bilet:', ticket);
  // Pe telefon, „Deschide biletul” și „Adaugă în calendar” se ating ușor (zonă de cel puțin 28px) și nu se suprapun.
  const links = await admin.$$eval('.lbb-ticket p a', as => as.map(a => { const r = a.getBoundingClientRect(); return { h: Math.round(r.height), l: r.left, r: r.right, t: r.top, b: r.bottom }; }));
  const overlap = links.length > 1 && links[0].r > links[1].l && links[0].l < links[1].r && links[0].b > links[1].t && links[0].t < links[1].b;
  if (links.length < 2 || links.some(x => x.h < 28) || overlap) fail('linkurile de pe bilet sunt greu de atins: ' + JSON.stringify(links));

  // 2) Rezervare fără plată ca vizitator.
  const guestCtx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const guest = await guestCtx.newPage();
  // Testul nu e despre cookies: vizitatorul și-a ales deja preferința (bannerul e testat în e2e-cookies.js).
  await guest.context().addCookies([{ name: 'lbb_cookie_consent', value: 'necessary', url: BASE }]);
  guest.on('pageerror', e => fail('pageerror: ' + e.message));
  await fillForm(guest, '+3736' + String(Math.floor(1e7 + Math.random() * 8e7)));
  await guest.screenshot({ path: (process.env.OUT || '.') + '/form-buttons.png', fullPage: true });
  const chosenTime = await guest.inputValue('[data-lbb="time"]');
  // Internet lent: cât serverul nu a răspuns, sub butoane scrie ce se întâmplă (anunțat și cititoarelor de ecran).
  // Citim mesajul chiar când pagina pleacă (pagehide) și îl păstrăm în sessionStorage pentru verificare.
  await guest.evaluate(() => window.addEventListener('pagehide', () => {
    const e = document.querySelector('[data-lbb="sending"]');
    sessionStorage.setItem('lbbSending', JSON.stringify(e ? { text: e.textContent, role: e.getAttribute('role'), shown: e.getClientRects().length > 0 } : { missing: true }));
  }));
  await Promise.all([guest.waitForNavigation(), guest.click('[data-lbb-submit][value="reserve"]')]);
  const sending = JSON.parse(await guest.evaluate(() => sessionStorage.getItem('lbbSending')) || '{}');
  if (!/Se trimite rezervarea/.test(sending.text || '') || sending.role !== 'status' || !sending.shown) fail('la trimitere lipsește mesajul „Se trimite…”: ' + JSON.stringify(sending));
  if (!/lbb_bilet=/.test(guest.url())) fail('rezervarea nu duce la pagina rezervării: ' + guest.url());
  const state = (await guest.textContent('.state')).trim();
  if (!/achitați la urcare/.test(state)) fail('starea rezervării e greșită: ' + state);
  const rticket = (await guest.textContent('.lbb-ticket')).replace(/\s+/g, ' ');
  if (!/De achitat la urcare .*MDL/.test(rticket)) fail('rezervarea nu arată suma la urcare: ' + rticket);
  console.log('rezervare:', state, '|', rticket);
  // Codul QR are text pentru cititoarele de ecran (imaginea generată nu se citește de două ori).
  await guest.waitForSelector('.lbb-ticket-qr img, .lbb-ticket-qr canvas', { state: 'attached' });
  const qr = await guest.$eval('.lbb-ticket-qr', e => ({ role: e.getAttribute('role'), label: e.getAttribute('aria-label') || '', imgs: [...e.querySelectorAll('img')].map(i => i.hasAttribute('alt')) }));
  const code = (rticket.match(/LB-[A-Z0-9]+/) || [''])[0];
  if (qr.role !== 'img' || !qr.label.includes(code) || qr.imgs.some(a => !a)) fail('codul QR nu are text alternativ: ' + JSON.stringify(qr));
  // Pagina biletului are date personale: fără cache, fără indexare, fără Referer (linkul conține cheia k=).
  const tr = await guest.request.get(guest.url());
  const h = tr.headers();
  // Și nu se poate încadra pe alt site (butonul de plată sub un strat invizibil).
  if (!/no-store/.test(h['cache-control'] || '') || !/noindex/.test(h['x-robots-tag'] || '') || h['referrer-policy'] !== 'no-referrer' || h['x-frame-options'] !== 'SAMEORIGIN' || !/frame-ancestors 'self'/.test(h['content-security-policy'] || '') || h['x-content-type-options'] !== 'nosniff') fail('pagina biletului: antete greșite ' + JSON.stringify({ cc: h['cache-control'], robots: h['x-robots-tag'], ref: h['referrer-policy'], xfo: h['x-frame-options'], csp: h['content-security-policy'], nosniff: h['x-content-type-options'] }));
  // Pe telefonul cel mai îngust (320px) eticheta („De achitat la urcare”) nu ia jumătate din bilet: valorile (numele,
  // ruta) au loc și nu se rup în mijlocul cuvântului; fără scroll orizontal.
  {
    const vp = guest.viewportSize();
    await guest.setViewportSize({ width: 320, height: 640 });
    const nw = await guest.evaluate(() => {
      const t = document.querySelector('.lbb-ticket table'); const th = t && t.querySelector('th');
      return { th: th ? Math.round(100 * th.getBoundingClientRect().width / t.getBoundingClientRect().width) : 100, hs: document.documentElement.scrollWidth > innerWidth + 1 };
    });
    if (nw.th > 45 || nw.hs) fail('biletul la 320px: coloana etichetelor ia ' + nw.th + '% din tabel' + (nw.hs ? ', scroll orizontal' : ''));
    // Telefonul biroului din text se atinge ușor (zonă de cel puțin 32px) și nu acoperă butoanele de dedesubt.
    const tap = await guest.evaluate(() => {
      const a = document.querySelector('.wrap a.lbb-phone'); const b = document.querySelector('.actions > :not([hidden])');
      if (!a) return null; const r = a.getBoundingClientRect();
      return { h: Math.round(r.height), gap: b ? Math.round(b.getBoundingClientRect().top - r.bottom) : 99 };
    });
    if (!tap || tap.h < 32 || tap.gap < 0) fail('biletul: telefonul biroului e greu de atins sau acoperă butoanele ' + JSON.stringify(tap));
    await guest.setViewportSize(vp);
  }
  // Contrast ridicat cu temă întunecată (Windows): codul QR rămâne pe alb, cu margine, altfel scannerul nu-l citește.
  {
    await guest.emulateMedia({ forcedColors: 'active', colorScheme: 'dark' });
    const qz = await guest.$eval('.lbb-ticket-qr', e => { const r = e.getBoundingClientRect(), i = e.querySelector('img').getBoundingClientRect(); return { bg: getComputedStyle(e).backgroundColor, m: Math.min(i.left - r.left, r.right - i.right, i.top - r.top, r.bottom - i.bottom) }; });
    await guest.emulateMedia({ forcedColors: 'none', colorScheme: 'light' });
    if (qz.bg !== 'rgb(255, 255, 255)' || qz.m < 12) fail('contrast ridicat întunecat: codul QR fără margine albă ' + JSON.stringify(qz));
  }
  // „Printează” se vede (și deschide printarea) doar cu JavaScript; fără el butonul n-ar face nimic, deci lipsește.
  {
    const printBtn = await guest.evaluate(() => { const b = document.querySelector('.actions .print'); let called = 0; window.print = () => { called++; }; if (b) b.click(); return { shown: !!b && b.getClientRects().length > 0, called }; });
    const nj = await browser.newContext({ javaScriptEnabled: false });
    const np = await nj.newPage();
    await np.goto(guest.url());
    const noJs = await np.evaluate(() => { const b = document.querySelector('.actions .print'); return !!b && b.getClientRects().length > 0; });
    await nj.close();
    if (!printBtn.shown || printBtn.called !== 1 || noJs) fail('butonul „Printează” pe pagina biletului: ' + JSON.stringify({ printBtn, noJs }));
  }
  // Pe hârtie starea rămâne vizibilă (un bilet anulat printat nu trebuie să pară valabil); butonul „Printează” nu.
  await guest.emulateMedia({ media: 'print' });
  const printed = await guest.evaluate(() => ({ state: getComputedStyle(document.querySelector('.state')).display, actions: getComputedStyle(document.querySelector('.actions')).display }));
  await guest.emulateMedia({ media: 'screen' });
  if (printed.state === 'none' || printed.actions !== 'none') fail('pagina biletului printată: ' + JSON.stringify(printed));
  // Biletul printat sau salvat ca PDF încape pe o singură foaie, A4 sau Letter, cu codul QR pe ea (o a doua pagină goală
  // sau QR-ul rupt pe pagina următoare ar face șoferul să caute biletul pe două foi).
  // page.pdf() păstrează media emulată: după emulateMedia('screen') de mai sus ar folosi stilul de ecran, nu pe cel de print.
  {
    const pages = {};
    await guest.emulateMedia({ media: 'print' });
    for (const format of ['A4', 'Letter']) {
      const pdf = (await guest.pdf({ format })).toString('latin1');
      pages[format] = { pagini: (pdf.match(/\/Type\s*\/Page[^s]/g) || []).length, imagini: (pdf.match(/\/Subtype\s*\/Image/g) || []).length };
    }
    await guest.emulateMedia({ media: 'screen' });
    if (Object.values(pages).some(x => x.pagini !== 1 || x.imagini < 1)) fail('biletul printat nu încape pe o foaie cu codul QR: ' + JSON.stringify(pages));
  }
  // Un link cu cheia greșită nu arată nimic din rezervare.
  const forged = await guest.request.get(guest.url().replace(/([?&]k=)[^&]+/, '$1' + '0'.repeat(20)));
  const fbody = await forged.text();
  // Biletul afișat (elementul), nu numele clasei din stilul comun al paginii.
  if (forged.status() !== 404 || /class="lbb-ticket[" ]|Test Pasager/.test(fbody)) fail('un link fals arată rezervarea: ' + forged.status());
  // Clientul cu un link stricat are măcar telefonul firmei.
  if (!/Biletul nu a fost găsit/.test(fbody) || !/href="tel:\+?\d{6,}"/.test(fbody)) fail('pagina „bilet negăsit” nu are telefonul firmei');
  // „Adaugă în calendar”: butonul de pe bilet și linkul din emailul primit de client descarcă un .ics al cursei.
  const calHref = await guest.$eval('.actions a.cal', a => a.href).catch(() => '');
  const cal = calHref ? await guest.request.get(calHref) : null;
  const calBody = cal ? await cal.text() : '';
  if (!cal || !/^text\/calendar/.test(cal.headers()['content-type'] || '') || !/^BEGIN:VCALENDAR\r\n/.test(calBody) || !calBody.includes('UID:' + code + '@') || !/DTSTART:\d{8}T\d{6}Z/.test(calBody)) fail('„Adaugă în calendar” de pe bilet nu dă un .ics valid: ' + calHref + ' ' + calBody.slice(0, 120));
  // „Trimite biletul”: ascuns unde browserul nu poate partaja; unde poate (telefon), trimite linkul biletului.
  if (await guest.isVisible('.actions .share')) fail('„Trimite biletul” apare deși browserul nu poate partaja');
  // Pe calculator, fără „Trimite”: „Copiază linkul biletului” pune în clipboard textul biletului și linkul lui.
  {
    await guest.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: new URL(guest.url()).origin });
    const canCopy = await guest.isVisible('.actions .copy');
    let copied = '';
    if (canCopy) {
      await guest.click('.actions .copy');
      await guest.waitForFunction(() => /copiat/.test(document.querySelector('.copy-status').textContent), null, { timeout: 5000 }).catch(() => {});
      copied = await guest.evaluate(() => navigator.clipboard.readText()).catch(() => '');
    }
    const btnText = canCopy ? await guest.textContent('.actions .copy') : '';
    if (!canCopy || !copied.endsWith(' ' + guest.url()) || copied.indexOf('Rezervare LibertBus ' + code + ': ') !== 0 || !/copiat/.test(btnText)) fail('„Copiază linkul biletului” nu copiază textul și linkul biletului: ' + JSON.stringify({ canCopy, copied, btnText }));
  }
  // Copierea refuzată de browser: textul și linkul biletului apar în fereastra browserului, gata de copiat de mână.
  {
    const denied = await guest.evaluate(async () => {
      const b = document.querySelector('.actions .copy');
      if (!b) return null;
      let shown = null;
      navigator.clipboard.writeText = () => Promise.reject(new Error('NotAllowedError'));
      window.prompt = (m, v) => { shown = { m, v }; return null; };
      b.click();
      for (let i = 0; i < 20 && !shown; i++) await new Promise(r => setTimeout(r, 50));
      return shown;
    });
    if (!denied || !denied.m || !(denied.v || '').endsWith(' ' + guest.url()) || (denied.v || '').indexOf('Rezervare LibertBus ' + code + ': ') !== 0) fail('„Copiază linkul biletului” cu copierea refuzată nu arată linkul de copiat: ' + JSON.stringify(denied));
  }
  const sharePage = await guest.context().newPage();
  await sharePage.addInitScript(() => { navigator.share = d => { window.__shared = d; return Promise.resolve(); }; });
  await sharePage.goto(guest.url());
  if (!(await sharePage.isVisible('.actions .share'))) fail('„Trimite biletul” lipsește pe un telefon care poate partaja');
  if (await sharePage.isVisible('.actions .copy')) fail('pe un telefon care poate partaja apare și „Copiază linkul biletului” (două butoane pentru același lucru)');
  else {
    await sharePage.click('.actions .share');
    const shared = await sharePage.evaluate(() => window.__shared || null);
    if (!shared || shared.url !== guest.url() || !new RegExp('^' + code + ' · .+ → .+ · \\d\\d\\.\\d\\d\\.\\d{4} — ').test(shared.title || '') || !new RegExp('^Rezervare LibertBus ' + code + ': .+ → .+, \\d\\d\\.\\d\\d\\.\\d{4} \\(\\S+\\), \\d\\d:\\d\\d(\\. Urcare: .+)?\\. De achitat la urcare: [\\d.,]+\\s(MDL|RON)$').test(shared.text || '')) fail('„Trimite biletul” nu trimite linkul biletului: ' + JSON.stringify(shared));    // Partajarea refuzată de telefon (NotAllowedError): textul și linkul apar de copiat; anulată de client
    // (AbortError, lista de aplicații închisă): nimic.
    const refused = await sharePage.evaluate(async () => {
      const out = {};
      for (const name of ['NotAllowedError', 'AbortError']) {
        let shown = null;
        navigator.share = () => Promise.reject(new DOMException('x', name));
        window.prompt = (m, v) => { shown = { m, v }; return null; };
        document.querySelector('.actions .share').click();
        await new Promise(r => setTimeout(r, 300));
        out[name] = shown;
      }
      return out;
    });
    const nv = (refused.NotAllowedError || {}).v || '';
    if (!nv.endsWith(' ' + guest.url()) || nv.indexOf('Rezervare LibertBus ' + code + ': ') !== 0 || refused.AbortError !== null) fail('„Trimite biletul” refuzat de telefon nu arată linkul de copiat (sau îl arată și la anulare): ' + JSON.stringify(refused));
  }
  await sharePage.close();
  // Telefon vechi, fără NodeList.forEach (Android 4.4, iOS 9): codul QR se desenează totuși, fără erori.
  const oldPage = await guest.context().newPage();
  const oldErrors = [];
  oldPage.on('pageerror', e => oldErrors.push(e.message));
  await oldPage.addInitScript(() => { delete NodeList.prototype.forEach; });
  await oldPage.goto(guest.url());
  const oldQr = await oldPage.$$eval('[data-qr]', els => els.map(el => el.querySelectorAll('canvas, img').length)).catch(() => []);
  if (!oldQr.length || oldQr.some(n => n === 0) || oldErrors.length) fail('pe un browser fără NodeList.forEach codul QR nu se desenează: ' + JSON.stringify({ oldQr, oldErrors }));
  await oldPage.close();
  // Butoanele de sub bilet au spațiu între ele fără flex gap (Safari < 14.1): nu se ating pe telefon.
  const actCss = await guest.$eval('style', st => (st.textContent.match(/\.actions\{[^}]*\}/) || [''])[0]).catch(() => '');
  const actBoxes = await guest.$$eval('.actions > :not([hidden])', els => els.map(e => { const r = e.getBoundingClientRect(); return [r.left, r.top, r.right, r.bottom]; }));
  const touching = actBoxes.some((a, i) => actBoxes.some((b, j) => j > i && a[0] < b[2] + 6 && b[0] < a[2] + 6 && a[1] < b[3] + 6 && b[1] < a[3] + 6));
  if (/gap/.test(actCss) || actBoxes.length < 2 || touching) fail('butoanele de sub bilet depind de flex gap sau se ating: ' + JSON.stringify({ actCss, actBoxes }));
  if (process.env.WP_PATH) {
    const fs = require('fs'), dir = process.env.WP_PATH + '/wp-content/mail';
    const mail = fs.existsSync(dir) ? fs.readdirSync(dir).sort().reverse().map(f => fs.readFileSync(dir + '/' + f, 'utf8')).find(m => m.includes(code) && /^TO: [^\n]*test@example\.com/m.test(m)) : '';
    // În HTML, esc_url scrie „&” ca &#038; (sau &amp;).
    const mailCal = mail ? ((mail.match(/href="([^"]*[?&;]ics=1[^"]*)"/) || [])[1] || '').replace(/&#038;|&amp;/g, '&') : '';
    if (!mailCal) fail('emailul rezervării nu are „Adaugă în calendar”' + (mail ? '' : ' (emailul clientului nu a fost găsit)'));
    else if (!/^BEGIN:VCALENDAR/.test(await (await guest.request.get(mailCal)).text())) fail('linkul de calendar din email nu dă un .ics: ' + mailCal);
  }
  await guest.waitForTimeout(500);
  await guest.screenshot({ path: (process.env.OUT || '.') + '/reservation.png', fullPage: true });
  // „Înapoi” din browser: data aleasă revine în câmp, deci orele și locurile trebuie să fie pentru ea, nu pentru azi.
  const asked = [];
  guest.on('request', r => { const m = r.url().match(/[?&]date=(\d{4}-\d{2}-\d{2})/); if (m && /route_id=/.test(r.url())) asked.push(m[1]); });
  await guest.goBack();
  await guest.waitForSelector('[data-lbb-submit]');
  await guest.waitForLoadState('networkidle');
  const shown = await guest.inputValue('[data-lbb="date"]');
  if (asked[asked.length - 1] !== shown) fail('după „Înapoi” data arătată e ' + shown + ', dar orele sunt pentru ' + asked.join(' → '));
  const backTime = await guest.inputValue('[data-lbb="time"]');
  // Ora revine dacă mai are locuri (rezervarea de mai sus poate fi ocupat-o pe ultimul).
  const stillOpen = await guest.$('[data-lbb="time"] option[value="' + chosenTime + '"]:not([disabled])');
  if (stillOpen && backTime !== chosenTime) fail('după „Înapoi” ora aleasă (' + chosenTime + ') s-a pierdut: ' + JSON.stringify(backTime));
  if (!stillOpen && backTime) fail('după „Înapoi” a revenit o oră fără locuri: ' + backTime);
  if (stillOpen && await guest.$('[data-lbb-submit]:disabled')) fail('după „Înapoi”, cu data și ora la loc, butoanele trebuie să meargă');
  console.log('după Înapoi: ora', backTime, '| data', shown, '| ore cerute pentru', asked.join(' → '));
  // Din memorie (bfcache, ex. Safari): butoanele blocate la trimitere se deblochează.
  const unblocked = await guest.evaluate(() => {
    const bs = [...document.querySelectorAll('[data-lbb-submit]')];
    const t = document.querySelector('[data-lbb="time"] option:not([disabled]):not([value=""])');
    if (t) { t.selected = true; t.parentNode.dispatchEvent(new Event('change')); }
    bs.forEach(b => { b.disabled = true; b.classList.add('is-busy'); });
    window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    return bs.map(b => b.value + ':' + (b.disabled ? 'blocat' : 'ok') + (b.classList.contains('is-busy') ? ':busy' : ''));
  });
  if (unblocked.some(x => !/:ok$/.test(x))) fail('revenit din bfcache, butoanele rămân blocate: ' + unblocked.join(', '));
  // Ora aleasă s-a umplut cât clientul era pe altă pagină: după „Înapoi” nu trebuie să rămână selectată
  // (Chrome pune la loc alegerea veche când formularul reface lista orelor, chiar dacă ora e acum plină).
  {
    const p2 = await guestCtx.newPage();
    await fillForm(p2, '+3736' + String(Math.floor(1e7 + Math.random() * 8e7)));
    const t = await p2.inputValue('[data-lbb="time"]');
    await p2.goto(BASE + '/politica-de-cookies/');
    // Ca în e2e-guest.js: o cerere încă pe drum la închiderea paginii nu trebuie să oprească tot scriptul.
    await p2.route('**/lbb/v1/departures**', async route => {
      try {
        const r = await route.fetch();
        const j = await r.json();
        (j.departures || []).forEach(d => { if (d.time === t) { d.bookable = false; d.reason = 'full'; d.free = 0; } });
        await route.fulfill({ response: r, json: j });
      } catch (e) {
        await route.continue().catch(() => {});
      }
    });
    let backRequests = 0;
    p2.on('request', r => { if (/route_id=/.test(r.url())) backRequests++; });
    await p2.goBack();
    await p2.waitForLoadState('networkidle');
    await p2.waitForTimeout(1500);
    // Încărcarea paginii (azi) + data pusă la loc; o oră plină nu se mai cere de încă două ori.
    if (backRequests > 3) fail('după „Înapoi” orele s-au cerut de ' + backRequests + ' ori');
    const st = await p2.evaluate(() => { const s = document.querySelector('[data-lbb="time"]'); const o = s.options[s.selectedIndex]; return { v: s.value, disabled: !!(o && o.disabled), btn: document.querySelector('[data-lbb-submit]').disabled }; });
    if (st.disabled || st.v === t) fail('după „Înapoi” a rămas selectată ora plină ' + t + ': ' + JSON.stringify(st));
    if (!st.v && !st.btn) fail('fără oră aleasă butoanele trebuie să fie blocate');
    await p2.unrouteAll({ behavior: 'ignoreErrors' });
    await p2.close();
  }
  // Telefon greșit, prins doar de server: după reîncărcare clientul nu reia totul de la capăt —
  // numele pasagerului, data, ora și emailul rămân, iar telefonul e marcat ca greșit.
  {
    const p3 = await guestCtx.newPage();
    await fillForm(p3, '123');
    const want = { day: await p3.inputValue('[data-lbb="date"]'), time: await p3.inputValue('[data-lbb="time"]') };
    await Promise.all([p3.waitForNavigation(), p3.click('[data-lbb-submit][value="reserve"]')]);
    if (/lbb_bilet=/.test(p3.url()) || !(await p3.$('.lbb-alert'))) fail('telefonul „123” a trecut de server: ' + p3.url());
    await p3.waitForFunction(t => document.querySelector('[data-lbb="time"]').value === t && document.querySelectorAll('input[name="lbb_names[]"]').length > 0, want.time, { timeout: 10000 }).catch(() => {});
    const kept = await p3.evaluate(() => ({
      names: [...document.querySelectorAll('input[name="lbb_names[]"]')].map(i => i.value),
      day: document.querySelector('[data-lbb="date"]').value,
      time: document.querySelector('[data-lbb="time"]').value,
      email: document.querySelector('input[name="lbb_email"]').value,
      phoneBad: document.querySelector('input[name="lbb_phone"]').getAttribute('aria-invalid'),
    }));
    if (kept.names[0] !== 'Test Pasager' || kept.day !== want.day || kept.time !== want.time || kept.email !== 'test@example.com' || kept.phoneBad !== 'true') fail('după eroarea de telefon s-au pierdut date din formular: ' + JSON.stringify({ want, kept }));
    await p3.close();
  }
  // Email cu o greșeală știută în domeniu („gmail.con”): mesajul propune adresa corectă, câmpul e marcat și
  // păstrează ce a scris clientul, ca să poată corecta doar domeniul.
  {
    const p4 = await guestCtx.newPage();
    await fillForm(p4, '+37369184111');
    await p4.fill('input[name="lbb_email"]', 'test@gmail.con');
    await Promise.all([p4.waitForNavigation(), p4.click('[data-lbb-submit][value="reserve"]')]);
    const alert = await p4.$('.lbb-alert');
    const msg = alert ? await alert.innerText() : '';
    const field = await p4.$eval('input[name="lbb_email"]', i => ({ value: i.value, bad: i.getAttribute('aria-invalid') }));
    if (/lbb_bilet=/.test(p4.url()) || !/test@gmail\.com/.test(msg) || field.value !== 'test@gmail.con' || field.bad !== 'true') fail('emailul „gmail.con” nu e semnalat corect: ' + JSON.stringify({ url: p4.url(), msg, field }));
    // Virgulă în loc de punct sau punct la capăt, scrise ușor pe telefon: browserul nu trimite formularul, iar câmpul
    // propune adresa reparată (nu mesajul general al browserului); după corectare câmpul e din nou valid.
    const typed = {};
    for (const v of ['test@gmail,com', 'test@gmail.com.', 'test@gmail..com', 'test@gmail,con', 'test@gmail.com']) {
      await p4.fill('input[name="lbb_email"]', v);
      typed[v] = await p4.$eval('input[name="lbb_email"]', i => i.checkValidity() ? '' : i.validationMessage);
    }
    if (['test@gmail,com', 'test@gmail.com.', 'test@gmail..com', 'test@gmail,con'].some(v => !/test@gmail\.com\?/.test(typed[v])) || typed['test@gmail.com'] !== '') fail('emailul cu virgulă, punct dublu sau la capăt nu propune adresa reparată: ' + JSON.stringify(typed));
    await p4.close();
  }
  // O eroare de la server care trimite la telefon („Pentru grupuri sunați la …”, aici 99 de locuri trimise de mână):
  // în mesajul de deasupra formularului telefonul se apasă direct pentru apel.
  {
    const p5 = await guestCtx.newPage();
    await fillForm(p5, '+37369184111');
    await p5.evaluate(() => {
      const sel = document.querySelector('[data-lbb="adults"]');
      sel.appendChild(new Option('99', '99'));
      sel.value = '99';
      const form = sel.form;
      for (let i = 1; i < 99; i++) {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'lbb_names[]'; inp.value = 'Test Pasager';
        form.appendChild(inp);
      }
    });
    await Promise.all([p5.waitForNavigation(), p5.click('[data-lbb-submit][value="reserve"]')]);
    const al = await p5.$eval('.lbb-alert', a => ({ text: a.textContent.replace(/\s+/g, ' '), tel: (a.querySelector('a.lbb-phone') || {}).getAttribute ? a.querySelector('a.lbb-phone').getAttribute('href') : '' })).catch(() => null);
    if (!al || !/Pentru grupuri sunați la/.test(al.text) || !/^tel:\+373\d{8}$/.test(al.tel)) fail('eroarea „Pentru grupuri sunați la …” fără telefon ca link: ' + JSON.stringify(al));
    await p5.close();
  }
  // Safari < 15.4 (iPhone cu iOS 14 / 15.0–15.3) nu are e.submitter: „Rezerv, achit la urcare” rămâne rezervare,
  // nu trimite clientul la plata online (primul buton).
  {
    const p6 = await guestCtx.newPage();
    await p6.addInitScript(() => { Object.defineProperty(SubmitEvent.prototype, 'submitter', { get() { return undefined; }, configurable: true }); });
    await fillForm(p6, '+3736' + String(Math.floor(1e7 + Math.random() * 8e7)));
    await Promise.all([p6.waitForNavigation(), p6.click('[data-lbb-submit][value="reserve"]')]);
    const st6 = (await p6.textContent('.state').catch(() => '')).trim();
    if (!/lbb_bilet=/.test(p6.url()) || !/achitați la urcare/.test(st6)) fail('fără e.submitter (Safari < 15.4) „Rezerv” nu duce la rezervare: ' + JSON.stringify({ url: p6.url(), st6 }));
    await p6.close();
  }
  // Telefon îngust (320px) și un nume lung de pasager: textul se rupe în bilet, nu iese peste chenar.
  {
    const p7 = await guestCtx.newPage({ viewport: { width: 320, height: 640 } });
    await p7.setViewportSize({ width: 320, height: 640 });
    await fillForm(p7, '+3736' + String(Math.floor(1e7 + Math.random() * 8e7)));
    await p7.fill('input[name="lbb_names[]"]', 'Maximilian-Constantin Popescu-Vasilescu-Andronachescu');
    await Promise.all([p7.waitForNavigation(), p7.click('[data-lbb-submit][value="reserve"]')]);
    const fit = await p7.evaluate(() => {
      const t = document.querySelector('.lbb-ticket');
      if (!t) { return { missing: true }; }
      const inner = t.getBoundingClientRect().right - parseFloat(getComputedStyle(t).paddingRight) - parseFloat(getComputedStyle(t).borderRightWidth);
      const out = [...t.querySelectorAll('td')].filter(td => {
        const r = document.createRange(); r.selectNodeContents(td);
        return [...r.getClientRects()].some(c => c.right > inner + 0.5);
      }).map(td => td.textContent.trim());
      return { out, page: document.documentElement.scrollWidth };
    });
    if (fit.missing || fit.out.length || fit.page > 320) fail('pe 320px un nume lung iese din bilet: ' + JSON.stringify(fit));
    await p7.close();
  }
  console.log(process.exitCode ? 'CURRENCY+RESERVE: PROBLEME' : 'CURRENCY+RESERVE: OK');
  await browser.close();
})().catch(require('./crash')('currency-reserve', () => BROWSER));

function browser_launch() { return chromium.launch(); }
