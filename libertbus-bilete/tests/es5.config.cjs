// Scripturile din formular rulează și pe telefoane vechi (Android cu WebView vechi, iPhone pe iOS 10–12):
// sintaxa trebuie să fie ES5 (fără =>, let/const, `…`, clase). tests/qa.sh: eslint -c tests/es5.config.cjs
module.exports = [ { files: [ '**/*.js' ], languageOptions: { ecmaVersion: 5, sourceType: 'script' }, rules: {} } ];
