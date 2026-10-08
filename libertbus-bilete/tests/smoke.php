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

// Card refuzat la bancă (Paynet: comanda „failed”), clientul încearcă din nou și plătește; apoi rambursare.
$fh  = LBB_Bookings::create_hold( $route, $tomorrow, '10:00', 1, 0, array( 'Refuz Card' ), '+37360000077', 'r@example.com' );
$fo  = wc_create_order();
$fi  = new WC_Order_Item_Product();
$fi->set_product( wc_get_product( LBB_Install::product_id() ) );
$fi->add_meta_data( '_lbb_token', $fh['token'], true );
$fo->add_item( $fi );
$fo->set_total( 120 );
$fo->save();
LBB_WooCommerce::attach_order( $fo );
$free_at   = function () use ( $route, $tomorrow ) {
	foreach ( LBB_Routes::departures_on( $route, $tomorrow ) as $d ) {
		if ( '10:00' === $d['time'] ) {
			return $d['free'];
		}
	}
	return null;
};
$free_held = $free_at();
$fo->update_status( 'failed' );
lbb_t( 'card refuzat: locul se eliberează', 'cancelled' === LBB_Bookings::get_by_token( $fh['token'] )['status'] && $free_held + 1 === $free_at() );
$fo->update_status( 'pending' );
lbb_t( 'a doua încercare de plată: locul se ține din nou', 'pending' === LBB_Bookings::get_by_token( $fh['token'] )['status'] && $free_held === $free_at(), LBB_Bookings::get_by_token( $fh['token'] ) );
$fo->update_status( 'processing' );
$fb = LBB_Bookings::get_by_token( $fh['token'] );
lbb_t( 'plata reușită după refuz emite biletul', 'confirmed' === $fb['status'] && preg_match( '/^LB-/', $fb['ticket_code'] ), $fb );
$fo->update_status( 'refunded' );
lbb_t( 'rambursarea anulează biletul și eliberează locul', 'cancelled' === LBB_Bookings::get_by_token( $fh['token'] )['status'] && $free_held + 1 === $free_at() );
$fo->update_status( 'pending' );
$fr = LBB_Bookings::get_by_token( $fh['token'] );
lbb_t( 'plata reluată după rambursare: biletul (cu cod) apare „neachitat”, nu „anulat”', 'pending' === $fr['status'] && $fr['ticket_code'] && 0 === strpos( LBB_Tickets::state( $fr )['text'], 'Plata nu e finalizată' ), $fr );
$fo->update_status( 'cancelled' ); // eliberează locul ținut din nou, altfel testele de mai jos nu mai au locuri
$fo->delete( true );

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

// Emailul text al comenzii (clienți cu emailuri „doar text”): numele cu apostrof rămâne întreg după
// curățarea făcută de WooCommerce, iar titlul e cu majuscule corecte.
$wpdb->update( LBB_Bookings::table(), array( 'passengers' => wp_json_encode( array( "Ana D'Angelo", 'L', 'M' ) ) ), array( 'id' => $late['id'] ) );
ob_start();
LBB_Tickets::email( $o2, false, true );
$lbb_plain = ob_get_clean();
$lbb_wce   = new WC_Email();
$lbb_plain = preg_replace( $lbb_wce->plain_search, $lbb_wce->plain_replace, wp_strip_all_tags( $lbb_plain ) );
lbb_t( 'emailul text: „Ana D\'Angelo” rămâne întreg, titlul „BILETELE DUMNEAVOASTRĂ”', false !== strpos( $lbb_plain, "Ana D'Angelo" ) && false !== strpos( $lbb_plain, 'BILETELE DUMNEAVOASTRĂ' ), $lbb_plain );

// Pagina biletului: semnătura.
$code = LBB_Bookings::get_by_token( $late['token'] )['ticket_code'];
lbb_t( 'linkul biletului e semnat', false !== strpos( LBB_Tickets::url( $code ), 'k=' . LBB_Tickets::signature( $code ) ) );

