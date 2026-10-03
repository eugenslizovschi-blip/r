// E2E: previzualizarea doar pentru administrator.
// Plata online e OPRITĂ în setări; pe pagina privată adminul vede formularul nou cu „Achit online”
// și poate cumpăra cu plata de test; vizitatorii văd tot formularele vechi.
// BASE=http://127.0.0.1:8080 node tests/e2e-preview.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };
(async () => {
  const browser = await chromium.launch();
  // Vizitator: pagina privată nu există, iar pe pagina publică rămâne formularul vechi chiar și cu ?lbb_preview=1.
  const guest = await (await browser.newContext()).newPage();
  const r = await guest.goto(BASE + '/previzualizare-bilete/');
  if (r.status() !== 404) fail('pagina privată e accesibilă vizitatorilor: ' + r.status());
  await guest.goto(BASE + '/formulare-vechi/?lbb_preview=1');
  if (await guest.$('.lbb-booking')) fail('vizitatorul vede formularul nou cu ?lbb_preview=1');
  if (!(await guest.$('.wpcf7'))) fail('vizitatorul nu mai vede formularul vechi');
  await guest.goto(BASE + '/balti-iasi/');
  if (await guest.$('[data-lbb-submit][value="pay"]')) fail('vizitatorul vede „Achit online” deși plata e oprită');

  // Linkul secret: fără login se vede formularul nou cu butonul de plată; o cheie greșită nu schimbă nimic.
  const TOKEN = process.env.LBB_PREVIEW_TOKEN;
  if (TOKEN) {
    const resp = await guest.goto(BASE + '/formulare-vechi/?lbb_preview=' + encodeURIComponent(TOKEN));
    if (!(await guest.$('.lbb-booking [data-lbb-submit][value="pay"]'))) fail('linkul secret nu arată formularul nou cu plata');
    if (await guest.$('.wpcf7')) fail('cu linkul secret a rămas formularul vechi');
    if (!/no-store|no-cache/.test(resp.headers()['cache-control'] || '')) fail('pagina de previzualizare se poate pune în cache');
    await guest.goto(BASE + '/formulare-vechi/?lbb_preview=gresit' + Date.now());
    if (await guest.$('.lbb-booking')) fail('o cheie greșită arată formularul nou');
    console.log('link secret: OK');
  } else {
    console.log('(LBB_PREVIEW_TOKEN lipsește: sar peste linkul secret)');
  }

  // Admin: pe pagina privată vede 2 formulare noi, cu butonul de plată și nota de previzualizare.
  const admin = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  admin.on('pageerror', e => fail('pageerror: ' + e.message));
  await admin.goto(BASE + '/wp-login.php');
  await admin.fill('#user_login', 'admin'); await admin.fill('#user_pass', 'admin');
  await Promise.all([admin.waitForNavigation(), admin.click('#wp-submit')]);
  await admin.goto(BASE + '/previzualizare-bilete/');
  const forms = await admin.$$('.lbb-booking');
  if (forms.length !== 2) fail('pe pagina privată trebuie 2 formulare noi, sunt ' + forms.length);
  if (await admin.$('.wpcf7')) fail('pe pagina privată a rămas un formular vechi');
  if (!(await admin.$('.lbb-preview-note'))) fail('lipsește nota de previzualizare');
  const second = forms[1];
  const preset = await second.$eval('[data-lbb="route"]', s => s.options[s.selectedIndex] && s.options[s.selectedIndex].text);
  if (preset !== 'Iași') fail('formularul de rută nu are Iași preselectat: ' + preset);
  const btnColor = await second.$eval('[data-lbb-submit][value="pay"]', b => getComputedStyle(b).backgroundColor);
  console.log('admin: formulare', forms.length, '| rută preselectată:', preset, '| culoare buton:', btnColor);

  // Cumpărare de test din previzualizare (plata online e oprită pentru clienți).
  await second.scrollIntoViewIfNeeded();
  await admin.waitForFunction(el => el.querySelectorAll('[data-lbb="time"] option:not([disabled])').length > 1, second);
  // Dată aleatoare și ora cu cele mai multe locuri, ca testul să poată rula de multe ori.
  const day = new Date(Date.now() + 86400000 * (2 + Math.floor(Math.random() * 25))).toISOString().slice(0, 10);
  await second.$eval('[data-lbb="date"]', (d, v) => { d.value = v; d.dispatchEvent(new Event('change')); }, day);
  await admin.waitForFunction(el => el.querySelectorAll('[data-lbb="time"] option:not([disabled])').length > 1, second);
  // În formularul compact lista arată doar ora: alegem prima oră la care se pot selecta 5 pasageri.
  const okTime = await second.evaluate(root => {
    const t = root.querySelector('[data-lbb="time"]');
    for (const o of [...t.options].filter(x => x.value && !x.disabled)) {
      t.value = o.value; t.dispatchEvent(new Event('change'));
      if ([...root.querySelector('[data-lbb="adults"]').options].some(a => a.value === '5')) return o.value;
    }
    return '';
  });
  if (!okTime) fail('nicio oră cu 5 locuri libere în ziua aleasă');
  // Formularele înlocuite sunt compacte: pasul 1 (cursa) → „Continuă” → pasul 2 (date și plată).
  if (await second.$eval('[data-lbb="step2"]', e => !e.hidden)) fail('pasul 2 e vizibil înainte de „Continuă”');
  await (await second.$('[data-lbb="adults"]')).selectOption('5');
  const h1 = await second.evaluate(e => e.getBoundingClientRect().height);
  await (await second.$('[data-lbb="next"]')).click();
  // Pasul 2 e într-o fereastră peste pagină; ambele butoane se văd pe ecran chiar cu 5 pasageri.
  if (!(await admin.$('.lbb-overlay .lbb-booking'))) fail('pasul 2 nu s-a deschis în fereastră');
  const btns = await admin.$$eval('.lbb-overlay [data-lbb-submit]', bs => bs.map(b => { const r = b.getBoundingClientRect(); return { v: b.value, ok: r.top >= 0 && r.bottom <= window.innerHeight && r.height > 30 }; }));
  if (btns.length !== 2 || btns.some(b => !b.ok)) fail('butoanele nu sunt toate vizibile pe ecran: ' + JSON.stringify(btns));
  await admin.screenshot({ path: (process.env.OUT || '.') + '/preview-step2-5pax.png' });
  // Tab rămâne în fereastră: de pe ultimul buton revine la primul element, Shift+Tab invers.
  await admin.focus('.lbb-overlay [data-lbb-submit][value="reserve"]');
  await admin.keyboard.press('Tab');
  if (!(await admin.evaluate(() => !!document.activeElement.closest('.lbb-overlay')))) fail('Tab iese din fereastră');
  await admin.keyboard.press('Shift+Tab');
  if (!(await admin.evaluate(() => document.activeElement.value === 'reserve'))) fail('Shift+Tab nu revine la ultimul buton');
  await admin.keyboard.press('Escape');
  if (await admin.$('.lbb-overlay')) fail('Escape nu închide fereastra');
  await (await second.$('[data-lbb="next"]')).click();
  if (await second.$eval('[data-lbb="step1"]', e => !e.hidden)) fail('pasul 1 nu s-a ascuns după „Continuă”');
  console.log('înălțime pas 1:', Math.round(h1), 'px');
  // Butoanele rămân lizibile sub cursor (tema Betheme schimbă culorile la hover).
  await (await second.$('[data-lbb-submit][value="reserve"]')).hover();
  const hov = await second.$eval('[data-lbb-submit][value="reserve"]', b => [getComputedStyle(b).backgroundColor, getComputedStyle(b).color]);
  if (hov[0] === hov[1]) fail('butonul „Rezerv” devine ilizibil la hover: ' + hov.join(' / '));
  const nameInputs = await second.$$('input[name="lbb_names[]"]');
  if (nameInputs.length !== 5) fail('trebuie 5 câmpuri de nume, sunt ' + nameInputs.length);
  for (let i = 0; i < nameInputs.length; i++) await nameInputs[i].fill('Admin Test ' + (i + 1));
  await (await second.$('input[name="lbb_phone"]')).fill('+37369184111');
  await (await second.$('input[name="lbb_email"]')).fill('admin-test@example.com');
  await second.screenshot({ path: (process.env.OUT || '.') + '/preview-form.png' });
  await Promise.all([admin.waitForNavigation(), (await second.$('[data-lbb-submit][value="pay"]')).click()]);
  if (!/checkout/.test(admin.url())) fail('previzualizarea nu duce la plată: ' + admin.url() + ' ' + ((await admin.$('.lbb-alert')) ? await admin.textContent('.lbb-alert') : ''));
  await admin.waitForSelector('#payment');
  await admin.waitForLoadState('networkidle');
  await admin.locator('#payment_method_lbb_test').check();
  if (await admin.locator('#terms').count()) await admin.locator('#terms').check();
  await Promise.all([admin.waitForURL(/order-received/, { timeout: 30000 }), admin.click('#place_order')]);
  const ticket = (await admin.textContent('.lbb-ticket')).replace(/\s+/g, ' ');
  if (!/Locuri 5/.test(ticket)) fail('biletul nu are 5 locuri: ' + ticket);
  if (!/Achitat online/.test(ticket)) fail('biletul de test nu s-a emis: ' + ticket);
  console.log('bilet de test:', ticket.slice(0, 120));
  console.log(process.exitCode ? 'PREVIEW: PROBLEME' : 'PREVIEW: OK');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
