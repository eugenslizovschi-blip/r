// E2E pe o instalare de test: rezervare ca admin cu plata de test, apoi verificarea biletului.
// BASE=http://127.0.0.1:8080 node tests/e2e-flow.js (user/parolă admin/admin)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8080';
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  const errors = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  // login
  await page.goto(BASE + '/wp-login.php');
  await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  // route page with preset
  await page.goto(BASE + '/balti-iasi/');
  await page.waitForSelector('[data-lbb="from"]');
  console.log('from preset:', await page.inputValue('[data-lbb="from"]'), '| route text:', await page.$eval('[data-lbb="route"]', s => s.options[s.selectedIndex].text));
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
  await names[0].fill('Ion Popescu'); await names[1].fill('Maria Popescu');
  await page.fill('input[name="lbb_phone"]', '+37369184111');
  await page.fill('input[name="lbb_email"]', 'client@example.com');
  console.log('summary:', (await page.textContent('[data-lbb="summary"]')).replace(/\s+/g, ' '));
  await page.screenshot({ path: (process.env.OUT || '.') + '/form.png', fullPage: true });
  await Promise.all([page.waitForNavigation(), page.click('[data-lbb="submit"]')]);
  console.log('after submit url:', page.url());
  const alert = await page.$('.lbb-alert'); if (alert) console.log('ALERT:', await alert.textContent());
  await page.waitForSelector('#payment', { timeout: 15000 });
  console.log('address field present:', !!(await page.$('#billing_address_1')));
  console.log('prefill first/last/phone:', await page.inputValue('#billing_first_name'), '/', await page.inputValue('#billing_last_name'), '/', await page.inputValue('#billing_phone'));
  console.log('review:', (await page.textContent('.woocommerce-checkout-review-order-table')).replace(/\s+/g, ' ').slice(0, 300));
  await page.screenshot({ path: (process.env.OUT || '.') + '/checkout.png', fullPage: true });
  await page.check('#payment_method_lbb_test');
  const terms = await page.$('#terms'); if (terms) await terms.check();
  await Promise.all([page.waitForURL(/order-received/, { timeout: 30000 }), page.click('#place_order')]);
  console.log('thank you url:', page.url());
  const ticket = await page.textContent('.lbb-ticket');
  console.log('ticket:', ticket.replace(/\s+/g, ' '));
  await page.screenshot({ path: (process.env.OUT || '.') + '/thankyou.png', fullPage: true });
  const link = await page.getAttribute('.lbb-ticket a', 'href');
  await page.goto(link);
  console.log('ticket page state:', await page.textContent('.state'));
  await page.waitForTimeout(1500);
  console.log('qr rendered:', !!(await page.$('.lbb-ticket-qr canvas, .lbb-ticket-qr img')));
  await page.screenshot({ path: (process.env.OUT || '.') + '/ticketpage.png', fullPage: true });
  console.log('JS errors:', errors.length ? errors : 'none');
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