// Calendarul din formular are limitele și fără JavaScript (telefon cu JS lent sau blocat).
$lbb_form_html = do_shortcode( '[libertbus_rezervare]' );
$lbb_today     = LBB_Settings::today();
lbb_t( 'câmpul de dată are min = azi și max = ultima zi de vânzare din HTML', (bool) preg_match( '/<input type="date" name="lbb_date"[^>]*min="' . preg_quote( $lbb_today, '/' ) . '"[^>]*max="(\d{4}-\d{2}-\d{2})"/', $lbb_form_html, $lbb_mm ) && $lbb_mm[1] > $lbb_today, isset( $lbb_mm[1] ) ? $lbb_mm[1] : substr( $lbb_form_html, 0, 0 ) );

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
lbb_t( 'CSV: „+cifră” urmat de formulă e neutralizat, telefonul cu spații rămâne', "'+1+cmd|' /C calc'!A0" === LBB_Admin::csv_safe( "+1+cmd|' /C calc'!A0" ) && '+373 (69) 184-111' === LBB_Admin::csv_safe( '+373 (69) 184-111' ) && "'+1+2" === LBB_Admin::csv_safe( '+1+2' ) );

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
// Pagina biletului: o cursă dintr-o zi trecută nu mai apare verde „valabil” (prin HTTP, pe biletul real).
$tk_state = function ( $url ) {
	$r = wp_remote_get( $url, array( 'timeout' => 20 ) );
	return is_wp_error( $r ) ? array( 0, '' ) : array( wp_remote_retrieve_response_code( $r ), wp_remote_retrieve_body( $r ) );
};
parse_str( (string) wp_parse_url( (string) $res1, PHP_URL_QUERY ), $tk_q );
$tk_b = LBB_Bookings::get_by_code( isset( $tk_q['lbb_bilet'] ) ? $tk_q['lbb_bilet'] : '' );
list( $tk_code, $tk_html ) = $tk_state( $res1 );
lbb_t( 'biletul de mâine: rezervare confirmată, verde', 200 === $tk_code && false !== strpos( $tk_html, 'class="state ok">Rezervare confirmată' ), $tk_code );
$tk_yday = ( new DateTimeImmutable( 'yesterday', LBB_Settings::tz() ) )->format( 'Y-m-d' );
$wpdb->update( LBB_Bookings::table(), array( 'travel_date' => $tk_yday ), array( 'id' => $tk_b['id'] ) );
list( $tk_code, $tk_html ) = $tk_state( $res1 );
lbb_t( 'biletul unei curse de ieri: „Cursa a avut loc”, nu „valabil”', 200 === $tk_code && false !== strpos( $tk_html, 'class="state past">Cursa a avut loc pe ' . gmdate( 'd.m.Y', strtotime( $tk_yday ) ) ) && false === strpos( $tk_html, 'state ok' ), $tk_code );
$wpdb->update( LBB_Bookings::table(), array( 'travel_date' => $tk_b['travel_date'] ), array( 'id' => $tk_b['id'] ) );
$tk_today = array( 'status' => 'confirmed', 'travel_date' => LBB_Settings::today() );
lbb_t( 'în ziua cursei biletul rămâne valabil', 'ok' === LBB_Tickets::state( $tk_today )['class'] );
lbb_t( 'biletul cu plata reluată (pending) nu apare „anulat”, ci neachitat', 'bad' === LBB_Tickets::state( array( 'status' => 'pending', 'travel_date' => LBB_Settings::today() ) )['class'] && 0 === strpos( LBB_Tickets::state( array( 'status' => 'pending', 'travel_date' => LBB_Settings::today() ) )['text'], 'Plata nu e finalizată' ) );
lbb_t( 'un bilet anulat dintr-o zi trecută rămâne „anulat”', 'Bilet anulat' === LBB_Tickets::state( array( 'status' => 'cancelled', 'travel_date' => $tk_yday ) )['text'] );
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
lbb_t( 'subiectul emailului clientului are codul rezervării', $em && $to_client && false !== strpos( $to_client[0]['subject'], $em['ticket_code'] ), $to_client ? $to_client[0]['subject'] : 'lipsă' );
lbb_t( 'emailurile de rezervare sunt HTML', $to_client && false !== strpos( implode( ' ', (array) $to_client[0]['headers'] ), 'text/html' ) );
$to_office = array_values( array_filter( $lbb_mails, function ( $m ) {
	return 'client-mail@example.com' !== $m['to'];
} ) );
lbb_t( 'emailul biroului duce direct la rezervare (căutare după cod)', $em && $to_office
	&& false !== strpos( $to_office[0]['message'], 'page=lbb-bookings&#038;q=' . $em['ticket_code'] ), $to_office ? substr( $to_office[0]['message'], -300 ) : 'lipsă' );
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

