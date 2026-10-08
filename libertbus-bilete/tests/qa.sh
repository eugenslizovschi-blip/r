#!/bin/bash
# QA local: sintaxă PHP, teste automate, rezervare cap-coadă.
# Folosire: WP_PATH=/cale/wordpress WP_CLI="php /cale/wp-cli.phar --allow-root" BASE=http://127.0.0.1:8080 tests/qa.sh
set -u
DIR="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0
# Coșurile WooCommerce rămase de la un test picat (coșul adminului se păstrează în contul lui) ar strica testele
# următoare: un bilet cu 1 loc ar apărea în comanda altui test. Fiecare test din browser pornește cu coșuri goale.
fresh_carts() {
  (cd "$WP_PATH" && $WP_CLI eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_sessions" ); $wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE \"_woocommerce_persistent_cart_%\"" );' >/dev/null 2>&1) || true
}
echo "== Comenzi WooCommerce în: $(cd "$WP_PATH" && $WP_CLI eval 'echo WC_Data_Store::load( "order" )->get_current_class_name();' 2>/dev/null)"
echo "== Sintaxă PHP"
for f in $(find "$DIR" -name '*.php'); do php -l "$f" >/dev/null || { echo "  eroare: $f"; FAIL=1; }; done
echo "== Compatibilitate PHP 7.4+ (PHPCompatibility)"
# PHPCS_DIR = un director cu tests/compat/composer.json instalat (composer install); fără el pasul se sare.
PHPCS_DIR="${PHPCS_DIR:-$(dirname "$WP_PATH")/phpcs}"
if [ -x "$PHPCS_DIR/vendor/bin/phpcs" ]; then
  OUT_COMPAT=$("$PHPCS_DIR/vendor/bin/phpcs" -q --standard=PHPCompatibility --runtime-set testVersion 7.4- --extensions=php "$DIR" 2>&1)
  if [ -n "$OUT_COMPAT" ]; then echo "$OUT_COMPAT" | head -30; FAIL=1; else echo "  ok"; fi
else
  echo "  sărit (setați PHPCS_DIR)"
fi
echo "== Teste automate"
OUT_SMOKE=$(cd "$WP_PATH" && $WP_CLI eval-file "$DIR/tests/smoke.php" 2>&1 | grep -v sendmail)
echo "$OUT_SMOKE" | grep -E "FAIL|eșuate|Fatal"
echo "$OUT_SMOKE" | grep -q " 0 eșuate" || FAIL=1
# Încălzire: după repornirea containerului prima comandă WooCommerce poate dura peste 30 s (cache-uri reci)
# și testele din browser ar pica din timeout, nu din cauza codului.
for u in / /balti-iasi/ /checkout/ /wp-login.php; do curl -s -o /dev/null -m 60 "$BASE$u" || true; done
# Și prima pagină din admin după pornire e lentă (verificări de actualizări etc.): o încărcăm o dată, autentificați.
WARM_CJ="${TMPDIR:-/tmp}/lbb-warm-cookies"; rm -f "$WARM_CJ"
curl -s -o /dev/null -m 60 -c "$WARM_CJ" -b "$WARM_CJ" "$BASE/wp-login.php" || true
curl -s -o /dev/null -m 60 -c "$WARM_CJ" -b "$WARM_CJ" -d "log=admin&pwd=admin&wp-submit=1&testcookie=1" "$BASE/wp-login.php" || true
for u in /wp-admin/ "/wp-admin/admin.php?page=lbb"; do
  T=$(curl -s -o /dev/null -m 120 -b "$WARM_CJ" -w "%{time_total}" "$BASE$u" || echo "?")
  echo "  încălzire $u: ${T}s"
