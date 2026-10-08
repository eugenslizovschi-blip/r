// Logarea adminului în teste: login(page, BASE[, { user, pass, timeout }]).
// wp-login.php mută focusul pe „Username” la 200 ms după încărcare (wp_attempt_focus). Dacă asta
// cade în mijlocul lui fill('#user_pass'), parola se scrie în câmpul de user și formularul pleacă
// fără parolă („Please fill out this field”). Așteptăm întâi focusul, apoi completăm și verificăm.
module.exports = async function login(page, base, opts = {}) {
  const user = opts.user || process.env.WP_USER || 'admin';
  const pass = opts.pass || process.env.WP_PASS || 'admin';
  const timeout = opts.timeout || 30000;
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout });
  await page.waitForSelector('#user_login', { timeout });
  await page.waitForFunction(() => document.activeElement && document.activeElement.id === 'user_login', null, { timeout: 5000 }).catch(() => {});
  for (let i = 0; i < 3; i++) {
    await page.fill('#user_login', user); await page.fill('#user_pass', pass);
    if (await page.inputValue('#user_login') === user && await page.inputValue('#user_pass') === pass) break;
  }
  await Promise.all([page.waitForNavigation({ timeout }), page.click('#wp-submit')]);
  // Login eșuat (ex. parolă în format nou după o actualizare WordPress): oprim clar, nu așteptăm la nesfârșit.
  if (/wp-login\.php/.test(page.url())) { console.error('FAIL login admin eșuat: ' + page.url()); process.exit(1); }
};