// Biroul anulează rezervarea: clientul primește un email (să nu vină degeaba la autocar).
$lbb_mails = array();
add_filter( 'pre_wp_mail', $lbb_catch, 1, 2 );
$cx_sent = $em && LBB_Bookings::cancel( $em['id'] ) && LBB_Tickets::send_cancellation_email( LBB_Bookings::get( $em['id'] ) );
lbb_t( 'fără email valid nu se trimite nimic la anulare', false === LBB_Tickets::send_cancellation_email( array_merge( (array) $em, array( 'email' => '' ) ) ) );
remove_filter( 'pre_wp_mail', $lbb_catch, 1 );
$cx = $lbb_mails ? $lbb_mails[0] : array( 'to' => '', 'subject' => '', 'message' => '', 'headers' => array() );
lbb_t( 'anularea din birou trimite un email clientului, cu codul, ruta și telefonul', $cx_sent && 1 === count( $lbb_mails ) && 'client-mail@example.com' === $cx['to']
	&& false !== strpos( $cx['subject'], 'anulată ' . $em['ticket_code'] ) && false !== strpos( $cx['message'], 'a fost anulată' ) && false !== strpos( $cx['message'], 'href="tel:' ), array( $cx['subject'], wp_strip_all_tags( $cx['message'] ) ) );
lbb_t( 'emailul de anulare vine de la firmă, cu răspuns spre birou', false !== strpos( implode( "\n", (array) $cx['headers'] ), 'Reply-To: ' . $office ) && (bool) preg_match( '/^From: ' . preg_quote( $from_name, '/' ) . ' </m', implode( "\n", (array) $cx['headers'] ) ) );

// Emailurile au și variantă text (multipart/alternative), lizibilă: cod, rută, link spre bilet, fără HTML.
$plain = $em ? LBB_Tickets::plain_text( LBB_Tickets::html( $em ) ) : '';
lbb_t( 'varianta text a biletului: cod, „Ruta …”, linkul biletului, fără etichete HTML', $em && false !== strpos( $plain, $em['ticket_code'] )
	&& (bool) preg_match( '/^Ruta .+→/mu', $plain ) && false !== strpos( $plain, '(' . LBB_Tickets::url( $em['ticket_code'] ) . ')' ) && false === strpos( $plain, '<' ), $plain );
