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
# Contează doar erorile scrise în debug.log în timpul acestei rulări: una veche (ex. de la o rulare oprită la
# jumătate) ar face QA să pice la nesfârșit.
LOG="$WP_PATH/wp-content/debug.log"; LOG_START=$( [ -f "$LOG" ] && wc -l < "$LOG" || echo 0 )
echo "== Serverul web: $(curl -sI "$BASE/" | tr -d '\r' | sed -n 's/^[Xx]-[Pp]owered-[Bb]y: //p' | head -1)"
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
# Comparațiile stricte: pe PHP 7.4 (ca pe libertbus.md) „abc” == 0 e adevărat, pe PHP 8 nu; testele rulează pe PHP 8.
echo "== Securitate WordPress (escapare, SQL pregătit, nonce, date de intrare), comparații stricte și texte traductibile"
# WPCS_DIR = un director cu tests/wpcs/composer.json instalat (composer install); fără el pasul se sare.
WPCS_DIR="${WPCS_DIR:-$(dirname "$WP_PATH")/wpcs}"
if [ -x "$WPCS_DIR/vendor/bin/phpcs" ]; then
  OUT_SEC=$("$WPCS_DIR/vendor/bin/phpcs" -q --standard=WordPress-Extra --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders,WordPress.WP.I18n,WordPress.PHP.StrictInArray,Universal.Operators.StrictComparisons --extensions=php --ignore='*/tests/*' "$DIR" 2>&1)
  if [ -n "$OUT_SEC" ]; then echo "$OUT_SEC" | head -30; FAIL=1; else echo "  ok"; fi
else
  echo "  sărit (setați WPCS_DIR)"
fi
echo "== JavaScript ES5 (sintaxă pentru telefoane vechi)"
# ESLINT = calea spre eslint; fără el pasul se sare. Verifică doar sintaxa (nu și funcții ca fetch/Promise).
ESLINT="${ESLINT:-$(command -v eslint || true)}"
if [ -n "$ESLINT" ]; then
  OUT_ES5=$("$ESLINT" -c "$DIR/tests/es5.config.cjs" "$DIR/assets/lbb.js" "$DIR/assets/cookies.js" 2>&1)
  if [ -n "$OUT_ES5" ]; then echo "$OUT_ES5" | head -20; FAIL=1; else echo "  ok"; fi
else
  echo "  sărit (setați ESLINT)"
fi
# Pe site-ul de test toate emailurile se opresc înainte de trimitere (mu-plugin mail-dump). Pornim fără eroare
# de email salvată: la final, una nouă înseamnă că un test sau codul a încercat să trimită un email real.
(cd "$WP_PATH" && $WP_CLI option delete lbb_mail_failed >/dev/null 2>&1) || true
echo "== Arhiva de instalare (fără teste, capturi și notițe)"
ZIP_DIR=$(mktemp -d); ZIP=$("$DIR/tests/build-zip.sh" "$ZIP_DIR" 2>&1)
ZIP_LIST=$( [ -f "$ZIP" ] && unzip -Z1 "$ZIP" )
if [ -z "$ZIP_LIST" ] || ! echo "$ZIP_LIST" | grep -qx 'libertbus-bilete/libertbus-bilete.php' || ! echo "$ZIP_LIST" | grep -qx 'libertbus-bilete/includes/class-lbb-tickets.php' || ! echo "$ZIP_LIST" | grep -qx 'libertbus-bilete/assets/lbb.js' || ! echo "$ZIP_LIST" | grep -qx 'libertbus-bilete/languages/libertbus-bilete.pot' \
  || echo "$ZIP_LIST" | grep -qE '(^|/)tests/|\.png$|PLAN\.md$' || [ "$(basename "$ZIP")" != "libertbus-bilete-$(sed -n 's/^ \* Version: *//p' "$DIR/libertbus-bilete.php" | tr -d '[:space:]').zip" ]; then
  echo "  arhivă greșită: $ZIP"; echo "$ZIP_LIST" | grep -E 'tests/|\.png$|PLAN' | head -3; FAIL=1
else echo "  ok ($(basename "$ZIP"), $(echo "$ZIP_LIST" | grep -vc '/$') fișiere, $(du -k "$ZIP" | cut -f1) KB)"; fi
rm -rf "$ZIP_DIR"
echo "== Traduceri (languages/libertbus-bilete.pot la zi, fără avertismente)"
# Șablonul pentru traducători (ex. versiunea rusă a site-ului): aceleași texte ca în cod. După ce se schimbă un text:
# php wp-cli.phar i18n make-pot . languages/libertbus-bilete.pot --exclude=tests --slug=libertbus-bilete
POT_TMP=$(mktemp -d)
POT_OUT=$(cd "$DIR" && $WP_CLI i18n make-pot . "$POT_TMP/new.pot" --exclude=tests --slug=libertbus-bilete 2>&1 | grep -i "warning")
pot_ids() { grep -E '^(msgctxt|msgid|msgid_plural) ' "$1" | sort; }
if [ -n "$POT_OUT" ] || [ ! -f "$POT_TMP/new.pot" ] || ! diff -q <(pot_ids "$DIR/languages/libertbus-bilete.pot") <(pot_ids "$POT_TMP/new.pot") >/dev/null; then
  echo "$POT_OUT" | head -5; diff <(pot_ids "$DIR/languages/libertbus-bilete.pot") <(pot_ids "$POT_TMP/new.pot") | head -6; echo "  PROBLEME (regenerați .pot)"; FAIL=1
