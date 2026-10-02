#!/bin/bash
# QA local: sintaxă PHP, teste automate, rezervare cap-coadă.
# Folosire: WP_PATH=/cale/wordpress WP_CLI="php /cale/wp-cli.phar --allow-root" BASE=http://127.0.0.1:8080 tests/qa.sh
set -u
DIR="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0
echo "== Sintaxă PHP"
for f in $(find "$DIR" -name '*.php'); do php -l "$f" >/dev/null || { echo "  eroare: $f"; FAIL=1; }; done
echo "== Teste automate"
OUT_SMOKE=$(cd "$WP_PATH" && $WP_CLI eval-file "$DIR/tests/smoke.php" 2>&1 | grep -v sendmail)
echo "$OUT_SMOKE" | grep -E "FAIL|eșuate|Fatal"
echo "$OUT_SMOKE" | grep -q " 0 eșuate" || FAIL=1
echo "== Rezervare cap-coadă"
OUT_FLOW=$(BASE="$BASE" node "$DIR/tests/e2e-flow.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_FLOW" | grep -E 'ticket:|FAIL|ALERT|pageerror'
echo "$OUT_FLOW" | grep -q 'ticket:' && ! echo "$OUT_FLOW" | grep -q 'FAIL' || FAIL=1
[ -f "$WP_PATH/wp-content/debug.log" ] && grep -E "Fatal|Warning|Notice" "$WP_PATH/wp-content/debug.log" | grep -i lbb && FAIL=1
echo "== Vizitator nelogat"
BASE="$BASE" node "$DIR/tests/e2e-guest.js" 2>&1 | grep -v CERT_AUTHORITY | grep -E 'GUEST|FAIL' | grep -q 'GUEST: OK' && echo "  ok" || { echo "  PROBLEME"; FAIL=1; }
echo "== Plată în RON + rezervare cu plata la urcare"
BASE="$BASE" node "$DIR/tests/e2e-currency-reserve.js" 2>&1 | grep -v CERT_AUTHORITY | grep -q 'CURRENCY+RESERVE: OK' && echo "  ok" || { echo "  PROBLEME"; FAIL=1; }
echo "== Previzualizare doar pentru admin (plata online oprită pentru clienți)"
(cd "$WP_PATH" && $WP_CLI eval '$s=LBB_Settings::all(); $s["allow_pay"]=0; update_option("lbb_settings",$s);')
BASE="$BASE" node "$DIR/tests/e2e-preview.js" 2>&1 | grep -v CERT_AUTHORITY | grep -q 'PREVIEW: OK' && echo "  ok" || { echo "  PROBLEME"; FAIL=1; }
(cd "$WP_PATH" && $WP_CLI eval '$s=LBB_Settings::all(); $s["allow_pay"]=1; update_option("lbb_settings",$s);')
echo "== Dispozitive (iPhone SE … desktop 1920), 5 browsere în paralel"
OUT_DEV=$(BASE="$BASE" OUT="${OUT:-.}" PARALLEL=5 node "$DIR/tests/e2e-devices.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_DEV" | grep -E '^✗|^    -|FAIL' ; echo "$OUT_DEV" | grep -c '^✓' | sed 's/^/  dispozitive OK: /'
echo "$OUT_DEV" | grep -qE '^✗|FAIL' && FAIL=1
echo "== Rezultat: $([ $FAIL = 0 ] && echo OK || echo PROBLEME)"
exit $FAIL