// Pe site-ul de test emailurile sunt oprite înainte de PHPMailer (pre_wp_mail): aici le lăsăm să ajungă la el,
// citim varianta text și trimiterea eșuează imediat (SMTP pe un port închis).
$lbb_alt   = null;
$lbb_saved = isset( $GLOBALS['wp_filter']['pre_wp_mail'] ) ? $GLOBALS['wp_filter']['pre_wp_mail'] : null;
remove_all_filters( 'pre_wp_mail' );
$lbb_spy = function ( $mailer ) use ( &$lbb_alt ) {
	$lbb_alt = $mailer->AltBody; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$mailer->isSMTP();
	$mailer->Host    = '127.0.0.1'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$mailer->Port    = 9; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$mailer->Timeout = 2; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
};
add_action( 'phpmailer_init', $lbb_spy, 1000 );
if ( $em ) {
	LBB_Tickets::send_cancellation_email( LBB_Bookings::get( $em['id'] ) );
}
remove_action( 'phpmailer_init', $lbb_spy, 1000 );
if ( $lbb_saved ) {
	$GLOBALS['wp_filter']['pre_wp_mail'] = $lbb_saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}
lbb_t( 'emailul de anulare pleacă și cu varianta text', is_string( $lbb_alt ) && false !== strpos( $lbb_alt, 'a fost anulată' ) && false === strpos( $lbb_alt, '<' ), $lbb_alt );

// Curățenia zilnică: coșurile abandonate dispar, dar o rezervare anulată de birou rămâne (are cod de bilet).
$cl_res  = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Curat Rezervat' ), '+37369000088', 'cl@example.com', 'MDL' );
$cl_res  = is_array( $cl_res ) ? LBB_Bookings::reserve( $cl_res['token'] ) : null;
$cl_hold = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Curat Cos' ), '+37369000089', 'cl2@example.com', 'MDL' );
if ( $cl_res && is_array( $cl_hold ) ) {
	LBB_Bookings::cancel( $cl_res['id'] );
	LBB_Bookings::cancel( $cl_hold['id'] );
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . LBB_Bookings::table() . ' SET updated_at = %s WHERE id IN (%d, %d)', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ), $cl_res['id'], $cl_hold['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	LBB_Bookings::cleanup();
}
$cl_kept = $cl_res ? LBB_Bookings::get( $cl_res['id'] ) : null;
lbb_t( 'curățenia păstrează rezervarea anulată de birou și șterge coșul abandonat', $cl_kept && 'cancelled' === $cl_kept['status'] && is_array( $cl_hold ) && ! LBB_Bookings::get( $cl_hold['id'] ) );

// API-ul public pentru ore și locuri (singurul fără autentificare): validează intrarea, nu arată rute inactive, nu se pune în cache.
$api = function ( $params ) {
	$req = new WP_REST_Request( 'GET', '/lbb/v1/departures' );
	$req->set_query_params( $params );
	return rest_do_request( $req );
};
$api_ok = $api( array( 'route_id' => $rid2, 'date' => $tomorrow ) );
lbb_t( 'API: ruta activă întoarce plecările, fără cache', 200 === $api_ok->get_status() && ! empty( $api_ok->get_data()['departures'] ) && 'no-store' === ( $api_ok->get_headers()['Cache-Control'] ?? '' ), array( $api_ok->get_status(), $api_ok->get_headers() ) );
lbb_t( 'API: data în alt format e refuzată (400)', 400 === $api( array( 'route_id' => $rid2, 'date' => '07.10.2026' ) )->get_status() );
lbb_t( 'API: fără rută e refuzat (400)', 400 === $api( array( 'date' => $tomorrow ) )->get_status() );
lbb_t( 'API: ruta inexistentă dă 404', 404 === $api( array( 'route_id' => 999999, 'date' => $tomorrow ) )->get_status() );
lbb_t( 'API: o dată imposibilă (31 februarie) nu are plecări', 200 === ( $api_imp = $api( array( 'route_id' => $rid2, 'date' => ( (int) gmdate( 'Y' ) + 1 ) . '-02-31' ) ) )->get_status() && array() === array_filter( $api_imp->get_data()['departures'], function ( $d ) { return ! empty( $d['bookable'] ); } ), $api_imp->get_data() );
$wpdb->update( LBB_Routes::table(), array( 'active' => 0 ), array( 'id' => $rid2 ) );
$api_off = $api( array( 'route_id' => $rid2, 'date' => $tomorrow ) )->get_status();
$wpdb->update( LBB_Routes::table(), array( 'active' => 1 ), array( 'id' => $rid2 ) );
lbb_t( 'API: o rută dezactivată nu se mai arată (404)', 404 === $api_off && LBB_Routes::get( $rid2 )['active'], $api_off );