else echo "  ok ($(grep -c '^msgid ' "$DIR/languages/libertbus-bilete.pot") texte)"; fi
rm -rf "$POT_TMP"
echo "== Teste automate"
OUT_SMOKE=$(cd "$WP_PATH" && $WP_CLI eval-file "$DIR/tests/smoke.php" 2>&1 | grep -v sendmail)
echo "$OUT_SMOKE" | grep -E "FAIL|eșuate|Fatal"
echo "$OUT_SMOKE" | grep -q " 0 eșuate" || FAIL=1
# README spune câte verificări și câte dispozitive sunt: dacă se adaugă un test și nu se actualizează, QA pică.
SMOKE_N=$(echo "$OUT_SMOKE" | sed -n 's/^\([0-9]*\) ok, \([0-9]*\) eșuate.*/\1 \2/p' | awk '{print $1+$2}')
README_N=$(sed -n 's/.*`tests\/smoke.php`: \([0-9]*\) de verificări.*/\1/p' "$DIR/README.md")
DEV_N=$(sed -n '/^const LIST = \[/,/^\];/p' "$DIR/tests/e2e-devices.js" | grep -c "^  \['")
README_DEV=$(sed -n 's/.*\*\*\([0-9]*\) dispozitive\*\*.*/\1/p' "$DIR/README.md")
if [ -z "$SMOKE_N" ] || [ "$SMOKE_N" != "$README_N" ] || [ "$DEV_N" != "$README_DEV" ]; then
  echo "  README nesincronizat: $README_N verificări în README, $SMOKE_N rulate; $README_DEV dispozitive în README, $DEV_N în tests/e2e-devices.js"; FAIL=1
