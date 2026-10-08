// La o eroare neprinsă (ex. timeout): pentru fiecare pagină deschisă arată adresa și salvează o captură în OUT,
// ca un blocaj rar (ex. plata de test care nu apare) să poată fi înțeles după rulare.
// Folosire: (async () => { BROWSER = await chromium.launch(); ... })().catch(require('./crash')('flow', () => BROWSER));
module.exports = (name, getBrowser) => async (err) => {
  console.error('FAIL', err);
  const browser = getBrowser();
  if (browser) {
    let i = 0;
    for (const ctx of browser.contexts()) {
      for (const page of ctx.pages()) {
        i++;
        const file = (process.env.OUT || '.') + '/crash-' + name + '-' + i + '.png';
        await page.screenshot({ path: file, fullPage: true, timeout: 10000 }).catch(() => {});
        console.error('FAIL pagina ' + i + ' la eroare: ' + page.url() + ' (captură: ' + file + ')');
      }
    }
    await browser.close().catch(() => {});
  }
  process.exit(1);
};