// Pe bilet ziua săptămânii e în română și când administratorul lucrează în engleză.
switch_to_locale( 'en_US' );
$dw_tue = LBB_WooCommerce::date_with_day( '2026-10-06' );
$dw_sun = LBB_WooCommerce::date_with_day( '2026-10-11' );
restore_previous_locale();
lbb_t( 'biletul arată ziua în română, oricare ar fi limba adminului', '06.10.2026 (marți)' === $dw_tue && '11.10.2026 (duminică)' === $dw_sun, array( $dw_tue, $dw_sun ) );
lbb_t( 'o dată invalidă rămâne cum e, fără eroare', 'x' === LBB_WooCommerce::date_with_day( 'x' ) );

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
// Google Tag Manager: încărcătorul din pagină nu conține gtag(, iar <noscript> pune un iframe de urmărire.
$gtm = LBB_Cookies::block_scripts( "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s);j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-TEST');</script>" );
lbb_t( 'cookies: încărcătorul Google Tag Manager e blocat', 0 === strpos( $gtm, '<script type="text/plain" data-lbb-consent="statistics">' ), $gtm );
$ns = LBB_Cookies::block_scripts( '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TEST" height="0" width="0"></iframe></noscript><noscript><img height="1" width="1" src="https://www.facebook.com/tr?id=1&ev=PageView&noscript=1"/></noscript><noscript><p>Activați JavaScript.</p></noscript>' );
lbb_t( 'cookies: urmărirea din <noscript> (GTM, Facebook) se scoate, restul rămâne', '<noscript><p>Activați JavaScript.</p></noscript>' === $ns, $ns );
lbb_t( 'cookies: și codul pus imediat după <body> trece prin blocare', has_action( 'wp_body_open', array( 'LBB_Cookies', 'buffer_start' ) ) && has_action( 'wp_body_open', array( 'LBB_Cookies', 'buffer_end' ) ) );
lbb_t( 'cookies: [lbb_firma_date] nu lasă câmpuri goale', false === strpos( LBB_Legal::company_block(), 'completați' ) && false !== strpos( LBB_Legal::company_block(), 'tel:' ) );
$pp_form = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Privacy Policy', 'post_content' => '[contact-form-7 id="1"]' ) );
lbb_t( 'cookies: o pagină „Privacy Policy” doar cu un formular nu contează ca politică', ! LBB_Legal::is_real_policy( $pp_form ) );
wp_delete_post( $pp_form, true ); // altfel fiecare rulare lasă o pagină publicată în plus

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

