// E2E pe o instalare de test, ca vizitator nelogat:
// formular → coș → plată; plata de test nu e vizibilă; scoaterea din coș eliberează locurile.
// BASE=http://127.0.0.1:8080 node tests/e2e-guest.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };
let BROWSER = null; // pentru capturile de la eroare (tests/crash.js)
(async () => {
  const browser = BROWSER = await chromium.launch();
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
  // Pagina dintr-un cache vechi, cu cheia formularului expirată: orele cerute de la server aduc o cheie nouă.
  {
    const nc = await browser.newPage({ viewport: { width: 390, height: 844 } });
    await nc.goto(BASE + '/balti-iasi/');
    await nc.waitForSelector('[data-lbb="route"] option:checked', { state: 'attached' });
    await nc.$eval('input[name="lbb_nonce"]', i => { i.value = 'expirata'; });
    await nc.fill('[data-lbb="date"]', new Date(Date.now() + 86400000 * 4).toISOString().slice(0, 10));
    await nc.dispatchEvent('[data-lbb="date"]', 'change');
    await nc.waitForFunction(() => document.querySelector('input[name="lbb_nonce"]').value !== 'expirata', null, { timeout: 8000 }).catch(() => {});
    const fresh = await nc.inputValue('input[name="lbb_nonce"]');
    if (!/^[a-f0-9]{10}$/.test(fresh)) fail('cheia expirată a formularului nu se reîmprospătează: ' + fresh);
    await nc.close();
  }
  // Pagina din cache de ieri: serverul spune că „azi” e altă zi; formularul mută intervalul și data pe ziua serverului.
  {
    const st = await browser.newPage({ viewport: { width: 390, height: 844 } });
    st.on('pageerror', e => fail('pageerror: ' + e.message));
    const plus = (iso, n) => new Date(Date.parse(iso + 'T12:00:00Z') + n * 86400000).toISOString().slice(0, 10);
    await st.route('**/lbb/v1/departures**', async route => {
      try {
        const res = await route.fetch();
        const body = await res.json();
        body.today = plus(body.today, 1);
        body.maxDate = plus(body.maxDate, 1);
        await route.fulfill({ response: res, json: body });
      } catch (e) { /* pagina s-a închis între timp */ }
    });
    await st.goto(BASE + '/balti-iasi/');
    const pageToday = await st.getAttribute('[data-lbb="date"]', 'min');
    await st.waitForFunction(t => document.querySelector('[data-lbb="date"]').min !== t, pageToday, { timeout: 8000 }).catch(() => {});
    const sd = await st.evaluate(() => { const d = document.querySelector('[data-lbb="date"]'); return { min: d.min, value: d.value }; });
    const want = plus(pageToday, 1);
    if (sd.min !== want || sd.value < want) fail('pagina din cache vechi nu ia ziua de azi a serverului: ' + JSON.stringify({ pageToday, sd }));
    await st.waitForLoadState('networkidle').catch(() => {});
    await st.unrouteAll({ behavior: 'ignoreErrors' });
    await st.close();
  }
  // Prețul schimbat de birou după ce pagina a intrat în cache: rezumatul arată prețul curent de la server.
  {
    const pr = await browser.newPage({ viewport: { width: 390, height: 844 } });
    pr.on('pageerror', e => fail('pageerror: ' + e.message));
    await pr.route('**/lbb/v1/departures**', async route => {
      try {
        const res = await route.fetch();
        const body = await res.json();
        Object.keys(body.route.prices).forEach(c => { body.route.prices[c] = [987, null]; });
        body.departures = [{ time: '08:45', free: 20, bookable: true, reason: '' }];
        await route.fulfill({ response: res, json: body });
      } catch (e) { /* pagina s-a închis între timp */ }
    });
    await pr.goto(BASE + '/balti-iasi/');
    await pr.waitForFunction(() => document.querySelector('[data-lbb="time"]').options.length > 1);
    await pr.selectOption('[data-lbb="time"]', '08:45').catch(() => {});
    await pr.waitForFunction(() => /987/.test(document.querySelector('[data-lbb="summary"]').textContent), null, { timeout: 8000 }).catch(() => {});
    const sum = await pr.textContent('[data-lbb="summary"]');
    if (!/987/.test(sum)) fail('rezumatul nu arată prețul curent al rutei: ' + sum);
    await pr.waitForLoadState('networkidle').catch(() => {});
    await pr.unrouteAll({ behavior: 'ignoreErrors' });
    await pr.close();
  }
  // Ruta oprită de birou cât pagina era în cache (API: 404): mesaj clar cu telefonul, fără reîncercări inutile.
  {
    const gone = await browser.newPage({ viewport: { width: 390, height: 844 } });
    gone.on('pageerror', e => fail('pageerror: ' + e.message));
    let calls = 0;
    await gone.route('**/lbb/v1/departures**', route => { calls++; route.fulfill({ status: 404, contentType: 'application/json', body: JSON.stringify({ code: 'lbb_not_found', message: 'Ruta nu există.' }) }); });
    await gone.goto(BASE + '/balti-iasi/');
    await gone.waitForFunction(() => /nu se mai rezervă online/.test(document.querySelector('[data-lbb="status"]').textContent), null, { timeout: 6000 }).catch(() => {});
    const gs = await gone.$eval('[data-lbb="status"]', e => ({ text: e.textContent, tel: !!e.querySelector('a[href^="tel:"]') }));
    if (!/nu se mai rezervă online/.test(gs.text) || !gs.tel || calls !== 1) fail('ruta oprită (404) nu are mesajul potrivit: ' + JSON.stringify({ gs, calls }));
    await gone.close();
  }
  // Lista de ore în română corectă: „1 loc liber”, „20 de locuri libere”, „7 locuri libere”; peste 100 contează
  // ultimele două cifre: „105 locuri libere”, dar „120 de locuri libere”.
  {
    const gr = await browser.newPage({ viewport: { width: 390, height: 844 } });
    await gr.route('**/lbb/v1/departures**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ departures: [{ time: '06:00', free: 1, bookable: true, reason: '' }, { time: '08:45', free: 20, bookable: true, reason: '' }, { time: '17:45', free: 7, bookable: true, reason: '' }, { time: '19:00', free: 105, bookable: true, reason: '' }, { time: '21:00', free: 120, bookable: true, reason: '' }] }) }));
    await gr.goto(BASE + '/balti-iasi/');
    await gr.waitForFunction(() => document.querySelector('[data-lbb="time"]').options.length > 5);
    const labels = await gr.$$eval('[data-lbb="time"] option', o => o.slice(1).map(x => x.textContent));
    if (!/^06:00 — 1 loc liber$/.test(labels[0]) || !/^08:45 — 20 de locuri libere$/.test(labels[1]) || !/^17:45 — 7 locuri libere$/.test(labels[2]) || !/^19:00 — 105 locuri libere$/.test(labels[3]) || !/^21:00 — 120 de locuri libere$/.test(labels[4])) fail('acordul la locurile libere: ' + JSON.stringify(labels));
    await gr.close();
  }
  // Sub dată, ziua în cuvinte: pe telefoanele în engleză câmpul arată 03/01/2027 (lună/zi), ușor de citit greșit.
  const hint0 = await page.textContent('[data-lbb="dateHint"]');
  if (!/^(luni|marți|miercuri|joi|vineri|sâmbătă|duminică), \d{1,2} (ianuarie|februarie|martie|aprilie|mai|iunie|iulie|august|septembrie|octombrie|noiembrie|decembrie)$/.test(hint0)) fail('data în cuvinte lipsește sub câmpul de dată: „' + hint0 + '”');
  await page.fill('[data-lbb="date"]', '2027-03-01');
  await page.dispatchEvent('[data-lbb="date"]', 'change');
  const hint1 = await page.textContent('[data-lbb="dateHint"]');
  if (hint1 !== 'luni, 1 martie') fail('data în cuvinte nu urmează data aleasă: „' + hint1 + '”');
  // Safari pe iPhone lasă date în afara min/max: mesajul trebuie să spună intervalul, nu „nu sunt plecări”.
  for (const bad of [new Date(Date.now() - 86400000 * 3), new Date(Date.now() + 86400000 * 400)]) {
    await page.fill('[data-lbb="date"]', bad.toISOString().slice(0, 10));
    await page.dispatchEvent('[data-lbb="date"]', 'change');
    const st = await page.textContent('[data-lbb="status"]');
    const timeOff = await page.$eval('[data-lbb="time"]', s => s.disabled);
    if (!/Online se poate rezerva de azi până pe \d\d\.\d\d\.\d{4}/.test(st) || !timeOff) fail('data în afara intervalului: „' + st + '”');
  }
  // Internet căzut la verificarea locurilor (toate cele 3 încercări eșuează): mesajul are „Încearcă din nou” și
  // telefonul; după ce revine conexiunea, butonul încarcă orele fără ca clientul să schimbe data sau ruta.
  {
    const p3 = await browser.newPage({ viewport: { width: 390, height: 844 } });
    p3.on('pageerror', e => fail('pageerror: ' + e.message));
    let down = true;
    await p3.route('**/lbb/v1/departures**', route => down ? route.fulfill({ status: 503, contentType: 'text/html', body: 'Service Unavailable' }) : route.continue());
    await p3.goto(BASE + '/balti-iasi/');
    const retry = await p3.waitForSelector('[data-lbb="status"] button.lbb-retry', { timeout: 15000 }).catch(() => null);
    const msg = retry ? (await p3.textContent('[data-lbb="status"]')).replace(/\s+/g, ' ') : '';
    const tel = retry ? await p3.$eval('[data-lbb="status"] a.lbb-phone', a => a.getAttribute('href')).catch(() => '') : '';
    if (!retry || !/Încearcă din nou/.test(msg) || !/sunați la \+373/.test(msg) || !/^tel:\+373\d{8}$/.test(tel)) fail('fără „Încearcă din nou” și telefonul ca link când verificarea locurilor eșuează: „' + msg + '” ' + tel);
    else {
      // „Încearcă din nou” și telefonul se văd la focus de la tastatură, și cu o temă care scoate conturul.
      await p3.addStyleTag({ content: 'a:focus,a:focus-visible,button:focus,button:focus-visible{outline:none!important}' });
      const ring = () => p3.evaluate(() => { const a = document.activeElement, s = getComputedStyle(a); return a.className + ':' + (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) >= 2); });
      await p3.focus('[data-lbb="status"] button.lbb-retry');
      await p3.keyboard.press('Tab');
      const ringTel = await ring();
      await p3.keyboard.press('Shift+Tab');
      const ringRetry = await ring();
      if (ringTel !== 'lbb-phone:true' || ringRetry !== 'lbb-retry:true') fail('focus invizibil de la tastatură pe „Încearcă din nou” / telefon: ' + ringRetry + ' ' + ringTel);
      down = false;
      await retry.click();
      const loaded = await p3.waitForFunction(() => !document.querySelector('[data-lbb="time"]').disabled && document.querySelectorAll('[data-lbb="time"] option:not([value=""])').length > 0, null, { timeout: 15000 }).then(() => true).catch(() => false);
      if (!loaded) fail('„Încearcă din nou” nu a încărcat orele: „' + (await p3.textContent('[data-lbb="status"]')) + '”');
    }
    await p3.close();
  }
  // Conexiune blocată (răspunsul nu mai vine): după câteva secunde apare „Încearcă din nou” cu telefonul, nu
  // „Se verifică locurile…” la nesfârșit; după ce revine conexiunea, butonul încarcă orele. Și pe telefoanele
  // fără AbortController (iOS < 11.3), unde cererea nu se poate opri.
  for (const noAbort of [false, true]) {
    const ph = await browser.newPage({ viewport: { width: 390, height: 844 } });
    ph.on('pageerror', e => fail('pageerror: ' + e.message));
    if (noAbort) await ph.addInitScript(() => { delete window.AbortController; });
    let hang = true;
    await ph.route('**/lbb/v1/departures**', route => { if (!hang) route.continue(); });
    const t0 = Date.now();
    await ph.goto(BASE + '/balti-iasi/');
    const retry = await ph.waitForSelector('[data-lbb="status"] button.lbb-retry', { timeout: 14000 }).catch(() => null);
    const secs = Math.round((Date.now() - t0) / 1000);
    const msg = retry ? (await ph.textContent('[data-lbb="status"]')).replace(/\s+/g, ' ') : (await ph.textContent('[data-lbb="status"]').catch(() => ''));
    if (!retry || !/sunați la \+373/.test(msg)) fail('conexiune blocată' + (noAbort ? ' (fără AbortController)' : '') + ': după ' + secs + ' s încă fără „Încearcă din nou” și telefon: „' + msg + '”');
    else {
      hang = false;
      await retry.click();
      const loaded = await ph.waitForFunction(() => !document.querySelector('[data-lbb="time"]').disabled && document.querySelectorAll('[data-lbb="time"] option:not([value=""])').length > 0, null, { timeout: 15000 }).then(() => true).catch(() => false);
      if (!loaded) fail('după conexiunea blocată, „Încearcă din nou” nu a încărcat orele: „' + (await ph.textContent('[data-lbb="status"]')) + '”');
    }
    await ph.close();
  }
  // Browser prea vechi (fără fetch): în loc de un formular care nu răspunde, apare mesajul cu telefonul.
  // Într-un browser obișnuit mesajul rămâne ascuns.
  {
    const old = await browser.newPage({ viewport: { width: 390, height: 844 } });
    await old.addInitScript(() => { window.fetch = undefined; });
    await old.goto(BASE + '/balti-iasi/');
    const shown = await old.$eval('[data-lbb-js-fail]', e => ({ visible: e.getClientRects().length > 0, tel: (e.querySelector('a[href^="tel:"]') || {}).href || '' })).catch(() => null);
    if (!shown || !shown.visible || !/^tel:\+373/.test(shown.tel)) fail('browser fără fetch: lipsește mesajul cu telefonul: ' + JSON.stringify(shown));
    await old.close();
    const hiddenNow = await page.$eval('[data-lbb-js-fail]', e => e.getClientRects().length === 0).catch(() => false);
    if (!hiddenNow) fail('mesajul pentru browsere vechi apare și într-un browser obișnuit');
  }
  // Browser fără calendar pentru dată (Safari pe Mac < 14.1): câmpul e text, clientul scrie „zz.ll.aaaa”,
  // iar formularul cere orele pentru data corectă, nu spune „în afara intervalului”.
  {
    const nd = await browser.newPage({ viewport: { width: 1024, height: 800 } });
    nd.on('pageerror', e => fail('pageerror: ' + e.message));
    await nd.addInitScript(() => {
      const desc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'type');
      Object.defineProperty(HTMLInputElement.prototype, 'type', { configurable: true, get() { const v = desc.get.call(this); return v === 'date' ? 'text' : v; }, set(v) { desc.set.call(this, v); } });
    });
    const asked = [];
    await nd.route('**/lbb/v1/departures**', route => { asked.push(new URL(route.request().url()).searchParams.get('date')); route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ departures: [{ time: '08:45', free: 20, bookable: true, reason: '' }] }) }); });
    await nd.goto(BASE + '/balti-iasi/');
    await nd.waitForFunction(() => document.querySelector('[data-lbb="time"]').options.length > 1);
    const ph = await nd.getAttribute('[data-lbb="date"]', 'placeholder');
    await nd.$eval('[data-lbb="date"]', e => e.setAttribute('type', 'text'));
    const d = new Date(Date.now() + 86400000 * 5), dd = String(d.getUTCDate()).padStart(2, '0'), mm = String(d.getUTCMonth() + 1).padStart(2, '0');
    await nd.fill('[data-lbb="date"]', dd + '.' + mm + '.' + d.getUTCFullYear());
    await nd.dispatchEvent('[data-lbb="date"]', 'change');
    await nd.waitForTimeout(400);
    const st = await nd.evaluate(() => ({ v: document.querySelector('[data-lbb="date"]').value, status: document.querySelector('[data-lbb="status"]').textContent, times: document.querySelector('[data-lbb="time"]').options.length }));
    const iso = d.getUTCFullYear() + '-' + mm + '-' + dd;
    if (ph !== 'zz.ll.aaaa' || st.v !== iso || asked[asked.length - 1] !== iso || st.times < 2) fail('data scrisă „zz.ll.aaaa” într-un browser fără calendar nu e înțeleasă: ' + JSON.stringify({ ph, st, asked }));
    // Anul cu două cifre („zz.ll.aa”), cum îl scriu mulți.
    await nd.fill('[data-lbb="date"]', dd + '.' + mm + '.' + String(d.getUTCFullYear()).slice(2));
    await nd.dispatchEvent('[data-lbb="date"]', 'change');
    await nd.waitForTimeout(300);
    const v2 = await nd.inputValue('[data-lbb="date"]');
    if (v2 !== iso) fail('data scrisă „zz.ll.aa” nu e înțeleasă: ' + v2);
    // O zi care nu există („31.11”), scrisă de mână: API-ul real răspunde fără plecări, iar formularul spune să
    // alegeți altă dată, nu „Nu am putut verifica locurile” (ca la o problemă de internet). Prima lună cu 30 de zile
    // care urmează: e mereu în intervalul de vânzare.
    await nd.unroute('**/lbb/v1/departures**');
    let ny = new Date().getUTCFullYear(), nm = new Date().getUTCMonth() + 1;
    while (new Date(Date.UTC(ny, nm, 0)).getUTCDate() !== 30) { nm++; if (nm > 12) { nm = 1; ny++; } }
    await nd.fill('[data-lbb="date"]', '31.' + String(nm).padStart(2, '0') + '.' + ny);
    await nd.dispatchEvent('[data-lbb="date"]', 'change');
    await nd.waitForFunction(() => { const s = document.querySelector('[data-lbb="status"]').textContent; return s && !/Se verifică/.test(s); }, null, { timeout: 15000 }).catch(() => {});
    const bad = await nd.evaluate(() => ({ status: document.querySelector('[data-lbb="status"]').textContent, retry: !!document.querySelector('[data-lbb="status"] button.lbb-retry'), times: document.querySelectorAll('[data-lbb="time"] option:not([value=""]):not([disabled])').length }));
    if (bad.retry || /Nu am putut verifica/.test(bad.status) || !/Alegeți altă dată/.test(bad.status) || bad.times) fail('o zi care nu există (31 într-o lună cu 30 de zile) arată eroare de rețea sau ore: ' + JSON.stringify(bad));
    await nd.close();
  }
  // Grup mai mare decât limita online: la numărul maxim de pasageri apare „pentru grupuri sunați la …” cu link de apel.
  {
    const pg = await browser.newPage({ viewport: { width: 390, height: 844 } });
    pg.on('pageerror', e => fail('pageerror: ' + e.message));
    await pg.route('**/lbb/v1/departures**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ departures: [{ time: '08:45', free: 20, bookable: true, reason: '' }] }) }));
    await pg.goto(BASE + '/balti-iasi/');
    await pg.waitForFunction(() => document.querySelector('[data-lbb="adults"]').options.length > 1);
    const before = await pg.isVisible('[data-lbb="groupNote"]');
    const descBefore = await pg.$eval('[data-lbb="adults"]', s => s.getAttribute('aria-describedby'));
    const max = await pg.$eval('[data-lbb="adults"]', s => s.options[s.options.length - 1].value);
    await pg.selectOption('[data-lbb="adults"]', max);
    await pg.waitForTimeout(200);
    // Lista „Adulți” e legată de notă (cititorul de ecran o spune) doar cât nota se vede.
    const desc = await pg.$eval('[data-lbb="adults"]', s => { const id = s.getAttribute('aria-describedby'); const n = id && document.getElementById(id); return n ? n.getAttribute('data-lbb') : null; });
    await pg.selectOption('[data-lbb="adults"]', '1');
    await pg.waitForTimeout(200);
    const descAfter = await pg.$eval('[data-lbb="adults"]', s => s.getAttribute('aria-describedby'));
    if (descBefore || desc !== 'groupNote' || descAfter) fail('nota pentru grupuri nu e legată de lista „Adulți” doar cât se vede: ' + JSON.stringify({ descBefore, desc, descAfter }));
    await pg.selectOption('[data-lbb="adults"]', max);
    await pg.waitForTimeout(200);
    const note = await pg.$eval('[data-lbb="groupNote"]', e => ({ visible: e.getClientRects().length > 0, text: e.textContent, tel: (e.querySelector('a[href^="tel:"]') || {}).href || '' }));
    if (before || !note.visible || !new RegExp('mai mult de ' + max + ' persoane').test(note.text) || !/^tel:\+373/.test(note.tel)) fail('nota pentru grupuri lipsește sau apare greșit: ' + JSON.stringify({ before, max, note }));
    await pg.close();
  }
  // O oră cu doar 2 locuri: lista se oprește la 2, iar nota spune de ce („mai sunt doar 2 locuri libere”) și ce se poate face.
  {
    const ps = await browser.newPage({ viewport: { width: 390, height: 844 } });
    ps.on('pageerror', e => fail('pageerror: ' + e.message));
    await ps.route('**/lbb/v1/departures**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ departures: [{ time: '08:45', free: 2, bookable: true, reason: '' }] }) }));
    await ps.goto(BASE + '/balti-iasi/');
    await ps.waitForFunction(() => document.querySelector('[data-lbb="time"]').value === '08:45');
    const one = await ps.isVisible('[data-lbb="groupNote"]');
    const opts = await ps.$$eval('[data-lbb="adults"] option', o => o.map(x => x.value));
    await ps.selectOption('[data-lbb="adults"]', '2');
    await ps.waitForTimeout(200);
    const sn = await ps.$eval('[data-lbb="groupNote"]', e => ({ visible: e.getClientRects().length > 0, text: e.textContent, tel: (e.querySelector('a[href^="tel:"]') || {}).href || '' }));
    if (one || opts[opts.length - 1] !== '2' || !sn.visible || !/mai sunt doar 2 locuri libere online/.test(sn.text) || /grupuri/.test(sn.text) || !/^tel:\+373/.test(sn.tel)) fail('la o oră cu 2 locuri, nota nu explică de ce lista se oprește la 2: ' + JSON.stringify({ one, opts, sn }));
    await ps.close();
  }
  // Toate cursele zilei pline: mesajul „nu mai sunt locuri online” are telefonul ca link de apel.
  {
    const p4 = await browser.newPage({ viewport: { width: 390, height: 844 } });
    p4.on('pageerror', e => fail('pageerror: ' + e.message));
    await p4.route('**/lbb/v1/departures**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ departures: [{ time: '08:45', free: 0, bookable: false, reason: 'full' }] }) }));
    await p4.goto(BASE + '/balti-iasi/');
    await p4.waitForSelector('[data-lbb="route"] option:checked', { state: 'attached' });
    await p4.fill('[data-lbb="date"]', new Date(Date.now() + 86400000 * 5).toISOString().slice(0, 10));
    await p4.dispatchEvent('[data-lbb="date"]', 'change');
    const full = await p4.waitForFunction(() => /nu mai sunt locuri online/.test(document.querySelector('[data-lbb="status"]').textContent), null, { timeout: 15000 }).then(() => true).catch(() => false);
    const href = full ? await p4.$eval('[data-lbb="status"] a.lbb-phone', a => a.getAttribute('href')).catch(() => '') : '';
    if (!full || !/^tel:\+373\d{8}$/.test(href)) fail('„nu mai sunt locuri online” fără telefon ca link: „' + (await p4.textContent('[data-lbb="status"]')) + '” ' + href);
    await p4.close();
  }
  // Ruta care circulă doar luni și joi (din setup-local.sh): într-o marți, mesajul spune zilele de circulație.
  {
    const p2 = await browser.newPage({ viewport: { width: 390, height: 844 } });
    p2.on('pageerror', e => fail('pageerror: ' + e.message));
    await p2.goto(BASE + '/balti-iasi/');
    await p2.selectOption('[data-lbb="from"]', 'Chișinău');
    const sucId = await p2.$$eval('[data-lbb="route"] option', os => { const o = os.find(x => x.textContent.trim() === 'Suceava'); return o ? o.value : ''; });
    if (!sucId) fail('ruta de test Chișinău → Suceava lipsește din formular');
    else {
      await p2.selectOption('[data-lbb="route"]', sucId);
      const tue = new Date(Date.now() + 86400000 * 7);
      tue.setUTCDate(tue.getUTCDate() + ((2 - tue.getUTCDay() + 7) % 7));
      await p2.fill('[data-lbb="date"]', tue.toISOString().slice(0, 10));
      await p2.dispatchEvent('[data-lbb="date"]', 'change');
      await p2.waitForFunction(() => /plecări/.test(document.querySelector('[data-lbb="status"]').textContent), null, { timeout: 15000 }).catch(() => {});
      const st = await p2.textContent('[data-lbb="status"]');
      if (!/nu sunt plecări[\s\S]*Ruta circulă doar: luni, joi\./.test(st)) fail('ruta care nu circulă zilnic nu spune zilele: „' + st + '”');
      // „Ziua următoare →” lângă mesaj: marți → miercuri (tot fără curse) → joi, cu plecări; butonul dispare.
      const ymd = d => d.toISOString().slice(0, 10);
      const plus = n => { const d = new Date(tue); d.setUTCDate(d.getUTCDate() + n); return ymd(d); };
      const step = async () => {
        const b = await p2.$('[data-lbb="status"] button.lbb-next-day');
        if (!b) return null;
        const h = (await b.boundingBox()).height;
        // Săgeata e decor: cititorul de ecran spune „Ziua următoare”, fără „săgeată dreapta”.
        if (await p2.getByRole('button', { name: 'Ziua următoare', exact: true }).count() !== 1) fail('butonul „Ziua următoare →” are săgeata în numele citit de cititorul de ecran');
        const before = await p2.inputValue('[data-lbb="date"]');
        await b.click();
        await p2.waitForFunction(v => document.querySelector('[data-lbb="date"]').value !== v && !/Se verifică/.test(document.querySelector('[data-lbb="status"]').textContent), before, { timeout: 15000 }).catch(() => {});
        await p2.waitForTimeout(300);
        return { h, date: await p2.inputValue('[data-lbb="date"]'), focus: await p2.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-lbb')) };
      };
      const s1 = await step();
      const s2 = s1 && await step();
      const thu = { opts: await p2.$$eval('[data-lbb="time"] option:not([disabled]):not([value=""])', o => o.length), btn: !!(await p2.$('[data-lbb="status"] button.lbb-next-day')) };
      if (!s1 || !s2 || s1.date !== plus(1) || s2.date !== plus(2) || s1.h < 40 || s1.focus !== 'date' || !thu.opts || thu.btn) fail('„Ziua următoare” nu trece la ziua cu plecări: ' + JSON.stringify({ s1, s2, thu, want: [plus(1), plus(2)] }));
    }
    await p2.close();
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

  // Numele pe telefon: majusculă la fiecare cuvânt, fără corectare automată (iOS schimbă numele de familie).
  const nameAttrs = await page.$eval('input[name="lbb_names[]"]', i => [i.getAttribute('autocapitalize'), i.getAttribute('autocorrect'), i.getAttribute('spellcheck')].join(','));
  if (nameAttrs !== 'words,off,false') fail('câmpul de nume nu oprește corectarea automată pe telefon: ' + nameAttrs);
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
  const optsBefore = await page.$eval('[data-lbb="adults"]', s => { s.options[0].__lbbMark = 1; return s.options.length; });
  gate = null; release();
  await page.waitForFunction(() => !document.querySelector('[data-lbb="time"]').disabled, null, { timeout: 15000 });
  await page.waitForTimeout(300);
  const typing = await page.evaluate(() => { const a = document.activeElement; return { name: a && a.name, value: a && a.value }; });
  if (typing.name !== 'lbb_names[]' || typing.value !== 'Io') fail('câmpul de nume s-a refăcut cât clientul scria: ' + JSON.stringify(typing));
  // La fel selectorul „Adulți”: dacă numărul maxim nu s-a schimbat, opțiunile rămân (un selector deschis pe telefon nu se închide).
  const opts = await page.$eval('[data-lbb="adults"]', s => ({ n: s.options.length, same: s.options[0].__lbbMark === 1 }));
  if (opts.n === optsBefore && !opts.same) fail('selectorul „Adulți” s-a refăcut fără motiv la sosirea orelor');
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
  // Pentru bilete finalizarea comenzii e scurtă: fără adresă poștală, telefonul obligatoriu, iar numele, telefonul
  // și emailul vin completate din formularul de rezervare (vizitatorul nu are date salvate).
  const co = await page.evaluate(() => {
    const v = id => (document.getElementById(id) || {}).value;
    const f = document.getElementById('billing_phone_field');
    return { addr: ['billing_address_1', 'billing_city', 'billing_postcode', 'billing_company'].filter(id => document.getElementById(id)), first: v('billing_first_name'), last: v('billing_last_name'), phone: v('billing_phone'), email: v('billing_email'), phoneReq: !!f && f.classList.contains('validate-required') };
  });
  if (co.addr.length || co.first !== 'Vasile' || co.last !== 'Guest' || co.phone !== '+40712345678' || co.email !== 'guest@example.com' || !co.phoneReq) fail('finalizarea comenzii pentru bilete nu e scurtă sau completată din formular: ' + JSON.stringify(co));
  console.log('checkout ok, țara:', await page.inputValue('#billing_country'));
  // Finalizarea cu blocuri (Store API) citește datele din clientul WooCommerce, nu din câmpurile clasice: și acolo vin completate.
  const sb = ((await (await page.request.get(`${BASE}/wp-json/wc/store/v1/cart`)).json()).billing_address) || {};
  if (sb.first_name !== 'Vasile' || sb.last_name !== 'Guest' || sb.phone !== '+40712345678' || sb.email !== 'guest@example.com') fail('finalizarea cu blocuri nu primește numele, telefonul și emailul din formular: ' + JSON.stringify(sb));

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
    // Cu un copil la alt preț, rezumatul arată ambele prețuri (altfel „250 MDL / pasager” nu se potrivește cu totalul).
    const note = (await kid.textContent('.lbb-summary-note')).replace(/\s+/g, ' ');
    const total = (await kid.textContent('.lbb-summary-total')).replace(/\s+/g, ' ');
    const m = note.match(/^([\d.]+) MDL.*?\/ adult · ([\d.]+) MDL.*?\/ copil/);
    const t = total.match(/([\d.]+) MDL/);
    const nr = x => +x.replace(/\./g, '');
    if (!m || !t || nr(m[1]) + nr(m[2]) !== nr(t[1])) fail('rezumatul nu explică totalul cu adult + copil: „' + total + '” / „' + note + '”');
    console.log('rezumat:', total, '|', note);
    await kid.selectOption('[data-lbb="adults"]', '2');
    const n2 = await names();
    if (n2 !== 'Pasager 1=Ion Popescu | Pasager 2= | Pasager 3 (copil)=Ana Popescu') fail('după 1→2 adulți copilul nu a rămas copil: ' + n2);
    console.log('adulți/copii:', n1, '→', n2);
    await kid.close();
  }
  // Cu JavaScript oprit formularul nu poate funcționa: se vede mesajul „activați JavaScript sau sunați la …”, cu
  // telefonul ca link de apel, iar butoanele de trimitere rămân dezactivate (nu pleacă un formular gol).
  {
    const nj = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } });
    const np = await nj.newPage();
    await np.goto(BASE + '/balti-iasi/');
    const nojs = await np.evaluate(() => {
      const root = document.querySelector('.lbb-booking');
      const msg = root && [...root.querySelectorAll('p')].find(e => /activați JavaScript/.test(e.textContent) && e.getClientRects().length);
      const tel = msg && msg.querySelector('a[href^="tel:"]');
      return { msg: !!msg, tel: tel ? tel.getAttribute('href') : '', enabled: root ? [...root.querySelectorAll('[data-lbb-submit]')].filter(b => !b.disabled).length : -1 };
    });
    if (!nojs.msg || !/^tel:\+\d{8,}$/.test(nojs.tel) || nojs.enabled !== 0) fail('cu JavaScript oprit: lipsește mesajul cu telefonul sau butoanele se pot apăsa: ' + JSON.stringify(nojs));
    await nj.close();
  }

  console.log(process.exitCode ? 'GUEST: PROBLEME' : 'GUEST: OK');
  await browser.close();
})().catch(require('./crash')('guest', () => BROWSER));
