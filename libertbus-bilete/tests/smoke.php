<?php
/**
 * Teste rapide pe o instalare de test: wp eval-file tests/smoke.php
 * NU rulați pe site-ul real: creează și anulează rezervări și comenzi.
 *
 * @package LibertBus_Bilete
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Rulați cu: wp eval-file tests/smoke.php\n" );
}

$GLOBALS['lbb_fail'] = 0;
$GLOBALS['lbb_ok']   = 0;
function lbb_t( $name, $cond, $info = '' ) {
	if ( $cond ) {
		$GLOBALS['lbb_ok']++;
		echo "  ok   $name\n";
	} else {
		$GLOBALS['lbb_fail']++;
		echo "  FAIL $name " . ( $info ? '— ' . ( is_scalar( $info ) ? $info : wp_json_encode( $info ) ) : '' ) . "\n";
	}
}

global $wpdb;
$tz       = wp_timezone();
$tomorrow = ( new DateTimeImmutable( 'tomorrow', $tz ) )->format( 'Y-m-d' );

// Rută de test cu 3 locuri, preț în RON.
$existing = LBB_Routes::find( 'TestA', 'TestB' );
if ( $existing ) {
	LBB_Routes::delete( $existing['id'] );
}
$rid = LBB_Routes::save( array( 'origin' => 'TestA', 'destination' => 'TestB', 'departures' => '10:00, 7:5, 23:59, 10:00', 'price' => 60, 'currency' => 'RON', 'capacity' => 3, 'active' => 1 ) );
lbb_t( 'rută salvată', is_int( $rid ) && $rid > 0, $rid );
$route = LBB_Routes::get( $rid );
lbb_t( 'orele normalizate și unice', array( '10:00', '23:59' ) === $route['times'], $route['times'] );
lbb_t( 'duplicat respins', is_wp_error( LBB_Routes::save( array( 'origin' => 'TestA', 'destination' => 'TestB', 'departures' => '11:00', 'price' => 1 ) ) ) );
lbb_t( 'aceeași plecare și destinație respinsă', is_wp_error( LBB_Routes::save( array( 'origin' => 'X', 'destination' => 'X', 'departures' => '11:00', 'price' => 1 ) ) ) );
lbb_t( 'fără ore respinsă', is_wp_error( LBB_Routes::save( array( 'origin' => 'X', 'destination' => 'Y', 'departures' => 'dimineața', 'price' => 1 ) ) ) );

$rate = LBB_Settings::get( 'rate_RON' );
lbb_t( 'conversie RON→MDL', abs( LBB_Settings::convert( 60, 'RON', 'MDL' ) - round( 60 * $rate, 2 ) ) < 0.01 );
lbb_t( 'conversie MDL→RON', abs( LBB_Settings::convert( 390, 'MDL', 'RON' ) - round( 390 / $rate, 2 ) ) < 0.01 );

$deps = LBB_Routes::departures_on( $route, $tomorrow );
lbb_t( 'mâine 2 plecări cu 3 locuri', 2 === count( $deps ) && 3 === $deps[0]['free'] && $deps[0]['bookable'], $deps );
lbb_t( 'data invalidă = fără plecări', array() === LBB_Routes::departures_on( $route, '2026-02-30' ) );
lbb_t( 'ieri = vânzare închisă', ! LBB_Routes::departures_on( $route, ( new DateTimeImmutable( 'yesterday', $tz ) )->format( 'Y-m-d' ) )[0]['bookable'] );
$far = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+' . ( LBB_Settings::get( 'max_days_ahead' ) + 2 ) . ' days' )->format( 'Y-m-d' );
lbb_t( 'prea departe în viitor = fără plecări', array() === LBB_Routes::departures_on( $route, $far ) );

$h1 = LBB_Bookings::create_hold( $route, $tomorrow, '10:00', 2, 0, array( 'A', 'B' ), '+37360000000', 'a@example.com' );
lbb_t( 'rezervare 2 locuri', is_array( $h1 ) && 2 === $h1['seats'] && 'hold' === $h1['status'], $h1 );
lbb_t( 'suma în moneda rutei', is_array( $h1 ) && 120.0 === $h1['amount'] && 'RON' === $h1['currency'], $h1 );
$h2 = LBB_Bookings::create_hold( $route, $tomorrow, '10:00', 2, 0, array( 'C', 'D' ), '+37360000000', 'a@example.com' );
lbb_t( 'depășirea locurilor respinsă', is_wp_error( $h2 ) && 'lbb_full' === $h2->get_error_code(), $h2 );
$h3 = LBB_Bookings::create_hold( $route, $tomorrow, '10:00', 1, 0, array( 'C' ), '+37360000000', 'a@example.com' );
lbb_t( 'ultimul loc rezervat', is_array( $h3 ) );
$deps = LBB_Routes::departures_on( $route, $tomorrow );
lbb_t( 'cursa apare completă', 0 === $deps[0]['free'] && 'full' === $deps[0]['reason'], $deps[0] );
lbb_t( 'ora inexistentă respinsă', is_wp_error( LBB_Bookings::create_hold( $route, $tomorrow, '11:11', 1, 0, array(), '', '' ) ) );
$r = LBB_Bookings::create_hold( $route, $tomorrow, '23:59', 99, 0, array(), '', '' );
lbb_t( 'prea mulți pasageri respinși, cu telefonul pentru grupuri', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), LBB_Settings::phone_text() ), $r );
$r = LBB_Bookings::create_hold( $route, ( new DateTimeImmutable( 'yesterday', $tz ) )->format( 'Y-m-d' ), '23:59', 1, 0, array( 'Ion' ), '', '' );
lbb_t( 'plecarea închisă e respinsă, cu telefonul', is_wp_error( $r ) && 'lbb_departure' === $r->get_error_code() && false !== strpos( $r->get_error_message(), LBB_Settings::phone_text() ), $r );
lbb_t( 'zero pasageri respinși', is_wp_error( LBB_Bookings::create_hold( $route, $tomorrow, '23:59', 0, 0, array(), '', '' ) ) );

// Expirare: locurile din coș se eliberează și se pot relua.
$wpdb->update( LBB_Bookings::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $h3['id'] ) );
$deps = LBB_Routes::departures_on( $route, $tomorrow );
lbb_t( 'rezervarea expirată eliberează locul', 1 === $deps[0]['free'], $deps[0] );
lbb_t( 'rezervarea expirată se reia dacă e loc', LBB_Bookings::refresh_hold( $h3['token'] ) );
$h4 = LBB_Bookings::create_hold( $route, $tomorrow, '23:59', 3, 0, array( 'E', 'F', 'G' ), '+37360000000', 'a@example.com' );
$wpdb->update( LBB_Bookings::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $h4['id'] ) );
$h5 = LBB_Bookings::create_hold( $route, $tomorrow, '23:59', 3, 0, array( 'H', 'I', 'J' ), '+37360000000', 'a@example.com' );
lbb_t( 'locurile expirate se vând altcuiva', is_array( $h5 ) );
lbb_t( 'rezervarea expirată nu se reia peste capacitate', ! LBB_Bookings::refresh_hold( $h4['token'] ) );

// Zile ale săptămânii.
$dow = (int) ( new DateTimeImmutable( $tomorrow, $tz ) )->format( 'N' );
LBB_Routes::save( array( 'origin' => 'TestA', 'destination' => 'TestB', 'departures' => '10:00, 23:59', 'price' => 60, 'currency' => 'RON', 'capacity' => 3, 'active' => 1, 'days' => array( 1 === $dow ? 2 : 1 ) ), $rid );
lbb_t( 'ruta nu circulă în ziua aceea', array() === LBB_Routes::departures_on( LBB_Routes::get( $rid ), $tomorrow ) );
LBB_Routes::save( array( 'origin' => 'TestA', 'destination' => 'TestB', 'departures' => '10:00, 23:59', 'price' => 60, 'currency' => 'RON', 'capacity' => 3, 'active' => 1 ), $rid );

// Comandă: plată, anulare, plată întârziată peste capacitate.
LBB_Bookings::release( $h1['token'] );
$route = LBB_Routes::get( $rid );
lbb_t( 'eliberarea manuală dă locurile înapoi', 2 === LBB_Routes::departures_on( $route, $tomorrow )[0]['free'] );

$order = wc_create_order();
$item  = new WC_Order_Item_Product();
$item->set_product( wc_get_product( LBB_Install::product_id() ) );
$item->add_meta_data( '_lbb_token', $h3['token'], true );
$order->add_item( $item );
$order->set_total( 234 );
$order->save();
LBB_WooCommerce::attach_order( $order );
lbb_t( 'comanda creată: rezervarea așteaptă plata', 'pending' === LBB_Bookings::get_by_token( $h3['token'] )['status'] );
$order->update_status( 'processing' );
$b = LBB_Bookings::get_by_token( $h3['token'] );
lbb_t( 'plata confirmă biletul și dă cod', 'confirmed' === $b['status'] && preg_match( '/^LB-[A-Z2-9]{6}$/', $b['ticket_code'] ), $b );
$order->update_status( 'cancelled' );
lbb_t( 'anularea comenzii eliberează locurile', 'cancelled' === LBB_Bookings::get_by_token( $h3['token'] )['status'] );

// Plată întârziată: rezervarea expiră, altcineva ia locurile, apoi vine plata.
$late = LBB_Bookings::create_hold( $route, $tomorrow, '10:00', 3, 0, array( 'K', 'L', 'M' ), '+37360000000', 'a@example.com' );
$o2   = wc_create_order();
$i2   = new WC_Order_Item_Product();
$i2->set_product( wc_get_product( LBB_Install::product_id() ) );
$i2->add_meta_data( '_lbb_token', $late['token'], true );
$o2->add_item( $i2 );
$o2->save();
LBB_WooCommerce::attach_order( $o2 );
$wpdb->update( LBB_Bookings::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $late['id'] ) );
$other = LBB_Bookings::create_hold( $route, $tomorrow, '10:00', 1, 0, array( 'N' ), '+37360000000', 'a@example.com' );
lbb_t( 'locul expirat e luat de alt client', is_array( $other ) );
$o2->payment_complete( 'TEST' );
$o2 = wc_get_order( $o2->get_id() );
$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $o2->get_id() ) ), 'content' );
lbb_t( 'plata întârziată: biletul se emite', 'confirmed' === LBB_Bookings::get_by_token( $late['token'] )['status'] );
lbb_t( 'plata întârziată: notă de avertizare', (bool) preg_grep( '/ATENȚIE/u', $notes ), $notes );
lbb_t( 'comanda doar cu bilete se finalizează automat', 'completed' === $o2->get_status(), $o2->get_status() );

// Pagina biletului: semnătura.
$code = LBB_Bookings::get_by_token( $late['token'] )['ticket_code'];
lbb_t( 'linkul biletului e semnat', false !== strpos( LBB_Tickets::url( $code ), 'k=' . LBB_Tickets::signature( $code ) ) );

// Validarea formularului.
$base = array( 'lbb_route' => $rid, 'lbb_date' => $tomorrow, 'lbb_time' => '23:59', 'lbb_adults' => 1, 'lbb_names' => array( 'Ion' ), 'lbb_phone' => '+37369184111', 'lbb_email' => 'ion@example.com' );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_names' => array() ) ) );
lbb_t( 'fără nume respins', is_wp_error( $r ) && 'lbb_names' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_names' => array( ' - ' ) ) ) );
lbb_t( 'nume fără litere („-”) respins', is_wp_error( $r ) && 'lbb_names' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_adults' => 2, 'lbb_names' => array( 'Ion Popescu', '1' ) ) ) );
lbb_t( 'al doilea pasager cu nume „1” respins', is_wp_error( $r ) && 'lbb_names' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_names' => array( 'Țîrdea Ștefan' ) ) ) );
lbb_t( 'nume cu diacritice acceptat (ajunge la verificarea locurilor)', ! is_wp_error( $r ) || 'lbb_names' !== $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_names' => array( 'Ли' ) ) ) );
lbb_t( 'nume scurt în chirilică acceptat', ! is_wp_error( $r ) || 'lbb_names' !== $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_phone' => '123' ) ) );
foreach ( array(
	'069 184 111'      => '+37369184111',
	'0231 12 345'      => '+37323112345',
	'00373 69 184 111' => '+37369184111',
	'373 69184111'     => '+37369184111',
	'+373 69-184-111'  => '+37369184111',
	'0740 123 456'     => '+40740123456',
	'+40 740 123 456'  => '+40740123456',
	'+49 151 2345678'  => '+491512345678',
	'69+184'           => '69184',
) as $in => $want ) {
	lbb_t( 'telefon normalizat: ' . $in, $want === LBB_Frontend::normalize_phone( $in ), LBB_Frontend::normalize_phone( $in ) );
}
lbb_t( 'telefon greșit respins', is_wp_error( $r ) && 'lbb_phone' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_phone' => '+373 69 184 111 69 184 111' ) ) );
lbb_t( 'telefon prea lung (tastat de două ori) respins cu mesaj clar', is_wp_error( $r ) && 'lbb_phone' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_email' => str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.com' ) ) );
lbb_t( 'email prea lung pentru baza de date respins cu mesaj clar', is_wp_error( $r ) && 'lbb_email' === $r->get_error_code(), $r );
lbb_t( 'câmpul de email are limită de lungime', false !== strpos( LBB_Frontend::shortcode( array() ), 'name="lbb_email" required autocomplete="email" maxlength="190"' ) );
lbb_t( 'câmpul de telefon are limită de lungime', false !== strpos( LBB_Frontend::shortcode( array() ), 'name="lbb_phone" required autocomplete="tel" inputmode="tel" maxlength="30"' ) );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_email' => 'nu-e-email' ) ) );
lbb_t( 'email greșit respins', is_wp_error( $r ) && 'lbb_email' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_time' => '12:34' ) ) );
lbb_t( 'oră greșită respinsă', is_wp_error( $r ) && 'lbb_time' === $r->get_error_code(), $r );
lbb_t( 'orașe fără diacritice se potrivesc', 'Bălți' === LBB_Frontend::match_city( 'balti', array( 'Bălți', 'Iași' ) ) && 'Târgu Mureș' === LBB_Frontend::match_city( 'Targu-Mures', array( 'Târgu Mureș' ) ) );
lbb_t( 'CSV fără formule', "'=SUM(A1)" === LBB_Admin::csv_safe( '=SUM(A1)' ) && '+37369184111' === LBB_Admin::csv_safe( '+37369184111' ) );

// Rezervare fără plată (achitare la urcare), pe o rută proaspătă.
$old2 = LBB_Routes::find( 'TestA', 'TestC' );
if ( $old2 ) {
	LBB_Routes::delete( $old2['id'] );
}
$rid2  = LBB_Routes::save( array( 'origin' => 'TestA', 'destination' => 'TestC', 'departures' => '10:00, 23:59', 'price' => 60, 'currency' => 'RON', 'capacity' => 3, 'active' => 1 ) );
$route = LBB_Routes::get( $rid2 );
$rid   = $rid2;
$base['lbb_route'] = $rid2;
$rv = LBB_Bookings::create_hold( $route, $tomorrow, '23:59', 2, 0, array( 'R1', 'R2' ), '+37369000001', 'r@example.com', 'RON' );
$rv = is_array( $rv ) ? LBB_Bookings::reserve( $rv['token'] ) : $rv;
lbb_t( 'rezervarea primește cod și stare „reserved”', 'reserved' === $rv['status'] && preg_match( '/^LB-/', $rv['ticket_code'] ), $rv );
$d  = LBB_Routes::departures_on( LBB_Routes::get( $rid ), $tomorrow );
lbb_t( 'rezervarea ocupă locurile fără expirare', 1 === $d[1]['free'], $d[1] );
lbb_t( 'rezervarea nu se poate „reînnoi” ca un coș', ! LBB_Bookings::refresh_hold( $rv['token'] ) );
list( $amt, $cur ) = LBB_Bookings::pay_amount( $rv );
lbb_t( 'suma de achitat e în moneda aleasă', 'RON' === $cur && abs( $amt - 120 ) < 0.01, array( $amt, $cur ) );
lbb_t( 'rezervările active se numără pe telefon', 1 === LBB_Bookings::active_reservations( '+37369000001' ) );
$desc = LBB_WooCommerce::describe( $rv );
lbb_t( 'biletul rezervat arată suma la urcare', isset( $desc['De achitat la urcare'] ) && "120\u{00A0}RON" === $desc['De achitat la urcare'], $desc );
lbb_t( 'anularea rezervării eliberează locurile', LBB_Bookings::cancel( $rv['id'] ) && 3 === LBB_Routes::departures_on( LBB_Routes::get( $rid ), $tomorrow )[1]['free'] );
lbb_t( 'o rezervare anulată nu se mai anulează', ! LBB_Bookings::cancel( $rv['id'] ) );

// Limita de rezervări neachitate pe telefon (prin formular).
$saved = get_option( 'lbb_settings', array() );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'reserve_limit' => 1, 'allow_reserve' => 1 ) ) );
$res1 = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '+37369000002', 'lbb_currency' => 'MDL' ) ) );
lbb_t( 'rezervarea din formular întoarce linkul biletului', is_string( $res1 ) && false !== strpos( $res1, 'lbb_bilet=' ), $res1 );
$res2 = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '+37369000002' ) ) );
lbb_t( 'a doua rezervare neachitată pe același telefon e refuzată', is_wp_error( $res2 ) && 'lbb_limit' === $res2->get_error_code(), $res2 );
$res2b = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '069 000 002' ) ) );
lbb_t( 'același număr scris local (069…) e prins de limită', is_wp_error( $res2b ) && 'lbb_limit' === $res2b->get_error_code(), $res2b );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_reserve' => 0 ) ) );
$res3 = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '+37369000003' ) ) );
lbb_t( 'rezervarea oprită din setări e refuzată', is_wp_error( $res3 ) && 'lbb_mode' === $res3->get_error_code(), $res3 );
update_option( 'lbb_settings', $saved );

// Monede de plată.
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'pay_currencies' => array( '', 'RON', 'XXX' ) ) ) );
lbb_t( 'setarea monedelor filtrează valorile invalide', array( 'RON' ) === LBB_Settings::pay_currencies(), LBB_Settings::pay_currencies() );
lbb_t( 'moneda implicită cade pe una acceptată', 'RON' === LBB_Settings::default_pay_currency( 'MDL' ) );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'pay_currencies' => 'MDL,RON' ) ) );
lbb_t( 'cu MDL și RON, moneda rutei e propusă implicit', 'RON' === LBB_Settings::default_pay_currency( 'RON' ) && 'MDL' === LBB_Settings::default_pay_currency( 'MDL' ) );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'pay_currencies' => 'MDL', 'show_approx' => 1 ) ) );
lbb_t( 'doar MDL: și rutele în RON se plătesc în MDL', 'MDL' === LBB_Settings::default_pay_currency( 'RON' ) );
lbb_t( 'echivalentul afișat e în RON', array( 'RON' ) === LBB_Settings::approx_currencies( 'MDL', 'RON' ) && array( 'MDL' ) === LBB_Settings::approx_currencies( 'RON', 'MDL' ) );
update_option( 'lbb_settings', $saved );
lbb_t( 'harta formularului are prețuri în fiecare monedă', (bool) array_filter( LBB_Routes::public_map(), function ( $list ) {
	return isset( $list[0]['prices']['MDL'], $list[0]['prices']['RON'] );
} ) );

// Potrivirea formularelor Contact Form 7 cu rutele, după titlu.
$t = LBB_Frontend::route_for_title( 'Balti - Iasi' );
lbb_t( 'titlul „Balti - Iasi” → Bălți → Iași (nu Iași Aeroport)', $t && 'Iași' === $t['destination'], $t );
$t = LBB_Frontend::route_for_title( 'Cluj - Falesti' );
lbb_t( 'titlul scurt „Cluj” → Cluj-Napoca', $t && 'Cluj-Napoca' === $t['origin'], $t );
lbb_t( 'titlurile fără rută nu se potrivesc', null === LBB_Frontend::route_for_title( 'trimite colet' ) && null === LBB_Frontend::route_for_title( 'din Balti ->' ) && null === LBB_Frontend::route_for_title( 'mobile bun - aici modificarile - da aici' ) );

// Butonul de plată online pornește doar din setări; configurația formularului rezistă la stripslashes().
$keep = get_option( 'lbb_settings', array() );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_pay' => 0, 'allow_reserve' => 1 ) ) );
$html = LBB_Frontend::shortcode( array() );
lbb_t( 'fără „allow_pay” nu apare butonul de plată', false === strpos( $html, 'value="pay"' ) && false !== strpos( $html, 'value="reserve"' ) );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'pay', 'lbb_phone' => '+37369000009' ) ) );
lbb_t( 'fără „allow_pay” plata e refuzată și pe server', is_wp_error( $r ) && 'lbb_mode' === $r->get_error_code(), $r );
preg_match( '/data-lbb-config="([^"]+)"/', stripslashes( $html ), $cm );
$cfg = $cm ? json_decode( base64_decode( html_entity_decode( $cm[1], ENT_QUOTES | ENT_HTML401, 'UTF-8' ) ), true ) : null;
lbb_t( 'configurația cu diacritice supraviețuiește stripslashes()', is_array( $cfg ) && 'Alegeți orașul de plecare' === $cfg['i18n']['chooseFrom'], $cm ? substr( $cm[1], 0, 40 ) : 'lipsă' );
lbb_t( 'mesajul „nu mai sunt locuri online” are telefonul', is_array( $cfg ) && false !== strpos( $cfg['i18n']['noneOpen'], LBB_Settings::phone_text() ), is_array( $cfg ) ? $cfg['i18n']['noneOpen'] : '' );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_pay' => 0, 'allow_reserve' => 0 ) ) );
lbb_t( 'fără niciun buton, formularul arată doar telefonul', false === strpos( LBB_Frontend::shortcode( array() ), '<form' ) );
update_option( 'lbb_settings', $keep );

// Linkul secret de previzualizare: stabil până se cere unul nou.
$tok = LBB_Settings::preview_token();
lbb_t( 'cheia de previzualizare e stabilă și lungă', strlen( $tok ) >= 16 && LBB_Settings::preview_token() === $tok );
$tok2 = LBB_Settings::preview_token( true );
lbb_t( 'link nou de previzualizare anulează cheia veche', $tok2 !== $tok && LBB_Settings::preview_token() === $tok2 );
lbb_t( 'linkul de previzualizare conține cheia', false !== strpos( LBB_Settings::preview_url(), 'lbb_preview=' . $tok2 ) );

// Emailurile de rezervare: unul clientului, unul biroului, cu codul, suma de la urcare și telefonul.
$lbb_mails = array();
$lbb_catch = function ( $null, $atts ) use ( &$lbb_mails ) {
	$lbb_mails[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $lbb_catch, 1, 2 );
$em = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Email Test' ), '+37369000077', 'client-mail@example.com', 'MDL' );
$em = is_array( $em ) ? LBB_Bookings::reserve( $em['token'] ) : null;
if ( $em ) {
	LBB_Tickets::send_reservation_emails( $em );
}
remove_filter( 'pre_wp_mail', $lbb_catch, 1 );
$to_client = array_values( array_filter( $lbb_mails, function ( $m ) {
	return 'client-mail@example.com' === $m['to'];
} ) );
lbb_t( 'rezervarea trimite 2 emailuri (client + birou)', 2 === count( $lbb_mails ), count( $lbb_mails ) );
lbb_t( 'emailul clientului are codul, suma la urcare și telefonul', $em && $to_client
	&& false !== strpos( $to_client[0]['message'], $em['ticket_code'] )
	&& false !== strpos( $to_client[0]['message'], 'De achitat la urcare' )
	&& false !== strpos( $to_client[0]['message'], 'href="tel:' ), $to_client ? substr( wp_strip_all_tags( $to_client[0]['message'] ), 0, 200 ) : 'lipsă' );
lbb_t( 'emailurile de rezervare sunt HTML', $to_client && false !== strpos( implode( ' ', (array) $to_client[0]['headers'] ), 'text/html' ) );
$to_office = array_values( array_filter( $lbb_mails, function ( $m ) {
	return 'client-mail@example.com' !== $m['to'];
} ) );
$h_client = $to_client ? implode( "\n", (array) $to_client[0]['headers'] ) : '';
$h_office = $to_office ? implode( "\n", (array) $to_office[0]['headers'] ) : '';
$office   = is_email( LBB_Settings::get( 'company_email' ) ) ? LBB_Settings::get( 'company_email' ) : get_option( 'admin_email' );
$from_name = LBB_Settings::get( 'company_name' ) ? LBB_Settings::get( 'company_name' ) : 'LibertBus';
lbb_t( 'emailul clientului vine de la firmă (nu „WordPress”)', (bool) preg_match( '/^From: ' . preg_quote( $from_name, '/' ) . ' <[^>]+@[^>]+>$/m', $h_client ), $h_client );
$keep_name = LBB_Settings::all();
update_option( 'lbb_settings', array_merge( $keep_name, array( 'company_name' => '' ) ) );
lbb_t( 'fără denumirea firmei, expeditorul e „LibertBus”', 0 === strpos( LBB_Tickets::from_header(), 'LibertBus <' ), LBB_Tickets::from_header() );
update_option( 'lbb_settings', array_merge( $keep_name, array( 'company_name' => "Rău\r\nBcc: x@y.z" ) ) );
lbb_t( 'denumirea firmei nu poate injecta antete în email', false === strpos( LBB_Tickets::from_header(), "\n" ), LBB_Tickets::from_header() );
update_option( 'lbb_settings', $keep_name );
lbb_t( 'răspunsul clientului ajunge la birou (Reply-To)', false !== strpos( $h_client, 'Reply-To: ' . $office ), $h_client );
lbb_t( 'biroul răspunde direct clientului (Reply-To)', false !== strpos( $h_office, 'Reply-To: client-mail@example.com' ), $h_office );

// Accesibilitate: prețul se anunță cititoarelor de ecran când se schimbă.
lbb_t( 'rezumatul cu prețul e anunțat (aria-live)', (bool) preg_match( '/data-lbb="summary"[^>]*aria-live="polite"/', LBB_Frontend::shortcode( array() ) ) );

// Pe bilet (pagină și email) apare telefonul de suport ca link de apel, pe un rând.
$notes = LBB_Tickets::notes_html();
lbb_t( 'biletul arată telefonul de suport ca link de apel', false !== strpos( $notes, 'href="tel:' . preg_replace( '/[^\d+]/', '', LBB_Settings::get( 'support_phone' ) ) . '"' ), $notes );
lbb_t( 'telefonul de pe bilet nu se rupe pe rânduri', false === strpos( strip_tags( $notes ), '691 84' ) );

// Curățenie.
foreach ( array( 'TestB', 'TestC' ) as $lbb_dest ) {
	$lbb_r = LBB_Routes::find( 'TestA', $lbb_dest );
	if ( $lbb_r ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LBB_Bookings::table() . ' WHERE route_id = %d', $lbb_r['id'] ) );
		LBB_Routes::delete( $lbb_r['id'] );
	}
}
$order->delete( true );
$o2->delete( true );

// Cookies: scripturile de statistică se blochează până la acord, restul rămân neatinse.
$ga = "<script async src=\"https://www.googletagmanager.com/gtag/js?id=G-X\"></script>\n<script>\nwindow.dataLayer = window.dataLayer || [];\nfunction gtag(){dataLayer.push(arguments);}\ngtag('config', 'G-X');\n</script>";
$out = LBB_Cookies::block_scripts( $ga );
lbb_t( 'cookies: scriptul Google Analytics extern e blocat', false !== strpos( $out, '<script type="text/plain" data-lbb-consent="statistics" async src="https://www.googletagmanager.com' ), $out );
lbb_t( 'cookies: codul gtag din pagină e blocat', 2 === substr_count( $out, 'type="text/plain"' ), $out );
$keep = '<script src="/wp-includes/js/jquery/jquery.min.js"></script><script type="application/ld+json">{"name":"gtag( x"}</script><script>var a = 1;</script>';
lbb_t( 'cookies: jQuery, JSON-LD și scripturile obișnuite rămân neatinse', LBB_Cookies::block_scripts( $keep ) === $keep );
$typed = LBB_Cookies::block_scripts( "<script type='text/javascript' src='https://connect.facebook.net/en_US/fbevents.js'></script>" );
lbb_t( 'cookies: tipul vechi se înlocuiește (Facebook Pixel, marketing)', "<script type=\"text/plain\" data-lbb-consent=\"marketing\" src='https://connect.facebook.net/en_US/fbevents.js'></script>" === $typed, $typed );
$sb = LBB_Cookies::block_scripts( '<script src="/wp-content/plugins/woocommerce/assets/js/sourcebuster/sourcebuster.min.js"></script>' );
lbb_t( 'cookies: sursa vizitei din WooCommerce e marketing', false !== strpos( $sb, 'data-lbb-consent="marketing"' ), $sb );
lbb_t( 'cookies: un script deja blocat nu se dublează', LBB_Cookies::block_scripts( $out ) === $out );
lbb_t( 'cookies: [lbb_firma_date] nu lasă câmpuri goale', false === strpos( LBB_Legal::company_block(), 'completați' ) && false !== strpos( LBB_Legal::company_block(), 'tel:' ) );
lbb_t( 'cookies: o pagină „Privacy Policy” doar cu un formular nu contează ca politică', ! LBB_Legal::is_real_policy( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Privacy Policy', 'post_content' => '[contact-form-7 id="1"]' ) ) ) );

// Setările „Banner cookies” și „Linkuri în subsol” chiar opresc ce promit (pagina reală, prin HTTP).
$cc_keep = get_option( 'lbb_settings', array() );
$cc_page = function () {
	$r = wp_remote_get( home_url( '/balti-iasi/' ), array( 'timeout' => 20 ) );
	return is_wp_error( $r ) ? '' : wp_remote_retrieve_body( $r );
};
$on = $cc_page();
lbb_t( 'cookies pornite: banner, linkuri în subsol și Google Analytics blocat', false !== strpos( $on, 'id="lbb-cc"' ) && false !== strpos( $on, 'id="lbb-legal-links"' ) && false !== strpos( $on, 'data-lbb-consent="statistics" async src="https://www.googletagmanager.com' ), strlen( $on ) );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'cookie_banner' => 0 ) ) );
$off = $cc_page();
lbb_t( 'banner oprit: fără banner, fără „Setări cookies”, Google Analytics nemodificat', false === strpos( $off, 'id="lbb-cc"' ) && false === strpos( $off, '#lbb-cookies' ) && false === strpos( $off, 'data-lbb-consent' ) && false !== strpos( $off, '<script async src="https://www.googletagmanager.com' ), strlen( $off ) );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'cookie_banner' => 1, 'footer_links' => 0 ) ) );
$nolinks = $cc_page();
lbb_t( 'linkuri în subsol oprite: lipsesc, bannerul rămâne', false === strpos( $nolinks, 'id="lbb-legal-links"' ) && false !== strpos( $nolinks, 'id="lbb-cc"' ), strlen( $nolinks ) );
update_option( 'lbb_settings', $cc_keep );

// Nota de confidențialitate din formularul de rezervare (Legea 195/2024): doar cu politica publicată.
$priv_id = LBB_Legal::page_id( 'privacy' );
$form    = LBB_Frontend::shortcode( array() );
lbb_t( 'formularul are nota cu link spre Politica de confidențialitate', $priv_id && false !== strpos( $form, 'lbb-privacy-note' ) && false !== strpos( $form, esc_url( get_permalink( $priv_id ) ) ), $priv_id );
wp_update_post( array( 'ID' => $priv_id, 'post_status' => 'draft' ) );
lbb_t( 'fără politică publicată, nota nu apare (fără link mort)', false === strpos( LBB_Frontend::shortcode( array() ), 'lbb-privacy-note' ) );
wp_update_post( array( 'ID' => $priv_id, 'post_status' => 'publish' ) );

// Fusul orar: cu „UTC+2” fix în WordPress (ca pe libertbus.md), orele merg după Chișinău, cu ora de vară.
$tz_keep  = array( get_option( 'timezone_string' ), get_option( 'gmt_offset' ) );
update_option( 'timezone_string', '' );
update_option( 'gmt_offset', 2 );
lbb_t( 'fus orar: „UTC+2” fix devine Europe/Chisinau', 'Europe/Chisinau' === LBB_Settings::tz()->getName(), LBB_Settings::tz()->getName() );
$chis = new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Chisinau' ) );
if ( '+03:00' === $chis->format( 'P' ) ) {
	// Vara: plecare peste 30 de minute (ora Chișinăului) = vânzare închisă (limita e 60 de minute).
	$soon = $chis->modify( '+30 minutes' );
	$tzr  = LBB_Routes::save( array( 'origin' => 'TzA', 'destination' => 'TzB', 'departures' => $soon->format( 'H:i' ), 'price' => 10, 'currency' => 'MDL', 'capacity' => 5, 'active' => 1 ) );
	$deps = LBB_Routes::departures_on( LBB_Routes::get( $tzr ), $soon->format( 'Y-m-d' ) );
	lbb_t( 'fus orar: vara, plecarea de peste 30 de minute e închisă', isset( $deps[0] ) && ! $deps[0]['bookable'], $deps );
	LBB_Routes::delete( $tzr );
}
update_option( 'timezone_string', 'Europe/Bucharest' );
lbb_t( 'fus orar: un oraș ales în WordPress are întâietate', 'Europe/Bucharest' === LBB_Settings::tz()->getName() );
update_option( 'timezone_string', '' );
update_option( 'gmt_offset', 5 );
lbb_t( 'fus orar: alt decalaj rămâne cum e', '+05:00' === ( new DateTimeImmutable( 'now', LBB_Settings::tz() ) )->format( 'P' ) );
update_option( 'timezone_string', $tz_keep[0] );
update_option( 'gmt_offset', $tz_keep[1] );

// Pagina Setări: fiecare setare are câmp, iar o salvare fără modificări nu schimbă nimic
// (o bifă fără câmp s-ar debifa pe tăcute la fiecare salvare).
wp_set_current_user( 1 );
ob_start();
LBB_Admin::page_settings();
$form_html = ob_get_clean();
$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="utf-8"?>' . $form_html );
libxml_clear_errors();
$post = array();
$seen = array();
foreach ( $dom->getElementsByTagName( 'form' ) as $f ) {
	$is_settings = false;
	foreach ( $f->getElementsByTagName( 'input' ) as $in ) {
		if ( 'action' === $in->getAttribute( 'name' ) && 'lbb_save_settings' === $in->getAttribute( 'value' ) ) {
			$is_settings = true;
		}
	}
	if ( ! $is_settings ) {
		continue;
	}
	foreach ( array( 'input', 'textarea', 'select' ) as $tag ) {
		foreach ( $f->getElementsByTagName( $tag ) as $el ) {
			$name = $el->getAttribute( 'name' );
			if ( '' === $name ) {
				continue;
			}
			$key          = preg_replace( '/\[\]$/', '', $name );
			$seen[ $key ] = true;
			$type         = strtolower( $el->getAttribute( 'type' ) );
			if ( ( 'checkbox' === $type || 'radio' === $type ) && ! $el->hasAttribute( 'checked' ) ) {
				continue;
			}
			$val = 'textarea' === $tag ? $el->textContent : $el->getAttribute( 'value' );
			if ( '[]' === substr( $name, -2 ) ) {
				$post[ $key ][] = $val;
			} else {
				$post[ $key ] = $val;
			}
		}
	}
}
$missing = array_diff( array_keys( LBB_Settings::defaults() ), array_keys( $seen ), array( 'rate_MDL' ) );
lbb_t( 'Setări: fiecare setare are câmp în formular', ! $missing, implode( ',', $missing ) );
$before = LBB_Settings::all();
LBB_Settings::save( $post );
$after   = LBB_Settings::all();
$changed = array();
foreach ( array_keys( LBB_Settings::defaults() ) as $k ) {
	$v = $before[ $k ];
	if ( (string) ( is_array( $v ) ? implode( ',', $v ) : $v ) !== (string) ( is_array( $after[ $k ] ) ? implode( ',', $after[ $k ] ) : $after[ $k ] ) ) {
		$changed[] = $k;
	}
}
lbb_t( 'Setări: salvarea fără modificări nu schimbă nicio setare', ! $changed, implode( ',', $changed ) );
update_option( 'lbb_settings', $before );

// Versiunea JS/CSS se schimbă odată cu fișierul, ca o actualizare să nu rămână cu JS vechi în cache.
LBB_Frontend::register_assets();
$js_ver = wp_scripts()->registered['lbb']->ver;
$css_ver = wp_styles()->registered['lbb']->ver;
lbb_t( 'versiunea lbb.js conține data fișierului', LBB_VERSION . '.' . filemtime( LBB_DIR . 'assets/lbb.js' ) === $js_ver, $js_ver );
lbb_t( 'versiunea lbb.css conține data fișierului', LBB_VERSION . '.' . filemtime( LBB_DIR . 'assets/lbb.css' ) === $css_ver, $css_ver );

echo "\n" . $GLOBALS['lbb_ok'] . ' ok, ' . $GLOBALS['lbb_fail'] . " eșuate\n";
if ( $GLOBALS['lbb_fail'] ) {
	exit( 1 );
}