// Câmpul-capcană pentru roboți, prin HTTP ca un vizitator: completat → refuzat fără rezervare; gol → rezervarea trece.
$hp_old = LBB_Routes::find( 'HpA', 'HpB' );
if ( $hp_old ) {
	LBB_Routes::delete( $hp_old['id'] );
}
$hp_rid  = LBB_Routes::save( array( 'origin' => 'HpA', 'destination' => 'HpB', 'departures' => '10:00', 'price' => 100, 'currency' => 'MDL', 'capacity' => 5, 'active' => 1 ) );
$hp_page = wp_remote_retrieve_body( wp_remote_get( home_url( '/balti-iasi/' ), array( 'timeout' => 20 ) ) );
preg_match( '/name="lbb_nonce" value="([^"]+)"/', $hp_page, $hp_nonce );
preg_match( '/name="lbb_form" value="([^"]+)"/', $hp_page, $hp_form );
$hp_post = function ( $trap ) use ( $hp_rid, $tomorrow, $hp_nonce, $hp_form ) {
	$body = array(
		'lbb_action' => 'book', 'lbb_mode' => 'reserve', 'lbb_nonce' => isset( $hp_nonce[1] ) ? $hp_nonce[1] : '', 'lbb_form' => isset( $hp_form[1] ) ? $hp_form[1] : '',
		'lbb_website' => $trap, 'lbb_route' => $hp_rid, 'lbb_date' => $tomorrow, 'lbb_time' => '10:00', 'lbb_adults' => 1,
		'lbb_names' => array( 'Ion Capcană' ), 'lbb_phone' => '+3736' . wp_rand( 1000000, 9999999 ), 'lbb_email' => 'hp@example.com', 'lbb_currency' => 'MDL',
	);
	return wp_remote_post( home_url( '/balti-iasi/' ), array( 'timeout' => 20, 'redirection' => 0, 'body' => $body ) );
};
$hp_count = function () use ( $wpdb, $hp_rid ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LBB_Bookings::table() . ' WHERE route_id = %d', $hp_rid ) );
};
$hp_r = $hp_post( 'http://spam.example' );
lbb_t( 'robot (câmpul-capcană completat): refuzat, fără rezervare', ! is_wp_error( $hp_r ) && 200 === wp_remote_retrieve_response_code( $hp_r ) && false !== strpos( wp_remote_retrieve_body( $hp_r ), 'Cererea nu a putut fi procesată' ) && 0 === $hp_count(), is_wp_error( $hp_r ) ? $hp_r : wp_remote_retrieve_response_code( $hp_r ) );
$hp_r = $hp_post( '' );
lbb_t( 'aceeași cerere fără capcană (om): rezervarea trece și duce la bilet', ! is_wp_error( $hp_r ) && 302 === wp_remote_retrieve_response_code( $hp_r ) && false !== strpos( (string) wp_remote_retrieve_header( $hp_r, 'location' ), 'lbb_bilet=' ) && 1 === $hp_count(), is_wp_error( $hp_r ) ? $hp_r : array( wp_remote_retrieve_response_code( $hp_r ), wp_remote_retrieve_header( $hp_r, 'location' ), substr( wp_strip_all_tags( wp_remote_retrieve_body( $hp_r ) ), 0, 300 ) ) );
// Căutarea din „Rezervări” (clientul sună cu codul, telefonul sau numele).
$sr_b   = LBB_Bookings::get( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LBB_Bookings::table() . ' WHERE route_id = %d', $hp_rid ) ) );
$sr_ids = function ( $q, $status = '' ) {
	return wp_list_pluck( LBB_Bookings::recent( 200, $status, $q ), 'id' );
};
$sr_local = '0' . substr( $sr_b['phone'], 4, 2 ) . ' ' . substr( $sr_b['phone'], 6 ); // +3736xxxxxxx → „06x xxxxxx”
lbb_t( 'căutare după codul biletului', array( $sr_b['id'] ) === $sr_ids( strtolower( $sr_b['ticket_code'] ) ), $sr_b['ticket_code'] );
lbb_t( 'căutare după telefon scris local, cu spații', in_array( $sr_b['id'], $sr_ids( $sr_local ), true ), $sr_local );
lbb_t( 'căutare după email', in_array( $sr_b['id'], $sr_ids( 'hp@example.com' ), true ) );
lbb_t( 'căutare fără rezultat', array() === $sr_ids( 'zzqq-nimic' ) );
$sr_h = LBB_Bookings::create_hold( LBB_Routes::get( $hp_rid ), $tomorrow, '10:00', 1, 0, array( 'Țîrdea Ștefan' ), '+37369555444', 'x@example.com' );
lbb_t( 'căutare după nume cu diacritice', ! is_wp_error( $sr_h ) && in_array( (int) $sr_h['id'], $sr_ids( 'Țîrdea', 'hold' ), true ), $sr_h );
lbb_t( 'căutarea păstrează filtrul de stare', ! is_wp_error( $sr_h ) && ! in_array( (int) $sr_h['id'], $sr_ids( 'Țîrdea', 'confirmed' ), true ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LBB_Bookings::table() . ' WHERE route_id = %d', $hp_rid ) );
LBB_Routes::delete( $hp_rid );

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

