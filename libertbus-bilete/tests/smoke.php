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
lbb_t( 'prea mulți pasageri respinși', is_wp_error( LBB_Bookings::create_hold( $route, $tomorrow, '23:59', 99, 0, array(), '', '' ) ) );
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
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_phone' => '123' ) ) );
lbb_t( 'telefon greșit respins', is_wp_error( $r ) && 'lbb_phone' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_email' => 'nu-e-email' ) ) );
lbb_t( 'email greșit respins', is_wp_error( $r ) && 'lbb_email' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_time' => '12:34' ) ) );
lbb_t( 'oră greșită respinsă', is_wp_error( $r ) && 'lbb_time' === $r->get_error_code(), $r );
lbb_t( 'orașe fără diacritice se potrivesc', 'Bălți' === LBB_Frontend::match_city( 'balti', array( 'Bălți', 'Iași' ) ) && 'Târgu Mureș' === LBB_Frontend::match_city( 'Targu-Mures', array( 'Târgu Mureș' ) ) );
lbb_t( 'CSV fără formule', "'=SUM(A1)" === LBB_Admin::csv_safe( '=SUM(A1)' ) && '+37369184111' === LBB_Admin::csv_safe( '+37369184111' ) );

// Curățenie.
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LBB_Bookings::table() . ' WHERE route_id = %d', $rid ) );
LBB_Routes::delete( $rid );
$order->delete( true );
$o2->delete( true );

echo "\n" . $GLOBALS['lbb_ok'] . ' ok, ' . $GLOBALS['lbb_fail'] . " eșuate\n";
if ( $GLOBALS['lbb_fail'] ) {
	exit( 1 );
}