done
rm -f "$WARM_CJ"
fresh_carts
echo "== Rezervare cap-coadă"
OUT_FLOW=$(BASE="$BASE" node "$DIR/tests/e2e-flow.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_FLOW" | grep -E 'ticket:|FAIL|ALERT|pageerror'
echo "$OUT_FLOW" | grep -q 'ticket:' && ! echo "$OUT_FLOW" | grep -q 'FAIL' || FAIL=1
[ -f "$WP_PATH/wp-content/debug.log" ] && grep -E "Fatal|Warning|Notice" "$WP_PATH/wp-content/debug.log" | grep -i lbb && FAIL=1
fresh_carts
echo "== Vizitator nelogat"
OUT_GUEST=$(BASE="$BASE" node "$DIR/tests/e2e-guest.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_GUEST" | grep -q 'GUEST: OK' && echo "  ok" || { echo "$OUT_GUEST" | grep -E 'FAIL|    at ' | head -8; echo "  PROBLEME"; FAIL=1; }
fresh_carts
echo "== Plată în RON + rezervare cu plata la urcare"
OUT_CR=$(BASE="$BASE" node "$DIR/tests/e2e-currency-reserve.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_CR" | grep -q 'CURRENCY+RESERVE: OK' && echo "  ok" || { echo "$OUT_CR" | grep -E 'FAIL|    at ' | head -8; echo "  PROBLEME"; FAIL=1; }
fresh_carts
echo "== Previzualizare doar pentru admin (plata online oprită pentru clienți)"
(cd "$WP_PATH" && $WP_CLI eval '$s=LBB_Settings::all(); $s["allow_pay"]=0; update_option("lbb_settings",$s);')
LBB_PREVIEW_TOKEN=$(cd "$WP_PATH" && $WP_CLI eval 'echo LBB_Settings::preview_token();' 2>/dev/null) BASE="$BASE" node "$DIR/tests/e2e-preview.js" 2>&1 | grep -v CERT_AUTHORITY > "${TMPDIR:-/tmp}/lbb-preview.out"
grep -q 'PREVIEW: OK' "${TMPDIR:-/tmp}/lbb-preview.out" && echo "  ok" || { grep -E 'FAIL|    at ' "${TMPDIR:-/tmp}/lbb-preview.out" | head -8; echo "  PROBLEME"; FAIL=1; }
(cd "$WP_PATH" && $WP_CLI eval '$s=LBB_Settings::all(); $s["allow_pay"]=1; update_option("lbb_settings",$s);')
fresh_carts
echo "== Paginile de admin (Panou, Rute, Pasageri, Rezervări, Setări) și CSV-ul cu pasageri"
LOG="$WP_PATH/wp-content/debug.log"; BEFORE=$( [ -f "$LOG" ] && wc -l < "$LOG" || echo 0 )
OUT_ADM=$(BASE="$BASE" node "$DIR/tests/e2e-admin.js" 2>&1 | grep -v CERT_AUTHORITY)
NEW_ERR=$( [ -f "$LOG" ] && tail -n +$((BEFORE + 1)) "$LOG" | grep -E "Fatal|Warning|Notice|Deprecated" | grep -i lbb )
echo "$OUT_ADM" | grep -q "ADMIN: OK" && [ -z "$NEW_ERR" ] && echo "  ok" || { echo "$OUT_ADM" | grep FAIL | head -5; echo "$NEW_ERR" | head -5; echo "  PROBLEME"; FAIL=1; }
echo "== Cookies: banner, Google Analytics blocat până la acord, paginile legale"
OUT_CC=$(BASE="$BASE" OUT="${OUT:-.}" node "$DIR/tests/e2e-cookies.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_CC" | grep -q "COOKIES: OK" && echo "  ok" || { echo "$OUT_CC" | grep FAIL | head -5; echo "  PROBLEME"; FAIL=1; }
echo "== Accesibilitate (axe-core): formular, banner cookies, pagina biletului, paginile de admin"
# AXE_JS = axe.min.js din pachetul axe-core (tests/setup-local.sh îl descarcă); fără el pasul se sare.
AXE_JS="${AXE_JS:-$(dirname "$WP_PATH")/axe/package/axe.min.js}"
if [ -f "$AXE_JS" ]; then
  OUT_A11Y=$(AXE_JS="$AXE_JS" BASE="$BASE" node "$DIR/tests/e2e-a11y.js" 2>&1 | grep -v CERT_AUTHORITY)
  echo "$OUT_A11Y" | grep -q "A11Y: OK" && echo "  ok" || { echo "$OUT_A11Y" | grep -E 'FAIL|    at ' | head -8; echo "  PROBLEME"; FAIL=1; }
else
  echo "  sărit (setați AXE_JS)"
fi
echo "== Dispozitive (iPhone SE … desktop 1920), 5 browsere în paralel"
OUT_DEV=$(BASE="$BASE" OUT="${OUT:-.}" PARALLEL=5 node "$DIR/tests/e2e-devices.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_DEV" | grep -E '^✗|^    -|FAIL' ; echo "$OUT_DEV" | grep -c '^✓' | sed 's/^/  dispozitive OK: /'
echo "$OUT_DEV" | grep -qE '^✗|FAIL' && FAIL=1
echo "== Rezultat: $([ $FAIL = 0 ] && echo OK || echo PROBLEME)"
exit $FAIL