// Panoul „Gata de plăți?” avertizează dacă bannerul de cookies e oprit (Legea 195/2024).
$cl_keep = LBB_Settings::all();
$cl_item = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'cookies' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
$it = $cl_item();
lbb_t( 'panou: bannerul de cookies pornit apare ca bifat', $it && true === $it['ok'], $it );
update_option( 'lbb_settings', array_merge( $cl_keep, array( 'cookie_banner' => 0 ) ) );
$it = $cl_item();
lbb_t( 'panou: bannerul de cookies oprit apare ca problemă', $it && false === $it['ok'] && false !== strpos( $it['detail'], '195/2024' ), $it );
// Telefonul pentru clienți apare în formular, pe bilet și în emailuri: un număr incomplet e semnalat.
$ph_item = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'phone' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
update_option( 'lbb_settings', array_merge( $cl_keep, array( 'support_phone' => '+373 691 84 111' ) ) );
$it = $ph_item();
lbb_t( 'panou: un telefon complet apare ca bifat', $it && true === $it['ok'], $it );
foreach ( array( '+373', '', '069 18' ) as $ph_bad ) {
	update_option( 'lbb_settings', array_merge( $cl_keep, array( 'support_phone' => $ph_bad ) ) );
	$it = $ph_item();
	lbb_t( 'panou: telefonul „' . $ph_bad . '” apare ca problemă', $it && false === $it['ok'], $it );
}
update_option( 'lbb_settings', $cl_keep );

// Copiii sunt ultimii în lista de pasageri: pe bilet, în lista pentru șofer și în CSV se văd ca „(copil)”.
$kids = array( 'passengers' => array( 'Ion Popescu', 'Maria Popescu', 'Ana Popescu' ), 'children' => 1, 'seats' => 3 );
lbb_t( 'pasageri: copilul e marcat „(copil)”', array( 'Ion Popescu', 'Maria Popescu', 'Ana Popescu (copil)' ) === LBB_Bookings::passenger_labels( $kids ), LBB_Bookings::passenger_labels( $kids ) );
lbb_t( 'pasageri: fără copii numele rămân la fel', array( 'Ion Popescu' ) === LBB_Bookings::passenger_labels( array( 'passengers' => array( 'Ion Popescu' ), 'children' => 0, 'seats' => 1 ) ) );
lbb_t( 'pasageri: fără nume pentru toate locurile nu ghicim cine e copil', array( 'Ion Popescu' ) === LBB_Bookings::passenger_labels( array( 'passengers' => array( 'Ion Popescu' ), 'children' => 1, 'seats' => 2 ) ) );
$kid_desc = LBB_WooCommerce::describe( array_merge( $rv, $kids, array( 'status' => 'confirmed' ) ) );
lbb_t( 'biletul arată care pasager e copil', false !== strpos( $kid_desc['Pasageri'], 'Ana Popescu (copil)' ), $kid_desc );
lbb_t( 'biletul scrie „1 copil”, nu „1 copii”', '3 (din care 1 copil)' === $kid_desc['Locuri'], $kid_desc['Locuri'] );

// Versiunea din antetul plugin-ului (cea din lista de pluginuri) e aceeași cu LBB_VERSION.
$lbb_header = get_file_data( LBB_DIR . 'libertbus-bilete.php', array( 'v' => 'Version' ) );
lbb_t( 'versiunea din antet e aceeași cu LBB_VERSION', LBB_VERSION === $lbb_header['v'], array( LBB_VERSION, $lbb_header['v'] ) );

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
