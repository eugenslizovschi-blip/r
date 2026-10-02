#!/bin/bash
# QA local: sintaxă PHP, teste automate, rezervare cap-coadă.
# Folosire: WP_PATH=/cale/wordpress WP_CLI="php /cale/wp-cli.phar --allow-root" BASE=http://127.0.0.1:8080 tests/qa.sh
set -u
DIR="$(cd "$(dirname "$0")/.." && pwd)"
FAIL=0
echo "== Sintaxă PHP"
for f in $(find "$DIR" -name '*.php'); do php -l "$f" >/dev/null || { echo "  eroare: $f"; FAIL=1; }; done
echo "== Teste automate"
(cd "$WP_PATH" && $WP_CLI eval-file "$DIR/tests/smoke.php" 2>&1 | grep -v sendmail | tail -3) || FAIL=1
echo "== Rezervare cap-coadă"
BASE="$BASE" node "$DIR/tests/e2e-flow.js" 2>&1 | grep -v CERT_AUTHORITY | grep -E 'ticket:|FAIL|ALERT|pageerror' || FAIL=1
[ -f "$WP_PATH/wp-content/debug.log" ] && grep -E "Fatal|Warning|Notice" "$WP_PATH/wp-content/debug.log" | grep -i lbb && FAIL=1
echo "== Rezultat: $([ $FAIL = 0 ] && echo OK || echo PROBLEME)"
exit $FAIL
