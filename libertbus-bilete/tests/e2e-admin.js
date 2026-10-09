// E2E: paginile de admin LibertBus (Panou, Rute și orar, Pasageri, Rezervări, Setări) se deschid fără erori,
// iar lista de pasageri se descarcă în CSV.
// BASE=http://127.0.0.1:8080 node tests/e2e-admin.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const login = require('./login');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };
let BROWSER = null; // pentru capturile de la eroare (tests/crash.js)
(async () => {
  const browser = BROWSER = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
  const page = await ctx.newPage();
  page.on('pageerror', e => fail('pageerror pe ' + page.url() + ': ' + e.message));
  await login(page, BASE);

  const pages = [
    ['lbb', /gata de plăți/i],
    ['lbb-routes', /Rute și orar/],
    ['lbb-manifest', /Pasageri/],
    ['lbb-bookings', /Rezervări/],
    ['lbb-settings', /Setări/],
  ];
  for (const [slug, title] of pages) {
    const r = await page.goto(BASE + '/wp-admin/admin.php?page=' + slug);
    const body = await page.textContent('#wpbody-content');
    if (r.status() !== 200) fail(slug + ': status ' + r.status());
    const h1 = await page.textContent('#wpbody-content .wrap h1').catch(() => '');
    if (!title.test(h1)) fail(slug + ': lipsește titlul ' + title + ' (' + h1 + ')');
    if (/Fatal error|Warning:|Notice:|Deprecated:|critical error/i.test(body)) fail(slug + ': eroare PHP în pagină: ' + (body.match(/(Fatal error|Warning:|Notice:|Deprecated:)[^\n]{0,160}/) || [''])[0]);
  }

  // Rezervările fără cod (coș abandonat, anulate) arată „—”, nu o căsuță de cod goală.
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-bookings&status=cancelled');
  const emptyCodes = await page.$$eval('#wpbody-content table.widefat code', cs => cs.filter(c => !c.textContent.trim()).length);
  if (emptyCodes) fail('Rezervări: ' + emptyCodes + ' căsuțe de cod goale');

  // Setări → Confidențialitate → Ghid: plugin-ul spune ce date despre călători prelucrează și cât le păstrează.
  await page.goto(BASE + '/wp-admin/privacy-policy-guide.php');
  const guide = (await page.textContent('#wpbody-content')).replace(/\s+/g, ' ');
  if (!/LibertBus Bilete/.test(guide) || !/3 ani după data cursei/.test(guide)) fail('ghidul de confidențialitate WordPress nu are textul LibertBus');

  // Căutarea în Rezervări: după codul unui bilet existent îl găsește; o căutare fără rezultat o spune clar.
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-bookings');
  const someCode = await page.$$eval('#wpbody-content table.widefat code', cs => (cs.map(c => c.textContent.trim()).find(t => /^LB-/.test(t)) || ''));
  if (someCode) {
    await page.fill('#lbb-q', someCode.toLowerCase());
    await Promise.all([page.waitForNavigation(), page.click('.lbb-search button')]);
    const found = await page.$$eval('#wpbody-content table.widefat tbody tr', rs => rs.map(r => r.textContent));
    if (found.length !== 1 || !found[0].includes(someCode)) fail('căutarea după cod ' + someCode + ' a dat ' + found.length + ' rânduri');
    if (await page.inputValue('#lbb-q') !== someCode.toLowerCase()) fail('căutarea nu rămâne în câmp după căutare');
    // Filtrele de stare („Toate”, „Anulate”…) păstrează căutarea.
    const lost = await page.$$eval('.subsubsub a', (as, q) => as.filter(a => new URL(a.href).searchParams.get('q') !== q).map(a => a.textContent), someCode.toLowerCase());
    if (lost.length) fail('filtrele de stare pierd căutarea: ' + lost.join(', '));
  } else fail('nu am găsit un bilet pentru testul de căutare');
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-bookings&q=zzqq-nimic');
  const none = await page.textContent('#wpbody-content table.widefat tbody');
  if (!/Nicio rezervare găsită/.test(none)) fail('căutarea fără rezultat nu spune nimic: ' + none.trim().slice(0, 80));

  // „Anulează” pe o rezervare cu plata la urcare: rezervarea se anulează (și clientul e anunțat prin email).
  // Biroul a căutat clientul după cod: după anulare rămâne pe aceeași căutare și vede rezervarea „Anulate”.
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-bookings&status=reserved');
  const resCode = await page.$eval('.lbb-bookings-table tbody tr:has(form) td:nth-child(5)', td => (td.textContent.match(/LB-[A-Z0-9]+/) || [''])[0]).catch(() => '');
  if (resCode) await page.goto(BASE + '/wp-admin/admin.php?page=lbb-bookings&q=' + encodeURIComponent(resCode));
  const cancelBtn = resCode ? await page.$('.lbb-bookings-table form button') : null;
  if (cancelBtn) {
    let askText = '';
    page.once('dialog', d => { askText = d.message(); d.accept(); });
    await Promise.all([page.waitForNavigation(), cancelBtn.click()]);
    // Confirmarea spune biroului ce urmează: locurile se eliberează și clientul primește email.
    if (!/clientul primește un email/.test(askText)) fail('confirmarea anulării nu spune că pleacă un email: ' + askText);
    const notice = await page.$$eval('#wpbody-content .notice', ns => ns.map(n => n.textContent).join(' | '));
    if (!/Rezervarea a fost anulată/.test(notice)) fail('anularea din Rezervări nu a mers: ' + notice.trim().slice(0, 120));
    const after = new URL(page.url());
    const row = (await page.textContent('.lbb-bookings-table tbody')).replace(/\s+/g, ' ');
    if (after.searchParams.get('q') !== resCode || !row.includes(resCode) || !/Anulate/.test(row)) fail('după anulare s-a pierdut căutarea: ' + page.url() + ' | ' + row.slice(0, 120));
    const body = await page.textContent('body');
    if (/Fatal error|Warning:|Notice:/i.test(body)) fail('eroare PHP după anulare');
  } else fail('nu am găsit o rezervare cu plata la urcare pentru testul de anulare');

  // Foaia printată pentru șofer: doar titlul și tabelul, fără meniu, filtre sau subsolul WordPress.
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-manifest');
  await page.emulateMedia({ media: 'print' });
  const printed = await page.evaluate(() => ['#adminmenumain', '#wpadminbar', '#wpfooter', '.lbb-noprint', '.update-nag', '.notice'].filter(sel => [...document.querySelectorAll(sel)].some(e => e.offsetParent !== null || getComputedStyle(e).display !== 'none' && e.getClientRects().length)));
  if (printed.length) fail('pe foaia printată apar: ' + printed.join(', '));
  await page.emulateMedia({ media: 'screen' });

  // Lista de pasageri se descarcă în CSV, cu antetul corect și BOM (diacritice corecte în Excel).
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-manifest');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('a.button:text-is("CSV")')]);
  const fs = require('fs');
  const csv = fs.readFileSync(await dl.path(), 'utf8');
  if (csv.charCodeAt(0) !== 0xFEFF) fail('CSV fără BOM: diacriticele se strică în Excel');
  if (!/^﻿?Data,Ora,Plecare,Destinatie,Bilet,Locuri,Pasageri,Telefon,Email,Plata,Comanda/.test(csv)) fail('antet CSV greșit: ' + csv.slice(0, 80));
  if (!/^pasageri-\d{4}-\d{2}-\d{2}\.csv$/.test(dl.suggestedFilename())) fail('nume de fișier CSV greșit: ' + dl.suggestedFilename());
  // Cu o rută aleasă, numele fișierului o conține (listele mai multor rute din aceeași zi nu se confundă).
  const rid = await page.$eval('select[name="route"] option:nth-child(2)', o => ({ id: o.value, text: o.textContent }));
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-manifest&route=' + rid.id);
  const [dl2] = await Promise.all([page.waitForEvent('download'), page.click('a.button:text-is("CSV")')]);
  if (!/^pasageri-\d{4}-\d{2}-\d{2}-[a-z0-9]+(-[a-z0-9]+)+\.csv$/.test(dl2.suggestedFilename())) fail('CSV pe o rută (' + rid.text + '): nume fără rută: ' + dl2.suggestedFilename());

  // Pe telefon (șoferul, dispecerul): lista de pasageri și rezervările încap pe ecran, fără derulare laterală,
  // iar codul biletului rămâne pe un rând.
  await page.setViewportSize({ width: 390, height: 844 });
  // Data pentru lista șoferului: de la o rezervare care apare în ea (plătită sau cu plata la urcare), nu de la
  // una anulată sau rămasă în coș (altfel lista e goală și testul depinde de ce au lăsat testele de dinainte).
  let firstDate = '';
  for (const st of ['confirmed', 'reserved']) {
    if (firstDate) break;
    await page.goto(BASE + '/wp-admin/admin.php?page=lbb-bookings&status=' + st);
    firstDate = await page.$$eval('#wpbody-content table.widefat tbody td', tds => { for (const td of tds) { const m = td.textContent.match(/(\d\d)\.(\d\d)\.(\d{4}) \d\d:\d\d/); if (m) return m[3] + '-' + m[2] + '-' + m[1]; } return ''; });
  }
  if (!firstDate) fail('nu am găsit o rezervare pentru testul pe telefon');
  for (const slug of ['lbb', 'lbb-routes', 'lbb-settings']) {
    await page.goto(BASE + '/wp-admin/admin.php?page=' + slug);
    const sw = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    if (sw[0] > sw[1] + 1) fail(slug + ': pe telefon pagina se derulează în lateral (' + sw[0] + ' > ' + sw[1] + ')');
  }
  // Câmpurile de text din formularul de rută și din Setări au înălțimea obișnuită din WordPress pe telefon (ușor de atins).
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-routes');
  const editUrl = await page.$eval('a[href*="page=lbb-routes&edit="]', a => a.href);
  for (const slug of [editUrl, 'lbb-settings']) {
    await page.goto(slug.startsWith('http') ? slug : BASE + '/wp-admin/admin.php?page=' + slug);
    if (!(await page.$('#wpbody-content form input[name="' + (slug.startsWith('http') ? 'origin' : 'support_phone') + '"]'))) fail(slug + ': formularul nu s-a deschis');
    const small = await page.$$eval('#wpbody-content form input:not([type]), #wpbody-content form input[type="text"]', ins => ins.filter(i => i.offsetParent && i.getBoundingClientRect().height < 36).map(i => i.name + '=' + Math.round(i.getBoundingClientRect().height)));
    if (small.length) fail(slug + ': câmpuri prea mici pentru deget pe telefon: ' + small.join(', '));
  }
  // Oră de plecare scrisă greșit: ruta se salvează, iar biroul vede exact ce oră n-a fost înțeleasă (nu dispare pe tăcute).
  await page.goto(editUrl);
  const origTimes = await page.inputValue('#lbb-times');
  await page.fill('#lbb-times', origTimes + ', 25:00');
  await Promise.all([page.waitForNavigation(), page.click('#wpbody-content form:has(#lbb-times) #submit')]);
  const timeWarn = await page.$$eval('#wpbody-content .notice', ns => ns.map(n => n.textContent).join(' | '));
  if (!/nu au fost înțelese[^|]*25:00/.test(timeWarn)) fail('ora greșită la rută nu e semnalată: ' + timeWarn.trim().slice(0, 160));
  if (!(await page.$('#lbb-times')) || await page.inputValue('#lbb-times') !== origTimes) fail('după avertisment, formularul rutei nu arată orele salvate');
  // O rută cu bilete pentru curse viitoare (Bălți → Iași, din testele de plată) nu se poate șterge din greșeală.
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-routes');
  const busyEdit = await page.$$eval('#wpbody-content table tbody tr', rs => { const r = rs.find(x => /Bălți\s*→\s*Iași/.test(x.textContent)); const a = r && r.querySelector('a[href*="edit="]'); return a ? a.href : ''; });
  if (busyEdit) {
    await page.goto(busyEdit);
    page.once('dialog', d => d.accept());
    await Promise.all([page.waitForNavigation(), page.click('#wpbody-content button.button-link-delete')]);
    const delMsg = await page.$$eval('#wpbody-content .notice', ns => ns.map(n => n.textContent).join(' | '));
    if (!/nu se poate șterge/.test(delMsg)) fail('ruta cu bilete viitoare s-a putut șterge: ' + delMsg.trim().slice(0, 160));
  } else fail('nu am găsit ruta Bălți → Iași în Rute și orar');
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-bookings&q=zzqq-nimic');
  const emptyLabel = await page.$eval('#wpbody-content table.widefat tbody td[colspan]', td => getComputedStyle(td, '::before').content);
  if (emptyLabel !== 'none' && emptyLabel !== 'normal') fail('pe telefon, rândul „nicio rezervare” are eticheta ' + emptyLabel);
  const searchFits = await page.$eval('#lbb-q', i => { const r = i.getBoundingClientRect(); return r.right <= window.innerWidth && r.height >= 36; });
  if (!searchFits) fail('pe telefon câmpul de căutare iese din ecran sau e prea mic');
  for (const url of ['/wp-admin/admin.php?page=lbb-bookings', '/wp-admin/admin.php?page=lbb-manifest&date=' + firstDate]) {
    await page.goto(BASE + url);
    const m = await page.evaluate(() => {
      const codes = [...document.querySelectorAll('#wpbody-content table.widefat code')];
      const lh = codes.length ? parseFloat(getComputedStyle(codes[0]).lineHeight) || 20 : 20;
      return { sw: document.documentElement.scrollWidth, iw: window.innerWidth, codes: codes.length, broken: codes.filter(c => c.getBoundingClientRect().height > lh * 1.6).length, label: codes.length ? getComputedStyle(codes[0].closest('td'), '::before').content : '' };
    });
    if (m.sw > m.iw + 1) fail(url + ': pe telefon pagina se derulează în lateral (' + m.sw + ' > ' + m.iw + ')');
    if (!m.codes || m.broken) fail(url + ': codul biletului se rupe pe mai multe rânduri pe telefon (' + m.broken + ' din ' + m.codes + ')');
    if (!/Bilet/.test(m.label)) fail(url + ': pe telefon valorile nu au eticheta coloanei: ' + m.label);
    await page.screenshot({ path: (process.env.OUT || '.') + '/admin-phone-' + (url.includes('manifest') ? 'manifest' : 'bookings') + '.png', fullPage: true });
  }

  console.log(process.exitCode ? 'ADMIN: PROBLEME' : 'ADMIN: OK');
  await browser.close();
})().catch(require('./crash')('admin', () => BROWSER));