fi
# Site-ul live rulează PHP 7.4: cu php7.4 instalat (tests/setup-local.sh îl pune), sintaxa și testele automate rulează
# și cu el, nu doar verificarea statică de mai sus. PHP74_BIN=/cale/php7.4 alege alt binar.
PHP74_BIN="${PHP74_BIN:-$(command -v php7.4 || true)}"
if [ -n "$PHP74_BIN" ] && [ "${WP_CLI#php }" != "$WP_CLI" ]; then
  echo "== Teste automate cu PHP 7.4 (ca pe site-ul live)"
  for f in $(find "$DIR" -name '*.php'); do "$PHP74_BIN" -l "$f" >/dev/null || { echo "  eroare PHP 7.4: $f"; FAIL=1; }; done
  OUT_74=$(cd "$WP_PATH" && "$PHP74_BIN" ${WP_CLI#php } eval-file "$DIR/tests/smoke.php" 2>&1 | grep -v sendmail)
  echo "$OUT_74" | grep -E "FAIL|eșuate|Fatal|Parse error"
  echo "$OUT_74" | grep -q " 0 eșuate" || FAIL=1
fi
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
[ -f "$LOG" ] && tail -n +$((LOG_START + 1)) "$LOG" | grep -E "Fatal|Warning|Notice" | grep -i lbb && FAIL=1
fresh_carts
echo "== Vizitator nelogat"
OUT_GUEST=$(BASE="$BASE" node "$DIR/tests/e2e-guest.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_GUEST" | grep -q 'GUEST: OK' && echo "  ok" || { echo "$OUT_GUEST" | grep -E 'FAIL|    at ' | head -8; echo "  PROBLEME"; FAIL=1; }
fresh_carts
echo "== Plată în RON + rezervare cu plata la urcare"
OUT_CR=$(BASE="$BASE" node "$DIR/tests/e2e-currency-reserve.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_CR" | grep -q 'CURRENCY+RESERVE: OK' && echo "  ok" || { echo "$OUT_CR" | grep -E 'FAIL|    at ' | head -8; echo "  PROBLEME"; FAIL=1; }
fresh_carts
echo "== Checkout cu blocuri: fără adresă poștală pentru bilete"
CHK_ID=$(cd "$WP_PATH" && $WP_CLI option get woocommerce_checkout_page_id 2>/dev/null)
CHK_OLD=$(cd "$WP_PATH" && $WP_CLI post get "$CHK_ID" --field=post_content 2>/dev/null)
(cd "$WP_PATH" && $WP_CLI post update "$CHK_ID" --post_content='<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout wc-block-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->' >/dev/null 2>&1)
OUT_BL=$(BASE="$BASE" node "$DIR/tests/e2e-blocks.js" 2>&1 | grep -v CERT_AUTHORITY)
(cd "$WP_PATH" && $WP_CLI post update "$CHK_ID" --post_content="$CHK_OLD" >/dev/null 2>&1)
echo "$OUT_BL" | grep -q 'BLOCKS: OK' && echo "  ok" || { echo "$OUT_BL" | grep -E 'FAIL|    at ' | head -8; echo "  PROBLEME"; FAIL=1; }
fresh_carts
echo "== Previzualizare doar pentru admin (plata online oprită pentru clienți)"
(cd "$WP_PATH" && $WP_CLI eval '$s=LBB_Settings::all(); $s["allow_pay"]=0; update_option("lbb_settings",$s);')
LBB_PREVIEW_TOKEN=$(cd "$WP_PATH" && $WP_CLI eval 'echo LBB_Settings::preview_token();' 2>/dev/null) BASE="$BASE" node "$DIR/tests/e2e-preview.js" 2>&1 | grep -v CERT_AUTHORITY > "${TMPDIR:-/tmp}/lbb-preview.out"
grep -q 'PREVIEW: OK' "${TMPDIR:-/tmp}/lbb-preview.out" && echo "  ok" || { grep -E 'FAIL|    at ' "${TMPDIR:-/tmp}/lbb-preview.out" | head -8; echo "  PROBLEME"; FAIL=1; }
(cd "$WP_PATH" && $WP_CLI eval '$s=LBB_Settings::all(); $s["allow_pay"]=1; update_option("lbb_settings",$s);')
fresh_carts
echo "== Paginile de admin (Panou, Rute, Pasageri, Rezervări, Setări) și CSV-ul cu pasageri"
BEFORE=$( [ -f "$LOG" ] && wc -l < "$LOG" || echo 0 )
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
  # Cu două monede de plată: butoanele MDL / RON apar în formular și sunt verificate și ele (și în contrast ridicat).
  PAY_OLD=$(cd "$WP_PATH" && $WP_CLI eval 'echo LBB_Settings::get("pay_currencies");' 2>/dev/null)
  (cd "$WP_PATH" && $WP_CLI eval '$s=LBB_Settings::all(); $s["pay_currencies"]="MDL,RON"; update_option("lbb_settings",$s);' >/dev/null 2>&1)
  OUT_A11Y=$(AXE_JS="$AXE_JS" BASE="$BASE" node "$DIR/tests/e2e-a11y.js" 2>&1 | grep -v CERT_AUTHORITY)
  (cd "$WP_PATH" && LBB_PAY_OLD="$PAY_OLD" $WP_CLI eval '$s=LBB_Settings::all(); $s["pay_currencies"]=getenv("LBB_PAY_OLD") ?: "MDL"; update_option("lbb_settings",$s);' >/dev/null 2>&1)
  echo "$OUT_A11Y" | grep -q "A11Y: OK" && echo "  ok" || { echo "$OUT_A11Y" | grep -E 'FAIL|    at ' | head -8; echo "  PROBLEME"; FAIL=1; }
else
  echo "  sărit (setați AXE_JS)"
fi
echo "== Dispozitive (iPhone SE … desktop 1920), 5 browsere în paralel"
OUT_DEV=$(BASE="$BASE" OUT="${OUT:-.}" PARALLEL=5 node "$DIR/tests/e2e-devices.js" 2>&1 | grep -v CERT_AUTHORITY)
echo "$OUT_DEV" | grep -E '^✗|^    -|FAIL' ; echo "$OUT_DEV" | grep -c '^✓' | sed 's/^/  dispozitive OK: /'
echo "$OUT_DEV" | grep -qE '^✗|FAIL' && FAIL=1
# Orice avertisment PHP din această rulare, și cele raportate în fișierele WordPress/WooCommerce (ex. esc_url( null )
# apelat din plugin apare în formatting.php, fără „lbb” în cale). Excepție: un notice al WooCommerce însuși, la
# anonimizarea clientului din Unelte → Șterge datele personale (WC_Admin Customers DataStore, nu codul nostru), și
# unul al WordPress 6.4: cron-ul lui de verificare HTTPS (de două ori pe zi) apelează o funcție pe care el însuși a
# declarat-o învechită; apare doar când cron-ul nimerește în timpul testelor, deci QA ar pica la întâmplare.
PHP_ERR=$( [ -f "$LOG" ] && tail -n +$((LOG_START + 1)) "$LOG" | grep -E "PHP (Fatal|Warning|Notice|Deprecated)" | grep -v "wpdb::prepare was called .*Unsupported value type (object)" | grep -v "Function wp_update_https_detection_errors is deprecated since version 6.4.0" )
if [ -n "$PHP_ERR" ]; then echo "== Avertismente PHP în debug.log:"; echo "$PHP_ERR" | sed -E 's/^\[[^]]*\] //' | sort | uniq -c | head -8; FAIL=1; fi
MAIL_FAILED=$(cd "$WP_PATH" && $WP_CLI eval '$f = get_option( "lbb_mail_failed" ); echo is_array( $f ) ? $f["message"] : "";' 2>/dev/null)
if [ -n "$MAIL_FAILED" ]; then echo "== Emailuri: un test a încercat o trimitere reală (eșuată): $MAIL_FAILED"; FAIL=1; fi
echo "== Rezultat: $([ $FAIL = 0 ] && echo OK || echo PROBLEME)"
exit $FAIL
