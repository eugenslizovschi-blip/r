// E2E: paginile de admin LibertBus (Panou, Rute și orar, Pasageri, Rezervări, Setări) se deschid fără erori,
// iar lista de pasageri se descarcă în CSV.
// BASE=http://127.0.0.1:8080 node tests/e2e-admin.js
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
const fail = (m) => { console.error('FAIL ' + m); process.exitCode = 1; };
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
  const page = await ctx.newPage();
  page.on('pageerror', e => fail('pageerror pe ' + page.url() + ': ' + e.message));
  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  if (/wp-login\.php/.test(page.url())) { console.error('FAIL login admin eșuat: ' + page.url()); process.exit(1); }

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

  // Lista de pasageri se descarcă în CSV, cu antetul corect și BOM (diacritice corecte în Excel).
  await page.goto(BASE + '/wp-admin/admin.php?page=lbb-manifest');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('a.button:text-is("CSV")')]);
  const fs = require('fs');
  const csv = fs.readFileSync(await dl.path(), 'utf8');
  if (csv.charCodeAt(0) !== 0xFEFF) fail('CSV fără BOM: diacriticele se strică în Excel');
  if (!/^﻿?Data,Ora,Plecare,Destinatie,Bilet,Locuri,Pasageri,Telefon,Email,Plata,Comanda/.test(csv)) fail('antet CSV greșit: ' + csv.slice(0, 80));
  if (!/^pasageri-\d{4}-\d{2}-\d{2}\.csv$/.test(dl.suggestedFilename())) fail('nume de fișier CSV greșit: ' + dl.suggestedFilename());

  console.log(process.exitCode ? 'ADMIN: PROBLEME' : 'ADMIN: OK');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
