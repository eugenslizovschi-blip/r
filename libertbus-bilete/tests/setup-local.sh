#!/bin/bash
# Pornește (sau reface) un WordPress de test cu WooCommerce și plugin-ul, pe http://127.0.0.1:8080.
# Folosire: tests/setup-local.sh /cale/director-de-lucru
set -e
W="${1:?director de lucru}"
PLUGIN="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$W" && cd "$W"
pgrep -x mariadbd >/dev/null || pgrep -x mysqld >/dev/null || { (mysqld_safe --user=mysql >/dev/null 2>&1 &); sleep 5; }
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS wp; CREATE USER IF NOT EXISTS 'wp'@'localhost' IDENTIFIED BY 'wp'; GRANT ALL ON wp.* TO 'wp'@'localhost';"
[ -f wp-cli.phar ] || curl -sSL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
# Aceeași versiune ca pe libertbus.md; WP_VERSION=7.1.2 tests/setup-local.sh … testează o actualizare.
WP_VERSION="${WP_VERSION:-6.4.3}"
[ -d wordpress ] || { curl -sSL -o wp.zip "https://wordpress.org/wordpress-$WP_VERSION.zip" && unzip -q wp.zip; }
WP="php $W/wp-cli.phar --allow-root --path=$W/wordpress"
if ! $WP core is-installed 2>/dev/null; then
  $WP config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost=localhost --skip-check --force
  $WP config set WP_DEBUG true --raw && $WP config set WP_DEBUG_LOG true --raw && $WP config set WP_DEBUG_DISPLAY false --raw
  $WP core install --url=http://127.0.0.1:8080 --title="LibertBus Test" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
  [ -d wordpress/wp-content/plugins/woocommerce ] || { curl -sSL -o wc.zip https://downloads.wordpress.org/plugin/woocommerce.8.7.0.zip && unzip -q -o wc.zip -d wordpress/wp-content/plugins/; }
fi
# Fără actualizări automate (altfel WordPress trece singur la ultima versiune), apoi versiunea cerută.
$WP config set AUTOMATIC_UPDATER_DISABLED true --raw >/dev/null
[ "$($WP core version)" = "$WP_VERSION" ] || $WP core update --version="$WP_VERSION" --force >/dev/null
# Baza de date pe versiunea fișierelor (după o trecere 7.x → 6.4 adminul cere altfel „Database Update Required”).
$WP --skip-themes --skip-plugins core update-db >/dev/null
# Tema de test e mereu Twenty Twenty-Four: există în 6.4 și în 7.x. O instalare făcută cu fișierele 7.x
# alege Twenty Twenty-Five, care pe 6.4 dă eroare fatală (cere funcții din WordPress 6.7).
$WP --skip-themes --skip-plugins theme activate twentytwentyfour >/dev/null
# Adresa site-ului mereu fixă (o reinstalare peste alte fișiere poate ghici „/wordpress” și strică toate linkurile).
$WP --skip-themes --skip-plugins option update home http://127.0.0.1:8080 >/dev/null
$WP --skip-themes --skip-plugins option update siteurl http://127.0.0.1:8080 >/dev/null
# Parola admin-ului de test, mereu din nou: WordPress 7 o salvează în alt format (bcrypt), pe care 6.4 nu-l citește.
$WP user update admin --user_pass=admin --skip-email >/dev/null
ln -sfn "$PLUGIN" wordpress/wp-content/plugins/libertbus-bilete
# Testele de plată au nevoie de butonul „Achit online” și de plata de test.
$WP eval '$s=get_option("lbb_settings",array()); if(is_array($s)){ $s["allow_pay"]=1; $s["test_gateway"]=1; update_option("lbb_settings",$s); }' 2>/dev/null || true
$WP plugin activate woocommerce libertbus-bilete
$WP option update timezone_string Europe/Chisinau && $WP option update woocommerce_currency MDL && $WP rewrite structure '/%postname%/'
if [ "$($WP post list --post_type=page --name=rezervare-bilet --format=count)" = 0 ]; then
  $WP eval 'WC_Install::create_pages();'
  $WP post update "$($WP option get woocommerce_checkout_page_id)" --post_content='[woocommerce_checkout]'
  $WP post update "$($WP option get woocommerce_cart_page_id)" --post_content='[woocommerce_cart]'
  $WP post create --post_type=page --post_status=publish --post_title='Rezervare bilet' --post_name=rezervare-bilet --post_content='[libertbus_rezervare]'
  $WP post create --post_type=page --post_status=publish --post_title='Balti - Iasi' --post_name=balti-iasi --post_content='<div style="height:1400px">Spațiu de test: formularul e jos pe pagină, ca pe homepage.</div>[libertbus_rezervare from="Balti" to="Iasi"]'
  $WP eval '$s=LBB_Settings::all(); $s["test_gateway"]=1; $s["allow_pay"]=1; update_option("lbb_settings",$s);'
fi
# Contact Form 7 + o pagină privată de previzualizare (ca pe libertbus.md: formularul #210 și formulare de rută).
[ -d wordpress/wp-content/plugins/contact-form-7 ] || { curl -sSL -o cf7.zip https://downloads.wordpress.org/plugin/contact-form-7.5.9.3.zip && unzip -q -o cf7.zip -d wordpress/wp-content/plugins/; }
$WP plugin activate contact-form-7 >/dev/null
if [ "$($WP post list --post_type=page --post_status=private --name=previzualizare-bilete --format=count)" = 0 ]; then
  F1=$($WP post create --post_type=wpcf7_contact_form --post_status=publish --post_title='mobile bun - homepage' --porcelain)
  F2=$($WP post create --post_type=wpcf7_contact_form --post_status=publish --post_title='Balti - Iasi' --porcelain)
  for f in $F1 $F2; do $WP post meta update $f _form '[text* nume] [submit "Trimite"]' >/dev/null; done
  $WP post create --post_type=page --post_status=private --post_title='Previzualizare bilete' --post_name=previzualizare-bilete --post_content="[contact-form-7 id=\"$F1\" title=\"h\"]<hr>[contact-form-7 id=\"$F2\" title=\"r\"]" >/dev/null
  $WP post create --post_type=page --post_status=publish --post_title='Formulare vechi' --post_name=formulare-vechi --post_content="[contact-form-7 id=\"$F1\" title=\"h\"]" >/dev/null
  $WP eval "\$s=get_option('lbb_settings',array()); \$s['preview_cf7']='$F1'; update_option('lbb_settings',\$s);"
fi
mkdir -p wordpress/wp-content/mu-plugins
# Doar pe site-ul de test: fără „async runner” al Action Scheduler (WooCommerce). Acesta pornește sarcinile în fundal
# printr-o cerere către admin-ajax cu cookie-urile vizitatorului curent; imediat după setup (comenzi de test șterse,
# importul Analytics de rulat) cererea încărca în paralel același coș și îl putea salva gol peste coșul abia completat:
# „Sorry, your session has expired” la plată, din când în când. Sarcinile rulează în continuare prin WP-Cron.
cat > wordpress/wp-content/mu-plugins/no-async-runner.php <<'PHP'
<?php
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
PHP
cat > wordpress/wp-content/mu-plugins/mail-dump.php <<'PHP'
<?php
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	wp_mkdir_p( WP_CONTENT_DIR . '/mail' );
	file_put_contents( WP_CONTENT_DIR . '/mail/' . microtime( true ) . '.html', 'TO: ' . implode( ',', (array) $atts['to'] ) . "\nSUBJECT: " . $atts['subject'] . "\n\n" . $atts['message'] );
	return true;
}, 10, 2 );
PHP
# Rezervările rămase din rulările anterioare se șterg: altfel, după sute de rulări, cursele din următoarele
# săptămâni se umplu și testele nu mai găsesc locuri (iar o oră „plină” apare din întâmplare, nu din test).
$WP eval 'global $wpdb; $wpdb->query( "DELETE FROM " . LBB_Bookings::table() );' >/dev/null
# Jurnalul de erori pornește gol la fiecare pregătire: QA caută în el doar erorile rulării curente.
: > wordpress/wp-content/debug.log
# Textul din ghidul de confidențialitate WordPress se salvează la prima vizită: îl ștergem, ca testul să vadă codul actual.
$WP eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = \"_wp_suggested_privacy_policy_content\" AND meta_value LIKE \"%LibertBus Bilete%\"" );' >/dev/null
# Stocarea comenzilor WooCommerce: pe rând HPOS (ore pare) și tabelele vechi „posts” (ore impare), ca rundele
# automate să le acopere pe amândouă (libertbus.md, actualizat de-a lungul anilor, folosește probabil „posts”).
# LBB_ORDER_STORAGE=hpos|posts o fixează. Comenzile de test se șterg întâi: WooCommerce nu schimbă stocarea
# cât timp există comenzi nesincronizate.
STORAGE="${LBB_ORDER_STORAGE:-$( [ $(( 10#$(date -u +%H) % 2 )) -eq 0 ] && echo hpos || echo posts )}"
$WP eval 'foreach ( wc_get_orders( array( "limit" => -1, "return" => "ids", "status" => "any" ) ) as $id ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } }' >/dev/null 2>&1 || true
$WP option update woocommerce_custom_orders_table_enabled "$( [ "$STORAGE" = hpos ] && echo yes || echo no )" >/dev/null
echo "Comenzi WooCommerce stocate în: $STORAGE ($($WP eval 'echo WC_Data_Store::load( "order" )->get_current_class_name();' 2>/dev/null))"
# Imită modulul „GA Google Analytics” de pe libertbus.md (același cod), plus scripturi care NU trebuie blocate.
cat > wordpress/wp-content/mu-plugins/fake-analytics.php <<'PHP'
<?php
add_action( 'wp_head', function () {
	echo "\t\t<script async src=\"https://www.googletagmanager.com/gtag/js?id=G-TEST000000\"></script>\n";
	echo "\t\t<script>\n\t\t\twindow.dataLayer = window.dataLayer || [];\n\t\t\tfunction gtag(){dataLayer.push(arguments);}\n\t\t\tgtag('js', new Date());\n\t\t\tgtag('config', 'G-TEST000000');\n\t\t</script>\n";
	echo '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Organization","name":"gtag( test"}</script>' . "\n";
	echo "<script>window.lbbTestInline = 1;</script>\n";
} );
// Google Tag Manager, cum îl pun pluginurile: încărcătorul și <noscript> imediat după <body>.
add_action( 'wp_body_open', function () {
	echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-TEST');</script>\n";
	echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TEST" height="0" width="0" style="display:none"></iframe></noscript>' . "\n";
} );
PHP
$WP eval 'LBB_Legal::create_missing();' >/dev/null
# Rutele din orarul inițial care lipsesc (ex. șterse de un test rulat pe cod vechi) se adaugă la loc.
$WP eval 'foreach ( include LBB_DIR . "data/routes-seed.php" as $r ) { LBB_Routes::save( array( "origin" => $r[0], "destination" => $r[1], "departures" => implode( ",", $r[2] ), "price" => $r[3], "currency" => $r[4], "page_url" => $r[5], "active" => 1 ) ); }' >/dev/null
# O rută cu preț pentru copii (testul pentru adulți și copii o caută: Chișinău → Iași).
# (save refuză singur o rută care există deja.)
$WP eval 'LBB_Routes::save( array( "origin" => "Chișinău", "destination" => "Iași", "departures" => "07:00, 15:30", "price" => 250, "child_price" => 150, "currency" => "MDL", "active" => 1 ) );' >/dev/null
# O rută care nu circulă zilnic (doar luni și joi): formularul trebuie să spună zilele când alegi altă zi.
$WP eval 'LBB_Routes::save( array( "origin" => "Chișinău", "destination" => "Suceava", "departures" => "10:00", "days" => array( 1, 4 ), "price" => 300, "currency" => "MDL", "active" => 1 ) );' >/dev/null
# Pagină cu un <footer> în conținut (semnătura unui citat): linkurile legale trebuie să ajungă în subsolul site-ului, nu aici.
$WP post list --post_type=page --name=citat --format=ids | grep -q . || $WP post create --post_type=page --post_status=publish --post_title='Citat' --post_name=citat --post_content='<blockquote><p>Călătorie plăcută!</p><footer>— LibertBus</footer></blockquote>' >/dev/null
cat > router.php <<'PHP'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = __DIR__ . '/wordpress' . $path;
if ( '/' !== $path && file_exists( $file ) && ! is_dir( $file ) ) { return false; }
if ( is_dir( $file ) && file_exists( $file . '/index.php' ) ) { $_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php'; require $file . '/index.php'; return; }
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/wordpress/index.php';
PHP
# axe-core pentru testul de accesibilitate (tests/qa.sh îl găsește în $W/axe); fără internet pasul se sare.
[ -f "$W/axe/package/axe.min.js" ] || { mkdir -p "$W/axe" && (cd "$W/axe" && npm pack axe-core@4 --silent >/dev/null 2>&1 && tar xzf axe-core-*.tgz) || true; }
# Verificatorul de compatibilitate PHP 7.4 (folosit de tests/qa.sh prin PHPCS_DIR=$W/phpcs).
if [ ! -x "$W/phpcs/vendor/bin/phpcs" ]; then
  mkdir -p "$W/phpcs" && cp "$PLUGIN/tests/compat/composer.json" "$W/phpcs/" && (cd "$W/phpcs" && COMPOSER_ALLOW_SUPERUSER=1 composer install --quiet --no-interaction >/dev/null 2>&1) || true
fi
[ -x "$W/phpcs/vendor/bin/phpcs" ] && "$W/phpcs/vendor/bin/phpcs" --config-set installed_paths "$W/phpcs/vendor/phpcompatibility/php-compatibility,$W/phpcs/vendor/phpcsstandards/phpcsutils" >/dev/null 2>&1 || true
# Controalele de securitate WordPress (folosite de tests/qa.sh prin WPCS_DIR=$W/wpcs).
if [ ! -x "$W/wpcs/vendor/bin/phpcs" ]; then
  mkdir -p "$W/wpcs" && cp "$PLUGIN/tests/wpcs/composer.json" "$W/wpcs/" && (cd "$W/wpcs" && COMPOSER_ALLOW_SUPERUSER=1 composer install --quiet --no-interaction >/dev/null 2>&1) || true
fi
[ -x "$W/wpcs/vendor/bin/phpcs" ] && "$W/wpcs/vendor/bin/phpcs" --config-set installed_paths "$W/wpcs/vendor/wp-coding-standards/wpcs,$W/wpcs/vendor/phpcsstandards/phpcsutils,$W/wpcs/vendor/phpcsstandards/phpcsextra" >/dev/null 2>&1 || true
# Serverul de test pornește mereu din nou, cu 4 procese: cu unul singur, o cerere lentă (ex. cron-ul WordPress)
# le blochează pe celelalte și testele din browser expiră din când în când.
pkill -f "[p]hp -S 127\.0\.0\.1:8080" 2>/dev/null || true; sleep 1
(PHP_CLI_SERVER_WORKERS=4 nohup php -S 127.0.0.1:8080 -t wordpress router.php > server.log 2>&1 &)
for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null http://127.0.0.1:8080/ && break; sleep 1; done
curl -s -o /dev/null -w "WordPress de test: %{http_code}\n" http://127.0.0.1:8080/rezervare-bilet/
