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

// O rulare oprită la jumătate (ex. o eroare fatală) nu-și face curățenia: rutele de test rămase cu rezervările lor
// și rezervările fără rută ar face rularea următoare să pice (locuri ocupate, limita de rezervări pe telefon).
foreach ( array( array( 'TestA', 'TestB' ), array( 'TestA', 'TestC' ), array( 'TestA', 'Orașul Nou' ), array( 'StergeA', 'StergeB' ), array( 'LungA', 'LungB' ), array( 'ReamintA', 'ReamintB' ), array( 'LimitaA', 'LimitaB' ), array( 'PlinA', 'PlinB' ), array( 'UrcareA', 'UrcareB' ), array( 'PretA', 'PretB' ), array( 'AziA', 'AziB' ), array( 'InchisA', 'InchisB' ), array( 'OrarA', 'OrarB' ), array( 'ZileA', 'ZileB' ), array( 'CurseA', 'CurseB' ), array( 'Fălești, centru', 'Târgu Mureș' ), array( 'HpA', 'HpB' ), array( 'TzA', 'TzB' ), array( 'StearsaA', 'StearsaB' ), array( 'CosA', 'CosB' ), array( 'PlataA', 'PlataB' ), array( 'ParalelA', 'ParalelB' ) ) as $lbb_pair ) {
	$lbb_old = LBB_Routes::find( $lbb_pair[0], $lbb_pair[1] );
	if ( $lbb_old ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LBB_Bookings::table() . ' WHERE route_id = %d', $lbb_old['id'] ) );
		LBB_Routes::delete( $lbb_old['id'] );
	}
}
$wpdb->query( 'DELETE b FROM ' . LBB_Bookings::table() . ' b LEFT JOIN ' . LBB_Routes::table() . ' r ON r.id = b.route_id WHERE r.id IS NULL' );

// Curățarea orară pierdută (ex. plugin actualizat prin suprascrierea fișierelor, fără reactivare): orice cerere
// către site o programează din nou, fără ca administratorul să facă ceva.
wp_clear_scheduled_hook( 'lbb_cleanup' );
$cr_gone = wp_next_scheduled( 'lbb_cleanup' );
wp_remote_get( home_url( '/' ), array( 'timeout' => 20 ) );
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'cron', 'options' );
$cr_back = wp_next_scheduled( 'lbb_cleanup' );
LBB_Install::ensure_cron();
lbb_t( 'curățarea orară pierdută se programează din nou la următoarea cerere către site', false === $cr_gone && $cr_back > time() && 'hourly' === wp_get_schedule( 'lbb_cleanup' ), array( $cr_gone, $cr_back ) );
// Dezactivarea plugin-ului (cârligul WordPress, nu doar funcția) oprește curățarea orară: altfel WP-Cron ar rula
// la fiecare oră un eveniment fără plugin. Reactivarea o pornește din nou.
do_action( 'deactivate_' . plugin_basename( LBB_FILE ) );
$cr_off = wp_next_scheduled( 'lbb_cleanup' );
do_action( 'activate_' . plugin_basename( LBB_FILE ) );
$cr_on = wp_next_scheduled( 'lbb_cleanup' );
LBB_Install::ensure_cron();
lbb_t( 'dezactivarea plugin-ului oprește curățarea orară, reactivarea o repornește', false === $cr_off && $cr_on > time(), array( $cr_off, $cr_on ) );

// Rută de test cu 3 locuri, preț în RON.
$rid = LBB_Routes::save( array( 'origin' => 'TestA', 'destination' => 'TestB', 'departures' => '10:00, 7:5, 23:59, 10:00', 'price' => 60, 'currency' => 'RON', 'capacity' => 3, 'active' => 1 ) );
lbb_t( 'rută salvată', is_int( $rid ) && $rid > 0, $rid );
$route = LBB_Routes::get( $rid );
lbb_t( 'orele normalizate și unice', array( '10:00', '23:59' ) === $route['times'], $route['times'] );
lbb_t( 'duplicat respins', is_wp_error( LBB_Routes::save( array( 'origin' => 'TestA', 'destination' => 'TestB', 'departures' => '11:00', 'price' => 1 ) ) ) );
lbb_t( 'aceeași plecare și destinație respinsă', is_wp_error( LBB_Routes::save( array( 'origin' => 'X', 'destination' => 'X', 'departures' => '11:00', 'price' => 1 ) ) ) );
lbb_t( 'fără ore respinsă', is_wp_error( LBB_Routes::save( array( 'origin' => 'X', 'destination' => 'Y', 'departures' => 'dimineața', 'price' => 1 ) ) ) );
// Orașul scris altfel (majuscule, fără diacritice) primește scrierea de pe celelalte rute, ca să nu apară de două ori.
$kc_r  = LBB_Routes::save( array( 'origin' => ' testa ', 'destination' => 'Orasul Nou', 'departures' => '11:00', 'price' => 1 ) );
$kc_g  = is_int( $kc_r ) ? LBB_Routes::get( $kc_r ) : array();
$kc_e  = is_int( $kc_r ) ? LBB_Routes::save( array( 'origin' => 'TestA', 'destination' => 'Orașul Nou', 'departures' => '11:00', 'price' => 1 ), $kc_r ) : 0;
$kc_g2 = is_int( $kc_r ) ? LBB_Routes::get( $kc_r ) : array();
$kc_x  = LBB_Routes::save( array( 'origin' => 'ORASUL NOU', 'destination' => 'orașul nou', 'departures' => '11:00', 'price' => 1 ) );
if ( is_int( $kc_r ) ) {
	LBB_Routes::delete( $kc_r );
}
if ( is_int( $kc_x ) ) {
	LBB_Routes::delete( $kc_x );
}
lbb_t( 'oraș scris altfel („testa”) salvat ca pe celelalte rute („TestA”); scrierea se poate corecta; „ORASUL NOU” → „orașul nou” respinsă', $kc_g && 'TestA' === $kc_g['origin'] && $kc_g2 && 'Orașul Nou' === $kc_g2['destination'] && is_wp_error( $kc_x ), array( $kc_g ? $kc_g['origin'] : null, $kc_g2 ? $kc_g2['destination'] : null, $kc_x ) );

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
// Ultimele locuri luate de altcineva cât clientul completa formularul: mesaj clar, cu telefonul, nu „0 locuri”.
$fl_r = LBB_Routes::save( array( 'origin' => 'PlinA', 'destination' => 'PlinB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'capacity' => 1, 'active' => 1 ) );
$fl_1 = is_int( $fl_r ) ? LBB_Bookings::create_hold( LBB_Routes::get( $fl_r ), $tomorrow, '10:00', 1, 0, array( 'Ion Plin' ), '+37369000077', 'plin@example.com', 'MDL' ) : null;
$fl_2 = is_int( $fl_r ) ? LBB_Bookings::create_hold( LBB_Routes::get( $fl_r ), $tomorrow, '10:00', 1, 0, array( 'Ana Plin' ), '+37369000076', 'plin2@example.com', 'MDL' ) : null;
if ( is_int( $fl_r ) ) {
	$wpdb->delete( LBB_Bookings::table(), array( 'route_id' => $fl_r ) );
	LBB_Routes::delete( $fl_r );
}
lbb_t( 'cursa plină între timp: „Locurile … tocmai s-au terminat”, cu telefonul, nu „au mai rămas 0 locuri”', is_array( $fl_1 ) && is_wp_error( $fl_2 ) && 'lbb_full' === $fl_2->get_error_code() && false !== strpos( $fl_2->get_error_message(), 'tocmai s-au terminat' ) && false !== strpos( $fl_2->get_error_message(), LBB_Settings::phone_text() ) && false === strpos( $fl_2->get_error_message(), ' 0 ' ), is_wp_error( $fl_2 ) ? $fl_2->get_error_message() : $fl_2 );
// „1 loc liber”, „5 locuri libere”, „20 de locuri libere”, „101 locuri”, „120 de locuri”: acordul din română.
lbb_t( 'numărul de locuri acordat ca în română (1 loc liber, 5 locuri, 20 de locuri, 101 locuri, 120 de locuri)', '1 loc liber' === LBB_Settings::ro_count( 1, 'loc liber', 'locuri libere' ) && '5 locuri libere' === LBB_Settings::ro_count( 5, 'loc liber', 'locuri libere' ) && '19 locuri libere' === LBB_Settings::ro_count( 19, 'loc liber', 'locuri libere' ) && '20 de locuri libere' === LBB_Settings::ro_count( 20, 'loc liber', 'locuri libere' ) && '101 locuri libere' === LBB_Settings::ro_count( 101, 'loc liber', 'locuri libere' ) && '120 de locuri libere' === LBB_Settings::ro_count( 120, 'loc liber', 'locuri libere' ) && '100 de locuri libere' === LBB_Settings::ro_count( 100, 'loc liber', 'locuri libere' ) && is_wp_error( $h2 ) && (bool) preg_match( '/^(A mai rămas 1 loc liber|Au mai rămas \d+ (de )?locuri libere) la această plecare\.$/u', $h2->get_error_message() ), is_wp_error( $h2 ) ? $h2->get_error_message() : $h2 );
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
// WooCommerce → Comenzi: căutarea după codul biletului („LB-…” din emailul clientului) găsește comanda, în ambele stocări.
$sc_hpos = class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
$sc_ids  = $sc_hpos ? wc_get_orders( array( 's' => $b['ticket_code'], 'search_filter' => 'all', 'return' => 'ids', 'limit' => 5 ) ) : WC_Data_Store::load( 'order' )->search_orders( $b['ticket_code'] );
lbb_t( 'WooCommerce → Comenzi: căutarea după codul biletului găsește comanda (' . ( $sc_hpos ? 'HPOS' : 'posts' ) . ')', in_array( $order->get_id(), array_map( 'intval', (array) $sc_ids ), true ) && $b['ticket_code'] === wc_get_order( $order->get_id() )->get_meta( '_lbb_codes' ), array( $sc_ids, $order->get_id() ) );
$oc_cols = array_keys( LBB_WooCommerce::order_columns( array( 'cb' => '', 'order_number' => '', 'order_date' => '', 'order_status' => '', 'order_total' => '' ) ) );
ob_start();
LBB_WooCommerce::order_column( 'lbb_tickets', $order->get_id() );
LBB_WooCommerce::order_column( 'order_total', $order );
$oc_html = ob_get_clean();
lbb_t( 'WooCommerce → Comenzi: coloana „Bilete” după „Stare”, cu codul biletului (și cu comanda ca obiect, HPOS)', array( 'cb', 'order_number', 'order_date', 'order_status', 'lbb_tickets', 'order_total' ) === $oc_cols && '<code>' . $b['ticket_code'] . '</code>' === $oc_html, array( $oc_cols, $oc_html ) );
// „—” singur e citit „linie lungă” sau deloc: cititorul de ecran aude „fără cod” / „fără bilete”.
$em_m = new ReflectionMethod( 'LBB_Admin', 'ticket_code_html' );
$em_m->setAccessible( true );
$em_o = wc_create_order();
ob_start();
LBB_WooCommerce::order_column( 'lbb_tickets', $em_o );
$em_col = ob_get_clean();
$em_o->delete( true );
lbb_t( 'codul lipsă („—”) are text pentru cititorul de ecran: „fără cod” în Rezervări, „fără bilete” în WooCommerce → Comenzi', '<span aria-hidden="true">—</span><span class="screen-reader-text">fără cod</span>' === $em_m->invoke( null, '' ) && '<code>LB-ABC234</code>' === $em_m->invoke( null, 'LB-ABC234' ) && false !== strpos( $em_col, 'aria-hidden="true">—</span><span class="screen-reader-text">fără bilete</span>' ), array( $em_m->invoke( null, '' ), $em_col ) );
// Comandă plătită înainte de versiunea 5 (fără codurile pe comandă): actualizarea le pune, o singură dată.
$order->delete_meta_data( '_lbb_codes' );
$order->save_meta_data();
$bf_n = LBB_Install::backfill_codes();
$bf_c = wc_get_order( $order->get_id() )->get_meta( '_lbb_codes' );
lbb_t( 'actualizarea pune codurile biletelor și pe comenzile plătite înainte (căutarea din WooCommerce → Comenzi), iar a doua rulare nu mai schimbă nimic', $bf_n >= 1 && $b['ticket_code'] === $bf_c && 0 === LBB_Install::backfill_codes(), array( $bf_n, $bf_c ) );
$order->update_status( 'cancelled' );
lbb_t( 'anularea comenzii eliberează locurile', 'cancelled' === LBB_Bookings::get_by_token( $h3['token'] )['status'] );

// Oraș cu apostrof sau „&”: linia comenzii are numele ca text („Sânt'Ana”), nu „Sânt&#039;Ana” (ajunge în
// emailurile WooCommerce și la bancă); în coș (HTML) rămâne escapat.
$ln_r = LBB_Routes::save( array( 'origin' => "Sânt'Ana", 'destination' => 'Vadul & Co', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'capacity' => 5, 'active' => 1 ) );
$ln_h = is_int( $ln_r ) ? LBB_Bookings::create_hold( LBB_Routes::get( $ln_r ), $tomorrow, '10:00', 1, 0, array( 'Ion Nume' ), '+37369000071', 'nume@example.com', 'MDL' ) : null;
$ln_i = new WC_Order_Item_Product();
$ln_v = array( 'lbb' => array( 'token' => $ln_h ? $ln_h['token'] : '' ) );
if ( $ln_h ) {
	LBB_WooCommerce::line_item_meta( $ln_i, 'k', $ln_v );
}
$ln_cart = LBB_WooCommerce::item_name( 'x', $ln_v );
if ( $ln_h ) {
	LBB_Bookings::release( $ln_h['token'] );
}
if ( is_int( $ln_r ) ) {
	LBB_Routes::delete( $ln_r );
}
lbb_t( "oraș cu apostrof și „&”: linia comenzii „Bilet Sânt'Ana → Vadul & Co”, fără &#039; / &amp;; în coș escapat", $ln_h && "Bilet Sânt'Ana → Vadul & Co" === $ln_i->get_name() && 'Bilet Sânt&#039;Ana → Vadul &amp; Co' === $ln_cart, array( $ln_r, $ln_i->get_name(), $ln_cart ) );

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
$fr_page = wp_remote_retrieve_body( wp_remote_get( LBB_Tickets::url( $fr['ticket_code'] ), array( 'timeout' => 20 ) ) );
lbb_t( 'comanda plătită și rambursată, trecută înapoi în „așteaptă plata”: biletul nu cere o nouă plată', false === strpos( $fr_page, 'Achită acum' ) && false === strpos( $fr_page, 'Adaugă în calendar' ), substr( wp_strip_all_tags( $fr_page ), 0, 300 ) );
$fo->update_status( 'cancelled' ); // eliberează locul ținut din nou, altfel testele de mai jos nu mai au locuri
$fo->delete( true );
// Plata reluată din „Plătește comanda” după ce locul a fost luat de altcineva: WooCommerce primește o eroare (și nu
// încasează), iar rezervarea celuilalt client rămâne; cu locul liber din nou, plata reluată ține locul.
$bp_r = LBB_Routes::save( array( 'origin' => 'PlataA', 'destination' => 'PlataB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'capacity' => 1, 'active' => 1 ) );
$bp   = array();
if ( is_int( $bp_r ) ) {
	if ( ! WC()->session ) {
		wc_load_cart();
	}
	$bp_route = LBB_Routes::get( $bp_r );
	$bp_h     = LBB_Bookings::create_hold( $bp_route, $tomorrow, '10:00', 1, 0, array( 'Plata Veche' ), '+37369000066', 'bp@example.com', 'MDL' );
	$bp_o     = wc_create_order();
	$bp_i     = new WC_Order_Item_Product();
	$bp_i->set_product( wc_get_product( LBB_Install::product_id() ) );
	$bp_i->add_meta_data( '_lbb_token', $bp_h['token'], true );
	$bp_o->add_item( $bp_i );
	$bp_o->set_total( 50 );
	$bp_o->save();
	LBB_WooCommerce::attach_order( $bp_o );
	$bp_o->update_status( 'failed' );
	$bp_other = LBB_Bookings::create_hold( $bp_route, $tomorrow, '10:00', 1, 0, array( 'Alt Client' ), '+37369000065', 'alt@example.com', 'MDL' );
	$bp_other = is_array( $bp_other ) ? LBB_Bookings::reserve( $bp_other['token'] ) : null;
	wc_clear_notices();
	LBB_WooCommerce::before_pay( wc_get_order( $bp_o->get_id() ) );
	$bp['full_err']   = wc_notice_count( 'error' );
	$bp['full_mine']  = LBB_Bookings::get_by_token( $bp_h['token'] )['status'];
	$bp['full_other'] = $bp_other ? LBB_Bookings::get( $bp_other['id'] )['status'] : null;
	if ( $bp_other ) {
		LBB_Bookings::cancel( $bp_other['id'] );
	}
	wc_clear_notices();
	LBB_WooCommerce::before_pay( wc_get_order( $bp_o->get_id() ) );
	$bp['free_err']  = wc_notice_count( 'error' );
	$bp['free_mine'] = LBB_Bookings::get_by_token( $bp_h['token'] )['status'];
	wc_clear_notices();
	$bp_o->update_status( 'cancelled' );
	$bp_o->delete( true );
	$wpdb->delete( LBB_Bookings::table(), array( 'route_id' => $bp_r ) );
	LBB_Routes::delete( $bp_r );
}
lbb_t( 'plata reluată când locul a fost luat între timp: eroare la plată, rezervarea celuilalt rămâne; cu loc liber, locul se ține din nou', 1 === ( $bp['full_err'] ?? null ) && 'cancelled' === $bp['full_mine'] && 'reserved' === $bp['full_other'] && 0 === $bp['free_err'] && 'pending' === $bp['free_mine'], $bp );

// O comandă neplătită niciodată, cu bilet care are deja cod (ex. comandă refăcută de birou): „Achită acum” duce la plată.
$pn_h = LBB_Bookings::create_hold( $route, $tomorrow, '10:00', 1, 0, array( 'Plata Noua' ), '+37369000087', 'pn@example.com', 'MDL' );
$pn_o = wc_create_order();
$pn_i = new WC_Order_Item_Product();
$pn_i->set_product( wc_get_product( LBB_Install::product_id() ) );
$pn_i->add_meta_data( '_lbb_token', $pn_h['token'], true );
$pn_i->set_total( 120 );
$pn_o->add_item( $pn_i );
$pn_o->set_total( 120 );
$pn_o->save();
LBB_WooCommerce::attach_order( $pn_o );
$pn_o->update_status( 'pending' );
$wpdb->update( LBB_Bookings::table(), array( 'ticket_code' => 'LB-PNTEST', 'status' => 'pending' ), array( 'token' => $pn_h['token'] ) );
$pn_page = wp_remote_retrieve_body( wp_remote_get( LBB_Tickets::url( 'LB-PNTEST' ), array( 'timeout' => 20 ) ) );
// Textele plugin-ului sunt în română: fără traducerea lor, lang e „ro-RO” și pe un site în engleză (cititorul de ecran
// ar pronunța altfel textul românesc ca pe unul englezesc); cu traducere, limba site-ului.
$tl_site  = get_locale();
$tl_none  = LBB_Settings::text_lang();
$tl_keep  = isset( $GLOBALS['l10n']['libertbus-bilete'] ) ? $GLOBALS['l10n']['libertbus-bilete'] : null;
$GLOBALS['l10n']['libertbus-bilete'] = new MO();
$tl_trans = LBB_Settings::text_lang();
if ( null === $tl_keep ) {
	unset( $GLOBALS['l10n']['libertbus-bilete'] );
} else {
	$GLOBALS['l10n']['libertbus-bilete'] = $tl_keep;
}
$tl_form  = LBB_Frontend::shortcode( array() );
lbb_t( 'limba textului (lang) pe pagina biletului și în formular: „ro-RO” fără traducere, chiar pe un site în engleză; cu traducere, limba ei', 0 !== strpos( $tl_site, 'ro' ) && 'ro-RO' === $tl_none && str_replace( '_', '-', $tl_site ) === $tl_trans && false !== strpos( $pn_page, '<html lang="ro-RO">' ) && preg_match( '/class="lbb-booking[^"]*" lang="ro-RO"/', $tl_form ), array( $tl_site, $tl_none, $tl_trans, substr( $pn_page, 0, 60 ) ) );
lbb_t( 'pagina biletului cere culori deschise (fără tema întunecată automată a telefonului, care ar recolora biletul arătat șoferului)', false !== strpos( $pn_page, '<meta name="color-scheme" content="only light">' ), substr( $pn_page, 0, 400 ) );
lbb_t( 'biletul unei comenzi neplătite niciodată are „Achită acum” spre plata comenzii', false !== strpos( $pn_page, 'Achită acum' ) && false !== strpos( $pn_page, esc_url( $pn_o->get_checkout_payment_url() ) ), substr( wp_strip_all_tags( $pn_page ), 0, 300 ) );
$pn_o->update_status( 'cancelled' );
$pn_o->delete( true );

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
// Avertismentul „locuri depășite” merge la biroul din Setări (cine sună clientul), nu doar la adminul WordPress.
$ob_keep  = get_option( 'lbb_settings' );
update_option( 'lbb_settings', array_merge( (array) $ob_keep, array( 'company_email' => 'birou-test@example.com' ) ) );
$ob_mails = array();
$ob_catch = function ( $null, $atts ) use ( &$ob_mails ) {
	$ob_mails[] = $atts;
	return true;
};
// Subiectul trece prin traducere, ca restul emailurilor (un site în rusă sau engleză îl poate traduce).
$ob_tr = function ( $tr, $text, $domain ) {
	return 'libertbus-bilete' === $domain && '[LibertBus] Locuri depășite — comanda #%s' === $text ? $tr . ' [tr]' : $tr;
};
add_filter( 'gettext', $ob_tr, 10, 3 );
add_filter( 'pre_wp_mail', $ob_catch, 1, 2 );
$o2->payment_complete( 'TEST' );
remove_filter( 'pre_wp_mail', $ob_catch, 1 );
remove_filter( 'gettext', $ob_tr, 10 );
update_option( 'lbb_settings', $ob_keep );
$ob_alert = array_values( array_filter( $ob_mails, function ( $m ) {
	return false !== strpos( (string) $m['subject'], 'Locuri depășite' );
} ) );
$ob_m = $ob_alert ? $ob_alert[0] : array( 'to' => '', 'message' => '', 'headers' => array() );
$ob_h = implode( "\n", (array) $ob_m['headers'] );
lbb_t( 'plata întârziată peste capacitate: emailul de avertizare merge la biroul din Setări, de la firmă, cu linkul comenzii; subiectul e traductibil', 1 === count( $ob_alert ) && false !== strpos( (string) $ob_m['subject'], ' [tr]' ) && 'birou-test@example.com' === $ob_m['to'] && false !== strpos( $ob_h, 'From: LibertBus <' ) && false !== strpos( $ob_m['message'], 'href="' . esc_url( $o2->get_edit_order_url() ) . '"' ) && false !== strpos( $ob_m['message'], 'ATENȚIE' ), array( wp_list_pluck( $ob_mails, 'to' ), $ob_h ) );
$o2 = wc_get_order( $o2->get_id() );
$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $o2->get_id() ) ), 'content' );
lbb_t( 'plata întârziată: biletul se emite', 'confirmed' === LBB_Bookings::get_by_token( $late['token'] )['status'] );
// „Finalizare automată” (implicit pornită): comanda plătită doar cu bilete devine „Finalizată” (clientul primește
// imediat emailul cu biletul); cu setarea oprită sau într-o comandă cu alte produse, rămâne cum o lasă WooCommerce.
$ac_keep  = get_option( 'lbb_settings' );
$ac_mixed = wc_create_order();
$ac_mixed->add_item( ( function () use ( $late ) {
	$it = new WC_Order_Item_Product();
	$it->set_product( wc_get_product( LBB_Install::product_id() ) );
	$it->add_meta_data( '_lbb_token', $late['token'], true );
	return $it;
} )() );
$ac_mixed->add_item( new WC_Order_Item_Product() ); // alt produs, fără bilet
$ac_mixed->update_meta_data( '_lbb_tickets', 'yes' );
$ac_mixed->save();
$ac_on    = LBB_WooCommerce::autocomplete( 'processing', $o2->get_id(), $o2 );
$ac_mix   = LBB_WooCommerce::autocomplete( 'processing', $ac_mixed->get_id(), $ac_mixed );
update_option( 'lbb_settings', array_merge( (array) $ac_keep, array( 'autocomplete' => 0 ) ) );
$ac_off   = LBB_WooCommerce::autocomplete( 'processing', $o2->get_id(), $o2 );
update_option( 'lbb_settings', $ac_keep );
$ac_mixed->delete( true );
lbb_t( 'finalizare automată: comanda plătită doar cu bilete devine „Finalizată”; oprită din Setări sau cu alte produse în comandă, rămâne „În procesare”', 'completed' === $o2->get_status() && 'completed' === $ac_on && 'processing' === $ac_off && 'processing' === $ac_mix, array( $o2->get_status(), $ac_on, $ac_off, $ac_mix ) );
lbb_t( 'plata întârziată: notă de avertizare', (bool) preg_grep( '/ATENȚIE/u', $notes ), $notes );
lbb_t( 'comanda doar cu bilete se finalizează automat', 'completed' === $o2->get_status(), $o2->get_status() );
// Pe pagina comenzii din WooCommerce, biroul vede biletele comenzii, cu linkul spre fiecare (doar cu drepturi de admin).
$ot_user = get_current_user_id();
$ot_late = LBB_Bookings::get_by_token( $late['token'] );
ob_start();
LBB_Admin::order_tickets( $o2 );
$ot_none = ob_get_clean();
wp_set_current_user( 1 );
ob_start();
LBB_Admin::order_tickets( $o2 );
$ot_html = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' );
ob_start();
$ot_eo = wc_create_order();
LBB_Admin::order_tickets( $ot_eo );
$ot_empty = ob_get_clean();
$ot_eo->delete( true );
wp_set_current_user( $ot_user );
lbb_t( 'pagina comenzii WooCommerce arată biletele comenzii (cod cu link, cursă, locuri, stare)', '' === $ot_none && '' === $ot_empty && false !== strpos( $ot_html, 'Bilete LibertBus' ) && false !== strpos( $ot_html, 'href="' . LBB_Tickets::url( $ot_late['ticket_code'] ) . '"' ) && false !== strpos( $ot_html, $route['origin'] . ' → ' . $route['destination'] . ', ' . LBB_WooCommerce::date_with_day( $tomorrow ) . ' 10:00, 3 locuri' ) && false !== strpos( $ot_html, LBB_Tickets::state( $ot_late )['text'] ), array( $ot_none, $ot_empty, wp_strip_all_tags( $ot_html ) ) );
lbb_t( 'pagina comenzii WooCommerce: cursa biletului are data cu ziua săptămânii, ca pe bilet', $ot_late && false !== strpos( $ot_html, LBB_WooCommerce::date_with_day( $ot_late['travel_date'] ) . ' ' . $ot_late['dep_time'] ), wp_strip_all_tags( $ot_html ) );

// Emailul text al comenzii (clienți cu emailuri „doar text”): numele cu apostrof rămâne întreg după
// curățarea făcută de WooCommerce, iar titlul e cu majuscule corecte.
$wpdb->update( LBB_Bookings::table(), array( 'passengers' => wp_json_encode( array( "Ana D'Angelo", 'L', 'M' ) ) ), array( 'id' => $late['id'] ) );
ob_start();
LBB_Tickets::email( $o2, false, true );
$lbb_plain = ob_get_clean();
$lbb_wce   = new WC_Email();
$lbb_plain = preg_replace( $lbb_wce->plain_search, $lbb_wce->plain_replace, wp_strip_all_tags( $lbb_plain ) );
ob_start();
LBB_Tickets::email( $o2, false, false );
$lbb_html1 = ob_get_clean();
ob_start();
LBB_Tickets::thankyou( $o2->get_id() );
$lbb_ty1 = ob_get_clean();
lbb_t( 'emailul text: „Ana D\'Angelo” rămâne întreg, titlul cu majuscule corecte, la singular pentru un bilet („BILETUL DUMNEAVOASTRĂ”)', false !== strpos( $lbb_plain, "Ana D'Angelo" ) && false !== strpos( $lbb_plain, 'BILETUL DUMNEAVOASTRĂ' ), $lbb_plain );
lbb_t( 'un singur bilet: emailul HTML și pagina de după plată spun „Biletul dumneavoastră” / „L-am trimis”, nu pluralul', false !== strpos( $lbb_html1, '<h2>Biletul dumneavoastră</h2>' ) && false !== strpos( $lbb_ty1, '<h2>Biletul dumneavoastră</h2>' ) && false !== strpos( $lbb_ty1, 'L-am trimis și pe email' ), array( substr( wp_strip_all_tags( $lbb_html1 ), 0, 80 ), substr( wp_strip_all_tags( $lbb_ty1 ), 0, 120 ) ) );
lbb_t( 'emailul text al comenzii are și „Adaugă în calendar” cu un link utilizabil (& nu &#038;)', (bool) preg_match( '#Adaugă în calendar: https?://\S+[?&]lbb_bilet=\S+&k=\w+&ics=1\n#u', $lbb_plain ), $lbb_plain );

// Pagina biletului: semnătura.
$code = LBB_Bookings::get_by_token( $late['token'] )['ticket_code'];
lbb_t( 'linkul biletului e semnat', false !== strpos( LBB_Tickets::url( $code ), 'k=' . LBB_Tickets::signature( $code ) ) );
// Cheile WordPress schimbate (ex. după o parolă expusă): biletele trimise și codurile QR rămân valabile.
$sk_sig    = LBB_Tickets::signature( $code );
$sk_legacy = substr( hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ), 0, 20 );
$sk_salt   = function () {
	return 'chei-noi-dupa-schimbare';
};
add_filter( 'salt', $sk_salt );
$sk_after  = LBB_Tickets::valid_signature( $code, $sk_sig ) && $sk_sig === LBB_Tickets::signature( $code );
$sk_old    = LBB_Tickets::valid_signature( $code, $sk_legacy );
remove_filter( 'salt', $sk_salt );
lbb_t( 'linkul biletului rămâne valabil după schimbarea cheilor WordPress; biletele vechi (semnătura de până acum) merg cât cheile nu se schimbă; o semnătură greșită sau goală e refuzată', strlen( (string) get_option( 'lbb_ticket_key' ) ) >= 32 && $sk_sig !== $sk_legacy && $sk_after && LBB_Tickets::valid_signature( $code, $sk_legacy ) && ! $sk_old && ! LBB_Tickets::valid_signature( $code, 'gresit' ) && ! LBB_Tickets::valid_signature( $code, '' ) );

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
	'+373 069 184 111' => '+37369184111',
	'+373 (0)69184111' => '+37369184111',
	'00373 0231 12345' => '+37323112345',
	'+40 0740 123 456' => '+40740123456',
	'+40 (0)740123456' => '+40740123456',
	'+49 0151 2345678' => '+4901512345678',
	'021 123 4567'     => '+40211234567',
	'0264 123 456'     => '+40264123456',
	'+40 (0)21 1234567' => '+40211234567',
	'40 264 123 456'   => '+40264123456',
	'022 123 456'      => '+37322123456',
	'69 184 111'       => '+37369184111',
	'79123456'         => '+37379123456',
	'22 123 456'       => '+37322123456',
	'740 123 456'      => '+40740123456',
) as $in => $want ) {
	lbb_t( 'telefon normalizat: ' . $in, $want === LBB_Frontend::normalize_phone( $in ), LBB_Frontend::normalize_phone( $in ) );
}
lbb_t( 'telefon greșit respins', is_wp_error( $r ) && 'lbb_phone' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_phone' => '+373 69 184 111 69 184 111' ) ) );
lbb_t( 'telefon prea lung (tastat de două ori) respins cu mesaj clar', is_wp_error( $r ) && 'lbb_phone' === $r->get_error_code(), $r );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_email' => str_repeat( 'a', 64 ) . '@' . str_repeat( 'b', 63 ) . '.' . str_repeat( 'c', 63 ) . '.com' ) ) );
lbb_t( 'email prea lung pentru baza de date respins cu mesaj clar', is_wp_error( $r ) && 'lbb_email' === $r->get_error_code(), $r );
lbb_t( 'câmpul de email are limită de lungime', (bool) preg_match( '/<input type="email" name="lbb_email"[^>]* maxlength="190"/', LBB_Frontend::shortcode( array() ) ) );
// Fără calendar pentru dată (Safari pe Mac < 14.1) câmpul e text: „25.12.2026” se înțelege ca 2026-12-25.
lbb_t( 'data scrisă de mână („25.12.2026”, „5/1/2027”, „25.12.26”) se înțelege; formatul ISO rămâne; altceva nu devine dată', '2026-12-25' === LBB_Frontend::iso_date( '25.12.2026' ) && '2027-01-05' === LBB_Frontend::iso_date( ' 5/1/2027 ' ) && '2026-12-25' === LBB_Frontend::iso_date( '2026-12-25' ) && '2026-12-25' === LBB_Frontend::iso_date( '25.12.26' ) && '2027-01-05' === LBB_Frontend::iso_date( '5/1/27' ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', LBB_Frontend::iso_date( '25.12.202' ) ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', LBB_Frontend::iso_date( '25 dec' ) ) );
preg_match( "/preg_match\\( '(\\/\\^[^']+\\/)', \\(string\\) \\\$date/", (string) file_get_contents( LBB_DIR . 'includes/class-lbb-frontend.php' ), $id_php );
preg_match( '/var m = (\/\^[^\n]+\/)\.exec\( v/', (string) file_get_contents( LBB_DIR . 'assets/lbb.js' ), $id_js );
lbb_t( 'data scrisă de mână cu punct la sfârșit („25.12.2026.”) se înțelege, iar regula din lbb.js e aceeași ca în PHP', '2026-12-25' === LBB_Frontend::iso_date( '25.12.2026.' ) && '2027-01-05' === LBB_Frontend::iso_date( ' 5.1.27. ' ) && isset( $id_php[1], $id_js[1] ) && $id_php[1] === $id_js[1], array( LBB_Frontend::iso_date( '25.12.2026.' ), isset( $id_php[1] ) ? $id_php[1] : null, isset( $id_js[1] ) ? $id_js[1] : null ) );
// După o eroare, formularul revine cu data trimisă: scrisă „zz.ll.aaaa” (browser fără calendar) revine ca dată ISO.
$pd_err = new ReflectionProperty( 'LBB_Frontend', 'error' );
$pd_err->setAccessible( true );
$pd_err->setValue( null, new WP_Error( 'lbb_test', 'test' ) );
$pd_post = $_POST;
$_POST   = array( 'lbb_date' => '25.12.2026', 'lbb_route' => 0 );
$pd_html = LBB_Frontend::shortcode( array() );
$_POST   = $pd_post;
$pd_err->setValue( null, null );
$pd_cfg  = preg_match( '/data-lbb-config="([^"]+)"/', $pd_html, $pd_m ) ? json_decode( base64_decode( html_entity_decode( $pd_m[1], ENT_QUOTES ) ), true ) : null;
lbb_t( 'după o eroare, data trimisă „25.12.2026” revine în formular ca 2026-12-25', isset( $pd_cfg['preset']['date'] ) && '2026-12-25' === $pd_cfg['preset']['date'], isset( $pd_cfg['preset'] ) ? $pd_cfg['preset'] : $pd_html );
// După „ați vrut să scrieți ion@gmail.com?”, câmpul păstrează ce a scris clientul (sanitize_email îl golea), escapat.
$pd_err->setValue( null, new WP_Error( 'lbb_email', 'test' ) );
$_POST   = array( 'lbb_email' => ' ion@gmail ' );
$pe_html = LBB_Frontend::shortcode( array() );
$_POST   = array( 'lbb_email' => 'a"><b>x@gmail.con' );
$pe_xss  = LBB_Frontend::shortcode( array() );
$_POST   = $pd_post;
$pd_err->setValue( null, null );
lbb_t( 'după o eroare la email, câmpul păstrează adresa scrisă („ion@gmail”), ca s-o corecteze, nu-l golește; valoarea e escapată', (bool) preg_match( '/name="lbb_email"[^>]* value="ion@gmail"/', $pe_html ) && ! preg_match( '/"><b>x/', $pe_xss ), preg_match( '/<input type="email"[^>]*>/', $pe_html, $pe_m ) ? $pe_m[0] : '' );
// Culoarea butoanelor aleasă deschisă (ex. galben): textul devine închis, ca „Rezerv” să se citească.
$ai_keep = get_option( 'lbb_settings' );
update_option( 'lbb_settings', array_merge( (array) $ai_keep, array( 'accent_color' => '#ffd700' ) ) );
wp_deregister_style( 'lbb' );
LBB_Frontend::register_assets();
$ai_css = implode( '', (array) wp_styles()->get_data( 'lbb', 'after' ) );
update_option( 'lbb_settings', $ai_keep );
wp_deregister_style( 'lbb' );
LBB_Frontend::register_assets();
lbb_t( 'culoarea butoanelor: text alb pe verde/albastru închis, text închis pe galben sau alb; aplicat în stilul formularului', '#fff' === LBB_Settings::accent_ink( '#00875a' ) && '#fff' === LBB_Settings::accent_ink( '#1e40af' ) && '#1d2733' === LBB_Settings::accent_ink( '#ffd700' ) && '#1d2733' === LBB_Settings::accent_ink( '#fff' ) && '#fff' === LBB_Settings::accent_ink( 'nu' ) && false !== strpos( $ai_css, '--lbb-accent:#ffd700;--lbb-accent-ink:#1d2733;--lbb-accent-text:#1d2733' ), $ai_css );
lbb_t( 'text și contururi pe alb (buton secundar, „Înapoi”, câmp selectat): culoarea aleasă dacă se citește, altfel închisă', '#00875a' === LBB_Settings::accent_text( '#00875a' ) && '#1e40af' === LBB_Settings::accent_text( '#1e40af' ) && '#1d2733' === LBB_Settings::accent_text( '#ffd700' ) && '#1d2733' === LBB_Settings::accent_text( '#f39c12' ) && '#1d2733' === LBB_Settings::accent_text( 'nu' ) && ! preg_match( '/(?<![\w-])color:\s*var\(--lbb-accent\)|outline:[^;]*var\(--lbb-accent\)/', (string) file_get_contents( LBB_DIR . 'assets/lbb.css' ) ) );
// Fiecare fișier PHP al plugin-ului refuză accesul direct din browser (defined( 'ABSPATH' ) || exit;): altfel o
// cerere la /wp-content/plugins/libertbus-bilete/includes/… poate afișa erori PHP cu căile serverului.
$da_bad = array();
foreach ( array_merge( array( LBB_DIR . 'libertbus-bilete.php', LBB_DIR . 'uninstall.php' ), glob( LBB_DIR . 'includes/class-*.php' ), glob( LBB_DIR . 'data/routes-*.php' ) ) as $da_f ) {
	if ( ! preg_match( "/^\\s*defined\\( '(ABSPATH|WP_UNINSTALL_PLUGIN)' \\) \\|\\| exit;/m", (string) file_get_contents( $da_f ) ) ) {
		$da_bad[] = basename( $da_f );
	}
}
$da_url  = plugins_url( 'includes/class-lbb-tickets.php', LBB_DIR . 'libertbus-bilete.php' );
$da_body = wp_remote_retrieve_body( wp_remote_get( $da_url, array( 'timeout' => 20 ) ) );
lbb_t( 'fișierele PHP refuză accesul direct (defined ABSPATH || exit), iar o cerere directă la un fișier din includes/ nu afișează nimic', ! $da_bad && '' === trim( $da_body ), array( $da_bad, substr( $da_body, 0, 200 ) ) );
$fh_r = wp_remote_get( home_url( '/?lbb_bilet=LB-XXXXXX&k=0' ), array( 'timeout' => 20 ) );
$fh_h = array( wp_remote_retrieve_header( $fh_r, 'x-frame-options' ), wp_remote_retrieve_header( $fh_r, 'content-security-policy' ), wp_remote_retrieve_header( $fh_r, 'x-content-type-options' ) );
lbb_t( 'pagina biletului nu se poate încadra pe alt site (X-Frame-Options, frame-ancestors) și are nosniff', array( 'SAMEORIGIN', "frame-ancestors 'self'", 'nosniff' ) === $fh_h, $fh_h );
// Pe un server cu listarea folderelor pornită (Apache „Options +Indexes”), /wp-content/plugins/libertbus-bilete/includes/
// ar arăta toate fișierele: fiecare folder are un index.php gol, inclus și în arhiva de instalare.
$ix_bad = array();
foreach ( array( '', 'includes/', 'assets/', 'data/', 'languages/' ) as $ix_d ) {
	$ix_body = file_exists( LBB_DIR . $ix_d . 'index.php' ) ? wp_remote_retrieve_body( wp_remote_get( plugins_url( $ix_d . 'index.php', LBB_DIR . 'libertbus-bilete.php' ), array( 'timeout' => 20 ) ) ) : 'lipsește';
	if ( '' !== trim( $ix_body ) ) {
		$ix_bad[] = $ix_d . 'index.php: ' . substr( $ix_body, 0, 40 );
	}
}
$ix_zip = (string) file_get_contents( LBB_DIR . 'tests/build-zip.sh' );
lbb_t( 'fiecare folder al plugin-ului are un index.php gol (fără listarea fișierelor pe server), inclus în arhivă', ! $ix_bad && false !== strpos( $ix_zip, '"$(basename "$DIR")/index.php"' ), $ix_bad );
$gt_d = ( new LBB_Gateway_Test() )->get_description();
lbb_t( 'plata de test are descriere (text, nu null: altfel WooCommerce 9+ scrie „Deprecated” la fiecare pagină de plată)', is_string( ( new LBB_Gateway_Test() )->description ) && '' !== trim( $gt_d ), $gt_d );
// Flex gap lipsește în Safari < 14.1 (iPhone-uri încă folosite): în rândurile flex spațiile sunt margini.
$fg_bad = array();
foreach ( array( 'assets/lbb.css', 'assets/cookies.css', 'includes/class-lbb-tickets.php' ) as $fg_f ) {
	preg_match_all( '/([^{}]+)\{([^{}]*)\}/', (string) file_get_contents( LBB_DIR . $fg_f ), $fg_m, PREG_SET_ORDER );
	foreach ( $fg_m as $fg_r ) {
		if ( preg_match( '/display:\s*(inline-)?flex/', $fg_r[2] ) && preg_match( '/(^|[;\s])(row-|column-)?gap:/', $fg_r[2] ) ) {
			$fg_bad[] = $fg_f . ': ' . trim( $fg_r[1] );
		}
	}
}
lbb_t( 'nicio regulă flex nu folosește gap (Safari < 14.1): formular, banner cookies, pagina biletului', ! $fg_bad, $fg_bad );
// Tot Safari < 14.1 nu știe „inset: 0”: fereastra peste pagină ar rămâne fără poziție; se scrie cu top/right/bottom/left.
$in_bad = array();
foreach ( array( 'assets/lbb.css', 'assets/cookies.css', 'includes/class-lbb-tickets.php' ) as $in_f ) {
	if ( preg_match_all( '/(?<![\w-])inset(-(block|inline)(-(start|end))?)?\s*:[^;}]*/', (string) file_get_contents( LBB_DIR . $in_f ), $in_m ) ) {
		foreach ( $in_m[0] as $in_r ) {
			$in_bad[] = $in_f . ': ' . trim( $in_r );
		}
	}
}
lbb_t( 'nicio regulă CSS nu folosește „inset” (Safari < 14.1): fereastra peste pagină are top/right/bottom/left', ! $in_bad, $in_bad );
// Safari < 15.4 nu știe :focus-visible: fiecare contur de focus are și varianta :focus, altfel de la tastatură nu se vede unde ești
// (la monede, butonul radio e ascuns și conturul e singurul semn).
$fv_bad = array();
foreach ( array( 'assets/lbb.css', 'assets/cookies.css' ) as $fv_f ) {
	$fv_css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( LBB_DIR . $fv_f ) );
	preg_match_all( '/([^{}]+)\{[^{}]*\}/', $fv_css, $fv_m );
	$fv_sel = array();
	foreach ( $fv_m[1] as $fv_s ) {
		foreach ( explode( ',', $fv_s ) as $fv_one ) {
			$fv_sel[] = trim( $fv_one );
		}
	}
	foreach ( $fv_sel as $fv_one ) {
		if ( false !== strpos( $fv_one, ':focus-visible' ) && false === strpos( $fv_one, ':not(:focus-visible)' ) ) {
			$fv_plain = str_replace( ':focus-visible', ':focus', $fv_one );
			$fv_reset = str_replace( ':focus-visible', ':focus:not(:focus-visible)', $fv_one );
			if ( ! in_array( $fv_plain, $fv_sel, true ) || ! in_array( $fv_reset, $fv_sel, true ) ) {
				$fv_bad[] = $fv_f . ': ' . $fv_one;
			}
		}
	}
}
lbb_t( 'contururile de focus merg și fără :focus-visible (Safari < 15.4): fiecare are varianta :focus, ascunsă la clic unde :focus-visible există', ! $fv_bad, $fv_bad );
// Pe telefon: tastatura nu pune majusculă la început și nu „corectează” adresa (ex. „gmail” → „Gmail”, „mail” → „mai”).
lbb_t( 'câmpul de email: fără majusculă automată și fără corector', false !== strpos( LBB_Frontend::shortcode( array() ), 'name="lbb_email" required autocomplete="email" autocapitalize="off" spellcheck="false"' ) );
lbb_t( 'câmpul de telefon are limită de lungime', false !== strpos( LBB_Frontend::shortcode( array() ), 'name="lbb_phone" required autocomplete="tel" inputmode="tel" maxlength="30"' ) );
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_email' => 'nu-e-email' ) ) );
lbb_t( 'email greșit respins', is_wp_error( $r ) && 'lbb_email' === $r->get_error_code(), $r );
// O greșeală știută în domeniu („gmail.con”) e oprită cu adresa corectă propusă; domeniile corecte și cele necunoscute trec.
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_email' => 'Ion.Rusu@GMAIL.CON' ) ) );
$et_ok = array();
foreach ( array( 'a@gmail.com', 'a@yahoo.com', 'a@mail.ru', 'a@gmail.co.uk', 'a@firma.md', 'a@gmail.con.md', 'a@yandex.ru', 'a@hotmail.co.uk', 'a@outlook.com' ) as $et_e ) {
	$et_ok[ $et_e ] = LBB_Frontend::email_typo( $et_e );
}
lbb_t( 'email cu domeniu greșit („gmail.con”, „gmial.com”, „mail.r”) oprit cu adresa corectă propusă; adresele corecte trec', is_wp_error( $r ) && 'lbb_email' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'Ion.Rusu@gmail.com' ) && 'x@gmail.com' === LBB_Frontend::email_typo( 'x@gmial.com' ) && 'x@mail.ru' === LBB_Frontend::email_typo( 'x@mail.r' ) && 'x@hotmail.com' === LBB_Frontend::email_typo( 'x@hotmial.com' ) && ! array_filter( $et_ok ), array( $r, $et_ok ) );
lbb_t( 'greșeli dese în domeniu („gmail.co”, „gmail.cpm”, „gmal.com”, „yandex.r”, „hotmail.co”) au adresa corectă propusă', 'a@gmail.com' === LBB_Frontend::email_typo( 'a@gmail.co' ) && 'a@gmail.com' === LBB_Frontend::email_typo( 'a@gmail.cpm' ) && 'a@gmail.com' === LBB_Frontend::email_typo( 'a@gmal.com' ) && 'a@yandex.ru' === LBB_Frontend::email_typo( 'a@yandex.r' ) && 'a@hotmail.com' === LBB_Frontend::email_typo( 'a@hotmail.co' ) && '' === LBB_Frontend::email_typo( 'a@hotmail.co.uk' ) );
lbb_t( 'orice domeniu terminat în „.con”, „.cmo”, „.comm” primește „.com” propus; „.co”, „.com.md”, „.md” trec', 'ion@firma.com' === LBB_Frontend::email_typo( 'ion@Firma.CON' ) && 'a@exemplu.md.com' === LBB_Frontend::email_typo( 'a@exemplu.md.cmo' ) && 'a@b.com' === LBB_Frontend::email_typo( 'a@b.comm' ) && '' === LBB_Frontend::email_typo( 'a@firma.co' ) && '' === LBB_Frontend::email_typo( 'a@firma.com.md' ) && '' === LBB_Frontend::email_typo( 'a@con.md' ) && '' === LBB_Frontend::email_typo( 'a@con' ) );
// „ion@gmail” e email valid pentru browser (type=email nu cere punct); sanitize_email îl golea și mesajul era doar „adresă invalidă”.
$r     = LBB_Frontend::book( array_merge( $base, array( 'lbb_email' => ' ion.rusu@Gmail ' ) ) );
$r_bad = LBB_Frontend::book( array_merge( $base, array( 'lbb_email' => 'ion rusu@gmail' ) ) );
lbb_t( 'domeniu fără „.com” („ion@gmail”, „a@yahoo”) oprit cu adresa completă propusă; „a@mail” și adrese stricate primesc mesajul general', is_wp_error( $r ) && 'lbb_email' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'ion.rusu@gmail.com?' ) && 'a@yahoo.com' === LBB_Frontend::email_typo( 'a@yahoo' ) && 'a@yandex.ru' === LBB_Frontend::email_typo( 'a@yandex' ) && '' === LBB_Frontend::email_typo( 'a@mail' ) && is_wp_error( $r_bad ) && false === strpos( $r_bad->get_error_message(), 'ați vrut' ), array( $r, $r_bad ) );
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
// O cursă de azi deja plecată nu mai ține ocupată limita de rezervări neachitate; una de azi, încă neplecată, da.
$ar_r   = LBB_Routes::save( array( 'origin' => 'LimitaA', 'destination' => 'LimitaB', 'departures' => '00:00, 23:59', 'price' => 50, 'currency' => 'MDL', 'active' => 1 ) );
$ar_ins = function ( $time ) use ( $wpdb, $ar_r ) {
	$wpdb->insert( LBB_Bookings::table(), array( 'token' => wp_generate_password( 32, false ), 'route_id' => $ar_r, 'travel_date' => LBB_Settings::today(), 'dep_time' => $time, 'seats' => 1, 'children' => 0, 'passengers' => '[]', 'phone' => '+37369000088', 'email' => '', 'amount' => 50, 'currency' => 'MDL', 'status' => 'reserved', 'ticket_code' => 'LB-LIM' . wp_rand( 1000, 9999 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
};
$ar_hm  = ( new DateTimeImmutable( 'now', LBB_Settings::tz() ) )->format( 'H:i' );
if ( is_int( $ar_r ) ) {
	$ar_ins( '00:00' );
	$ar_n1 = LBB_Bookings::active_reservations( '+37369000088' );
	$ar_ins( '23:59' );
	$ar_n2 = LBB_Bookings::active_reservations( '+37369000088' );
	$wpdb->delete( LBB_Bookings::table(), array( 'route_id' => $ar_r ) );
	LBB_Routes::delete( $ar_r );
}
// La 00:00–00:04 sau la 23:59 cursele de test nu sunt încă / mai sunt plecate: verificarea nu are sens atunci.
lbb_t( 'limita de rezervări neachitate nu numără cursele de azi deja plecate', ! is_int( $ar_r ) ? false : ( $ar_hm < '00:05' || $ar_hm >= '23:59' || ( 0 === $ar_n1 && 1 === $ar_n2 ) ), array( $ar_hm, isset( $ar_n1 ) ? $ar_n1 : null, isset( $ar_n2 ) ? $ar_n2 : null ) );
// „Achit online” trimis de două ori pentru aceeași cursă (dublu-tap, „Înapoi” de la plată și alt număr de pasageri):
// în coș rămâne doar ultimul bilet, iar locurile celui vechi se eliberează; o altă cursă (tur-retur) se adaugă alături.
$cs_r    = LBB_Routes::save( array( 'origin' => 'CosA', 'destination' => 'CosB', 'departures' => '10:00, 23:58', 'price' => 50, 'currency' => 'MDL', 'capacity' => 5, 'active' => 1 ) );
$cs_keep = get_option( 'lbb_settings', array() );
$cs_res  = array();
if ( is_int( $cs_r ) && function_exists( 'WC' ) ) {
	update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_pay' => 1 ) ) );
	if ( ! WC()->cart ) {
		wc_load_cart();
	}
	WC()->cart->empty_cart();
	$cs_d   = array( 'lbb_route' => $cs_r, 'lbb_date' => $tomorrow, 'lbb_time' => '23:58', 'lbb_adults' => 1, 'lbb_names' => array( 'Ion Cos', 'Ana Cos' ), 'lbb_phone' => '+37369000077', 'lbb_email' => 'cos@example.com', 'lbb_mode' => 'pay' );
	$cs_tok = function () {
		return array_values( array_map( function ( $i ) {
			return isset( $i['lbb']['token'] ) ? $i['lbb']['token'] : '';
		}, WC()->cart->get_cart() ) );
	};
	$cs_res['a']     = LBB_Frontend::book( $cs_d );
	$cs_t1           = $cs_tok();
	$cs_res['b']     = LBB_Frontend::book( array_merge( $cs_d, array( 'lbb_adults' => 2 ) ) );
	$cs_t2           = $cs_tok();
	$cs_res['old']   = $cs_t1 ? LBB_Bookings::get_by_token( $cs_t1[0] )['status'] : null;
	$cs_res['new']   = $cs_t2 ? LBB_Bookings::get_by_token( $cs_t2[0] )['seats'] : null;
	// „Înapoi” și altă oră în aceeași zi (corectură): tot un singur bilet, cel nou.
	$cs_res['time']  = LBB_Frontend::book( array_merge( $cs_d, array( 'lbb_time' => '10:00' ) ) );
	$cs_t3           = $cs_tok();
	$cs_res['timeb'] = $cs_t3 ? LBB_Bookings::get_by_token( $cs_t3[0] )['dep_time'] : null;
	$cs_res['other'] = LBB_Frontend::book( array_merge( $cs_d, array( 'lbb_time' => '10:00', 'lbb_date' => ( new DateTimeImmutable( $tomorrow ) )->modify( '+1 day' )->format( 'Y-m-d' ) ) ) );
	$cs_res['n']     = array( count( $cs_t1 ), count( $cs_t2 ), count( $cs_tok() ) );
	// Checkout-ul cu blocuri ia câmpurile din regulile pe țară: cu „Plată simplificată” și doar bilete în coș, adresa
	// e ascunsă și opțională acolo (clientul nu e obligat la stradă, oraș, cod poștal); cu coșul gol rămâne ca înainte.
	$cs_lk = LBB_Settings::all();
	update_option( 'lbb_settings', array_merge( $cs_lk, array( 'simple_checkout' => 1 ) ) );
	WC()->countries->locale = null;
	$cs_loc = WC()->countries->get_country_locale();
	$cs_res['loc'] = array( $cs_loc['default']['address_1']['required'], ! empty( $cs_loc['default']['city']['hidden'] ), $cs_loc['RO']['postcode']['required'], ! empty( $cs_loc['MD']['address_1']['hidden'] ), ! empty( $cs_loc['default']['phone']['required'] ) && ! empty( $cs_loc['MD']['phone']['required'] ) );
	// Autocar plin cu biletul din coș (5 din 5 locuri): același bilet trimis din nou nu e refuzat cu „s-au terminat”.
	// empty_cart() nu eliberează locurile ținute: le eliberăm întâi, ca autocarul să fie gol.
	array_map( array( 'LBB_Bookings', 'release' ), $cs_tok() );
	WC()->cart->empty_cart();
	WC()->countries->locale = null;
	$cs_loc = WC()->countries->get_country_locale();
	$cs_res['loc0'] = array( $cs_loc['default']['address_1']['required'], ! empty( $cs_loc['default']['city']['hidden'] ) );
	WC()->countries->locale = null;
	update_option( 'lbb_settings', $cs_lk );
	$cs_full         = array_merge( $cs_d, array( 'lbb_time' => '10:00', 'lbb_adults' => 5, 'lbb_names' => array( 'Ion Cos', 'Ana Cos', 'Vlad Cos', 'Dan Cos', 'Eva Cos' ) ) );
	$cs_res['full1'] = LBB_Frontend::book( $cs_full );
	$cs_res['full2'] = LBB_Frontend::book( $cs_full );
	$cs_res['fulln'] = count( $cs_tok() );
	// „Înapoi” de la plată și „Rezerv, achit la urcare” pentru aceeași cursă: rezervarea se face, iar biletul neplătit
	// iese din coș (altfel clientul ar plăti și cursa rezervată, iar locurile ar fi ținute de două ori).
	$cs_hold         = $cs_tok();
	update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_reserve' => 1, 'reserve_limit' => 0 ) ) );
	$cs_res['rsv']   = LBB_Frontend::book( array_merge( $cs_full, array( 'lbb_mode' => 'reserve' ) ) );
	$cs_res['rsvn']  = count( $cs_tok() );
	$cs_res['rsvo']  = $cs_hold ? LBB_Bookings::get_by_token( $cs_hold[0] )['status'] : null;
	WC()->cart->empty_cart();
	update_option( 'lbb_settings', $cs_keep );
	$wpdb->delete( LBB_Bookings::table(), array( 'route_id' => $cs_r ) );
	LBB_Routes::delete( $cs_r );
}
lbb_t( 'aceeași cursă trimisă din nou cu „Achit online” înlocuiește biletul din coș (fără plată dublă), locurile vechi se eliberează, și pe autocarul plin cu propriul bilet; altă oră în aceeași zi înlocuiește și ea, iar „Rezerv” scoate din coș biletul neplătit al aceleiași curse; altă zi sau tur-retur se adaugă', true === ( $cs_res['a'] ?? null ) && true === ( $cs_res['b'] ?? null ) && true === ( $cs_res['other'] ?? null ) && array( 1, 1, 2 ) === ( $cs_res['n'] ?? null ) && 'cancelled' === $cs_res['old'] && 2 === $cs_res['new'] && true === $cs_res['time'] && isset( $cs_t3 ) && 1 === count( $cs_t3 ) && '10:00' === $cs_res['timeb'] && true === $cs_res['full1'] && true === $cs_res['full2'] && 1 === $cs_res['fulln'] && is_string( $cs_res['rsv'] ) && false !== strpos( $cs_res['rsv'], 'lbb_bilet=' ) && 0 === $cs_res['rsvn'] && 'cancelled' === $cs_res['rsvo'], array_map( function ( $v ) {
	return is_wp_error( $v ) ? $v->get_error_message() : $v;
}, $cs_res ) );
lbb_t( 'checkout cu blocuri: cu „Plată simplificată” și doar bilete în coș, strada, orașul și codul poștal sunt ascunse și opționale, telefonul obligatoriu (WooCommerce 9+ îl face opțional); cu coșul gol, adresa rămâne obligatorie', isset( $cs_res['loc'], $cs_res['loc0'] ) && array( false, true, false, true, true ) === $cs_res['loc'] && array( true, false ) === $cs_res['loc0'], array( isset( $cs_res['loc'] ) ? $cs_res['loc'] : null, isset( $cs_res['loc0'] ) ? $cs_res['loc0'] : null ) );
$desc = LBB_WooCommerce::describe( $rv );
lbb_t( 'biletul rezervat arată suma la urcare', isset( $desc['De achitat la urcare'] ) && "120\u{00A0}RON" === $desc['De achitat la urcare'], $desc );
// Titlul paginii biletului = numele propus la „Salvare ca PDF”: cu ruta și data, nu doar codul.
$pt_b  = LBB_Bookings::get( $rv['id'] );
$pt_r  = LBB_Routes::get( $pt_b['route_id'] );
$pt_t  = LBB_Tickets::page_title( $pt_b );
$pt_ok = 0 === strpos( $pt_t, $pt_b['ticket_code'] . ' · ' . $pt_r['origin'] . ' → ' . $pt_r['destination'] . ' · ' . gmdate( 'd.m.Y', strtotime( $pt_b['travel_date'] . ' 12:00' ) ) . ' — ' );
lbb_t( 'titlul paginii biletului (numele PDF-ului salvat) are codul, ruta și data; link greșit: „Bilet negăsit — site”', $pt_ok && 'Bilet negăsit — ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) === LBB_Tickets::page_title( null ), array( $pt_t, LBB_Tickets::page_title( null ) ) );
// Fișierul de calendar descărcat de pe bilet are și ruta și data în nume (ca lista de pasageri), fără diacritice.
$if_n = LBB_Tickets::ics_filename( $pt_b );
$if_h = wp_remote_retrieve_header( wp_remote_get( add_query_arg( 'ics', 1, LBB_Tickets::url( $pt_b['ticket_code'] ) ), array( 'timeout' => 20 ) ), 'content-disposition' );
lbb_t( 'fișierul .ics de pe bilet: „COD-ruta-data.ics”, fără diacritice, și chiar așa se descarcă', $if_n === $pt_b['ticket_code'] . '-' . sanitize_title( remove_accents( $pt_r['origin'] . ' ' . $pt_r['destination'] ) ) . '-' . $pt_b['travel_date'] . '.ics' && ! preg_match( '/[^\x20-\x7e]/', $if_n ) && false !== strpos( (string) $if_h, 'filename="' . $if_n . '"' ), array( $if_n, $if_h ) );
// „Trimite biletul”: titlul (subiectul în aplicația de email) e cel al paginii, cu ruta și data, nu doar codul.
preg_match( '/data-share-title="([^"]*)"/', wp_remote_retrieve_body( wp_remote_get( LBB_Tickets::url( $pt_b['ticket_code'] ), array( 'timeout' => 20 ) ) ), $st_m );
lbb_t( '„Trimite biletul” are ca titlu (subiectul emailului) codul, ruta și data', isset( $st_m[1] ) && html_entity_decode( $st_m[1], ENT_QUOTES, 'UTF-8' ) === $pt_t, isset( $st_m[1] ) ? $st_m[1] : null );
// Cititorul de ecran (navigare după titluri) află din titlul paginii și ce bilet e, nu doar numele site-ului.
preg_match( '/<h1[^>]*>(.*?)<\/h1>/s', wp_remote_retrieve_body( wp_remote_get( LBB_Tickets::url( $pt_b['ticket_code'] ), array( 'timeout' => 20 ) ) ), $h1_m );
lbb_t( 'pagina biletului: titlul (h1) are pentru cititorul de ecran și „Rezervare / Bilet” cu codul', isset( $h1_m[1] ) && false !== strpos( $h1_m[1], '<span class="sr"> — Rezervare ' . $pt_b['ticket_code'] . '</span>' ), isset( $h1_m[1] ) ? $h1_m[1] : null );
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
lbb_t( 'un bilet anulat dintr-o zi trecută rămâne „anulat”', 'Bilet anulat' === LBB_Tickets::state( array( 'status' => 'cancelled', 'travel_date' => $tk_yday, 'order_id' => 123 ) )['text'] );
// „Adaugă în calendar” doar până la plecare: deschis după ce autocarul a plecat (aceeași zi), linkul nu mai apare.
$ac_now  = new DateTimeImmutable( 'now', LBB_Settings::tz() );
$ac_gone = $ac_now->modify( '-1 minute' );
$ac_base = array( 'status' => 'confirmed', 'order_id' => 77, 'ticket_code' => 'LB-TESTCA', 'route_id' => $rid, 'seats' => 1, 'children' => 0, 'passengers' => array(), 'currency' => 'MDL', 'pay_currency' => 'MDL', 'amount' => 100 );
$ac_left = array_merge( $ac_base, array( 'travel_date' => $ac_gone->format( 'Y-m-d' ), 'dep_time' => $ac_gone->format( 'H:i' ) ) );
$ac_next = array_merge( $ac_base, array( 'travel_date' => $ac_now->modify( '+1 day' )->format( 'Y-m-d' ), 'dep_time' => '10:00' ) );
lbb_t( '„Adaugă în calendar” lipsește după plecarea autocarului (aceeași zi) și apare pentru o cursă care urmează', false === strpos( LBB_Tickets::html( $ac_left ), 'ics=1' ) && false !== strpos( LBB_Tickets::html( $ac_next ), 'ics=1' ), array( $ac_left['travel_date'] . ' ' . $ac_left['dep_time'] ) );
lbb_t( 'la printare biletul nu se rupe între două pagini (și în email, cu stil inline)', 1 === preg_match( '/<div class="lbb-ticket" style="[^"]*page-break-inside:avoid;break-inside:avoid;/', LBB_Tickets::html( $ac_next ) ) );
// Și în email (fără media queries): eticheta nu e „nowrap” și ia cel mult 38%, ca pe telefon numele să aibă loc.
lbb_t( 'biletul (și în email): eticheta ia cel mult 38% și poate trece pe două rânduri, valoarea are loc pe telefon', (bool) preg_match_all( '/<th [^>]*style="[^"]*width:38%;[^"]*">/', LBB_Tickets::html( $rv ) ) && false === strpos( LBB_Tickets::html( $rv ), 'nowrap' ) );
// Rută ștearsă din admin (rezervările rămân): fără „Adaugă în calendar”, care n-ar avea ce cursă să descrie.
$ac_gone_r = array_merge( $ac_next, array( 'route_id' => 987654 ) );
lbb_t( 'rută ștearsă: biletul nu mai are „Adaugă în calendar” (linkul doar reîncărca pagina)', '' === LBB_Tickets::ics( $ac_gone_r ) && false === strpos( LBB_Tickets::html( $ac_gone_r ), 'ics=1' ) && ! LBB_Tickets::can_add_to_calendar( $ac_gone_r ) );
// Rezervarea cu plata la urcare anulată de birou (fără comandă) rămâne „rezervare”: clientul n-a avut bilet.
$ra_b = array( 'status' => 'cancelled', 'travel_date' => $tk_yday, 'order_id' => 0, 'ticket_code' => 'LB-TESTRA', 'route_id' => 0, 'seats' => 1, 'children' => 0, 'passengers' => array(), 'dep_time' => '10:00' );
$ra_h = LBB_Tickets::html( $ra_b );
lbb_t( 'rezervare anulată: „Rezervare anulată” și „Rezervare LibertBus”, nu „Bilet anulat”; biletul plătit anulat rămâne „Bilet”', 'Rezervare anulată' === LBB_Tickets::state( $ra_b )['text'] && false !== strpos( $ra_h, 'Rezervare LibertBus' ) && false === strpos( $ra_h, 'Bilet LibertBus' ) && LBB_Tickets::is_reservation( array( 'status' => 'reserved', 'order_id' => 0 ) ) && ! LBB_Tickets::is_reservation( array( 'status' => 'cancelled', 'order_id' => 55 ) ) && ! LBB_Tickets::is_reservation( array( 'status' => 'confirmed', 'order_id' => 55 ) ), array( LBB_Tickets::state( $ra_b ), substr( wp_strip_all_tags( $ra_h ), 0, 60 ) ) );
// Alt pasager pe aceeași cursă = rezervare nouă (o rezervare identică e doar formularul trimis din nou, vezi mai jos).
$res2 = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '+37369000002', 'lbb_names' => array( 'Maria' ) ) ) );
lbb_t( 'a doua rezervare neachitată pe același telefon e refuzată', is_wp_error( $res2 ) && 'lbb_limit' === $res2->get_error_code(), $res2 );
$res2b = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '069 000 002', 'lbb_names' => array( 'Maria' ) ) ) );
lbb_t( 'același număr scris local (069…) e prins de limită', is_wp_error( $res2b ) && 'lbb_limit' === $res2b->get_error_code(), $res2b );
// Aceeași rezervare trimisă din nou („Înapoi” și iar „Rezerv”, reîncercare pe internet lent), și cu numărul scris
// local: clientul ajunge la rezervarea făcută deja, fără una nouă, fără emailuri duble și fără „limită atinsă”.
$tw_n     = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LBB_Bookings::table() . ' WHERE phone = %s', '+37369000002' ) );
};
$tw_n0    = $tw_n();
$tw_mails = array();
$tw_catch = function ( $null, $atts ) use ( &$tw_mails ) {
	$tw_mails[] = $atts['to'];
	return true;
};
add_filter( 'pre_wp_mail', $tw_catch, 1, 2 );
$tw_again = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '+37369000002', 'lbb_currency' => 'MDL' ) ) );
$tw_local = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '069 000 002', 'lbb_currency' => 'MDL' ) ) );
remove_filter( 'pre_wp_mail', $tw_catch, 1 );
$tw_more  = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '+37369000002', 'lbb_adults' => 2, 'lbb_names' => array( 'Ion', 'Ion' ) ) ) );
lbb_t( 'aceeași rezervare trimisă din nou duce la rezervarea făcută deja: fără una nouă și fără emailuri; alt număr de pasageri nu e „aceeași”', is_string( $res1 ) && $res1 === $tw_again && $res1 === $tw_local && $tw_n() === $tw_n0 && ! $tw_mails && is_wp_error( $tw_more ) && 'lbb_limit' === $tw_more->get_error_code(), array( $res1, $tw_again, $tw_local, $tw_n0, $tw_n(), $tw_mails, $tw_more ) );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_reserve' => 0 ) ) );
$res3 = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_phone' => '+37369000003' ) ) );
lbb_t( 'rezervarea oprită din setări e refuzată', is_wp_error( $res3 ) && 'lbb_mode' === $res3->get_error_code(), $res3 );
update_option( 'lbb_settings', $saved );

// Cursul valutar golit din greșeală sau 0 păstrează cursul de dinainte (altfel 60 RON ar deveni 0,01 MDL); „4,1” e 4.1.
$rt_keep = get_option( 'lbb_settings' );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'rate_RON' => 3.9 ) ) );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'rate_RON' => '' ) ) );
$rt_empty = LBB_Settings::get( 'rate_RON' );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'rate_RON' => '0' ) ) );
$rt_zero = LBB_Settings::get( 'rate_RON' );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'rate_RON' => '4,1' ) ) );
$rt_comma = LBB_Settings::get( 'rate_RON' );
lbb_t( 'curs valutar gol sau 0 păstrează cursul anterior; „4,1” se salvează 4.1', 3.9 === (float) $rt_empty && 3.9 === (float) $rt_zero && 4.1 === (float) $rt_comma, array( $rt_empty, $rt_zero, $rt_comma ) );
// La fel pentru „Locuri online pe cursă (implicit)”: gol sau 0 ar face toate cursele „complet”.
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'default_capacity' => 18 ) ) );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'default_capacity' => '' ) ) );
$dc_empty = LBB_Settings::get( 'default_capacity' );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'default_capacity' => '0' ) ) );
$dc_zero = LBB_Settings::get( 'default_capacity' );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'default_capacity' => '25' ) ) );
lbb_t( 'locurile implicite goale sau 0 păstrează valoarea anterioară; 25 se salvează', 18 === (int) $dc_empty && 18 === (int) $dc_zero && 25 === (int) LBB_Settings::get( 'default_capacity' ), array( $dc_empty, $dc_zero, LBB_Settings::get( 'default_capacity' ) ) );
// Greșeli de tastare în sus („1500” minute în loc de „15”): plafonate, altfel locurile ar sta blocate o zi.
$mx_keep = get_option( 'lbb_settings' );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'cart_hold_minutes' => '1500', 'payment_minutes' => '5000', 'max_days_ahead' => '9999', 'cutoff_minutes' => '6000' ) ) );
$mx_got = array( (int) LBB_Settings::get( 'cart_hold_minutes' ), (int) LBB_Settings::get( 'payment_minutes' ), (int) LBB_Settings::get( 'max_days_ahead' ), (int) LBB_Settings::get( 'cutoff_minutes' ) );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'cart_hold_minutes' => '45', 'payment_minutes' => '60', 'max_days_ahead' => '180', 'cutoff_minutes' => '90' ) ) );
$mx_ok = array( (int) LBB_Settings::get( 'cart_hold_minutes' ), (int) LBB_Settings::get( 'payment_minutes' ), (int) LBB_Settings::get( 'max_days_ahead' ), (int) LBB_Settings::get( 'cutoff_minutes' ) );
update_option( 'lbb_settings', $mx_keep );
lbb_t( 'Setări: minutele în coș / la plată, zilele înainte și închiderea vânzării prea mari se plafonează (120, 240, 365, 2880); valorile normale rămân', array( 120, 240, 365, 2880 ) === $mx_got && array( 45, 60, 180, 90 ) === $mx_ok, array( $mx_got, $mx_ok ) );
// La fel pentru zilele de vânzare înainte și pasagerii pe rezervare (altfel devin 1: o zi, un pasager).
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'max_days_ahead' => 60, 'max_passengers' => 6 ) ) );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'max_days_ahead' => '', 'max_passengers' => '0' ) ) );
$md_blank = array( LBB_Settings::get( 'max_days_ahead' ), LBB_Settings::get( 'max_passengers' ) );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'max_days_ahead' => '30', 'max_passengers' => '4' ) ) );
$md_set = array( LBB_Settings::get( 'max_days_ahead' ), LBB_Settings::get( 'max_passengers' ) );
lbb_t( 'zilele înainte și pasagerii pe rezervare goi sau 0 păstrează valoarea anterioară; 30 și 4 se salvează', array( 60, 6 ) === array_map( 'intval', $md_blank ) && array( 30, 4 ) === array_map( 'intval', $md_set ), array( $md_blank, $md_set ) );
update_option( 'lbb_settings', $rt_keep );

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

// Orașele în ordinea alfabetică românească (nu pe octeți, unde „Bălți” ajunge după „Buzău”), în formular și în lista din admin.
$ab_l = array( 'Roman', 'Buzău', 'Rîșcani', 'Bălți', 'Ștefan Vodă', 'Bacău', 'Sovata', 'Țiganca', 'Tiraspol' );
usort( $ab_l, array( 'LBB_Routes', 'compare_cities' ) );
$ab_m = LBB_Routes::public_map();
$ab_k = array_keys( $ab_m );
$ab_s = $ab_k;
usort( $ab_s, array( 'LBB_Routes', 'compare_cities' ) );
$ab_d = true;
foreach ( $ab_m as $ab_list ) {
	$ab_t = wp_list_pluck( $ab_list, 'to' );
	$ab_u = $ab_t;
	usort( $ab_u, array( 'LBB_Routes', 'compare_cities' ) );
	$ab_d = $ab_d && $ab_t === $ab_u;
}
lbb_t( 'orașe în ordine alfabetică românească: „Bălți” înainte de „Brașov”, „Rîșcani” înainte de „Roman”, „Tiraspol” înainte de „Țiganca”, la plecări și destinații', array( 'Bacău', 'Bălți', 'Buzău', 'Rîșcani', 'Roman', 'Sovata', 'Ștefan Vodă', 'Tiraspol', 'Țiganca' ) === $ab_l && count( $ab_k ) > 1 && $ab_k === $ab_s && $ab_d, array( $ab_l, $ab_k ) );

// Erorile din formular care spun „sunați la …” au telefonul ca link de apel; restul textului rămâne escapat.
$al_err  = LBB_Bookings::create_hold( LBB_Routes::get( $rid ), $tomorrow, '10:00', 99, 0, array(), '+37369000000', 'a@example.com' );
$al_html = is_wp_error( $al_err ) ? LBB_Frontend::alert_html( $al_err->get_error_message() ) : '';
$al_xss  = LBB_Frontend::alert_html( '<b>x</b> sunați la ' . LBB_Settings::phone_text() );
lbb_t( 'mesajul de eroare din formular are telefonul ca link de apel și restul escapat', false !== strpos( $al_html, '<a class="lbb-phone" href="tel:' . preg_replace( '/[^\d+]/', '', LBB_Settings::get( 'support_phone' ) ) . '">' ) && 0 === strpos( $al_xss, '&lt;b&gt;x&lt;/b&gt;' ) && 1 === substr_count( $al_xss, '<a ' ), array( $al_html, $al_xss ) );

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
// Nota pentru grupuri în română corectă, oricare ar fi limita din Setări.
$gn_txt = array();
foreach ( array( 8, 20, 1 ) as $gn_n ) {
	update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_reserve' => 1, 'max_passengers' => $gn_n ) ) );
	preg_match( '/data-lbb-config="([^"]+)"/', LBB_Frontend::shortcode( array() ), $gn_m );
	$gn_c = $gn_m ? json_decode( base64_decode( html_entity_decode( $gn_m[1], ENT_QUOTES | ENT_HTML401, 'UTF-8' ) ), true ) : null;
	$gn_txt[ $gn_n ] = is_array( $gn_c ) ? $gn_c['i18n']['groupNote'] : '';
}
lbb_t( 'nota pentru grupuri: „mai mult de 8 persoane”, „20 de persoane”, „1 persoană”', 0 === strpos( $gn_txt[8], 'Sunteți mai mult de 8 persoane?' ) && 0 === strpos( $gn_txt[20], 'Sunteți mai mult de 20 de persoane?' ) && 0 === strpos( $gn_txt[1], 'Sunteți mai mult de 1 persoană?' ), $gn_txt );
// Refuzul de pe server pentru prea mulți pasageri, tot în română corectă („20 de locuri”, „1 loc”).
$ms_txt = array();
foreach ( array( 20, 1 ) as $ms_n ) {
	update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_reserve' => 1, 'max_passengers' => $ms_n ) ) );
	$ms_r            = LBB_Frontend::book( array_merge( $base, array( 'lbb_mode' => 'reserve', 'lbb_adults' => $ms_n + 1, 'lbb_children' => 0, 'lbb_names' => array_fill( 0, $ms_n + 1, 'Ion Popescu' ) ) ) );
	$ms_txt[ $ms_n ] = is_wp_error( $ms_r ) ? $ms_r->get_error_message() : 'nerefuzat';
}
lbb_t( 'prea mulți pasageri: „cel mult 20 de locuri odată”, „cel mult 1 loc odată”', 0 === strpos( $ms_txt[20], 'Online se pot rezerva cel mult 20 de locuri odată.' ) && 0 === strpos( $ms_txt[1], 'Online se pot rezerva cel mult 1 loc odată.' ), $ms_txt );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_pay' => 0, 'allow_reserve' => 0, 'max_passengers' => $keep['max_passengers'] ?? 8 ) ) );
lbb_t( 'fără niciun buton, formularul arată doar telefonul', false === strpos( LBB_Frontend::shortcode( array() ), '<form' ) );
update_option( 'lbb_settings', $keep );

// Linkul secret de previzualizare: stabil până se cere unul nou.
$tok = LBB_Settings::preview_token();
lbb_t( 'cheia de previzualizare e stabilă și lungă', strlen( $tok ) >= 16 && LBB_Settings::preview_token() === $tok );
$tok2 = LBB_Settings::preview_token( true );
lbb_t( 'link nou de previzualizare anulează cheia veche', $tok2 !== $tok && LBB_Settings::preview_token() === $tok2 );
lbb_t( 'linkul de previzualizare conține cheia', false !== strpos( LBB_Settings::preview_url(), 'lbb_preview=' . $tok2 ) );

// Politica de cookies spune adevărul despre cookie-ul de acord: același nume și aceeași durată ca în cookies.js
// (dacă se schimbă durata în cod, testul cere și actualizarea paginii legale).
$ck_js = (string) file_get_contents( LBB_DIR . 'assets/cookies.js' );
$ck_nm = preg_match( "/var NAME = '([a-z_]+)'/", $ck_js, $ck_a ) ? $ck_a[1] : '';
$ck_dd = preg_match( '/var DAYS = (\d+);/', $ck_js, $ck_b ) ? (int) $ck_b[1] : 0;
$ck_m  = new ReflectionMethod( 'LBB_Legal', 'content' );
$ck_m->setAccessible( true );
$ck_tx = (string) $ck_m->invoke( null, 'cookies' );
$ck_mo = (int) round( $ck_dd / 30 );
lbb_t( 'Politica de cookies: cookie-ul de acord are numele și durata din cookies.js (' . $ck_nm . ', ' . $ck_dd . ' zile = ' . $ck_mo . ' luni)', '' !== $ck_nm && $ck_dd > 0 && (bool) preg_match( '#<strong>' . preg_quote( $ck_nm, '#' ) . '</strong>[^<]*Durata: ' . $ck_mo . ' luni#u', $ck_tx ), preg_match( '#<li><strong>' . preg_quote( $ck_nm, '#' ) . '.*?</li>#u', $ck_tx, $ck_c ) ? $ck_c[0] : '' );

// Termenul de păstrare din politica de confidențialitate e cel aplicat de curățenie (filtrul lbb_retention_years).
$rp_3 = (string) $ck_m->invoke( null, 'privacy' );
$rp_f = function () {
	return 5;
};
add_filter( 'lbb_retention_years', $rp_f );
$rp_5  = (string) $ck_m->invoke( null, 'privacy' );
$rp_y5 = LBB_Bookings::retention_years();
remove_filter( 'lbb_retention_years', $rp_f );
lbb_t( 'politica de confidențialitate spune termenul aplicat de curățenia automată (3 ani; 5 ani dacă filtrul îl schimbă)', 3 === LBB_Bookings::retention_years() && 5 === $rp_y5 && false !== strpos( $rp_3, 'Păstrare: 3 ani de la data călătoriei' ) && false !== strpos( $rp_5, 'Păstrare: 5 ani de la data călătoriei' ) && false === strpos( $rp_5, '3 ani de la data' ), preg_match( '/Păstrare: [^<]*călătoriei/u', $rp_5, $rp_m ) ? $rp_m[0] : '' );

// Termenii: „se închide cu 60 de minute”, dar „cu 15 minute” (nu „15 de minute”), după închiderea din Setări.
$tc_keep = get_option( 'lbb_settings' );
$tc_txt  = array();
foreach ( array( 15, 60 ) as $tc_n ) {
	update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'cutoff_minutes' => $tc_n ) ) );
	$tc_txt[ $tc_n ] = (string) $ck_m->invoke( null, 'terms' );
}
update_option( 'lbb_settings', $tc_keep );
lbb_t( 'Termenii: închiderea vânzării în română corectă („cu 15 minute”, „cu 60 de minute”)', false !== strpos( $tc_txt[15], 'se închide cu 15 minute înainte' ) && false !== strpos( $tc_txt[60], 'se închide cu 60 de minute înainte' ), array( preg_match( '/se închide cu [^.]*/u', $tc_txt[15], $tc_m ) ? $tc_m[0] : '' ) );

// Panoul semnalează erorile PHP afișate vizitatorilor (WP_DEBUG cu WP_DEBUG_DISPLAY, implicit pornit).
$dbg_row = null;
foreach ( LBB_Admin::checklist() as $dbg_it ) {
	if ( 'debug' === $dbg_it['id'] ) {
		$dbg_row = $dbg_it;
	}
}
lbb_t( 'panoul semnalează erorile PHP afișate vizitatorilor: WP_DEBUG cu afișare (și implicit, fără WP_DEBUG_DISPLAY) da; afișare oprită sau WP_DEBUG oprit nu', LBB_Admin::errors_shown( true, true ) && LBB_Admin::errors_shown( true, 1 ) && ! LBB_Admin::errors_shown( true, false ) && ! LBB_Admin::errors_shown( false, true ) && $dbg_row && true === $dbg_row['ok'], $dbg_row );
// Emailul WooCommerce care duce biletul („Comandă finalizată” cu finalizarea automată) oprit: panoul îl semnalează.
$te_key  = 'woocommerce_customer_completed_order_settings';
$te_keep = get_option( $te_key, null );
$te_find = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'ticket_email' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
$te_auto = LBB_Settings::get( 'autocomplete' );
update_option( $te_key, array_merge( is_array( $te_keep ) ? $te_keep : array(), array( 'enabled' => 'no' ) ) );
$te_off = $te_find();
update_option( $te_key, array_merge( is_array( $te_keep ) ? $te_keep : array(), array( 'enabled' => 'yes' ) ) );
$te_on = $te_find();
null === $te_keep ? delete_option( $te_key ) : update_option( $te_key, $te_keep );
lbb_t( 'panoul semnalează emailul WooCommerce cu biletul oprit („Comandă finalizată”), cu link spre setările lui', $te_auto && $te_off && false === $te_off['ok'] && false !== strpos( $te_off['fix'], 'section=wc_email_customer_completed_order' ) && $te_on && true === $te_on['ok'], array( $te_off, $te_on ) );
// Panoul: întâi ce lipsește (✘), apoi ce e de verificat (!), apoi ce e gata (✔), cu numărul lor în titlu.
$pd_keep = get_option( 'woocommerce_email_from_address', null );
update_option( 'woocommerce_email_from_address', 'birou@gmail.com' ); // Cel puțin un „de verificat”.
ob_start();
LBB_Admin::page_dashboard();
$pd_html = ob_get_clean();
null === $pd_keep ? delete_option( 'woocommerce_email_from_address' ) : update_option( 'woocommerce_email_from_address', $pd_keep );
$pd_cls  = array();
if ( preg_match_all( '/<td style="width:24px" class="(lbb-(?:ok|warn|bad))"/', $pd_html, $pd_m ) ) {
	$pd_cls = $pd_m[1];
}
$pd_rank = array_map( function ( $c ) {
	return array( 'lbb-bad' => 0, 'lbb-warn' => 1, 'lbb-ok' => 2 )[ $c ];
}, $pd_cls );
$pd_sorted = $pd_rank;
sort( $pd_sorted );
$pd_nb = count( array_keys( $pd_cls, 'lbb-bad', true ) );
$pd_nw = count( array_keys( $pd_cls, 'lbb-warn', true ) );
// Pe fiecare rând: „Rezolvă” la ce lipsește, „Verifică” la ce e de verificat (cu titlul pentru cititorul de ecran).
$pd_rows = preg_split( '/<tr>/', $pd_html );
$pd_btn  = true;
foreach ( $pd_rows as $pd_r ) {
	if ( false !== strpos( $pd_r, 'class="lbb-warn"' ) && false !== strpos( $pd_r, 'class="button"' ) ) {
		$pd_btn = $pd_btn && false !== strpos( $pd_r, '>Verifică</a>' ) && false !== strpos( $pd_r, 'aria-label="Verifică: ' );
	}
	if ( false !== strpos( $pd_r, 'class="lbb-bad"' ) && false !== strpos( $pd_r, 'class="button"' ) ) {
		$pd_btn = $pd_btn && false !== strpos( $pd_r, '>Rezolvă</a>' );
	}
}
lbb_t( 'panoul: butonul spune „Verifică” la avertismente și „Rezolvă” la ce lipsește', $pd_btn && false !== strpos( $pd_html, '>Verifică</a>' ), null );
lbb_t( 'panoul „Gata de plăți?”: problemele sunt primele (lipsă, apoi de verificat, apoi gata), cu numărul lor în titlu', count( $pd_cls ) === count( LBB_Admin::checklist() ) && $pd_rank === $pd_sorted && $pd_nw >= 1
	&& false !== strpos( $pd_html, $pd_nw . ' de verificat' ) && ( 0 === $pd_nb || false !== strpos( $pd_html, $pd_nb . ' de rezolvat' ) ), array( $pd_cls, $pd_nb, $pd_nw ) );

// Emailul „Comandă nouă” trebuie să ajungă la biroul din Setări: altfel (sau oprit) panoul avertizează.
$on_key  = 'woocommerce_new_order_settings';
$on_keep = get_option( $on_key, null );
$on_find = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'office_new_order' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
$on_off_mail = LBB_Settings::office_email();
update_option( $on_key, array( 'enabled' => 'yes', 'recipient' => 'altcineva@example.com' ) );
$on_other = $on_find();
update_option( $on_key, array( 'enabled' => 'yes', 'recipient' => 'altcineva@example.com, ' . strtoupper( $on_off_mail ) ) );
$on_both = $on_find();
update_option( $on_key, array( 'enabled' => 'no', 'recipient' => $on_off_mail ) );
$on_disabled = $on_find();
null === $on_keep ? delete_option( $on_key ) : update_option( $on_key, $on_keep );
lbb_t( 'panoul avertizează când emailul „Comandă nouă” nu ajunge la biroul din Setări sau e oprit', $on_other && 'warn' === $on_other['ok'] && false !== strpos( $on_other['detail'], $on_off_mail ) && $on_both && true === $on_both['ok'] && $on_disabled && 'warn' === $on_disabled['ok'], array( $on_other, $on_both, $on_disabled ) );

// Expeditorul emailurilor WooCommerce pe Gmail (DMARC): panoul avertizează; cu o adresă a site-ului nu.
$mf_keep = get_option( 'woocommerce_email_from_address', null );
$mf_find = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'mail_from' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
update_option( 'woocommerce_email_from_address', 'birou.libertbus@Gmail.com' );
$mf_gm = $mf_find();
update_option( 'woocommerce_email_from_address', 'bilete@libertbus.md' );
$mf_ok = $mf_find();
null === $mf_keep ? delete_option( 'woocommerce_email_from_address' ) : update_option( 'woocommerce_email_from_address', $mf_keep );
lbb_t( 'panoul avertizează când emailurile cu biletul pleacă „de la” Gmail (respinse des, DMARC); cu o adresă a site-ului e în regulă', $mf_gm && 'warn' === $mf_gm['ok'] && false !== strpos( $mf_gm['detail'], 'gmail.com' ) && $mf_ok && true === $mf_ok['ok'], array( $mf_gm, $mf_ok ) );
// WooCommerce 9+ în modul „În curând”: panoul îl semnalează (coșul și plata ascunse); fără opțiune (WooCommerce 8) nu apare.
$cs_keep = get_option( 'woocommerce_coming_soon', null );
$cs_find = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'coming_soon' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
update_option( 'woocommerce_coming_soon', 'yes' );
$cs_on = $cs_find();
update_option( 'woocommerce_coming_soon', 'no' );
$cs_off = $cs_find();
delete_option( 'woocommerce_coming_soon' );
$cs_none = $cs_find();
null === $cs_keep ? delete_option( 'woocommerce_coming_soon' ) : update_option( 'woocommerce_coming_soon', $cs_keep );
lbb_t( 'panoul semnalează magazinul în modul „În curând” (WooCommerce 9+), cu linkul spre Vizibilitatea site-ului; fără opțiune nu apare', $cs_on && false === $cs_on['ok'] && false !== strpos( $cs_on['fix'], 'tab=site-visibility' ) && '' !== $cs_on['detail'] && $cs_off && true === $cs_off['ok'] && null === $cs_none, array( $cs_on, $cs_off, $cs_none ) );
lbb_t( 'panoul semnalează PHP fără actualizări de securitate (7.4, 8.0); 8.1 și mai nou nu', LBB_Admin::php_outdated( '7.4.33' ) && LBB_Admin::php_outdated( '8.0.30' ) && ! LBB_Admin::php_outdated( '8.1.0' ) && ! LBB_Admin::php_outdated( '8.3.6' ) && in_array( 'php', wp_list_pluck( LBB_Admin::checklist(), 'id' ), true ) );
// Rândul apare doar cui poate face actualizările (altora WordPress le arată 0 actualizări, deci „verde” pe nedrept).
$upd_user = get_current_user_id();
wp_set_current_user( 1 );
$upd_rows = array( in_array( 'updates', wp_list_pluck( LBB_Admin::checklist(), 'id' ), true ) );
wp_set_current_user( 0 );
$upd_rows[] = in_array( 'updates', wp_list_pluck( LBB_Admin::checklist(), 'id' ), true );
wp_set_current_user( $upd_user );
lbb_t( 'panoul semnalează actualizările în așteptare în română corectă („WordPress, 3 pluginuri, 1 temă”, „20 de pluginuri”); fără ele, nimic; rândul doar pentru cine poate actualiza', 'WordPress, 3 pluginuri, 1 temă' === LBB_Admin::updates_pending( array( 'counts' => array( 'wordpress' => 1, 'plugins' => 3, 'themes' => 1 ) ) ) && '20 de pluginuri' === LBB_Admin::updates_pending( array( 'counts' => array( 'plugins' => 20 ) ) ) && '' === LBB_Admin::updates_pending( array( 'counts' => array( 'wordpress' => 0, 'plugins' => 0, 'themes' => 0, 'translations' => 2 ) ) ) && $upd_rows[0] && ! $upd_rows[1], LBB_Admin::updates_pending( array( 'counts' => array( 'wordpress' => 1, 'plugins' => 3, 'themes' => 1 ) ) ) );
// „Trimite un email de test”: reușit → mesaj verde cu adresa biroului; email care nu pleacă → mesaj de eroare.
$tm_keep = array( $_POST, $_REQUEST, get_current_user_id() );
$tm_go   = function ( $ok ) {
	$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'lbb_test_mail' ) );
	$sent     = array();
	$mail     = function ( $null, $atts ) use ( $ok, &$sent ) {
		$sent[] = $atts;
		return $ok;
	};
	$loc      = '';
	$cb       = function ( $l ) use ( &$loc ) {
		$loc = $l;
		throw new Exception( 'redirect' );
	};
	add_filter( 'pre_wp_mail', $mail, 99, 2 ); // După filtrul care salvează emailurile în mediul de test.
	add_filter( 'wp_redirect', $cb, 1 );
	try {
		LBB_Admin::test_mail();
	} catch ( Exception $e ) {
		unset( $e );
	}
	remove_filter( 'wp_redirect', $cb, 1 );
	remove_filter( 'pre_wp_mail', $mail, 99 );
	parse_str( (string) wp_parse_url( $loc, PHP_URL_QUERY ), $q );
	return array( isset( $q['lbb_msg'] ) ? rawurldecode( $q['lbb_msg'] ) : '', ! empty( $q['lbb_err'] ), $sent );
};
$tm_opt  = get_option( 'lbb_mail_test' );
$tm_row  = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'mail' === $it['id'] ) {
			return $it['detail'];
		}
	}
	return '';
};
delete_option( 'lbb_mail_test' );
$tm_none = $tm_row();
wp_set_current_user( 1 );
$tm_ok   = $tm_go( true );
$tm_done = $tm_row();
$tm_bad  = $tm_go( false );
$tm_kept = $tm_row();
false === $tm_opt ? delete_option( 'lbb_mail_test' ) : update_option( 'lbb_mail_test', $tm_opt, false );
list( $_POST, $_REQUEST ) = $tm_keep;
wp_set_current_user( $tm_keep[2] );
$tm_to = LBB_Settings::office_email();
lbb_t( 'panoul spune dacă emailul a fost testat: „Încă netestat” până la primul email de test reușit, apoi data și adresa (un test eșuat nu le schimbă)', false !== strpos( $tm_none, 'Încă netestat' ) && false !== strpos( $tm_done, 'Ultimul email de test: ' ) && false !== strpos( $tm_done, ', la ' . $tm_to . '.' ) && $tm_kept === $tm_done, array( $tm_none, $tm_done, $tm_kept ) );
lbb_t( 'email de test din Setări: pleacă la birou, de la firmă; reușit → mesaj cu adresa, eșuat → eroare cu ce de verificat', 1 === count( $tm_ok[2] ) && $tm_to === $tm_ok[2][0]['to'] && false !== strpos( $tm_ok[2][0]['subject'], '[LibertBus] Email de test' ) && preg_grep( '/^From: /', (array) $tm_ok[2][0]['headers'] ) && false !== strpos( $tm_ok[0], 'Emailul de test a fost trimis la ' . $tm_to ) && ! $tm_ok[1] && false !== strpos( $tm_bad[0], 'nu a putut fi trimis' ) && false !== strpos( $tm_bad[0], 'SMTP' ) && $tm_bad[1], array( $tm_ok[0], $tm_ok[1], $tm_bad[0], $tm_bad[1] ) );
// Salvarea Setărilor cu „Email pentru clienți” greșit: mesaj de avertizare, nu golire pe tăcute.
$se_keep = array( get_option( 'lbb_settings' ), $_POST, $_REQUEST, get_current_user_id() );
$se_go   = function ( $email, $more = array() ) {
	$_POST    = array_merge( LBB_Settings::all(), array( 'company_email' => $email, '_wpnonce' => wp_create_nonce( 'lbb_save_settings' ) ), $more );
	$_REQUEST = $_POST;
	$loc      = '';
	$cb       = function ( $l ) use ( &$loc ) {
		$loc = $l;
		throw new Exception( 'redirect' );
	};
	add_filter( 'wp_redirect', $cb, 1 );
	try {
		LBB_Admin::save_settings();
	} catch ( Exception $e ) {
		unset( $e );
	}
	remove_filter( 'wp_redirect', $cb, 1 );
	parse_str( (string) wp_parse_url( $loc, PHP_URL_QUERY ), $q );
	return array( isset( $q['lbb_msg'] ) ? rawurldecode( $q['lbb_msg'] ) : '', ! empty( $q['lbb_err'] ) );
};
wp_set_current_user( 1 );
$se_bad  = $se_go( 'birou@gmail' );
$se_typo = $se_go( 'birou@gmail.con' );
$se_ok   = $se_go( 'birou@firma.md' );
$se_inv  = $se_go( 'birou@firma.md', array( 'rate_RON' => '0,256' ) );
$se_norm = $se_go( 'birou@firma.md', array( 'rate_RON' => '3,9' ) );
$se_id12 = $se_go( 'birou@firma.md', array( 'company_idno' => '100360012345' ) );
$se_id13 = $se_go( 'birou@firma.md', array( 'company_idno' => '1003 600 123 456' ) );
$se_id1  = $se_go( 'birou@firma.md', array( 'company_idno' => '7' ) );
$se_id21 = $se_go( 'birou@firma.md', array( 'company_idno' => '100360012345612345678' ) );
$se_id0  = $se_go( 'birou@firma.md', array( 'company_idno' => '' ) );
$se_tl   = $se_go( 'birou@firma.md', array( 'support_phone' => '069 184 111' ) );
$se_ts   = $se_go( 'birou@firma.md', array( 'support_phone' => '+373 69' ) );
$se_ti   = $se_go( 'birou@firma.md', array( 'support_phone' => '+373 691 84 111' ) );
update_option( 'lbb_settings', $se_keep[0] );
list( , $_POST, $_REQUEST ) = $se_keep;
wp_set_current_user( $se_keep[3] );
lbb_t( 'Setări: emailul biroului greșit e semnalat la salvare (nevalid: unde merg emailurile; „gmail.con”: adresa propusă); unul corect nu', false !== strpos( $se_bad[0], '„birou@gmail” de la „Email pentru clienți” nu e validă' ) && $se_bad[1] && false !== strpos( $se_typo[0], 'Ați vrut să scrieți birou@gmail.com?' ) && $se_typo[1] && false === strpos( $se_ok[0], 'Atenție' ) && ! $se_ok[1], array( $se_bad, $se_typo, $se_ok ) );
lbb_t( 'Setări: IDNO-ul fără 13 cifre e semnalat la salvare; 13 cifre (și cu spații) sau gol nu', false !== strpos( $se_id12[0], 'IDNO-ul „100360012345” are 12 cifre, nu 13' ) && $se_id12[1] && false === strpos( $se_id13[0], 'IDNO' ) && ! $se_id13[1] && false === strpos( $se_id0[0], 'IDNO' ) && ! $se_id0[1], array( $se_id12, $se_id13, $se_id0 ) );
lbb_t( 'Setări: avertizarea IDNO în română corectă („1 cifră”, „21 de cifre”)', false !== strpos( $se_id1[0], '„7” are 1 cifră, nu 13' ) && false !== strpos( $se_id21[0], 'are 21 de cifre, nu 13' ), array( $se_id1[0], $se_id21[0] ) );
lbb_t( 'Setări: telefonul pentru clienți incomplet sau fără prefixul țării e semnalat la salvare; +373 … nu', false !== strpos( $se_tl[0], '„069 184 111” nu are prefixul țării' ) && $se_tl[1] && false !== strpos( $se_ts[0], '„+373 69” e incomplet' ) && $se_ts[1] && false === strpos( $se_ti[0], 'telefonul' ) && ! $se_ti[1], array( $se_tl, $se_ts, $se_ti ) );
lbb_t( 'Setări: cursul RON scris invers („0,256” în loc de 3,9) e semnalat la salvare; un curs normal nu', false !== strpos( $se_inv[0], 'cursul RON 0,256 pare scris invers' ) && $se_inv[1] && false === strpos( $se_norm[0], 'invers' ) && ! $se_norm[1], array( $se_inv, $se_norm ) );

// Culoarea butoanelor copiată fără „#” se păstrează; un cod stricat revine la culoarea implicită.
$ac_keep = get_option( 'lbb_settings' );
$ac_got  = array();
foreach ( array( '1e40af', ' #B91C1C ', 'abc', 'zzz', '#12345' ) as $ac_in ) {
	LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'accent_color' => $ac_in ) ) );
	$ac_got[ $ac_in ] = LBB_Settings::get( 'accent_color' );
}
update_option( 'lbb_settings', $ac_keep );
lbb_t( 'Setări: culoarea butoanelor fără „#” („1e40af”) se păstrează; un cod stricat revine la #00875a', array( '1e40af' => '#1e40af', ' #B91C1C ' => '#B91C1C', 'abc' => '#abc', 'zzz' => '#00875a', '#12345' => '#00875a' ) === $ac_got, $ac_got );

// Fără extensia mbstring (unele găzduiri PHP 7.4) WordPress înlocuiește doar mb_substr și mb_strlen: orice altă
// funcție mb_* se folosește doar după function_exists, altfel site-ul ar da eroare fatală.
$mb_bad = array();
foreach ( array_merge( glob( LBB_DIR . 'includes/*.php' ), array( LBB_FILE, LBB_DIR . 'uninstall.php' ) ) as $mb_f ) {
	foreach ( file( $mb_f ) as $mb_n => $mb_line ) {
		if ( preg_match_all( '/\b(mb_[a-z_]+)\s*\(/', $mb_line, $mb_m ) ) {
			foreach ( array_unique( $mb_m[1] ) as $mb_fn ) {
				if ( ! in_array( $mb_fn, array( 'mb_substr', 'mb_strlen' ), true ) && false === strpos( $mb_line, "function_exists( '" . $mb_fn . "' )" ) ) {
					$mb_bad[] = basename( $mb_f ) . ':' . ( $mb_n + 1 ) . ' ' . $mb_fn;
				}
			}
		}
	}
}
lbb_t( 'funcțiile mb_* fără înlocuitor în WordPress sunt folosite doar după function_exists (găzduiri fără mbstring)', ! $mb_bad, $mb_bad );

// Antetul plugin-ului: WordPress 6.5+ nu-l lasă activat fără WooCommerce; WooCommerce îl știe compatibil cu
// comenzile HPOS și cu coșul / finalizarea pe blocuri (fără avertismentul „plugin incompatibil”).
$hd_rp  = get_file_data( LBB_FILE, array( 'rp' => 'Requires Plugins', 'php' => 'Requires PHP' ) );
$hd_cmp = array();
foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $hd_f ) {
	$hd_c            = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( $hd_f );
	$hd_cmp[ $hd_f ] = in_array( plugin_basename( LBB_FILE ), isset( $hd_c['compatible'] ) ? $hd_c['compatible'] : array(), true );
}
lbb_t( 'antetul plugin-ului cere WooCommerce și PHP 7.4; compatibil declarat cu HPOS și cu blocurile', 'woocommerce' === $hd_rp['rp'] && '7.4' === $hd_rp['php'] && $hd_cmp['custom_order_tables'] && $hd_cmp['cart_checkout_blocks'], array( $hd_rp, $hd_cmp ) );

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
lbb_t( 'subiectele emailurilor de rezervare (client și birou) au data cu ziua săptămânii, ca pe bilet', 2 === count( array_filter( $lbb_mails, function ( $m ) use ( $tomorrow ) {
	return false !== strpos( (string) $m['subject'], LBB_WooCommerce::date_with_day( $tomorrow ) . ' 10:00' );
} ) ), wp_list_pluck( $lbb_mails, 'subject' ) );
$to_office = array_values( array_filter( $lbb_mails, function ( $m ) {
	return 'client-mail@example.com' !== $m['to'];
} ) );
$office_plain = $to_office ? LBB_Tickets::plain_text( $to_office[0]['message'] ) : '';
lbb_t( 'varianta text a emailului către birou: telefon și email fără linkuri dublate', false !== strpos( $office_plain, 'Telefon: +37369000077' ) && false !== strpos( $office_plain, 'Email: client-mail@example.com' ) && false === strpos( $office_plain, 'mailto:' ) && false === strpos( $office_plain, 'tel:' ), $office_plain );
lbb_t( 'emailul către birou are telefonul și emailul clientului ca linkuri (apel / răspuns dintr-o atingere)', $to_office && false !== strpos( $to_office[0]['message'], 'href="tel:+37369000077"' ) && false !== strpos( $to_office[0]['message'], 'href="mailto:client-mail@example.com"' ), $to_office ? substr( $to_office[0]['message'], -400 ) : 'lipsă' );
// Etichetele din emailul către birou sunt traductibile (și „Email”, nu doar „Telefon”).
$em_tr = function ( $tr, $text, $domain ) {
	return 'libertbus-bilete' === $domain && 'Email' === $text ? 'E-mail-tr' : $tr;
};
$em_m2 = array();
$em_c2 = function ( $null, $atts ) use ( &$em_m2 ) {
	$em_m2[] = $atts;
	return true;
};
add_filter( 'gettext', $em_tr, 10, 3 );
add_filter( 'pre_wp_mail', $em_c2, 1, 2 );
if ( $em ) {
	LBB_Tickets::send_reservation_emails( $em );
}
remove_filter( 'pre_wp_mail', $em_c2, 1 );
remove_filter( 'gettext', $em_tr, 10 );
$em_off = array_values( array_filter( $em_m2, function ( $m ) {
	return 'client-mail@example.com' !== $m['to'];
} ) );
lbb_t( 'emailul către birou: eticheta „Email” e traductibilă, ca „Telefon”', $em_off && false !== strpos( $em_off[0]['message'], 'E-mail-tr: <a href="mailto:client-mail@example.com"' ), $em_off ? substr( wp_strip_all_tags( $em_off[0]['message'] ), -200 ) : $em_m2 );
lbb_t( 'emailurile plugin-ului au font fără serife (ca pagina biletului) și limba textului (lang, pentru cititorul de ecran), pentru client și birou', 2 === count( array_filter( $lbb_mails, function ( $m ) {
	return 0 === strpos( $m['message'], '<div lang="' . esc_attr( LBB_Settings::text_lang() ) . '" style="font-family:' ) && false !== strpos( substr( $m['message'], 0, 180 ), 'sans-serif' );
} ) ), array_map( function ( $m ) {
	return substr( $m['message'], 0, 80 );
}, $lbb_mails ) );
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
update_option( 'lbb_settings', array_merge( $keep_name, array( 'company_name' => 'SC "LibertBus" SRL' ) ) );
lbb_t( 'denumirea firmei cu ghilimele (SC "LibertBus" SRL) apare la expeditor cu un singur spațiu între cuvinte', 0 === strpos( LBB_Tickets::from_header(), 'SC LibertBus SRL <' ), LBB_Tickets::from_header() );
update_option( 'lbb_settings', $keep_name );
lbb_t( 'răspunsul clientului ajunge la birou (Reply-To)', false !== strpos( $h_client, 'Reply-To: ' . $office ), $h_client );
lbb_t( 'biroul răspunde direct clientului (Reply-To)', false !== strpos( $h_office, 'Reply-To: client-mail@example.com' ), $h_office );
// Răspunsul automat de concediu al biroului (Reply-To = clientul) nu pleacă la client; emailul clientului rămâne normal.
lbb_t( 'notificările pentru birou au Auto-Submitted (rezervare nouă, locuri depășite), emailul clientului nu', false !== strpos( $h_office, 'Auto-Submitted: auto-generated' ) && false !== strpos( $ob_h, 'Auto-Submitted: auto-generated' ) && false === strpos( $h_client, 'Auto-Submitted' ), array( $h_office, $ob_h ) );

// Biroul anulează rezervarea: clientul primește un email (să nu vină degeaba la autocar).
$lbb_mails = array();
add_filter( 'pre_wp_mail', $lbb_catch, 1, 2 );
$cx_sent = $em && LBB_Bookings::cancel( $em['id'] ) && LBB_Tickets::send_cancellation_email( LBB_Bookings::get( $em['id'] ) );
lbb_t( 'fără email valid nu se trimite nimic la anulare', false === LBB_Tickets::send_cancellation_email( array_merge( (array) $em, array( 'email' => '' ) ) ) );
$cx_past_n = count( $lbb_mails );
$cx_past   = $em ? LBB_Tickets::send_cancellation_email( array_merge( LBB_Bookings::get( $em['id'] ), array( 'travel_date' => gmdate( 'Y-m-d', strtotime( LBB_Settings::today() . ' -1 day' ) ) ) ) ) : true;
$cx_now    = new DateTimeImmutable( 'now', LBB_Settings::tz() );
// Plecarea de acum 2 ore, cu data ei: după miezul nopții (00:53 → 22:53) e ieri, nu o cursă de azi încă neplecată.
$cx_gone   = $cx_now->modify( '-2 hours' );
$cx_today  = $em ? LBB_Tickets::send_cancellation_email( array_merge( LBB_Bookings::get( $em['id'] ), array( 'travel_date' => $cx_gone->format( 'Y-m-d' ), 'dep_time' => $cx_gone->format( 'H:i' ) ) ) ) : true;
lbb_t( 'anularea unei rezervări pe o cursă trecută (clientul n-a venit) nu trimite email clientului, nici azi după ora plecării', false === $cx_past && false === $cx_today && count( $lbb_mails ) === $cx_past_n, array( $cx_past, $cx_today, $cx_gone->format( 'Y-m-d H:i' ) ) );
lbb_t( 'cursa a plecat: după data și ora plecării în fusul orar al orarului', LBB_Bookings::departed( array( 'travel_date' => '2020-01-01', 'dep_time' => '10:00' ) ) && ! LBB_Bookings::departed( array( 'travel_date' => $cx_now->modify( '+1 day' )->format( 'Y-m-d' ), 'dep_time' => '00:00' ) ) && ( (int) $cx_now->format( 'G' ) >= 22 || ! LBB_Bookings::departed( array( 'travel_date' => $cx_now->format( 'Y-m-d' ), 'dep_time' => $cx_now->modify( '+2 hours' )->format( 'H:i' ) ) ) ) );
remove_filter( 'pre_wp_mail', $lbb_catch, 1 );
$cx = $lbb_mails ? $lbb_mails[0] : array( 'to' => '', 'subject' => '', 'message' => '', 'headers' => array() );
lbb_t( 'anularea din birou trimite un email clientului, cu codul, ruta și telefonul', $cx_sent && 1 === count( $lbb_mails ) && 'client-mail@example.com' === $cx['to']
	&& false !== strpos( $cx['subject'], 'anulată ' . $em['ticket_code'] ) && false !== strpos( $cx['message'], 'a fost anulată' ) && false !== strpos( $cx['message'], 'href="tel:' ), array( $cx['subject'], wp_strip_all_tags( $cx['message'] ) ) );
// Ruta are pagină pe site: emailul de anulare duce și acolo („Rezervați din nou online”); fără pagină, doar telefonul.
$cx_r = LBB_Routes::find( 'Bălți', 'Iași' );
$lbb_mails = array();
add_filter( 'pre_wp_mail', $lbb_catch, 1, 2 );
if ( $em && $cx_r ) {
	LBB_Tickets::send_cancellation_email( array_merge( LBB_Bookings::get( $em['id'] ), array( 'route_id' => $cx_r['id'] ) ) );
}
remove_filter( 'pre_wp_mail', $lbb_catch, 1 );
$cx_link = $lbb_mails ? $lbb_mails[0]['message'] : '';
$cx_form = LBB_Admin::pages_with_form();
lbb_t( 'emailul de anulare are „Rezervați din nou online” spre pagina rutei; o rută fără pagină duce la pagina cu formularul', $cx_r && $cx_form && false !== strpos( $cx_link, 'href="' . esc_url( $cx_r['page_url'] ) . '">Rezervați din nou online' ) && false !== strpos( $cx['message'], 'href="' . esc_url( get_permalink( $cx_form[0]->ID ) ) . '">Rezervați din nou online' ), array( substr( wp_strip_all_tags( $cx_link ), 0, 200 ), substr( $cx['message'], 0, 400 ) ) );
lbb_t( 'emailul de anulare vine de la firmă, cu răspuns spre birou', false !== strpos( implode( "\n", (array) $cx['headers'] ), 'Reply-To: ' . $office ) && (bool) preg_match( '/^From: ' . preg_quote( $from_name, '/' ) . ' </m', implode( "\n", (array) $cx['headers'] ) ) );

// Emailurile au și variantă text (multipart/alternative), lizibilă: cod, rută, link spre bilet, fără HTML.
$plain = $em ? LBB_Tickets::plain_text( LBB_Tickets::html( $em ) ) : '';
lbb_t( 'varianta text a biletului: cod, „Ruta …”, linkul biletului, fără etichete HTML', $em && false !== strpos( $plain, $em['ticket_code'] )
	&& (bool) preg_match( '/^Ruta .+→/mu', $plain ) && false !== strpos( $plain, '(' . LBB_Tickets::url( $em['ticket_code'] ) . ')' ) && false === strpos( $plain, '<' ), $plain );
// Pe site-ul de test emailurile sunt oprite înainte de PHPMailer (pre_wp_mail): aici le lăsăm să ajungă la el,
// citim varianta text și trimiterea eșuează imediat (SMTP pe un port închis).
$lbb_alt   = null;
$lbb_mf    = get_option( 'lbb_mail_failed' ); // eșecul provocat aici nu e o problemă reală: nu rămâne în panou
// Copie, nu același obiect: remove_all_filters() golește obiectul WP_Hook, iar „restaurarea” lui ar lăsa
// restul testelor fără filtru (emailurile ar încerca să plece cu adevărat).
$lbb_saved = isset( $GLOBALS['wp_filter']['pre_wp_mail'] ) ? clone $GLOBALS['wp_filter']['pre_wp_mail'] : null;
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
false === $lbb_mf ? delete_option( 'lbb_mail_failed' ) : update_option( 'lbb_mail_failed', $lbb_mf, false );
lbb_t( 'după testul cu PHPMailer real, emailurile de test sunt din nou oprite înainte de trimitere', (bool) has_filter( 'pre_wp_mail' ) );
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
// După 3 ani de la cursă (politica de confidențialitate) dispar numele, telefonul și emailul; codul rămâne.
$rt_old = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Vechi Pasager' ), '+37369000090', 'vechi@example.com', 'MDL' );
$rt_new = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Recent Pasager' ), '+37369000091', 'recent@example.com', 'MDL' );
$rt_old = is_array( $rt_old ) ? LBB_Bookings::reserve( $rt_old['token'] ) : null;
$rt_new = is_array( $rt_new ) ? LBB_Bookings::reserve( $rt_new['token'] ) : null;
if ( $rt_old && $rt_new ) {
	$rt_day = new DateTimeImmutable( LBB_Settings::today(), LBB_Settings::tz() );
	$wpdb->update( LBB_Bookings::table(), array( 'travel_date' => $rt_day->modify( '-3 years -2 days' )->format( 'Y-m-d' ) ), array( 'id' => $rt_old['id'] ) );
	$wpdb->update( LBB_Bookings::table(), array( 'travel_date' => $rt_day->modify( '-2 years' )->format( 'Y-m-d' ) ), array( 'id' => $rt_new['id'] ) );
	LBB_Bookings::cleanup();
}
$rt_o = $rt_old ? LBB_Bookings::get( $rt_old['id'] ) : null;
$rt_n = $rt_new ? LBB_Bookings::get( $rt_new['id'] ) : null;
lbb_t( 'după 3 ani de la cursă datele personale se șterg, codul rămâne; o cursă de acum 2 ani rămâne neatinsă', $rt_o && '' === $rt_o['phone'] && '' === $rt_o['email'] && ! array_filter( (array) $rt_o['passengers'] ) && $rt_o['ticket_code'] === $rt_old['ticket_code']
	&& $rt_n && 'recent@example.com' === $rt_n['email'] && array( 'Recent Pasager' ) === array_values( (array) $rt_n['passengers'] ), array( $rt_o, $rt_n ) );
lbb_t( 'curățenia păstrează rezervarea anulată de birou și șterge coșul abandonat', $cl_kept && 'cancelled' === $cl_kept['status'] && is_array( $cl_hold ) && ! LBB_Bookings::get( $cl_hold['id'] ) );

// Unelte → Exportă / Șterge datele personale: rezervările clientului apar și se șterg; biletul viitor rămâne.
$pv_ex  = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$pv_er  = apply_filters( 'wp_privacy_personal_data_erasers', array() );
$pv_old = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Gdpr Vechi' ), '+37369000092', 'gdpr@example.com', 'MDL' );
$pv_new = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Gdpr Viitor' ), '+37369000093', 'gdpr@example.com', 'MDL' );
$pv_old = is_array( $pv_old ) ? LBB_Bookings::reserve( $pv_old['token'] ) : null;
$pv_new = is_array( $pv_new ) ? LBB_Bookings::reserve( $pv_new['token'] ) : null;
if ( $pv_old ) {
	$wpdb->update( LBB_Bookings::table(), array( 'travel_date' => gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) ), array( 'id' => $pv_old['id'] ) );
}
$pv_exp = isset( $pv_ex['libertbus-bilete'] ) ? call_user_func( $pv_ex['libertbus-bilete']['callback'], 'gdpr@example.com', 1 ) : array( 'data' => array() );
$pv_txt = wp_json_encode( $pv_exp['data'] );
lbb_t( 'export date personale: ambele rezervări ale clientului, cu nume, telefon și cod', 2 === count( $pv_exp['data'] ) && false !== strpos( $pv_txt, 'Gdpr Vechi' ) && false !== strpos( $pv_txt, '+37369000093' ) && false !== strpos( $pv_txt, 'plata la urcare' ) && $pv_new && false !== strpos( $pv_txt, $pv_new['ticket_code'] ), $pv_txt );
$pv_day = $pv_new ? LBB_WooCommerce::date_with_day( $pv_new['travel_date'] ) . ' 10:00' : '';
lbb_t( 'export date personale: data cursei ca pe bilet (zi.lună.an, ziua săptămânii), nu în format ISO', $pv_new && preg_match( '/^\\d{2}\\.\\d{2}\\.\\d{4} \\(/', $pv_day ) && false !== strpos( $pv_txt, wp_json_encode( $pv_day ) ) && false === strpos( $pv_txt, $pv_new['travel_date'] ), $pv_txt );
$pv_res = isset( $pv_er['libertbus-bilete'] ) ? call_user_func( $pv_er['libertbus-bilete']['callback'], 'gdpr@example.com', 1 ) : array();
$pv_o   = $pv_old ? LBB_Bookings::get( $pv_old['id'] ) : null;
$pv_n   = $pv_new ? LBB_Bookings::get( $pv_new['id'] ) : null;
lbb_t( 'ștergere date personale: cursa trecută anonimizată, biletul pentru cursa viitoare păstrat cu mesaj', ! empty( $pv_res['items_removed'] ) && ! empty( $pv_res['items_retained'] ) && ! empty( $pv_res['messages'] )
	&& $pv_o && '' === $pv_o['email'] && '' === $pv_o['phone'] && ! array_filter( (array) $pv_o['passengers'] ) && $pv_n && 'gdpr@example.com' === $pv_n['email'], array( $pv_res, $pv_o, $pv_n ) );

// Descrierea scurtă a produsului „Bilet autocar” nu apare clientului (coș / checkout cu blocuri), dar rămâne în admin.
$sd_p = wc_get_product( LBB_Install::product_id() );
lbb_t( 'produsul bilet: descrierea scurtă (notă internă) e goală pentru client și rămâne în admin', $sd_p && '' === $sd_p->get_short_description() && '' !== $sd_p->get_short_description( 'edit' ), $sd_p ? $sd_p->get_short_description( 'edit' ) : null );
// Și la crearea produsului (instalare nouă): WooCommerce citește descrierea pentru salvare prin filtrul de mai sus.
$sd_opt = get_option( LBB_Install::PRODUCT_OPTION );
update_option( LBB_Install::PRODUCT_OPTION, 0 );
LBB_Install::ensure_product();
$sd_new = (int) get_option( LBB_Install::PRODUCT_OPTION );
$sd_ex  = $sd_new ? (string) get_post_field( 'post_excerpt', $sd_new ) : '';
if ( $sd_new && (int) $sd_opt !== $sd_new ) {
	wp_delete_post( $sd_new, true );
}
update_option( LBB_Install::PRODUCT_OPTION, $sd_opt );
lbb_t( 'produsul bilet creat din nou păstrează descrierea scurtă pentru admin, iar filtrul pentru client rămâne activ', '' !== $sd_ex && false !== has_filter( 'woocommerce_product_get_short_description', array( 'LBB_WooCommerce', 'short_description' ) ) && '' === wc_get_product( LBB_Install::product_id() )->get_short_description(), $sd_ex );

// Antetul „WC tested up to” acoperă versiunea WooCommerce pe care rulează testele: altfel, după actualizarea
// WooCommerce pe site, pagina de pluginuri avertizează „netestat cu versiunea dumneavoastră”.
$wt_h  = get_file_data( LBB_FILE, array( 'wc' => 'WC tested up to' ) );
$wt_mm = implode( '.', array_slice( explode( '.', WC()->version ), 0, 2 ) );
lbb_t( 'antetul „WC tested up to” acoperă WooCommerce ' . $wt_mm . ' (versiunea testată aici)', '' !== $wt_h['wc'] && version_compare( $wt_h['wc'], $wt_mm, '>=' ), array( $wt_h['wc'], WC()->version ) );

// Produsul „Bilet autocar” nu apare în sitemap (WordPress, Yoast): pagina lui trimite la formular.
$sm_p = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
$sm_u = $sm_p ? wp_list_pluck( $sm_p->get_url_list( 1, 'product' ), 'loc' ) : array();
$sm_y = apply_filters( 'wpseo_exclude_from_sitemap_by_post_ids', array() );
lbb_t( 'sitemap: produsul „Bilet autocar” lipsește (redirecționare), și din Yoast', $sm_p && ! in_array( get_permalink( LBB_Install::product_id() ), $sm_u, true ) && in_array( LBB_Install::product_id(), $sm_y, true ), array( $sm_u, $sm_y ) );

// Emailurile HTML pleacă cu varianta text alăturată (multipart): PHPMailer-ul primește AltBody, iar următorul email
// trimis de site (alt plugin, WordPress) nu-l moștenește. PHPMailer de test: nu trimite nimic.
require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
global $phpmailer;
$mh_keep   = $phpmailer;
$phpmailer = new class( true ) extends PHPMailer\PHPMailer\PHPMailer {
	public function send() {
		return true;
	}
};
$mh_go = function () {
	return null; // Trece de filtrul care salvează emailurile în mediul de test: ajunge la PHPMailer.
};
add_filter( 'pre_wp_mail', $mh_go, 20 );
$mh_ok   = LBB_Tickets::mail_html( 'alt@example.com', 'Test', '<p>Bilet <a href="https://example.com/b">deschide</a></p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
$mh_alt  = $phpmailer->AltBody;
wp_mail( 'altul@example.com', 'Alt plugin', 'Text simplu' );
$mh_next = $phpmailer->AltBody;
remove_filter( 'pre_wp_mail', $mh_go, 20 );
$phpmailer = $mh_keep;
lbb_t( 'emailul HTML are și varianta text (AltBody), iar următorul email al site-ului nu o moștenește', $mh_ok && false !== strpos( $mh_alt, 'Bilet' ) && false !== strpos( $mh_alt, 'https://example.com/b' ) && false === strpos( $mh_alt, '<p>' ) && '' === $mh_next, array( $mh_alt, $mh_next ) );

// Tabelul biletului (email, pagina de mulțumire, pagina biletului): etichetele sunt antete de rând pentru cititorul de
// ecran (scope="row"), ca „Pasageri” să fie citit împreună cu numele de pe același rând.
$sr_b = LBB_Bookings::get_by_code( (string) $wpdb->get_var( 'SELECT ticket_code FROM ' . LBB_Bookings::table() . " WHERE ticket_code <> '' ORDER BY id DESC LIMIT 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$sr_h = $sr_b ? LBB_Tickets::html( $sr_b ) : '';
lbb_t( 'tabelul biletului: fiecare etichetă e antet de rând (th scope="row")', $sr_h && preg_match_all( '/<th\b/', $sr_h ) >= 3 && preg_match_all( '/<th\b/', $sr_h ) === preg_match_all( '/<th scope="row"/', $sr_h ), $sr_h ? substr( $sr_h, 0, 300 ) : null );

// Telefonul biroului din notele biletului (și în email, fără CSS-ul site-ului) are zona de atingere scrisă pe link.
$nt_h = LBB_Tickets::notes_html();
lbb_t( 'notele biletului: telefonul biroului are padding direct pe link (zonă de atingere și în email)', '' === trim( (string) LBB_Settings::get( 'support_phone' ) ) || (bool) preg_match( '/<a class="lbb-phone" style="padding:10px 0;" href="tel:/', $nt_h ), $nt_h );

// Subiectul emailului WooCommerce cu biletul spune codul, ruta și data; un subiect scris de administrator rămâne.
$es_id = (int) $wpdb->get_var( 'SELECT order_id FROM ' . LBB_Bookings::table() . " WHERE status = 'confirmed' AND ticket_code <> '' AND order_id > 0 GROUP BY order_id HAVING COUNT(*) = 1 ORDER BY order_id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$es_o  = $es_id ? wc_get_order( $es_id ) : null;
$es_b  = $es_id ? LBB_Bookings::by_order( $es_id ) : array();
// Prin get_subject(), ca la trimiterea reală (WooCommerce scrie întâi subiectul implicit în setările emailului).
$es_em   = WC()->mailer()->emails['WC_Email_Customer_Completed_Order'];
$es_key  = $es_em->get_option_key();
$es_keep = get_option( $es_key );
$es_em->object = $es_o;
$es_s1   = $es_o ? $es_em->get_subject() : '';
update_option( $es_key, array_merge( is_array( $es_keep ) ? $es_keep : array(), array( 'subject' => 'Comanda {order_number}' ) ) );
$es_em->init_settings();
$es_s2   = $es_o ? $es_em->get_subject() : '';
false === $es_keep ? delete_option( $es_key ) : update_option( $es_key, $es_keep );
$es_em->init_settings();
$es_pe   = WC()->mailer()->emails['WC_Email_Customer_Processing_Order'];
$es_pe->object = wc_create_order();
$es_s3   = $es_pe->get_subject();
// Comandă cu două bilete (ex. tur-retur): subiectul le numește pe amândouă.
$es_two = wc_create_order();
foreach ( array( 'Dus Doi', 'Intors Doi' ) as $es_n ) {
	$es_h  = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( $es_n ), '+37369000096', 'doua-bilete@example.com', 'MDL' );
	$es_it = new WC_Order_Item_Product();
	$es_it->set_product( wc_get_product( LBB_Install::product_id() ) );
	$es_it->add_meta_data( '_lbb_token', is_array( $es_h ) ? $es_h['token'] : '', true );
	$es_two->add_item( $es_it );
}
$es_two->save();
LBB_WooCommerce::attach_order( $es_two );
$es_two->update_status( 'processing' );
$es_tc  = wp_list_pluck( array_filter( LBB_Bookings::by_order( $es_two->get_id() ), function ( $b ) { return 'confirmed' === $b['status']; } ), 'ticket_code' );
$es_pe->object = wc_get_order( $es_two->get_id() );
$es_s5  = $es_pe->get_subject();
lbb_t( 'comanda cu două bilete: subiectul emailului le are pe amândouă („Biletele dumneavoastră: LB-…, LB-…”)', 2 === count( $es_tc ) && 'Biletele dumneavoastră: ' . implode( ', ', $es_tc ) . ' — ' . get_bloginfo( 'name' ) === $es_s5, array( $es_s5, $es_tc ) );
$es_two->update_status( 'cancelled' ); // Locurile se eliberează pentru testele următoare de pe aceeași cursă.
// Rambursare completă: clientul află în subiect ce bilet s-a anulat; la rambursare parțială subiectul WooCommerce rămâne.
$es_r  = wc_create_order();
$es_rh = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Ramb Ursat' ), '+37369000098', 'ramburs@example.com', 'MDL' );
$es_ri = new WC_Order_Item_Product();
$es_ri->set_product( wc_get_product( LBB_Install::product_id() ) );
$es_ri->add_meta_data( '_lbb_token', is_array( $es_rh ) ? $es_rh['token'] : '', true );
$es_r->add_item( $es_ri );
$es_r->save();
LBB_WooCommerce::attach_order( $es_r );
$es_r->update_status( 'processing' );
$es_r->update_status( 'refunded' );
$es_rc = wp_list_pluck( LBB_Bookings::by_order( $es_r->get_id() ), 'ticket_code' );
$es_re = WC()->mailer()->emails['WC_Email_Customer_Refunded_Order'];
$es_re->object = wc_get_order( $es_r->get_id() );
$es_re->partial_refund = false;
$es_re->id = 'customer_refunded_order'; // Ca WooCommerce la trimitere: id-ul se schimbă după tipul rambursării.
$es_s9  = $es_re->get_subject();
$es_re->partial_refund = true;
$es_re->id = 'customer_partially_refunded_order';
$es_s10 = $es_re->get_subject();
$es_re->partial_refund = false;
$es_re->id = 'customer_refunded_order';
lbb_t( 'rambursare completă: subiectul spune biletul anulat („Bilet anulat și rambursat: LB-… · ruta · data”); la rambursare parțială rămâne cel WooCommerce', 1 === count( $es_rc ) && '' !== $es_rc[0] && 0 === strpos( $es_s9, 'Bilet anulat și rambursat: ' . $es_rc[0] . ' · ' ) && false === strpos( $es_s10, 'Bilet' ), array( $es_s9, $es_s10, $es_rc ) );
// Un nume de site cu „&” (WordPress îl salvează „&amp;”) apare în subiect ca text, nu ca entitate HTML.
$es_bn = get_option( 'blogname' );
update_option( 'blogname', 'Autocare & Bilete' );
$es_em->object = $es_o;
$es_s8 = $es_o ? $es_em->get_subject() : '';
update_option( 'blogname', $es_bn );
lbb_t( 'numele site-ului cu „&” apare în subiectul emailului ca „&”, nu „&amp;”', false !== strpos( get_option( 'blogname' ) . get_bloginfo( 'name' ), $es_bn ) && false !== strpos( $es_s8, '— Autocare & Bilete' ) && false === strpos( $es_s8, '&amp;' ), $es_s8 );
// Emailurile către birou pentru comandă anulată / plată eșuată spun cursa (fără cod, dacă plata n-a trecut).
$es_ca = WC()->mailer()->emails['WC_Email_Cancelled_Order'];
$es_ca->object = wc_get_order( $es_two->get_id() );
$es_s6 = $es_ca->get_subject();
$es_f  = wc_create_order();
$es_fh = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Plata Esuata' ), '+37369000097', 'esuat@example.com', 'MDL' );
$es_fi = new WC_Order_Item_Product();
$es_fi->set_product( wc_get_product( LBB_Install::product_id() ) );
$es_fi->add_meta_data( '_lbb_token', is_array( $es_fh ) ? $es_fh['token'] : '', true );
$es_f->add_item( $es_fi );
$es_f->save();
LBB_WooCommerce::attach_order( $es_f );
$es_f->update_status( 'failed' );
$es_fa = WC()->mailer()->emails['WC_Email_Failed_Order'];
$es_fa->object = wc_get_order( $es_f->get_id() );
$es_s7 = $es_fa->get_subject();
$es_rt = LBB_Routes::get( $rid2 );
lbb_t( 'emailurile către birou „comandă anulată” / „plată eșuată” spun cursa: numărul de bilete, respectiv ruta, data și ora (fără cod)', '[' . get_bloginfo( 'name' ) . '] Comandă anulată #' . $es_two->get_order_number() . ': 2 bilete' === $es_s6
	&& '[' . get_bloginfo( 'name' ) . '] Plată eșuată #' . $es_f->get_order_number() . ': ' . $es_rt['origin'] . ' → ' . $es_rt['destination'] . ' · ' . gmdate( 'd.m.Y', strtotime( $tomorrow ) ) . ' 10:00' === $es_s7, array( $es_s6, $es_s7 ) );
$es_ne   = WC()->mailer()->emails['WC_Email_New_Order'];
$es_ne->object = $es_o;
$es_s4   = $es_o ? $es_ne->get_subject() : '';
lbb_t( 'emailul „Comandă nouă” către birou are numărul comenzii și biletul (cod, rută, dată)', $es_b && $es_o && 0 === strpos( $es_s4, '[' . get_bloginfo( 'name' ) . '] Comandă nouă #' . $es_o->get_order_number() . ': ' . $es_b[0]['ticket_code'] . ' · ' ), $es_s4 );
lbb_t( 'emailul cu biletul are în subiect codul, ruta și data; subiectul scris de administrator și comenzile fără bilete rămân', $es_b && 0 === strpos( $es_s1, 'Biletul dumneavoastră: ' . $es_b[0]['ticket_code'] . ' · ' ) && false !== strpos( $es_s1, ' → ' ) && $es_o && 'Comanda ' . $es_o->get_order_number() === $es_s2 && false === strpos( $es_s3, 'Bilet' ), array( $es_s1, $es_s2, $es_s3 ) );

// Indexuri pe dată și telefon (tabelul păstrează 3 ani de rezervări); o instalare veche (versiunea 5) le primește la actualizare.
$ix_names = function () use ( $wpdb ) {
	return array_unique( wp_list_pluck( $wpdb->get_results( 'SHOW INDEX FROM ' . LBB_Bookings::table() ), 'Key_name' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
};
$ix_new = $ix_names();
$wpdb->query( 'ALTER TABLE ' . LBB_Bookings::table() . ' DROP INDEX travel_date, DROP INDEX phone' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
update_option( 'lbb_db_version', '5' );
LBB_Install::maybe_upgrade();
$ix_up = $ix_names();
lbb_t( 'tabelul rezervărilor are indexuri pe dată și telefon, și după actualizarea de la versiunea 5', in_array( 'travel_date', $ix_new, true ) && in_array( 'phone', $ix_new, true ) && in_array( 'travel_date', $ix_up, true ) && in_array( 'phone', $ix_up, true ) && LBB_DB_VERSION === get_option( 'lbb_db_version' ), array( $ix_new, $ix_up ) );

// Date stricate trimise formularului (liste în loc de text, numere negative, date imposibile, caractere invalide), ca
// de la un robot: rezervarea e refuzată cu un mesaj, fără avertismente PHP (care în debug.log ar ascunde erorile reale).
$fz_warn = array();
set_error_handler( function ( $no, $msg, $file, $line ) use ( &$fz_warn ) {
	if ( false !== strpos( $file, 'libertbus-bilete' ) ) {
		$fz_warn[] = $msg . ' @' . basename( $file ) . ':' . $line;
	}
	return false;
} );
$fz_rid  = (int) $rid2;
$fz_res  = array();
foreach ( array(
	array( 'lbb_route' => array( $fz_rid ), 'lbb_names' => 'Ion', 'lbb_adults' => array( 1 ), 'lbb_date' => array( 'x' ), 'lbb_time' => array( 'y' ), 'lbb_phone' => array( '1' ), 'lbb_email' => array( 'a' ), 'lbb_currency' => array( 'MDL' ), 'lbb_mode' => array( 'pay' ) ),
	array( 'lbb_route' => $fz_rid, 'lbb_names' => array( array( 'x' => 'Ion' ) ), 'lbb_adults' => '-5', 'lbb_children' => '999999999999', 'lbb_date' => '31.02.2026', 'lbb_time' => '25:99', 'lbb_phone' => "\0", 'lbb_email' => "\xC3\x28", 'lbb_currency' => 'XXX', 'lbb_mode' => 'reserve' ),
	array( 'lbb_route' => 'abc', 'lbb_names' => array( '' ), 'lbb_adults' => 'abc' ),
	array( 'lbb_route' => 999999, 'lbb_names' => array( 'A B' ), 'lbb_adults' => 1, 'lbb_date' => $tomorrow, 'lbb_time' => '10:00', 'lbb_phone' => '+37369000099', 'lbb_email' => 'a@b.md', 'lbb_mode' => 'reserve' ),
) as $fz_data ) {
	$fz_res[] = LBB_Frontend::book( $fz_data );
}
// Câte un singur câmp stricat, restul valid (altfel rezervarea s-ar opri înainte să ajungă la câmpul stricat).
$fz_ok = array( 'lbb_route' => $fz_rid, 'lbb_names' => array( 'Fuzz Test' ), 'lbb_adults' => 1, 'lbb_date' => $tomorrow, 'lbb_time' => '10:00', 'lbb_phone' => '+37369000099', 'lbb_email' => 'fuzz@example.com', 'lbb_mode' => 'reserve' );
foreach ( array( 'lbb_names' => array( array( 'x' => 'Ion Pop' ) ), 'lbb_phone' => array( '+37369000099' ), 'lbb_email' => array( 'fuzz@example.com' ), 'lbb_date' => array( $tomorrow ), 'lbb_time' => array( '10:00' ) ) as $fz_k => $fz_v ) {
	$fz_res[] = LBB_Frontend::book( array_merge( $fz_ok, array( $fz_k => $fz_v ) ) );
}
restore_error_handler();
lbb_t( 'date stricate în formular (liste, negative, date imposibile): refuzate cu mesaj, fără avertismente PHP', ! $fz_warn && 9 === count( array_filter( $fz_res, 'is_wp_error' ) ), array( $fz_warn, array_map( function ( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : $r; }, $fz_res ) ) );

// Coșul abandonat al clientului (expirat) dispare la cererea de ștergere; coșul încă activ rămâne.
$ph_old = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Cos Abandonat' ), '+37369000095', 'gdpr-hold@example.com', 'MDL' );
$ph_new = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Cos Activ' ), '+37369000095', 'gdpr-hold@example.com', 'MDL' );
if ( is_array( $ph_old ) ) {
	$wpdb->update( LBB_Bookings::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => $ph_old['id'] ) );
}
$ph_res = isset( $pv_er['libertbus-bilete'] ) ? call_user_func( $pv_er['libertbus-bilete']['callback'], 'gdpr-hold@example.com', 1 ) : array();
lbb_t( 'ștergere date personale: coșul abandonat (expirat) al clientului dispare imediat, coșul încă activ rămâne', is_array( $ph_old ) && is_array( $ph_new ) && ! empty( $ph_res['items_removed'] ) && ! LBB_Bookings::get( $ph_old['id'] ) && LBB_Bookings::get( $ph_new['id'] ), $ph_res );
if ( is_array( $ph_new ) ) {
	$wpdb->delete( LBB_Bookings::table(), array( 'id' => $ph_new['id'] ) );
}

// WooCommerce anonimizează o comandă (la cerere sau după perioada setată): dispar și numele de pe rândul comenzii și din rezervare.
$wa_h = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), $tomorrow, '10:00', 1, 0, array( 'Anonim Comanda' ), '+37369000094', 'wa@example.com', 'MDL' );
$wa_o = wc_create_order();
$wa_i = new WC_Order_Item_Product();
$wa_i->set_product( wc_get_product( LBB_Install::product_id() ) );
$wa_i->add_meta_data( '_lbb_token', is_array( $wa_h ) ? $wa_h['token'] : '', true );
$wa_i->add_meta_data( 'Pasageri', 'Anonim Comanda', true );
$wa_o->add_item( $wa_i );
$wa_o->save();
LBB_WooCommerce::attach_order( $wa_o );
WC_Privacy_Erasers::remove_order_personal_data( wc_get_order( $wa_o->get_id() ) );
$wa_o    = wc_get_order( $wa_o->get_id() );
$wa_meta = '';
foreach ( $wa_o->get_items() as $wa_it ) {
	$wa_meta .= (string) $wa_it->get_meta( 'Pasageri' );
}
$wa_b = is_array( $wa_h ) ? LBB_Bookings::get( $wa_h['id'] ) : null;
lbb_t( 'anonimizarea WooCommerce șterge numele de pe comandă și din rezervare', '' === $wa_meta && $wa_b && '' === $wa_b['email'] && ! array_filter( (array) $wa_b['passengers'] ), array( $wa_meta, $wa_b ) );
$wa_o->delete( true );

// Același lucru pentru rezervările cu comandă: ștergerea la cererea clientului și curățenia de 3 ani curăță și comanda.
// Prima cursă cu locuri libere (testul poate rula de mai multe ori pe aceeași bază fără ca o cursă să se umple).
$oe_slot = function () use ( $rid2 ) {
	$route = LBB_Routes::get( $rid2 );
	for ( $d = 1; $d <= 30; $d++ ) {
		$day = gmdate( 'Y-m-d', time() + $d * DAY_IN_SECONDS );
		foreach ( LBB_Routes::departures_on( $route, $day ) as $dep ) {
			if ( $dep['free'] > 0 && '' === $dep['reason'] ) {
				return array( $route, $day, $dep['time'] );
			}
		}
	}
	return array( $route, gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ), '10:00' );
};
$oe_make = function ( $name, $email ) use ( $oe_slot ) {
	list( $route, $day, $time ) = $oe_slot();
	$h = LBB_Bookings::create_hold( $route, $day, $time, 1, 0, array( $name ), '+37369000095', $email, 'MDL' );
	$o = wc_create_order();
	$i = new WC_Order_Item_Product();
	$i->set_product( wc_get_product( LBB_Install::product_id() ) );
	$i->add_meta_data( '_lbb_token', is_array( $h ) ? $h['token'] : '', true );
	$i->add_meta_data( 'Pasageri', $name, true );
	$o->add_item( $i );
	$o->save();
	LBB_WooCommerce::attach_order( $o );
	return array( $h, $o->get_id() );
};
$oe_names = function ( $order_id ) {
	$out = '';
	foreach ( wc_get_order( $order_id )->get_items() as $it ) {
		$out .= (string) $it->get_meta( 'Pasageri' );
	}
	return $out;
};
list( $oe_h1, $oe_o1 ) = $oe_make( 'Sters La Cerere', 'erase-order@example.com' );
list( $oe_h2, $oe_o2 ) = $oe_make( 'Sters Dupa Trei Ani', 'old-order@example.com' );
$oe_past = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
$oe_old  = ( new DateTimeImmutable( LBB_Settings::today(), LBB_Settings::tz() ) )->modify( '-3 years -2 days' )->format( 'Y-m-d' );
$wpdb->update( LBB_Bookings::table(), array( 'travel_date' => $oe_past ), array( 'id' => is_array( $oe_h1 ) ? $oe_h1['id'] : 0 ) );
$wpdb->update( LBB_Bookings::table(), array( 'travel_date' => $oe_old ), array( 'id' => is_array( $oe_h2 ) ? $oe_h2['id'] : 0 ) );
$pv_er = apply_filters( 'wp_privacy_personal_data_erasers', array() );
call_user_func( $pv_er['libertbus-bilete']['callback'], 'erase-order@example.com', 1 );
LBB_Bookings::cleanup();
$oe_notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $oe_o1 ) ), 'content' );
lbb_t( 'ștergerea la cerere și curățenia de 3 ani șterg și numele de pe comandă (cu notă în comandă)', is_array( $oe_h1 ) && is_array( $oe_h2 ) && '' === $oe_names( $oe_o1 ) && '' === $oe_names( $oe_o2 ) && (bool) preg_grep( '/Numele pasagerilor au fost șterse/u', $oe_notes ), array( $oe_names( $oe_o1 ), $oe_names( $oe_o2 ), $oe_notes ) );
wc_get_order( $oe_o1 )->delete( true );
wc_get_order( $oe_o2 )->delete( true );

// Dezinstalarea cu „șterge datele” nu lasă opțiuni în urmă: fiecare opțiune folosită în cod apare în uninstall.php.
$un_src  = '';
foreach ( array_merge( array( LBB_DIR . 'libertbus-bilete.php' ), glob( LBB_DIR . 'includes/*.php' ) ) as $un_f ) {
	$un_src .= file_get_contents( $un_f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
preg_match_all( "/_option\\(\\s*'(lbb_[a-z_]+)'/", $un_src, $un_m );
preg_match_all( "/const\\s+[A-Z_]*OPTION\\s*=\\s*'(lbb_[a-z_]+)'/", $un_src, $un_c );
$un_opts = array_unique( array_merge( $un_m[1], $un_c[1] ) );
$un_file = file_get_contents( LBB_DIR . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$un_miss = array_values( array_filter( $un_opts, function ( $o ) use ( $un_file ) {
	return false === strpos( $un_file, "'" . $o . "'" );
} ) );
lbb_t( 'dezinstalarea șterge toate opțiunile plugin-ului (' . count( $un_opts ) . ')', count( $un_opts ) >= 6 && ! $un_miss, $un_miss );
// Și datele temporare lbb_* (set_transient), cu tot cu timpul lor de expirare.
$un_tr = (bool) preg_match( "/set_transient\\(\\s*'lbb_/", $un_src );
lbb_t( 'dezinstalarea șterge și datele temporare lbb_* (transient + timeout)', ! $un_tr || ( false !== strpos( $un_file, '\\\\_transient\\\\_lbb\\\\_%' ) && false !== strpos( $un_file, '\\\\_transient\\\\_timeout\\\\_lbb\\\\_%' ) ), array( $un_tr ) );

// Pagina „comandă primită” înainte de confirmarea băncii: mesaj cu „Verifică din nou” spre aceeași pagină.
$ty_h = LBB_Bookings::create_hold( LBB_Routes::get( $rid2 ), gmdate( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ), '10:00', 1, 0, array( 'Asteapta Banca' ), '+37369000096', 'ty@example.com', 'MDL' );
$ty_o = wc_create_order();
$ty_i = new WC_Order_Item_Product();
$ty_i->set_product( wc_get_product( LBB_Install::product_id() ) );
$ty_i->add_meta_data( '_lbb_token', is_array( $ty_h ) ? $ty_h['token'] : '', true );
$ty_o->add_item( $ty_i );
$ty_o->save();
LBB_WooCommerce::attach_order( $ty_o );
ob_start();
LBB_Tickets::thankyou( $ty_o->get_id() );
$ty_html = ob_get_clean();
lbb_t( 'comandă neconfirmată încă: „Verifică din nou” duce la aceeași pagină de comandă', false !== strpos( $ty_html, 'Verifică din nou' ) && false !== strpos( $ty_html, esc_url( $ty_o->get_checkout_order_received_url() ) ), $ty_html );
$_GET = array( 'lbb_wait' => '6' );
ob_start();
LBB_Tickets::thankyou( $ty_o->get_id() );
$ty_last = ob_get_clean();
$_GET = array();
lbb_t( 'comandă neconfirmată: pagina se reîncarcă singură la 5 s (lbb_wait=1, 2 …), de cel mult 6 ori; apoi rămâne doar butonul', false !== strpos( $ty_html, 'setTimeout(' ) && false !== strpos( $ty_html, 'lbb_wait=1' ) && false !== strpos( $ty_html, '5000' ) && false === strpos( $ty_last, 'setTimeout(' ) && false !== strpos( $ty_last, 'Verifică din nou' ), array( $ty_html, $ty_last ) );
lbb_t( 'comandă neconfirmată: cât se reîncarcă scrie „Pagina se actualizează singură”; după ultima încercare dă telefonul biroului (link de apel)', false !== strpos( $ty_html, 'Pagina se actualizează singură' ) && false === strpos( $ty_html, 'sunați la' ) && false !== strpos( $ty_last, 'sunați la ' . LBB_Settings::phone_link() ) && false === strpos( $ty_last, 'se actualizează singură' ), array( wp_strip_all_tags( $ty_html ), wp_strip_all_tags( $ty_last ) ) );
lbb_t( 'comandă neconfirmată: mesajul „Biletele se emit…” e anunțat de cititorul de ecran (role="status")', (bool) preg_match( '/<div class="woocommerce-info lbb-awaiting" role="status">/', $ty_html ) );
// Plata refuzată: pagina nu mai promite bilete (WooCommerce arată deja „plata nu a reușit”).
$ty_o->update_status( 'failed' );
ob_start();
LBB_Tickets::thankyou( $ty_o->get_id() );
$ty_fail = ob_get_clean();
lbb_t( 'plată refuzată: pe pagina comenzii nu apare „biletele vin imediat”', false === strpos( $ty_fail, 'Biletele se emit' ) && false === strpos( $ty_fail, 'Verifică din nou' ), $ty_fail );
$ty_o->delete( true );

// Ore de plecare scrise greșit la o rută: le numim, ca biroul să afle că acea cursă n-a fost salvată.
lbb_t( 'orele neînțelese la o rută sunt semnalate (25:00, 8-45), cele bune nu', array( '25:00', '8-45' ) === LBB_Routes::unrecognized_times( "08:45, 25:00\n13.30; 8-45 17:05" ) && array() === LBB_Routes::unrecognized_times( '06:00, 7.15' ), LBB_Routes::unrecognized_times( "08:45, 25:00\n13.30; 8-45 17:05" ) );
$pt_in = '08:45, 10.11.2026, 2026.10.11, 17.30.';
lbb_t( 'o dată scrisă la orele de plecare („10.11.2026”) nu devine o cursă la 10:11, ci e semnalată', array( '08:45', '17:30' ) === LBB_Routes::parse_times( $pt_in ) && array( '10.11.2026', '2026.10.11' ) === LBB_Routes::unrecognized_times( $pt_in ), array( LBB_Routes::parse_times( $pt_in ), LBB_Routes::unrecognized_times( $pt_in ) ) );

// O rută cu bilete sau rezervări pentru curse viitoare nu se șterge (doar i se oprește vânzarea).
$ud_r = LBB_Routes::save( array( 'origin' => 'StergeA', 'destination' => 'StergeB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'capacity' => 5, 'active' => 1 ) );
$ud_before = is_int( $ud_r ) ? LBB_Routes::upcoming_bookings( $ud_r ) : -1;
$ud_h = is_int( $ud_r ) ? LBB_Bookings::create_hold( LBB_Routes::get( $ud_r ), $tomorrow, '10:00', 1, 0, array( 'Sterge Ruta' ), '+37369000097', 'ud@example.com', 'MDL' ) : null;
$ud_b = is_array( $ud_h ) ? LBB_Bookings::reserve( $ud_h['token'] ) : null;
$ud_after = is_int( $ud_r ) ? LBB_Routes::upcoming_bookings( $ud_r ) : -1;
if ( $ud_b ) {
	LBB_Bookings::cancel( $ud_b['id'] );
}
$ud_cancel = is_int( $ud_r ) ? LBB_Routes::upcoming_bookings( $ud_r ) : -1;
lbb_t( 'ruta cu o rezervare viitoare e blocată la ștergere; după anulare se poate șterge', 0 === $ud_before && 1 === $ud_after && 0 === $ud_cancel, array( $ud_before, $ud_after, $ud_cancel ) );
if ( is_int( $ud_r ) ) {
	LBB_Routes::delete( $ud_r );
}

// O oră sau o zi scoasă din orarul unei rute care are deja rezervări pe ea: adminul primește cursele afectate.
$sb_base = array( 'origin' => 'OrarA', 'destination' => 'OrarB', 'price' => 50, 'currency' => 'MDL', 'capacity' => 5, 'active' => 1 );
$sb_r    = LBB_Routes::save( $sb_base + array( 'departures' => '10:00, 14:00' ) );
$sb_h    = is_int( $sb_r ) ? LBB_Bookings::create_hold( LBB_Routes::get( $sb_r ), $tomorrow, '10:00', 1, 0, array( 'Orar Schimbat' ), '+37369000096', 'sb@example.com', 'MDL' ) : null;
$sb_b    = is_array( $sb_h ) ? LBB_Bookings::reserve( $sb_h['token'] ) : null;
$sb_key  = gmdate( 'd.m.Y', strtotime( $tomorrow . ' 12:00 UTC' ) ) . ' 10:00';
$sb_same = is_int( $sb_r ) ? LBB_Routes::stranded_bookings( LBB_Routes::get( $sb_r ) ) : null;
if ( is_int( $sb_r ) ) {
	LBB_Routes::save( $sb_base + array( 'departures' => '14:00' ), $sb_r );
}
$sb_time = is_int( $sb_r ) ? LBB_Routes::stranded_bookings( LBB_Routes::get( $sb_r ) ) : null;
$sb_days = array_values( array_diff( array( 1, 2, 3, 4, 5, 6, 7 ), array( (int) gmdate( 'N', strtotime( $tomorrow . ' 12:00 UTC' ) ) ) ) );
if ( is_int( $sb_r ) ) {
	LBB_Routes::save( $sb_base + array( 'departures' => '10:00, 14:00', 'days' => $sb_days ), $sb_r );
}
$sb_day = is_int( $sb_r ) ? LBB_Routes::stranded_bookings( LBB_Routes::get( $sb_r ) ) : null;
if ( $sb_b ) {
	LBB_Bookings::cancel( $sb_b['id'] );
}
$sb_gone = is_int( $sb_r ) ? LBB_Routes::stranded_bookings( LBB_Routes::get( $sb_r ) ) : null;
lbb_t( 'ora sau ziua scoasă din orar, cu o rezervare pe ea, e semnalată; orarul neschimbat sau rezervarea anulată nu', array() === $sb_same && array( $sb_key => 1 ) === $sb_time && array( $sb_key => 1 ) === $sb_day && array() === $sb_gone, array( $sb_same, $sb_time, $sb_day, $sb_gone ) );
if ( is_int( $sb_r ) ) {
	LBB_Routes::delete( $sb_r );
}

// O zi în care ruta nu circulă: serverul (și formularul fără JavaScript) spune zilele de circulație.
$rd_route = array( 'days' => array( 1, 4 ) );
$rd_note  = LBB_Routes::running_days_note( $rd_route, '2026-10-13' ); // marți
$rd_on    = LBB_Routes::running_days_note( $rd_route, '2026-10-15' ); // joi
$rd_daily = LBB_Routes::running_days_note( array( 'days' => array( 1, 2, 3, 4, 5, 6, 7 ) ), '2026-10-13' );
$rd_r     = LBB_Routes::save( array( 'origin' => 'ZileA', 'destination' => 'ZileB', 'departures' => '10:00', 'days' => array( 1, 4 ), 'price' => 50, 'currency' => 'MDL', 'active' => 1 ) );
$rd_tue   = new DateTime( $tomorrow . ' 12:00' );
while ( '2' !== $rd_tue->format( 'N' ) ) {
	$rd_tue->modify( '+1 day' );
}
$rd_hold = is_int( $rd_r ) ? LBB_Bookings::create_hold( LBB_Routes::get( $rd_r ), $rd_tue->format( 'Y-m-d' ), '10:00', 1, 0, array( 'Zi Gresita' ), '+37369000095', 'rd@example.com', 'MDL' ) : null;
$_GET = array( 'page' => 'lbb-routes' );
ob_start();
LBB_Admin::page_routes();
$rd_list = ob_get_clean();
$_GET = array();
if ( is_int( $rd_r ) ) {
	LBB_Routes::delete( $rd_r );
}
lbb_t( 'lista de rute din panou: zilele cu numele întreg („luni, joi”), nu „Lu, Jo” (citit literal de cititorul de ecran)', (bool) preg_match( '#ZileA → ZileB.*?<td>luni, joi</td>#su', $rd_list ) && false === strpos( $rd_list, 'Lu, Jo' ), preg_match( '#ZileA → ZileB.{0,200}#su', $rd_list, $rd_m ) ? wp_strip_all_tags( $rd_m[0] ) : '' );
lbb_t( 'zi în care ruta nu circulă: mesajul spune „Ruta circulă doar: luni, joi.”', 'Ruta circulă doar: luni, joi.' === $rd_note && '' === $rd_on && '' === $rd_daily && is_wp_error( $rd_hold ) && false !== strpos( $rd_hold->get_error_message(), 'Ruta circulă doar: luni, joi.' ), array( $rd_note, $rd_on, $rd_daily, is_wp_error( $rd_hold ) ? $rd_hold->get_error_message() : $rd_hold ) );

// Preț pentru copii mai mare decât pentru adulți (greșeală de tastare): biroul e avertizat la salvare.
$cp_hi  = LBB_Routes::child_price_warning( array( 'price' => 240.0, 'child_price' => 2400.0, 'currency' => 'MDL' ) );
$cp_ok  = LBB_Routes::child_price_warning( array( 'price' => 240.0, 'child_price' => 150.0, 'currency' => 'MDL' ) );
$cp_eq  = LBB_Routes::child_price_warning( array( 'price' => 240.0, 'child_price' => 240.0, 'currency' => 'MDL' ) );
$cp_non = LBB_Routes::child_price_warning( array( 'price' => 240.0, 'child_price' => null, 'currency' => 'MDL' ) );
lbb_t( 'prețul de copil mai mare decât cel de adult e semnalat, cu prețurile ca pe bilet („2.400 MDL”, nu „2400.00”); egal, mai mic sau gol nu', false !== strpos( $cp_hi, '(' . LBB_WooCommerce::money( 2400, 'MDL' ) . ')' ) && false !== strpos( $cp_hi, '(' . LBB_WooCommerce::money( 240, 'MDL' ) . ')' ) && false === strpos( $cp_hi, '.00' ) && '' === $cp_ok && '' === $cp_eq && '' === $cp_non, array( $cp_hi, $cp_ok, $cp_eq, $cp_non ) );

// Lista pentru șofer cu mai multe curse în aceeași zi: deasupra tabelului, locurile pe fiecare cursă.
$dt_r = LBB_Routes::save( array( 'origin' => 'CurseA', 'destination' => 'CurseB', 'departures' => '07:00, 19:00', 'price' => 50, 'currency' => 'MDL', 'capacity' => 9, 'active' => 1 ) );
$dt_b = array();
foreach ( array( array( '07:00', 2 ), array( '19:00', 1 ), array( '07:00', 1 ) ) as $dt_x ) {
	$dt_h = is_int( $dt_r ) ? LBB_Bookings::create_hold( LBB_Routes::get( $dt_r ), $tomorrow, $dt_x[0], $dt_x[1], 0, array_fill( 0, $dt_x[1], 'Curse Test' ), '+37369000094', 'dt@example.com', 'MDL' ) : null;
	$dt_b[] = is_array( $dt_h ) ? LBB_Bookings::reserve( $dt_h['token'] ) : null;
}
$dt_tot = is_int( $dt_r ) ? LBB_Bookings::departure_totals( LBB_Bookings::manifest( $tomorrow, $dt_r ) ) : null;
$_GET   = array( 'page' => 'lbb-manifest', 'date' => $tomorrow, 'route' => $dt_r );
ob_start();
LBB_Admin::page_manifest();
$dt_html = ob_get_clean();
lbb_t( 'lista pentru șofer are în titlu data cu ziua săptămânii, ca pe bilet („21.10.2026 (miercuri)”)', false !== strpos( $dt_html, esc_html( 'Pasageri — ' . LBB_WooCommerce::date_with_day( $tomorrow ) ) ), preg_match( '#<h1[^>]*>.*?</h1>#s', $dt_html, $dw_m ) ? $dw_m[0] : '' );
lbb_t( 'lista pentru șofer: butonul „CSV” are un nume care spune ce face (cititor de ecran), care conține textul vizibil', (bool) preg_match( '#<a class="button" href="[^"]*lbb_manifest_csv[^"]*" aria-label="Descarcă lista de pasageri în CSV \(Excel\)"[^>]*>CSV</a>#u', $dt_html ), preg_match( '#<a[^>]*lbb_manifest_csv[^>]*>CSV</a>#', $dt_html, $cv_m ) ? $cv_m[0] : '' );
// Peste 20 de locuri pe cursă, lista pentru șofer scrie „25 de locuri” (acordul din română).
$ro_html = '';
if ( isset( $dt_b[0]['id'] ) ) {
	$wpdb->update( LBB_Bookings::table(), array( 'seats' => 24 ), array( 'id' => $dt_b[0]['id'] ) );
	$_GET = array( 'page' => 'lbb-manifest', 'date' => $tomorrow, 'route' => $dt_r );
	ob_start();
	LBB_Admin::page_manifest();
	$ro_html = ob_get_clean();
	$_GET = array();
	$wpdb->update( LBB_Bookings::table(), array( 'seats' => 2 ), array( 'id' => $dt_b[0]['id'] ) );
}
lbb_t( 'lista pentru șofer: „25 de locuri” la o cursă cu peste 20 de pasageri', false !== strpos( $ro_html, '<li>07:00 CurseA → CurseB: 25 de locuri' ), substr( wp_strip_all_tags( $ro_html ), 0, 400 ) );
// Un număr salvat local, cu spații (rezervări din versiuni vechi, introduse de birou): linkul de apel are totuși +373.
$tl_b = isset( $dt_b[1]['id'] ) ? $dt_b[1] : null;
if ( $tl_b ) {
	$wpdb->update( LBB_Bookings::table(), array( 'phone' => '069 000 095' ), array( 'id' => $tl_b['id'] ) );
}
$_GET = array( 'page' => 'lbb-manifest', 'date' => $tomorrow, 'route' => $dt_r );
ob_start();
LBB_Admin::page_manifest();
$tl_man = ob_get_clean();
$_GET = array( 'page' => 'lbb-bookings', 'q' => $tl_b ? $tl_b['ticket_code'] : '' );
ob_start();
LBB_Admin::page_bookings();
$tl_list = ob_get_clean();
if ( $tl_b ) {
	$wpdb->update( LBB_Bookings::table(), array( 'phone' => '+37369000094' ), array( 'id' => $tl_b['id'] ) );
}
lbb_t( 'telefonul clientului salvat local („069 000 095”) are link de apel cu +373 în lista pentru șofer și în Rezervări', $tl_b && false !== strpos( $tl_man, '<a href="tel:+37369000095">069 000 095</a>' ) && false !== strpos( $tl_list, '<a href="tel:+37369000095">069 000 095</a>' ) && false === strpos( $tl_man . $tl_list, 'tel:069' ), array( substr( wp_strip_all_tags( $tl_list ), 0, 400 ) ) );
lbb_t( 'Rezervări: coloana „Cursa” are data cu ziua săptămânii, ca pe bilet', $tl_b && false !== strpos( $tl_list, esc_html( LBB_WooCommerce::date_with_day( $tl_b['travel_date'] ) . ' ' . $tl_b['dep_time'] ) ), $tl_b ? LBB_WooCommerce::date_with_day( $tl_b['travel_date'] ) : '' );
lbb_t( 'Rezervări: prima coloană se numește „Nr.” (nu „#”, citit „diez”), și în antet, și pe telefon', false !== strpos( $tl_list, '<thead><tr><th>Nr.</th>' ) && false !== strpos( $tl_list, 'td:nth-child(1)::before{content:"Nr.:"}' ) && false === strpos( $tl_list, '<th>#</th>' ), substr( $tl_list, 0, 400 ) );
// Data scrisă de mână în lista pentru șofer (browser fără calendar): „zz.ll.aaaa” deschide ziua cerută, nu azi.
$md_dmy = gmdate( 'd.m.Y', strtotime( $tomorrow . ' 12:00' ) );
$_GET   = array( 'page' => 'lbb-manifest', 'date' => $md_dmy, 'route' => $dt_r );
ob_start();
LBB_Admin::page_manifest();
$md_html = ob_get_clean();
lbb_t( 'lista pentru șofer: data scrisă „' . $md_dmy . '” deschide ziua aceea', false !== strpos( $md_html, 'name="date" value="' . $tomorrow . '"' ) && false !== strpos( $md_html, 'Curse Test' ), substr( wp_strip_all_tags( $md_html ), 0, 300 ) );
// Ziua de azi fără pasageri pe rută: lista trimite la următoarea zi cu pasageri (mâine); după mâine nu mai e niciuna.
$_GET    = array( 'page' => 'lbb-manifest', 'date' => LBB_Settings::today(), 'route' => $dt_r );
ob_start();
LBB_Admin::page_manifest();
$nx_html = ob_get_clean();
$_GET    = array();
$nx_a    = is_int( $dt_r ) ? LBB_Bookings::next_manifest_date( LBB_Settings::today(), $dt_r ) : null;
$nx_b    = is_int( $dt_r ) ? LBB_Bookings::next_manifest_date( $tomorrow, $dt_r ) : null;
foreach ( $dt_b as $dt_x ) {
	if ( $dt_x ) {
		LBB_Bookings::cancel( $dt_x['id'] );
	}
}
$nx_c = is_int( $dt_r ) ? LBB_Bookings::next_manifest_date( LBB_Settings::today(), $dt_r ) : null;
lbb_t( 'lista pentru șofer goală trimite la următoarea zi cu pasageri pe aceeași rută; rezervările anulate nu contează', $tomorrow === $nx_a && '' === $nx_b && '' === $nx_c && (bool) preg_match( '#class="lbb-next-day" href="[^"]*date=' . $tomorrow . '[^"]*route=' . $dt_r . '"[^>]*>Următoarea zi cu pasageri: ' . preg_quote( wp_date( 'd.m.Y', strtotime( $tomorrow . ' 12:00' ) ), '#' ) . '#u', html_entity_decode( $nx_html, ENT_QUOTES, 'UTF-8' ) ), array( $nx_a, $nx_b, $nx_c, substr( wp_strip_all_tags( $nx_html ), -200 ) ) );
if ( is_int( $dt_r ) ) {
	LBB_Routes::delete( $dt_r );
}
lbb_t( 'lista pentru șofer arată locurile și suma de încasat la urcare pe fiecare cursă din zi', array( '07:00 CurseA → CurseB' => 3, '19:00 CurseA → CurseB' => 1 ) === $dt_tot && false !== strpos( $dt_html, "<li>07:00 CurseA → CurseB: 3 locuri · de încasat la urcare 150\u{00A0}MDL</li><li>19:00 CurseA → CurseB: 1 loc · de încasat la urcare 50\u{00A0}MDL</li>" ), array( $dt_tot, substr( wp_strip_all_tags( $dt_html ), 0, 400 ) ) );
// Un bilet plătit online nu intră în suma de încasat la urcare a cursei.
$dd_rows = array(
	array( 'status' => 'reserved', 'dep_time' => '07:00', 'origin' => 'A', 'destination' => 'B', 'seats' => 2, 'adults' => 2, 'children' => 0, 'route_id' => 0, 'currency' => 'MDL', 'pay_currency' => 'MDL', 'amount' => 100 ),
	array( 'status' => 'confirmed', 'dep_time' => '07:00', 'origin' => 'A', 'destination' => 'B', 'seats' => 1, 'adults' => 1, 'children' => 0, 'route_id' => 0, 'currency' => 'MDL', 'pay_currency' => 'MDL', 'amount' => 50 ),
);
$dd_due = LBB_Bookings::departure_due( $dd_rows );
lbb_t( 'suma de încasat pe cursă numără doar rezervările cu plata la urcare', array( '07:00 A → B' => array( 'MDL' => 100.0 ) ) == $dd_due, $dd_due ); // phpcs:ignore Universal.Operators.StrictComparisons -- 100 sau 100.0, după conversie.
// CSV-ul listei de pasageri: suma de la urcare are coloane numerice proprii (se adună în Excel); online = goale.
$cr_base = array( 'ticket_code' => 'LB-T1', 'passengers' => array( 'Ion Pop' ), 'phone' => '+37369000001', 'email' => 'c@example.com', 'order_id' => 0 );
// CSV cerut cu o dată stricată (link trunchiat): mesaj traductibil, răspuns 400 și link înapoi, nu „Data invalidă” fără ieșire.
$cv_user = get_current_user_id();
wp_set_current_user( 1 );
$cv_keep = array( $_GET, $_REQUEST );
$_GET     = array( 'action' => 'lbb_manifest_csv', 'date' => '2026-13-45x' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'lbb_manifest_csv' ) );
$cv_die  = null;
$cv_h    = function () use ( &$cv_die ) {
	return function ( $message, $title, $args ) use ( &$cv_die ) {
		$cv_die = array( $message, $title, $args );
		throw new Exception( 'wp_die' );
	};
};
add_filter( 'wp_die_handler', $cv_h );
try {
	LBB_Admin::manifest_csv();
} catch ( Exception $e ) {
	unset( $e );
}
remove_filter( 'wp_die_handler', $cv_h );
list( $_GET, $_REQUEST ) = $cv_keep;
wp_set_current_user( $cv_user );
lbb_t( 'CSV cu dată stricată: mesaj clar și traductibil, răspuns 400, link înapoi', is_array( $cv_die ) && false !== strpos( (string) $cv_die[0], 'Alegeți data din calendar' ) && isset( $cv_die[2]['response'], $cv_die[2]['back_link'] ) && 400 === $cv_die[2]['response'] && $cv_die[2]['back_link'], $cv_die );
// O zi care nu există („31.11.2026”, scrisă de mână): lista revine la azi, CSV-ul cere altă dată; nu 01.12 fără pasageri.
$vd_user = get_current_user_id();
wp_set_current_user( 1 );
$vd_keep = array( $_GET, $_REQUEST );
$_GET    = array( 'page' => 'lbb-manifest', 'date' => '31.11.2026' );
ob_start();
LBB_Admin::page_manifest();
$vd_page = ob_get_clean();
$_GET     = array( 'action' => 'lbb_manifest_csv', 'date' => '2026-11-31' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'lbb_manifest_csv' ) );
$cv_die   = null;
add_filter( 'wp_die_handler', $cv_h );
ob_start();
try {
	LBB_Admin::manifest_csv();
} catch ( Exception $e ) {
	unset( $e );
}
$vd_csv = ob_get_clean();
remove_filter( 'wp_die_handler', $cv_h );
list( $_GET, $_REQUEST ) = $vd_keep;
wp_set_current_user( $vd_user );
lbb_t( 'o zi care nu există (31.11): lista arată ziua de azi, nu 01.12.2026; CSV-ul cere altă dată', false !== strpos( $vd_page, esc_html( LBB_WooCommerce::date_with_day( LBB_Settings::today() ) ) ) && false === strpos( $vd_page, '01.12.2026' ) && is_array( $cv_die ) && false !== strpos( (string) $cv_die[0], 'Alegeți data din calendar' ) && '' === $vd_csv, array( is_array( $cv_die ), substr( $vd_csv, 0, 60 ) ) );
// Fără drept de acces (vizitator delogat): 403 cu link înapoi, nu 500 ca o eroare a serverului.
$cv_403 = array();
$cv_h2  = function () use ( &$cv_403 ) {
	return function ( $message, $title, $args ) use ( &$cv_403 ) {
		$cv_403[] = isset( $args['response'] ) ? $args['response'] : 500;
		throw new Exception( 'wp_die' );
	};
};
$cv_user = get_current_user_id();
wp_set_current_user( 0 );
add_filter( 'wp_die_handler', $cv_h2 );
foreach ( array( array( 'LBB_Admin', 'manifest_csv' ), array( 'LBB_Admin', 'save_settings' ), array( 'LBB_Legal', 'create' ) ) as $cv_cb ) {
	try {
		call_user_func( $cv_cb );
	} catch ( Exception $e ) {
		unset( $e );
	}
}
remove_filter( 'wp_die_handler', $cv_h2 );
wp_set_current_user( $cv_user );
lbb_t( 'acțiunile din panou (CSV, setări, pagini legale) fără drept de acces răspund 403, nu 500', array( 403, 403, 403 ) === $cv_403, $cv_403 );
$cr_res  = LBB_Admin::manifest_csv_row( '2026-10-21', array_merge( $dd_rows[0], $cr_base, array( 'passengers' => array( 'Ion Pop', 'Ana Pop' ), 'currency' => 'RON', 'pay_currency' => 'RON', 'amount' => 30.5 ) ) );
$cr_onl  = LBB_Admin::manifest_csv_row( '2026-10-21', array_merge( $dd_rows[1], $cr_base ) );
lbb_t( 'CSV-ul listei de pasageri are suma de încasat și moneda în coloane separate (goale la plata online)', 13 === count( $cr_res ) && 'la urcare 30.5 RON' === $cr_res[9] && 30.5 === $cr_res[11] && 'RON' === $cr_res[12] && 'online' === $cr_onl[9] && '' === $cr_onl[11] && '' === $cr_onl[12], array( $cr_res, $cr_onl ) );

// Separatorul CSV din Setări: „;” pentru Excel în română, cu virgulă zecimală la sume; altceva revine la „,”.
$cs_keep = get_option( 'lbb_settings' );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'csv_separator' => ';' ) ) );
$cs_semi = LBB_Settings::get( 'csv_separator' );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'csv_separator' => '|' ) ) );
$cs_bad = LBB_Settings::get( 'csv_separator' );
update_option( 'lbb_settings', $cs_keep );
$cs_f1 = LBB_Admin::csv_fields( $cr_res, ';' );
$cs_f2 = LBB_Admin::csv_fields( $cr_res, ',' );
$_GET  = array( 'page' => 'lbb-settings' );
ob_start();
LBB_Admin::page_settings();
$cs_page = ob_get_clean();
$_GET = array();
lbb_t( 'CSV: separatorul „;” se poate alege în Setări (alt semn revine la „,”); cu „;” suma are virgulă zecimală (30,5), cu „,” rămâne 30.5', ';' === $cs_semi && ',' === $cs_bad && '30,5' === $cs_f1[11] && '30.5' === (string) $cs_f2[11] && 'la urcare 30.5 RON' === $cs_f1[9] && false !== strpos( $cs_page, 'name="csv_separator"' ) && ',' === LBB_Settings::get( 'csv_separator' ), array( $cs_semi, $cs_bad, $cs_f1[11], $cs_f2[11] ) );

// Zile fără curse (sărbători): în setări, cu date scrise ca 25.12.2026 / 2026-12-25; în aceste zile nu se vinde nimic.
// Fără an („25.12, 1.1”) sau cu anul scurt („25.12.99”): următoarea apariție, respectiv 2099.
$zy_today = LBB_Settings::today();
$zy_next  = function ( $m, $d ) use ( $zy_today ) {
	$y = (int) substr( $zy_today, 0, 4 );
	return sprintf( '%04d-%02d-%02d', sprintf( '%04d-%02d-%02d', $y, $m, $d ) < $zy_today ? $y + 1 : $y, $m, $d );
};
$zy_p = LBB_Settings::parse_dates( '25.12, 1.1, 25.12.99, 32.13' );
lbb_t( 'zile fără curse: „25.12”, „1.1” fără an devin următoarea apariție, „25.12.99” devine 2099, „32.13” e semnalat', in_array( $zy_next( 12, 25 ), $zy_p['dates'], true ) && in_array( $zy_next( 1, 1 ), $zy_p['dates'], true ) && in_array( '2099-12-25', $zy_p['dates'], true ) && array( '32.13' ) === $zy_p['bad'], $zy_p );
$zc_p = LBB_Settings::parse_dates( '25.12.2099, 1/1/2100 2099-12-26 32.01.2099 abc 01.01.2000 25.12.2099' );
lbb_t( 'zile fără curse: datele se înțeleg în mai multe forme, cele greșite se semnalează, cele trecute se ignoră', array( '2099-12-25', '2099-12-26', '2100-01-01' ) === $zc_p['dates'] && array( '32.01.2099', 'abc' ) === $zc_p['bad'], $zc_p );
$zd_p = LBB_Settings::parse_dates( '24.12.2099. 26-12-2099, 31.12.99.' );
lbb_t( 'zile fără curse: punctul de la sfârșit („24.12.2099.”) și liniuțele („26-12-2099”) sunt înțelese, nu semnalate ca greșite', array( '2099-12-24', '2099-12-26', '2099-12-31' ) === $zd_p['dates'] && array() === $zd_p['bad'], $zd_p );
$zc_keep = get_option( 'lbb_settings' );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'closed_dates' => '2099-12-26; 25.12.2099 nu' ) ) );
$zc_saved = LBB_Settings::get( 'closed_dates' );
$zc_r  = LBB_Routes::save( array( 'origin' => 'InchisA', 'destination' => 'InchisB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'active' => 1 ) );
$zc_rt = is_int( $zc_r ) ? LBB_Routes::get( $zc_r ) : null;
$zc_open = $zc_rt ? count( LBB_Routes::departures_on( $zc_rt, $tomorrow ) ) : 0;
$zc_b  = $zc_rt ? LBB_Bookings::create_hold( $zc_rt, $tomorrow, '10:00', 1, 0, array( 'Inchis Unu' ), '+37369000095', 'zc@example.com', 'MDL' ) : null;
$zc_b  = is_array( $zc_b ) ? LBB_Bookings::reserve( $zc_b['token'] ) : $zc_b;
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'closed_dates' => gmdate( 'd.m.Y', strtotime( $tomorrow . ' 12:00' ) ) ) ) );
$zc_closed = $zc_rt ? LBB_Routes::departures_on( $zc_rt, $tomorrow ) : null;
$zc_hold   = $zc_rt ? LBB_Bookings::create_hold( $zc_rt, $tomorrow, '10:00', 1, 0, array( 'Inchis Doi' ), '+37369000096', 'zc@example.com', 'MDL' ) : null;
$zc_req    = new WP_REST_Request( 'GET', '/lbb/v1/departures' );
$zc_req->set_query_params( array( 'route_id' => $zc_r, 'date' => $tomorrow ) );
$zc_rest   = rest_do_request( $zc_req )->get_data();
$zc_req->set_query_params( array( 'route_id' => $zc_r, 'date' => gmdate( 'Y-m-d', strtotime( $tomorrow . ' +1 day' ) ) ) );
$zc_rest2  = rest_do_request( $zc_req )->get_data();
$zc_sold   = LBB_Bookings::sold_on_dates( array( $tomorrow, '2099-12-26' ) );
$_GET = array( 'page' => 'lbb-manifest', 'date' => $tomorrow, 'route' => $zc_r );
ob_start();
LBB_Admin::page_manifest();
$zc_man = ob_get_clean();
$_GET = array( 'page' => 'lbb-manifest', 'date' => gmdate( 'Y-m-d', strtotime( $tomorrow . ' +1 day' ) ), 'route' => $zc_r );
ob_start();
LBB_Admin::page_manifest();
$zc_man2 = ob_get_clean();
$_GET = array();
update_option( 'lbb_settings', $zc_keep );
if ( is_int( $zc_r ) ) {
	$wpdb->delete( LBB_Bookings::table(), array( 'route_id' => $zc_r ) );
	LBB_Routes::delete( $zc_r );
}
lbb_t( 'zi fără curse: REST spune „closed” (formularul arată „zi liberă”), o zi obișnuită nu', true === ( $zc_rest['closed'] ?? null ) && false === ( $zc_rest2['closed'] ?? null ) && 1 === count( $zc_rest2['departures'] ?? array() ) && false !== strpos( (string) file_get_contents( LBB_DIR . 'assets/lbb.js' ), 'data.closed ? t.closedDay' ), array( $zc_rest, $zc_rest2 ) );
lbb_t( 'lista pentru șofer într-o zi fără curse care are totuși bilete: avertizează biroul să anunțe pasagerii', false !== strpos( $zc_man, 'lbb-closed-day' ) && false !== strpos( $zc_man, 'Inchis Unu' ) && false === strpos( $zc_man2, 'lbb-closed-day' ), substr( wp_strip_all_tags( $zc_man ), 0, 300 ) );
lbb_t( 'zile fără curse: se salvează ca 26.12.2099, 25.12.2099 → sortate, în formatul de pe ecran', '25.12.2099, 26.12.2099' === $zc_saved, $zc_saved );
lbb_t( 'într-o zi fără curse nu se arată plecări și nu se poate rezerva; rezervarea făcută înainte se vede la avertizare', 1 === $zc_open && array() === $zc_closed && is_wp_error( $zc_hold ) && array() === ( $zc_rest['departures'] ?? null ) && ( $zc_sold[ $tomorrow ] ?? 0 ) >= 1 && array( $tomorrow ) === array_keys( $zc_sold ), array( $zc_open, $zc_closed, $zc_hold, $zc_rest, $zc_sold ) );

// Checkout-ul cu blocuri: datele din formular ajung pe clientul WooCommerce, dar nu peste ce are deja clientul.
$fc_c = new WC_Customer();
$fc_1 = LBB_WooCommerce::fill_customer( $fc_c, array( 'name' => 'Ana Maria Pop', 'phone' => '+37369000097', 'email' => 'fc@example.com' ) );
$fc_k = new WC_Customer();
$fc_k->set_billing_email( 'cont@example.com' );
$fc_k->set_billing_first_name( 'Ion' );
LBB_WooCommerce::fill_customer( $fc_k, array( 'name' => 'Ana Pop', 'phone' => '+37369000097', 'email' => 'fc@example.com' ) );
lbb_t( 'clientul WooCommerce primește numele, telefonul și emailul din formular, fără să le suprascrie pe cele existente', true === $fc_1 && 'Ana' === $fc_c->get_billing_first_name() && 'Maria Pop' === $fc_c->get_billing_last_name() && '+37369000097' === $fc_c->get_billing_phone() && 'fc@example.com' === $fc_c->get_billing_email() && 'cont@example.com' === $fc_k->get_billing_email() && 'Ion' === $fc_k->get_billing_first_name() && 'Pop' === $fc_k->get_billing_last_name() && false === LBB_WooCommerce::fill_customer( null, array() ) );

// Panoul principal: câți pasageri sunt azi (plătiți + la urcare), cu link spre lista pentru șofer.
$pt_before = LBB_Admin::stats()['seats_today'];
$pt_tom_before = LBB_Admin::stats()['seats_tomorrow'];
$pt_r = LBB_Routes::save( array( 'origin' => 'AziA', 'destination' => 'AziB', 'departures' => '23:59', 'price' => 50, 'currency' => 'MDL', 'active' => 1 ) );
$pt_b = is_int( $pt_r ) ? $wpdb->insert( LBB_Bookings::table(), array( 'token' => wp_generate_password( 32, false ), 'route_id' => $pt_r, 'travel_date' => LBB_Settings::today(), 'dep_time' => '23:59', 'seats' => 3, 'children' => 0, 'passengers' => '[]', 'phone' => '', 'email' => '', 'amount' => 150, 'currency' => 'MDL', 'status' => 'reserved', 'ticket_code' => 'LB-AZI' . wp_rand( 100, 999 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ) : false;
$pt_after = LBB_Admin::stats()['seats_today'];
$pt_tom = is_int( $pt_r ) ? $wpdb->insert( LBB_Bookings::table(), array( 'token' => wp_generate_password( 32, false ), 'route_id' => $pt_r, 'travel_date' => $tomorrow, 'dep_time' => '23:59', 'seats' => 2, 'children' => 0, 'passengers' => '[]', 'phone' => '', 'email' => '', 'amount' => 100, 'currency' => 'MDL', 'status' => 'confirmed', 'ticket_code' => 'LB-MAI' . wp_rand( 100, 999 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) ) : false;
$pt_tom_after = LBB_Admin::stats()['seats_tomorrow'];
ob_start();
LBB_Admin::page_dashboard();
$pt_html = ob_get_clean();
if ( is_int( $pt_r ) ) {
	$wpdb->delete( LBB_Bookings::table(), array( 'route_id' => $pt_r ) );
	LBB_Routes::delete( $pt_r );
}
lbb_t( 'panoul arată și pasagerii de mâine, cu link spre lista de mâine', 1 === $pt_tom && $pt_tom_before + 2 === $pt_tom_after && (bool) preg_match( '#lbb-card-tomorrow" href="[^"]*page=lbb-manifest&(amp;|\#038;)date=' . $tomorrow . '"><b>' . $pt_tom_after . '</b>Pasageri mâine#u', $pt_html ), array( $pt_tom, $pt_tom_before, $pt_tom_after ) );
lbb_t( 'panoul arată pasagerii de azi (plătiți + la urcare), cu link spre lista pentru șofer', 1 === $pt_b && $pt_before + 3 === $pt_after && (bool) preg_match( '#<a class="lbb-card lbb-card-today" href="[^"]*page=lbb-manifest"><b>' . $pt_after . '</b>Pasageri azi#u', $pt_html ), array( $pt_b, $pt_before, $pt_after, $wpdb->last_error ) );

// Prețul rutei scris cu spațiu sau punct la mii („1 200”, „1.200”) nu devine 1 MDL.
$pa_ok = true;
foreach ( array( '1 200' => 1200.0, "1\u{00A0}200" => 1200.0, '1.200' => 1200.0, '1.200,50' => 1200.5, '1,200.50' => 1200.5, '240' => 240.0, '240,5' => 240.5, '240.50' => 240.5, '240 MDL' => 240.0, '' => 0.0 ) as $pa_in => $pa_want ) {
	if ( $pa_want !== LBB_Routes::parse_amount( $pa_in ) ) {
		$pa_ok = false;
		echo '  prețul „' . $pa_in . '” → ' . LBB_Routes::parse_amount( $pa_in ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
$pa_r  = LBB_Routes::save( array( 'origin' => 'PretA', 'destination' => 'PretB', 'departures' => '10:00', 'price' => '1 200', 'child_price' => '1 000,50', 'currency' => 'MDL', 'active' => 1 ) );
$pa_rt = is_int( $pa_r ) ? LBB_Routes::get( $pa_r ) : null;
if ( is_int( $pa_r ) ) {
	LBB_Routes::delete( $pa_r );
}
lbb_t( 'prețul rutei scris „1 200” / „1.200,50” se salvează corect (nu 1 MDL)', $pa_ok && $pa_rt && 1200.0 === (float) $pa_rt['price'] && 1000.5 === (float) $pa_rt['child_price'], $pa_rt ? array( $pa_rt['price'], $pa_rt['child_price'] ) : $pa_r );

// Locul de urcare al rutei (autogara, peronul) apare pe bilet / în email și ca loc în calendar.
$bd_r = LBB_Routes::save( array( 'origin' => 'UrcareA', 'destination' => 'UrcareB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'active' => 1, 'boarding' => 'Autogara Nord, peronul 3' ) );
$bd_b = array( 'route_id' => $bd_r, 'travel_date' => $tomorrow, 'dep_time' => '10:00', 'seats' => 1, 'children' => 0, 'passengers' => array( 'Ion Pop' ), 'status' => 'confirmed', 'amount' => 50, 'currency' => 'MDL', 'pay_currency' => 'MDL', 'ticket_code' => 'LB-URC1' );
$bd_d = is_int( $bd_r ) ? LBB_WooCommerce::describe( $bd_b ) : array();
$bd_i = is_int( $bd_r ) ? LBB_Tickets::ics( $bd_b ) : '';
$bd_h = is_int( $bd_r ) ? LBB_Tickets::html( $bd_b ) : '';
$bd_s = is_int( $bd_r ) ? LBB_Tickets::share_text( $bd_b ) : '';
$bd_m = array();
foreach ( LBB_Routes::public_map() as $bd_o => $bd_list ) {
	foreach ( $bd_list as $bd_x ) {
		if ( $bd_x['id'] === $bd_r ) {
			$bd_m = $bd_x;
		}
	}
}
$_GET = array( 'page' => 'lbb-routes' );
ob_start();
LBB_Admin::page_routes();
$bd_list = ob_get_clean();
$_GET = array();
$bd_e = is_int( $bd_r ) ? LBB_Routes::save( array_merge( LBB_Routes::get( $bd_r ), array( 'departures' => '10:00', 'boarding' => '' ) ), $bd_r ) : null;
$bd_n = is_int( $bd_r ) ? LBB_WooCommerce::describe( $bd_b ) : array();
$bd_i2 = is_int( $bd_r ) ? LBB_Tickets::ics( $bd_b ) : '';
if ( is_int( $bd_r ) ) {
	LBB_Routes::delete( $bd_r );
}
lbb_t( 'lista de rute arată locul de urcare sub rută și câte rute active nu-l au', false !== strpos( $bd_list, '<span class="description lbb-boarding">Urcare: Autogara Nord, peronul 3</span>' ) && (bool) preg_match( '#lbb-no-boarding">\\d+ (de )?rut#u', $bd_list ), substr( wp_strip_all_tags( $bd_list ), 0, 300 ) );
lbb_t( 'lista de rute arată prețul ca în formular și pe bilet („50 MDL”), nu „50.00 MDL”', false !== strpos( $bd_list, '<td>' . esc_html( LBB_WooCommerce::money( 50, 'MDL' ) ) ) && false === strpos( $bd_list, '50.00 MDL' ), substr( wp_strip_all_tags( $bd_list ), 0, 300 ) );
$bd_rs = LBB_Tickets::share_text( array_merge( $bd_b, array( 'status' => 'reserved' ) ) );
lbb_t( '„Trimite biletul” la o rezervare cu plata la urcare scrie „Rezervare”, ca pagina, și suma de achitat la urcare; biletul plătit nu are sumă', 0 === strpos( $bd_rs, 'Rezervare LibertBus LB-URC1: ' ) && '. De achitat la urcare: ' . LBB_WooCommerce::money( 50, 'MDL' ) === substr( $bd_rs, -strlen( '. De achitat la urcare: ' . LBB_WooCommerce::money( 50, 'MDL' ) ) ) && false === strpos( $bd_s, 'De achitat' ), $bd_rs );
lbb_t( '„Trimite biletul” are și textul cursei (cod, rută, zi, oră) și locul de urcare; o rută ștearsă nu lasă text gol', 'Bilet LibertBus LB-URC1: UrcareA → UrcareB, ' . LBB_WooCommerce::date_with_day( $tomorrow ) . ', 10:00. Urcare: Autogara Nord, peronul 3' === $bd_s && false !== strpos( LBB_Tickets::share_text( array_merge( $bd_b, array( 'route_id' => 0 ) ) ), '(rută ștearsă)' ) && false === strpos( LBB_Tickets::share_text( array_merge( $bd_b, array( 'route_id' => 0 ) ) ), 'Urcare:' ), $bd_s );
lbb_t( 'locul de urcare ajunge și în formular (rezumatul de dinainte de plată)', 'Autogara Nord, peronul 3' === ( $bd_m['boarding'] ?? null ) && false !== strpos( (string) file_get_contents( LBB_DIR . 'assets/lbb.js' ), "t.boarding + ': ' + route.boarding" ), $bd_m );
lbb_t( 'locul de urcare al rutei apare pe bilet și în calendar; fără el, rândul lipsește și calendarul are orașul', 'Autogara Nord, peronul 3' === ( $bd_d['Loc de urcare'] ?? null ) && false !== strpos( $bd_h, 'Autogara Nord, peronul 3' ) && false !== strpos( $bd_i, "LOCATION:Autogara Nord\\, peronul 3\r\n" ) && ! isset( $bd_n['Loc de urcare'] ) && false !== strpos( $bd_i2, "LOCATION:UrcareA\r\n" ), array( $bd_d, $bd_n, $bd_e ) );

// Reamintirea din ziua dinaintea cursei: doar dacă e pornită, doar ziua (9–21), o singură dată, doar pentru mâine.
$rm_r  = LBB_Routes::save( array( 'origin' => 'ReamintA', 'destination' => 'ReamintB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'active' => 1, 'boarding' => 'Autogara Sud' ) );
$rm_rt = is_int( $rm_r ) ? LBB_Routes::get( $rm_r ) : null;
$rm_d2 = gmdate( 'Y-m-d', strtotime( $tomorrow . ' 12:00 +1 day' ) );
$rm_a  = $rm_rt ? LBB_Bookings::create_hold( $rm_rt, $tomorrow, '10:00', 1, 0, array( 'Ana Reamintire' ), '+37369000098', 'rm@example.com', 'MDL' ) : null;
$rm_a  = is_array( $rm_a ) ? LBB_Bookings::reserve( $rm_a['token'] ) : $rm_a;
$rm_b  = $rm_rt ? LBB_Bookings::create_hold( $rm_rt, $rm_d2, '10:00', 1, 0, array( 'Ion Poimaine' ), '+37369000099', 'rm2@example.com', 'MDL' ) : null;
$rm_b  = is_array( $rm_b ) ? LBB_Bookings::reserve( $rm_b['token'] ) : $rm_b;
$rm_keep   = get_option( 'lbb_settings' );
$lbb_mails = array();
add_filter( 'pre_wp_mail', $lbb_catch, 1, 2 );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'remind_day_before' => 0 ) ) );
$rm_off = LBB_Tickets::send_reminders( 12 );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'remind_day_before' => 1 ) ) );
// Rezervarea abia făcută (biletul tocmai a plecat pe email) nu primește încă o reamintire; una de ieri, da.
$rm_fresh = LBB_Tickets::send_reminders( 12 );
if ( is_array( $rm_a ) ) {
	$wpdb->update( LBB_Bookings::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'id' => $rm_a['id'] ) );
}
$rm_night = LBB_Tickets::send_reminders( 23 );
$rm_day   = LBB_Tickets::send_reminders( 12 );
$rm_mine  = array_values( array_filter( $lbb_mails, function ( $m ) {
	return in_array( $m['to'], array( 'rm@example.com', 'rm2@example.com' ), true ) || ( is_array( $m['to'] ) && array_intersect( $m['to'], array( 'rm@example.com', 'rm2@example.com' ) ) );
} ) );
$rm_again = LBB_Tickets::send_reminders( 12 );
$_GET = array( 'page' => 'lbb-manifest', 'date' => $tomorrow, 'route' => $rm_r );
ob_start();
LBB_Admin::page_manifest();
$rm_man = ob_get_clean();
$_GET = array( 'page' => 'lbb-manifest', 'date' => $tomorrow );
ob_start();
LBB_Admin::page_manifest();
$rm_man_all = ob_get_clean();
$_GET = array();
$_GET = array( 'page' => 'lbb-bookings', 'q' => 'rm@example.com' );
ob_start();
LBB_Admin::page_bookings();
$rm_list = ob_get_clean();
$_GET = array( 'page' => 'lbb-bookings', 'q' => 'rm2@example.com' );
ob_start();
LBB_Admin::page_bookings();
$rm_list2 = ob_get_clean();
// Codul dictat la telefon, scris fără liniuță sau cu spațiu, găsește rezervarea.
$cd_code = (string) $wpdb->get_var( 'SELECT ticket_code FROM ' . LBB_Bookings::table() . " WHERE ticket_code LIKE 'LB-%' LIMIT 1" ); // phpcs:ignore WordPress.DB
$cd_bare = substr( $cd_code, 3 );
$cd_got  = array();
foreach ( array( 'LB' . $cd_bare, 'lb ' . strtolower( $cd_bare ), 'LB_' . $cd_bare ) as $cd_q ) {
	$cd_got[ $cd_q ] = in_array( $cd_code, wp_list_pluck( LBB_Bookings::recent( 50, '', $cd_q ), 'ticket_code' ), true );
}
lbb_t( 'Rezervări: codul scris fără liniuță („LB3KVU8W”, „lb 3kvu8w”) găsește biletul', '' !== $cd_bare && ! in_array( false, $cd_got, true ), $cd_got );
// Numele căutat fără diacritice („Stefan Turcanu”) găsește pasagerul salvat „Ștefan Țurcanu”.
$dn_r  = LBB_Routes::save( array( 'origin' => 'DiacrA', 'destination' => 'DiacrB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'active' => 1 ) );
$dn_rt = is_int( $dn_r ) ? LBB_Routes::get( $dn_r ) : null;
$dn_h  = $dn_rt ? LBB_Bookings::create_hold( $dn_rt, $tomorrow, '10:00', 1, 0, array( 'Ștefan Țurcanu-Bălan' ), '+37369000077', 'dn@example.com', 'MDL' ) : null;
$dn_b  = is_array( $dn_h ) ? LBB_Bookings::reserve( $dn_h['token'] ) : $dn_h;
$dn_id = is_array( $dn_b ) ? (int) $dn_b['id'] : 0;
$dn_got = array();
foreach ( array( 'Stefan', 'turcanu', 'Turcanu-Balan', 'Ștefan', 'ștefan' ) as $dn_q ) {
	$dn_got[ $dn_q ] = in_array( $dn_id, array_map( 'intval', wp_list_pluck( LBB_Bookings::recent( 200, '', $dn_q ), 'id' ) ), true );
}
$dn_none = in_array( $dn_id, array_map( 'intval', wp_list_pluck( LBB_Bookings::recent( 200, '', 'Stefanescu' ), 'id' ) ), true );
if ( $dn_id ) {
	$wpdb->delete( LBB_Bookings::table(), array( 'id' => $dn_id ) );
}
if ( is_int( $dn_r ) ) {
	LBB_Routes::delete( $dn_r );
}
lbb_t( 'Rezervări: numele căutat fără diacritice sau cu altă literă mică/mare („Stefan”, „turcanu”, „Turcanu-Balan”, „ștefan”) găsește „Ștefan Țurcanu-Bălan”; „Stefanescu” nu', $dn_id && ! in_array( false, $dn_got, true ) && ! $dn_none, array( $dn_got, $dn_none ) );
// Căutare cu un filtru de stare fără rezultate (ex. „Anulate” rămas ales): link spre toate stările, cu aceeași căutare.
$sf_counts = LBB_Bookings::status_counts( 'rm@example.com' );
$sf_status = '';
foreach ( array( 'cancelled', 'hold', 'pending', 'confirmed', 'reserved' ) as $sf_k ) {
	if ( empty( $sf_counts[ $sf_k ] ) ) {
		$sf_status = $sf_k;
		break;
	}
}
$_GET = array( 'page' => 'lbb-bookings', 'q' => 'rm@example.com', 'status' => $sf_status );
ob_start();
LBB_Admin::page_bookings();
$sf_list = ob_get_clean();
lbb_t( 'căutare fără rezultate în filtrul ales: link „Vezi rezultatele din toate stările (N)” cu aceeași căutare', '' !== $sf_status && ! empty( $sf_counts[''] ) && false !== strpos( $sf_list, 'Vezi rezultatele din toate stările (' . (int) $sf_counts[''] . ')' ) && false !== strpos( $sf_list, 'q=rm%40example.com"' ) && false === strpos( $rm_list, 'Vezi rezultatele din toate stările' ), array( $sf_status, $sf_counts ) );
$_GET = array();
$rm_claim = $rm_b && is_array( $rm_b ) ? array( LBB_Bookings::claim_reminder( $rm_b['id'] ), LBB_Bookings::claim_reminder( $rm_b['id'] ) ) : array();
// Zi închisă sau oră scoasă din orar: fără „vă reamintim de cursa de mâine”.
$rm_c = $rm_rt ? LBB_Bookings::create_hold( $rm_rt, $tomorrow, '10:00', 1, 0, array( 'Ana Inchis' ), '+37369000097', 'rm3@example.com', 'MDL' ) : null;
$rm_c = is_array( $rm_c ) ? LBB_Bookings::reserve( $rm_c['token'] ) : $rm_c;
if ( is_array( $rm_c ) ) {
	$wpdb->update( LBB_Bookings::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), array( 'id' => $rm_c['id'] ) );
}
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'closed_dates' => gmdate( 'd.m.Y', strtotime( $tomorrow . ' 12:00' ) ) ) ) );
$rm_closed = LBB_Tickets::send_reminders( 12 );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'closed_dates' => '' ) ) );
if ( is_int( $rm_r ) ) {
	$wpdb->update( LBB_Routes::table(), array( 'departures' => '11:00' ), array( 'id' => $rm_r ) );
}
$rm_moved = LBB_Tickets::send_reminders( 12 );
$rm_n3    = count( array_filter( $lbb_mails, function ( $m ) {
	return 'rm3@example.com' === $m['to'];
} ) );
$rm_n2    = count( array_filter( $lbb_mails, function ( $m ) {
	return 'rm@example.com' === $m['to'];
} ) );
remove_filter( 'pre_wp_mail', $lbb_catch, 1 );
update_option( 'lbb_settings', $rm_keep );
if ( is_int( $rm_r ) ) {
	$wpdb->delete( LBB_Bookings::table(), array( 'route_id' => $rm_r ) );
	LBB_Routes::delete( $rm_r );
}
$rm_m = $rm_mine ? $rm_mine[0] : array( 'to' => '', 'subject' => '', 'message' => '' );
lbb_t( 'reamintirea nu pleacă pentru o rezervare făcută în ultimele 12 ore (biletul tocmai a fost trimis)', 0 === $rm_fresh && $rm_day >= 1, array( $rm_fresh, $rm_day ) );
lbb_t( 'lista pentru șofer pe o singură rută arată sus ruta și locul de urcare (nu și pe toate rutele)', false !== strpos( $rm_man, '<p class="lbb-manifest-route"><strong>ReamintA → ReamintB</strong> · Loc de urcare: Autogara Sud</p>' ) && false === strpos( $rm_man_all, 'lbb-manifest-route' ), substr( wp_strip_all_tags( $rm_man ), 0, 300 ) );
lbb_t( 'reamintirea se „revendică” atomic: a doua încercare pe aceeași rezervare nu mai trimite', array( true, false ) === $rm_claim, $rm_claim );
lbb_t( 'Rezervări arată când a plecat reamintirea (și nimic la cele fără reamintire)', (bool) preg_match( '#<small class="lbb-reminded">reamintit \\d\\d\\.\\d\\d \\d\\d:\\d\\d</small>#', $rm_list ) && false === strpos( $rm_list2, 'lbb-reminded' ) && false !== strpos( $rm_list2, 'Ion Poimaine' ), substr( wp_strip_all_tags( $rm_list ), -300 ) );
lbb_t( 'reamintirea de mâine: oprită implicit, nu noaptea, o singură dată, doar pentru cursele de mâine, nu în zilele fără curse sau la ore scoase din orar, cu biletul și locul de urcare', is_array( $rm_a ) && is_array( $rm_b ) && 0 === (int) LBB_Settings::defaults()['remind_day_before'] && 0 === $rm_off && 0 === $rm_night && $rm_day >= 1 && 1 === count( $rm_mine ) && 'rm@example.com' === $rm_m['to'] && 0 === strpos( $rm_m['subject'], 'Mâine: ReamintA → ReamintB, ora 10:00 — ' . $rm_a['ticket_code'] ) && false !== strpos( $rm_m['message'], 'Plata se face la urcare' ) && false !== strpos( $rm_m['message'], 'Autogara Sud' ) && 0 === $rm_again && 1 === $rm_n2 && is_array( $rm_c ) && 0 === $rm_closed && 0 === $rm_moved && 0 === $rm_n3, array( $rm_off, $rm_night, $rm_day, $rm_again, $rm_closed, $rm_moved, $rm_n3, count( $rm_mine ), $rm_m['subject'] ) );

// Politica de confidențialitate numește și emailul de reamintire (scopul prelucrării emailului).
$lg_m = new ReflectionMethod( 'LBB_Legal', 'content' );
$lg_m->setAccessible( true );
lbb_t( 'politica de confidențialitate numește reamintirea cursei printre scopuri', false !== strpos( (string) $lg_m->invoke( null, 'privacy' ), 'reamintirea cursei cu o zi înainte' ) );

// Un text mai lung decât coloana (loc de urcare, oraș) nu mai trece drept „salvat”: biroul primește un mesaj clar.
$ln_r = LBB_Routes::save( array( 'origin' => 'LungA', 'destination' => 'LungB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'active' => 1 ) );
$ln_u = is_int( $ln_r ) ? LBB_Routes::save( array( 'origin' => 'LungA', 'destination' => 'LungB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'active' => 1, 'boarding' => str_repeat( 'Autogara ', 40 ) ), $ln_r ) : null;
$ln_n = LBB_Routes::save( array( 'origin' => str_repeat( 'Oraș', 30 ), 'destination' => 'LungC', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'active' => 1 ) );
$ln_ok = is_int( $ln_r ) ? LBB_Routes::save( array( 'origin' => 'LungA', 'destination' => 'LungB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'active' => 1, 'boarding' => str_repeat( 'ă', 255 ) ), $ln_r ) : null;
$ln_b = is_int( $ln_r ) ? LBB_Routes::get( $ln_r )['boarding'] : '';
if ( is_int( $ln_r ) ) {
	LBB_Routes::delete( $ln_r );
}
if ( is_int( $ln_n ) && $ln_n ) {
	LBB_Routes::delete( $ln_n );
}
lbb_t( 'rută cu un câmp prea lung: eroare clară, nu „salvată”; 255 de caractere (cu diacritice) încap', is_wp_error( $ln_u ) && false !== strpos( $ln_u->get_error_message(), 'Locul de urcare e prea lung' ) && is_wp_error( $ln_n ) && false !== strpos( $ln_n->get_error_message(), 'Plecarea e prea lung' ) && $ln_ok === $ln_r && 255 === mb_strlen( $ln_b ), array( $ln_u, $ln_n, $ln_ok, mb_strlen( $ln_b ) ) );

// Paginile legale din Setări pentru cine nu poate edita paginile: titlul fără link, fără avertismente PHP
// (get_edit_post_link() dă null, iar esc_url( null ) scria „Deprecated” în debug.log); adminul vede linkul de editare.
$lg_id   = LBB_Legal::page_id( 'privacy' );
$lg_user = get_current_user_id();
$lg_warn = array();
set_error_handler( function ( $no, $str ) use ( &$lg_warn ) {
	$lg_warn[] = $str;
	return true;
}, E_DEPRECATED | E_NOTICE | E_WARNING | E_USER_DEPRECATED | E_USER_NOTICE | E_USER_WARNING );
wp_set_current_user( 0 );
ob_start();
LBB_Legal::settings_box();
$lg_anon = ob_get_clean();
restore_error_handler();
$lg_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $lg_admins ? (int) $lg_admins[0] : 1 );
ob_start();
LBB_Legal::settings_box();
$lg_adm = ob_get_clean();
wp_set_current_user( $lg_user );
lbb_t( 'paginile legale din Setări fără drept de editare: titlul fără link și fără avertismente PHP; adminul are linkul de editare', $lg_id && ! $lg_warn && false !== strpos( $lg_anon, esc_html( get_the_title( $lg_id ) ) ) && false === strpos( $lg_anon, 'post.php?post=' . $lg_id ) && false !== strpos( $lg_adm, 'post.php?post=' . $lg_id ), array( $lg_id, $lg_warn ) );

// Data „actualizată la” din politici e în fusul orarului (Chișinău), și cu fusul WordPress pus „UTC+2” fix.
$tzl_keep = array( get_option( 'timezone_string' ), get_option( 'gmt_offset' ) );
update_option( 'timezone_string', '' );
update_option( 'gmt_offset', 2 );
$tzl_seen = array();
$tzl_cb   = function ( $out, $format, $ts, $tz ) use ( &$tzl_seen ) {
	if ( 'd.m.Y' === $format ) {
		$tzl_seen[] = $tz->getName();
	}
	return $out;
};
add_filter( 'wp_date', $tzl_cb, 10, 4 );
$tzl_m = new ReflectionMethod( 'LBB_Legal', 'content' );
$tzl_m->setAccessible( true );
$tzl_m->invoke( null, 'privacy' );
$tzl_m->invoke( null, 'cookies' );
remove_filter( 'wp_date', $tzl_cb, 10 );
update_option( 'timezone_string', $tzl_keep[0] );
update_option( 'gmt_offset', $tzl_keep[1] );
lbb_t( 'politici: data „actualizată la” în ora Chișinăului, nu în decalajul fix UTC+2', array( 'Europe/Chisinau', 'Europe/Chisinau' ) === $tzl_seen, $tzl_seen );

// Accesibilitate în admin: explicația de sub un câmp e legată de el (aria-describedby), fiecare id o singură dată.
ob_start();
LBB_Admin::page_settings();
$ad_set = ob_get_clean();
// Linkurile care se deschid într-o filă nouă (Setări: previzualizarea; comanda: biletul) o spun cititorului de ecran.
preg_match_all( '#<a [^>]*target="_blank"[^>]*>.*?</a>#s', $ad_set . $ot_html, $nt_links );
$nt_bad = array_filter( $nt_links[0], function ( $a ) {
	return false === strpos( $a, '<span class="screen-reader-text"> (se deschide într-o filă nouă)</span>' );
} );
lbb_t( 'admin: linkurile cu target="_blank" anunță cititorului de ecran fila nouă', count( $nt_links[0] ) >= 2 && ! $nt_bad, array( count( $nt_links[0] ), array_values( $nt_bad ) ) );
$_GET = array( 'page' => 'lbb-routes', 'add' => 1 );
ob_start();
LBB_Admin::page_routes();
$ad_rt = ob_get_clean();
$_GET = array();
$ad_ok = true;
foreach ( array( $ad_set, $ad_rt ) as $ad_html ) {
	preg_match_all( '/aria-describedby="([^"]+)"/', $ad_html, $ad_m );
	foreach ( $ad_m[1] as $ad_id ) {
		if ( 1 !== substr_count( $ad_html, 'id="' . $ad_id . '"' ) ) {
			$ad_ok = false;
		}
	}
}
lbb_t( 'admin: explicațiile câmpurilor sunt legate de câmp (aria-describedby spre un id unic)', $ad_ok && false !== strpos( $ad_set, 'aria-describedby="lbb-closed_dates-desc"' ) && false !== strpos( $ad_rt, 'aria-describedby="lbb-boarding-desc"' ) && false !== strpos( $ad_rt, 'aria-describedby="lbb-cap-desc"' ) );

// IDNO-ul scris cu spații sau puncte se salvează doar cu cifrele (panoul cere 13 cifre).
$id_keep = get_option( 'lbb_settings' );
LBB_Settings::save( array_merge( LBB_Settings::all(), array( 'company_idno' => ' 1003 600.123-456 ' ) ) );
$id_saved = LBB_Settings::get( 'company_idno' );
update_option( 'lbb_settings', $id_keep );
lbb_t( 'IDNO-ul cu spații/puncte se salvează ca 13 cifre lipite', '1003600123456' === $id_saved, $id_saved );

// Pe pagina biletului: „Adaugă în calendar” (.ics) cu ora plecării în UTC, memento și rânduri de cel mult 75 de octeți.
$ic_r = LBB_Routes::save( array( 'origin' => 'Fălești, centru', 'destination' => 'Târgu Mureș', 'departures' => '10:15', 'price' => 50, 'currency' => 'MDL', 'active' => 1 ) );
$ic_h = is_int( $ic_r ) ? LBB_Bookings::create_hold( LBB_Routes::get( $ic_r ), $tomorrow, '10:15', 2, 0, array( 'Calendar Unu', 'Calendar Doi' ), '+37369000093', 'ic@example.com', 'MDL' ) : null;
$ic_b = is_array( $ic_h ) ? LBB_Bookings::reserve( $ic_h['token'] ) : null;
$ic   = $ic_b ? LBB_Tickets::ics( LBB_Bookings::get_by_code( $ic_b['ticket_code'] ) ) : '';
$ic_s = ( new DateTimeImmutable( $tomorrow . ' 10:15', LBB_Settings::tz() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
$ic_l = $ic ? explode( "\r\n", rtrim( $ic, "\r\n" ) ) : array();
$ic_long = array_filter( $ic_l, function ( $l ) {
	return strlen( $l ) > 75 || ! mb_check_encoding( $l, 'UTF-8' );
} );
$ic_flat = str_replace( "\r\n ", '', $ic );
lbb_t( 'calendarul rezervării cu plata la urcare spune „Rezervare” și suma de achitat la șofer (biletul plătit online: „Bilet”, fără sumă)', false !== strpos( $ic_flat, "De achitat la urcare: 100\u{00A0}MDL." ) && false !== strpos( $ic_flat, 'DESCRIPTION:Rezervare ' . $ic_b['ticket_code'] ) && false === strpos( str_replace( "\r\n ", '', $bd_i ), 'De achitat' ) && false !== strpos( str_replace( "\r\n ", '', $bd_i ), 'DESCRIPTION:Bilet LB-URC1' ), substr( $ic_flat, 0, 600 ) );
$ic_keep = get_option( 'lbb_settings' );
update_option( 'lbb_settings', array_merge( (array) $ic_keep, array( 'support_phone' => '069 184 111' ) ) );
$ic_tel = $ic_b ? str_replace( "\r\n ", '', LBB_Tickets::ics( LBB_Bookings::get_by_code( $ic_b['ticket_code'] ) ) ) : '';
update_option( 'lbb_settings', array_merge( (array) $ic_keep, array( 'support_phone' => '' ) ) );
$ic_notel = $ic_b ? str_replace( "\r\n ", '', LBB_Tickets::ics( LBB_Bookings::get_by_code( $ic_b['ticket_code'] ) ) ) : '';
update_option( 'lbb_settings', $ic_keep );
lbb_t( 'calendarul are telefonul biroului cu prefixul țării; fără telefon în setări nu apare „Telefon:”', (bool) preg_match( '/^DESCRIPTION:Rezervare [^\r\n]* Telefon: \+37369184111\r$/m', $ic_tel ) && false === strpos( $ic_notel, 'Telefon:' ), substr( $ic_tel, 0, 700 ) );
$ic_http = $ic_b ? wp_remote_get( add_query_arg( 'ics', 1, LBB_Tickets::url( $ic_b['ticket_code'] ) ), array( 'timeout' => 20 ) ) : null;
$ic_type = is_array( $ic_http ) ? wp_remote_retrieve_header( $ic_http, 'content-type' ) : '';
$ic_page = $ic_b ? wp_remote_retrieve_body( wp_remote_get( LBB_Tickets::url( $ic_b['ticket_code'] ), array( 'timeout' => 20 ) ) ) : '';
$ic_mail = $ic_b ? LBB_Tickets::html( LBB_Bookings::get_by_code( $ic_b['ticket_code'] ) ) : '';
if ( $ic_b ) {
	LBB_Bookings::cancel( $ic_b['id'] );
}
$ic_mail_x = $ic_b ? LBB_Tickets::html( LBB_Bookings::get_by_code( $ic_b['ticket_code'] ) ) : '';
$ic_css = apply_filters( 'woocommerce_email_styles', '', null );
lbb_t( 'tabelul biletului ține spațierea în emailurile WooCommerce pe telefon (regulă mai specifică în media query)', (bool) preg_match( '/@media screen and \(max-width: 600px\)\{#body_content \.lbb-ticket td[^}]*padding: 3px[^}]*!important/', $ic_css ), $ic_css );
lbb_t( 'emailul cu biletul valabil are și „Adaugă în calendar”; după anulare nu', false !== strpos( $ic_mail, 'Adaugă în calendar' ) && false !== strpos( $ic_mail, 'ics=1' ) && false === strpos( $ic_mail_x, 'Adaugă în calendar' ), array( substr( wp_strip_all_tags( $ic_mail ), -160 ), substr( wp_strip_all_tags( $ic_mail_x ), -160 ) ) );
$ic_page_x = $ic_b ? wp_remote_retrieve_body( wp_remote_get( LBB_Tickets::url( $ic_b['ticket_code'] ), array( 'timeout' => 20 ) ) ) : '';
$ic_again  = LBB_Tickets::booking_page_url( null );
lbb_t( 'pagina biletului anulat are „Rezervați din nou online” spre pagina cu formularul; biletul valabil nu', $ic_again && false !== strpos( $ic_page_x, 'class="again" href="' . esc_url( $ic_again ) . '"' ) && false === strpos( $ic_page, 'Rezervați din nou online' ), array( $ic_again, substr( wp_strip_all_tags( $ic_page_x ), 0, 600 ) ) );
$ic_gone = $ic_b ? wp_remote_retrieve_header( wp_remote_get( add_query_arg( 'ics', 1, LBB_Tickets::url( $ic_b['ticket_code'] ) ), array( 'timeout' => 20 ) ), 'content-type' ) : '';
if ( is_int( $ic_r ) ) {
	LBB_Routes::delete( $ic_r );
}
lbb_t( 'bilet: .ics cu plecarea în UTC, memento cu 2 ore înainte, virgule escapate, rânduri ≤75 octeți; linkul apare doar pe biletul valabil', $ic && 0 === strpos( $ic, "BEGIN:VCALENDAR\r\n" ) && false !== strpos( $ic, 'DTSTART:' . $ic_s . "\r\n" ) && false !== strpos( $ic, 'TRIGGER:-PT2H' ) && false !== strpos( $ic_flat, 'LOCATION:Fălești\\, centru' ) && false !== strpos( $ic_flat, 'Fălești\\, centru → Târgu Mureș' ) && ! $ic_long && 0 === strpos( (string) $ic_type, 'text/calendar' ) && false !== strpos( $ic_page, 'Adaugă în calendar' ) && false === strpos( $ic_page, 'Achită acum' ) && 0 !== strpos( (string) $ic_gone, 'text/calendar' ), array( $ic_type, $ic_gone, array_values( $ic_long ), substr( $ic, 0, 400 ) ) );

// Calendarul ține cont de ora de iarnă/vară a Moldovei: 08:45 înseamnă 06:45 UTC iarna și 05:45 UTC vara
// (inclusiv imediat după schimbarea orei din 25 octombrie 2026).
$dst_r   = LBB_Routes::find( 'Bălți', 'Iași' );
$dst_ics = function ( $date ) use ( $dst_r ) {
	preg_match( '/DTSTART:(\S+)/', LBB_Tickets::ics( array( 'route_id' => $dst_r['id'], 'travel_date' => $date, 'dep_time' => '08:45', 'ticket_code' => 'LB-DSTTST', 'seats' => 1 ) ), $m );
	return isset( $m[1] ) ? $m[1] : '';
};
$dst_got = $dst_r ? array( $dst_ics( '2026-10-24' ), $dst_ics( '2026-10-26' ), $dst_ics( '2027-01-15' ), $dst_ics( '2027-07-15' ) ) : array();
lbb_t( 'calendar: ora plecării în UTC respectă ora de vară și de iarnă (Europe/Chisinau)', array( '20261024T054500Z', '20261026T064500Z', '20270115T064500Z', '20270715T054500Z' ) === $dst_got, $dst_got );
// Un text stricat (octeți UTF-8 invalizi, aici dintr-o traducere) nu blochează .ics: tăietura rândului nu ajunge la 0.
$bad_tr = function ( $tr, $text ) {
	return 0 === strpos( $text, 'Bilet %1$s' ) ? str_repeat( "\x80", 200 ) : $tr;
};
add_filter( 'gettext', $bad_tr, 10, 2 );
$bad_ics = $dst_r ? LBB_Tickets::ics( array( 'route_id' => $dst_r['id'], 'travel_date' => '2027-01-15', 'dep_time' => '08:45', 'ticket_code' => 'LB-BADTXT', 'seats' => 1 ) ) : '';
remove_filter( 'gettext', $bad_tr, 10 );
$bad_long = array_filter( explode( "\r\n", $bad_ics ), function ( $l ) {
	return strlen( $l ) > 75;
} );
lbb_t( 'calendar: text cu octeți invalizi se împarte în rânduri ≤75 octeți, fără buclă infinită', '' !== $bad_ics && false !== strpos( $bad_ics, 'END:VCALENDAR' ) && ! $bad_long, strlen( $bad_ics ) );

// Lista Rezervări are o limită: când e atinsă, sub tabel scrie că se văd doar ultimele și cum le găsiți pe celelalte.
$bl_page = function ( $limit ) {
	$f = function () use ( $limit ) {
		return $limit;
	};
	add_filter( 'lbb_bookings_list_limit', $f );
	$_GET = array( 'page' => 'lbb-bookings' );
	ob_start();
	LBB_Admin::page_bookings();
	$html = ob_get_clean();
	$_GET = array();
	remove_filter( 'lbb_bookings_list_limit', $f );
	return $html;
};
$bl_small = $bl_page( 1 );
$bl_20    = $bl_page( 20 );
$bl_3     = $bl_page( 3 );
$bl_big   = $bl_page( 100000 );
lbb_t( 'Rezervări: mesajul limitei în română corectă („ultima rezervare”, „ultimele 3 rezervări”, „ultimele 20 de rezervări”)', false !== strpos( $bl_small, 'Se arată doar ultima rezervare.' ) && false !== strpos( $bl_3, 'Se arată doar ultimele 3 rezervări.' ) && false !== strpos( $bl_20, 'Se arată doar ultimele 20 de rezervări.' ), array( LBB_Bookings::status_counts( '' ) ) );
lbb_t( 'Rezervări: la limita listei apare „Se arată doar ultimele…”, sub limită nu', false !== strpos( $bl_small, 'Se arată doar ultima' ) && false === strpos( $bl_big, 'Se arată doar ultim' ), substr( wp_strip_all_tags( $bl_small ), -200 ) );

// În lista de rute, „oprită” spune și de ce: debifată sau fără preț.
$rs_ok   = LBB_Admin::route_state( array( 'active' => 1, 'price' => 240.0 ) );
$rs_off  = LBB_Admin::route_state( array( 'active' => 0, 'price' => 240.0 ) );
$rs_free = LBB_Admin::route_state( array( 'active' => 1, 'price' => 0.0 ) );
lbb_t( 'lista de rute arată de ce o rută e oprită (inactivă / fără preț)', true === $rs_ok[0] && false === $rs_off[0] && false !== strpos( $rs_off[1], 'inactivă' ) && false === $rs_free[0] && false !== strpos( $rs_free[1], 'fără preț' ), array( $rs_ok, $rs_off, $rs_free ) );

// API-ul public pentru ore și locuri (singurul fără autentificare): validează intrarea, nu arată rute inactive, nu se pune în cache.
$api = function ( $params ) {
	$req = new WP_REST_Request( 'GET', '/lbb/v1/departures' );
	$req->set_query_params( $params );
	return rest_do_request( $req );
};
$api_ok = $api( array( 'route_id' => $rid2, 'date' => $tomorrow ) );
lbb_t( 'API: ruta activă întoarce plecările, fără cache', 200 === $api_ok->get_status() && ! empty( $api_ok->get_data()['departures'] ) && 'no-store' === ( $api_ok->get_headers()['Cache-Control'] ?? '' ), array( $api_ok->get_status(), $api_ok->get_headers() ) );
// Pagina din cache vechi: API-ul dă o cheie proaspătă a formularului, valabilă pentru un vizitator nelogat.
$nc_user = get_current_user_id();
wp_set_current_user( 0 );
$nc_data = $api( array( 'route_id' => $rid2, 'date' => $tomorrow ) )->get_data();
$nc_ok   = ! empty( $nc_data['nonce'] ) && false !== wp_verify_nonce( $nc_data['nonce'], 'lbb_book' );
$nc_ent  = null;
foreach ( LBB_Routes::public_map() as $nc_list ) {
	foreach ( $nc_list as $nc_e ) {
		if ( (int) $nc_e['id'] === (int) $rid2 ) {
			$nc_ent = $nc_e;
		}
	}
}
$nc_ok   = $nc_ok && $nc_ent && $nc_ent === ( $nc_data['route'] ?? null );
$nc_ok   = $nc_ok && LBB_Settings::today() === ( $nc_data['today'] ?? '' ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $nc_data['maxDate'] ?? '' ) && $nc_data['maxDate'] > $nc_data['today'];
wp_set_current_user( $nc_user );
$nc_cfg  = preg_match( '/data-lbb-config="([^"]+)"/', LBB_Frontend::shortcode( array() ), $nc_m ) ? json_decode( base64_decode( html_entity_decode( $nc_m[1], ENT_QUOTES ) ), true ) : array();
lbb_t( 'API: cheia formularului, ziua de azi și prețul rutei vin proaspete (pagina poate fi din cache), iar formularul știe dacă vizitatorul e nelogat', $nc_ok && array_key_exists( 'guest', $nc_cfg ) && ( 0 === $nc_user ) === $nc_cfg['guest'], array( $nc_data, isset( $nc_cfg['guest'] ) ? $nc_cfg['guest'] : null ) );
lbb_t( 'API: data în alt format e refuzată (400)', 400 === $api( array( 'route_id' => $rid2, 'date' => '07.10.2026' ) )->get_status() );
lbb_t( 'API: fără rută e refuzat (400)', 400 === $api( array( 'date' => $tomorrow ) )->get_status() );
lbb_t( 'API: ruta inexistentă dă 404', 404 === $api( array( 'route_id' => 999999, 'date' => $tomorrow ) )->get_status() );
// Date care trec de format, dar nu se pot rezerva: zi imposibilă, zi trecută, prea departe în viitor.
$api_odd = array();
foreach ( array( '2026-02-31', '2026-13-01', '2020-01-01', '2099-01-01' ) as $api_d ) {
	$api_r = $api( array( 'route_id' => $rid2, 'date' => $api_d ) );
	$api_b = array_filter( (array) ( $api_r->get_data()['departures'] ?? array() ), function ( $dep ) {
		return ! empty( $dep['bookable'] );
	} );
	$api_odd[ $api_d ] = array( $api_r->get_status(), count( $api_b ) );
}
$api_past = $api( array( 'route_id' => $rid2, 'date' => gmdate( 'Y-m-d', strtotime( LBB_Settings::today() . ' -1 day' ) ) ) )->get_data();
lbb_t( 'API: o zi trecută nu arată orele și locurile ocupate atunci (ocuparea din trecut nu e publică)', array() === ( $api_past['departures'] ?? null ), $api_past['departures'] ?? null );
lbb_t( 'API: zi imposibilă (31 februarie), trecută sau peste ani: răspuns normal, nicio oră de rezervat', array( '2026-02-31' => array( 200, 0 ), '2026-13-01' => array( 200, 0 ), '2020-01-01' => array( 200, 0 ), '2099-01-01' => array( 200, 0 ) ) === $api_odd, $api_odd );
// Erorile trec prin același filtru ca la o cerere HTTP adevărată: și ele sunt fără cache (un 404 păstrat într-un cache de pagini ar ascunde ruta pornită din nou).
$api_nc = function ( $params ) use ( $api ) {
	$req = new WP_REST_Request( 'GET', '/lbb/v1/departures' );
	$req->set_query_params( $params );
	$res = apply_filters( 'rest_post_dispatch', rest_ensure_response( $api( $params ) ), rest_get_server(), $req );
	return array( $res->get_status(), $res->get_headers()['Cache-Control'] ?? '' );
};
$api_nc_404 = $api_nc( array( 'route_id' => 999999, 'date' => $tomorrow ) );
$api_nc_400 = $api_nc( array( 'route_id' => $rid2, 'date' => '07.10.2026' ) );
// Filtrul „fără cache” atinge doar API-ul plugin-ului: restul (WordPress, Store API WooCommerce) își păstrează antetele.
$nc_other = new WP_REST_Request( 'GET', '/wp/v2/types' );
$nc_other = apply_filters( 'rest_post_dispatch', rest_ensure_response( array( 'ok' => 1 ) ), rest_get_server(), $nc_other );
$nc_store = new WP_REST_Request( 'GET', '/wc/store/v1/products' );
$nc_store = apply_filters( 'rest_post_dispatch', rest_ensure_response( array( 'ok' => 1 ) ), rest_get_server(), $nc_store );
lbb_t( 'API: „fără cache” doar pe rutele lbb/v1, nu pe restul API-ului WordPress și WooCommerce', ! isset( $nc_other->get_headers()['Cache-Control'] ) && ! isset( $nc_store->get_headers()['Cache-Control'] ), array( $nc_other->get_headers(), $nc_store->get_headers() ) );
lbb_t( 'API: și răspunsurile de eroare (404, 400) sunt fără cache', array( 404, 'no-store' ) === $api_nc_404 && array( 400, 'no-store' ) === $api_nc_400, array( $api_nc_404, $api_nc_400 ) );
lbb_t( 'API: o dată imposibilă (31 februarie) nu are plecări', 200 === ( $api_imp = $api( array( 'route_id' => $rid2, 'date' => ( (int) gmdate( 'Y' ) + 1 ) . '-02-31' ) ) )->get_status() && array() === array_filter( $api_imp->get_data()['departures'], function ( $d ) { return ! empty( $d['bookable'] ); } ), $api_imp->get_data() );
$wpdb->update( LBB_Routes::table(), array( 'active' => 0 ), array( 'id' => $rid2 ) );
$api_off  = $api( array( 'route_id' => $rid2, 'date' => $tomorrow ) )->get_status();
$book_off = LBB_Frontend::book( array( 'lbb_route' => $rid2 ) );
$wpdb->update( LBB_Routes::table(), array( 'active' => 1 ), array( 'id' => $rid2 ) );
lbb_t( 'API: o rută dezactivată nu se mai arată (404)', 404 === $api_off && LBB_Routes::get( $rid2 )['active'], $api_off );
// O rută fără preț nu apare în formular: nici API-ul nu o arată, iar o cerere făcută de mână e refuzată.
$fp_price = LBB_Routes::get( $rid2 )['price'];
$wpdb->update( LBB_Routes::table(), array( 'price' => 0 ), array( 'id' => $rid2 ) );
$api_free  = $api( array( 'route_id' => $rid2, 'date' => $tomorrow ) )->get_status();
$book_free = LBB_Frontend::book( array( 'lbb_route' => $rid2 ) );
$wpdb->update( LBB_Routes::table(), array( 'price' => $fp_price ), array( 'id' => $rid2 ) );
lbb_t( 'rută dezactivată sau fără preț: API-ul dă 404, iar rezervarea trimisă direct e refuzată', 404 === $api_free && is_wp_error( $book_off ) && 'lbb_route' === $book_off->get_error_code() && is_wp_error( $book_free ) && 'lbb_route' === $book_free->get_error_code() && (float) LBB_Routes::get( $rid2 )['price'] === (float) $fp_price, array( $api_free, $book_off, $book_free ) );

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
lbb_t( 'cookies pornite: banner, linkuri în subsol și Google Analytics blocat; bannerul și linkurile au limba textului (lang)', false !== strpos( $on, 'id="lbb-cc" lang="' . LBB_Settings::text_lang() . '"' ) && false !== strpos( $on, 'id="lbb-legal-links" lang="' . LBB_Settings::text_lang() . '"' ) && false !== strpos( $on, 'data-lbb-consent="statistics" async src="https://www.googletagmanager.com' ), strlen( $on ) );
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
$hp_post = function ( $trap, $field = 'lbb_hp' ) use ( $hp_rid, $tomorrow, $hp_nonce, $hp_form ) {
	$body = array(
		'lbb_action' => 'book', 'lbb_mode' => 'reserve', 'lbb_nonce' => isset( $hp_nonce[1] ) ? $hp_nonce[1] : '', 'lbb_form' => isset( $hp_form[1] ) ? $hp_form[1] : '',
		$field => $trap, 'lbb_route' => $hp_rid, 'lbb_date' => $tomorrow, 'lbb_time' => '10:00', 'lbb_adults' => 1,
		'lbb_names' => array( 'Ion Capcană' ), 'lbb_phone' => '+3736' . wp_rand( 1000000, 9999999 ), 'lbb_email' => 'hp@example.com', 'lbb_currency' => 'MDL',
	);
	return wp_remote_post( home_url( '/balti-iasi/' ), array( 'timeout' => 20, 'redirection' => 0, 'body' => $body ) );
};
$hp_count = function () use ( $wpdb, $hp_rid ) {
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LBB_Bookings::table() . ' WHERE route_id = %d', $hp_rid ) );
};
$hp_r = $hp_post( 'http://spam.example' );
lbb_t( 'robot (câmpul-capcană completat): refuzat, fără rezervare; mesajul are telefonul biroului (link de apel), capcana nu are eticheta „Website”', ! is_wp_error( $hp_r ) && 200 === wp_remote_retrieve_response_code( $hp_r ) && false !== strpos( wp_remote_retrieve_body( $hp_r ), 'Cererea nu a putut fi procesată. Pentru rezervare sunați la ' . LBB_Settings::phone_link() ) && ! preg_match( '/class="lbb-hp"[^>]*><label>\s*Website/i', $hp_page ) && false !== strpos( $hp_page, 'name="lbb_hp"' ) && 0 === $hp_count(), is_wp_error( $hp_r ) ? $hp_r : wp_remote_retrieve_response_code( $hp_r ) );
// Numele capcanei nu seamănă cu un câmp pe care managerii de parole îl completează singuri (website, url, email…);
// trimisă cu numele vechi (pagină din cache), tot e prinsă.
preg_match( '/class="lbb-hp".*?name="([^"]+)"/s', $hp_page, $hp_name );
$hp_old = $hp_post( 'http://spam.example', 'lbb_website' );
lbb_t( 'capcana anti-roboți are un nume neutru („lbb_hp”, nu „website”), iar numele vechi trimis dintr-o pagină din cache e tot refuzat', isset( $hp_name[1] ) && ! preg_match( '/web|url|site|mail|name|phone|tel|addr|company/i', $hp_name[1] ) && ! is_wp_error( $hp_old ) && false !== strpos( wp_remote_retrieve_body( $hp_old ), 'Cererea nu a putut fi procesată' ) && 0 === $hp_count(), isset( $hp_name[1] ) ? $hp_name[1] : null );
$hp_r = $hp_post( '' );
lbb_t( 'aceeași cerere fără capcană (om): rezervarea trece și duce la bilet', ! is_wp_error( $hp_r ) && 302 === wp_remote_retrieve_response_code( $hp_r ) && false !== strpos( (string) wp_remote_retrieve_header( $hp_r, 'location' ), 'lbb_bilet=' ) && 1 === $hp_count(), is_wp_error( $hp_r ) ? $hp_r : array( wp_remote_retrieve_response_code( $hp_r ), wp_remote_retrieve_header( $hp_r, 'location' ), substr( wp_strip_all_tags( wp_remote_retrieve_body( $hp_r ) ), 0, 300 ) ) );
// Ultimele locuri cerute în aceeași clipă: 6 rezervări trimise simultan (cereri HTTP paralele, serverul de test are
// mai multe procese) pe o cursă cu 2 locuri. Lacătul pe cursă lasă să treacă exact 2; ceilalți află că s-au terminat.
$pl_r   = LBB_Routes::save( array( 'origin' => 'ParalelA', 'destination' => 'ParalelB', 'departures' => '10:00', 'price' => 100, 'currency' => 'MDL', 'capacity' => 2, 'active' => 1 ) );
$pl_res = array();
if ( is_int( $pl_r ) && function_exists( 'curl_multi_init' ) ) {
	$pl_keep = get_option( 'lbb_settings', array() );
	update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_reserve' => 1, 'reserve_limit' => 0 ) ) );
	$pl_mh = curl_multi_init();
	$pl_hs = array();
	for ( $pl_i = 0; $pl_i < 6; $pl_i++ ) {
		$pl_h = curl_init( home_url( '/balti-iasi/' ) );
		curl_setopt_array( $pl_h, array(
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_POSTFIELDS     => http_build_query( array(
				'lbb_action' => 'book', 'lbb_mode' => 'reserve', 'lbb_nonce' => isset( $hp_nonce[1] ) ? $hp_nonce[1] : '', 'lbb_form' => isset( $hp_form[1] ) ? $hp_form[1] : '',
				'lbb_route' => $pl_r, 'lbb_date' => $tomorrow, 'lbb_time' => '10:00', 'lbb_adults' => 1, 'lbb_names' => array( 'Paralel ' . chr( 65 + $pl_i ) . 'x' ),
				'lbb_phone' => '+3736900' . sprintf( '%04d', 5100 + $pl_i ), 'lbb_email' => 'paralel' . $pl_i . '@example.com', 'lbb_currency' => 'MDL',
			) ),
		) );
		curl_multi_add_handle( $pl_mh, $pl_h );
		$pl_hs[] = $pl_h;
	}
	do {
		$pl_st = curl_multi_exec( $pl_mh, $pl_run );
		if ( $pl_run ) {
			curl_multi_select( $pl_mh, 1 );
		}
	} while ( $pl_run && CURLM_OK === $pl_st );
	foreach ( $pl_hs as $pl_h ) {
		$pl_code  = curl_getinfo( $pl_h, CURLINFO_HTTP_CODE );
		$pl_body  = (string) curl_multi_getcontent( $pl_h );
		$pl_res[] = 302 === $pl_code ? 'ok' : ( false !== strpos( $pl_body, 'tocmai s-au terminat' ) || false !== strpos( $pl_body, 'Au mai rămas' ) || false !== strpos( $pl_body, 'A mai rămas' ) ? 'plin' : 'alt:' . $pl_code );
		curl_multi_remove_handle( $pl_mh, $pl_h );
	}
	curl_multi_close( $pl_mh );
	$pl_db = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(seats),0) FROM ' . LBB_Bookings::table() . " WHERE route_id = %d AND status IN ('reserved','hold','pending','confirmed')", $pl_r ) );
	// Lacătul chiar ține pe loc: cu lacătul cursei luat din altă conexiune, o rezervare nouă așteaptă până la eliberare
	// (fără lacăt ar trece imediat, iar două cereri ar putea vinde același loc).
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LBB_Bookings::table() . ' WHERE route_id = %d', $pl_r ) );
	$pl_db2  = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
	$pl_name = 'lbb_' . md5( $wpdb->prefix . $pl_r . $tomorrow . '10:00' );
	$pl_got  = $pl_db2->query( "SELECT GET_LOCK('" . $pl_db2->real_escape_string( $pl_name ) . "', 0)" )->fetch_row()[0];
	$pl_h    = curl_init( home_url( '/balti-iasi/' ) );
	curl_setopt_array( $pl_h, array( CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => http_build_query( array(
		'lbb_action' => 'book', 'lbb_mode' => 'reserve', 'lbb_nonce' => isset( $hp_nonce[1] ) ? $hp_nonce[1] : '', 'lbb_form' => isset( $hp_form[1] ) ? $hp_form[1] : '',
		'lbb_route' => $pl_r, 'lbb_date' => $tomorrow, 'lbb_time' => '10:00', 'lbb_adults' => 1, 'lbb_names' => array( 'Lacat Test' ),
		'lbb_phone' => '+37369005199', 'lbb_email' => 'lacat@example.com', 'lbb_currency' => 'MDL',
	) ) ) );
	$pl_mh = curl_multi_init();
	curl_multi_add_handle( $pl_mh, $pl_h );
	$pl_t0 = microtime( true );
	do {
		curl_multi_exec( $pl_mh, $pl_run );
		curl_multi_select( $pl_mh, 0.1 );
	} while ( $pl_run && microtime( true ) - $pl_t0 < 2 );
	$pl_waiting = (bool) $pl_run; // încă în așteptare după 2 secunde
	$pl_db2->query( "SELECT RELEASE_LOCK('" . $pl_db2->real_escape_string( $pl_name ) . "')" );
	while ( $pl_run ) {
		curl_multi_exec( $pl_mh, $pl_run );
		curl_multi_select( $pl_mh, 0.1 );
	}
	$pl_after = curl_getinfo( $pl_h, CURLINFO_HTTP_CODE );
	curl_multi_remove_handle( $pl_mh, $pl_h );
	curl_multi_close( $pl_mh );
	$pl_db2->close();
	$pl_lock = array( 'luat' => '1' === (string) $pl_got, 'asteapta' => $pl_waiting, 'dupa' => $pl_after );
	update_option( 'lbb_settings', $pl_keep );
	$wpdb->delete( LBB_Bookings::table(), array( 'route_id' => $pl_r ) );
	LBB_Routes::delete( $pl_r );
}
$pl_ok = count( array_keys( $pl_res, 'ok', true ) );
lbb_t( '6 rezervări simultane pe ultimele 2 locuri: trec exact 2, ceilalți primesc „locurile s-au terminat”; o rezervare așteaptă cât cursa e blocată de alta (lacăt), apoi trece', 2 === $pl_ok && 4 === count( array_keys( $pl_res, 'plin', true ) ) && 2 === ( $pl_db ?? -1 ) && array( 'luat' => true, 'asteapta' => true, 'dupa' => 302 ) === ( $pl_lock ?? null ), array( $pl_res, $pl_db ?? null, $pl_lock ?? null ) );

// Biletul nu se poate pune în coș ocolind formularul (fără cursă, locuri ținute și preț calculat): nici cu
// ?add-to-cart=ID, nici prin Store API (coșul și finalizarea cu blocuri). Cereri HTTP reale, cu cookie-urile sesiunii.
$dc_pid  = LBB_Install::product_id();
$dc_cart = function ( $cookies ) {
	$r = wp_remote_get( rest_url( 'wc/store/v1/cart' ), array( 'timeout' => 20, 'cookies' => $cookies ) );
	$d = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
	return array( $r, is_array( $d ) && isset( $d['items'] ) ? count( $d['items'] ) : -1 );
};
$dc_get  = wp_remote_get( add_query_arg( 'add-to-cart', $dc_pid, home_url( '/' ) ), array( 'timeout' => 20, 'redirection' => 0 ) );
$dc_ck   = is_wp_error( $dc_get ) ? array() : wp_remote_retrieve_cookies( $dc_get );
list( $dc_c1, $dc_n1 ) = $dc_cart( $dc_ck );
$dc_ck2  = is_wp_error( $dc_c1 ) ? array() : wp_remote_retrieve_cookies( $dc_c1 );
$dc_add  = wp_remote_post( rest_url( 'wc/store/v1/cart/add-item' ), array(
	'timeout' => 20,
	'cookies' => $dc_ck2,
	'headers' => array( 'Content-Type' => 'application/json', 'Nonce' => is_wp_error( $dc_c1 ) ? '' : (string) wp_remote_retrieve_header( $dc_c1, 'nonce' ) ),
	'body'    => wp_json_encode( array( 'id' => $dc_pid, 'quantity' => 1 ) ),
) );
$dc_body = is_wp_error( $dc_add ) ? array() : (array) json_decode( wp_remote_retrieve_body( $dc_add ), true );
list( , $dc_n2 ) = $dc_cart( $dc_ck2 );
lbb_t( 'produsul „Bilet autocar” nu intră în coș direct: nici cu ?add-to-cart, nici prin Store API (coșul cu blocuri)', $dc_pid && 0 === $dc_n1 && 400 === wp_remote_retrieve_response_code( $dc_add ) && 'woocommerce_rest_add_to_cart_error' === ( $dc_body['code'] ?? '' ) && 0 === $dc_n2, array( $dc_n1, wp_remote_retrieve_response_code( $dc_add ), $dc_body, $dc_n2 ) );
// Căutarea din „Rezervări” (clientul sună cu codul, telefonul sau numele).
$sr_b   = LBB_Bookings::get( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . LBB_Bookings::table() . ' WHERE route_id = %d', $hp_rid ) ) );
$sr_ids = function ( $q, $status = '' ) {
	return wp_list_pluck( LBB_Bookings::recent( 200, $status, $q ), 'id' );
};
$sr_local = '0' . substr( $sr_b['phone'], 4, 2 ) . ' ' . substr( $sr_b['phone'], 6 ); // +3736xxxxxxx → „06x xxxxxx”
lbb_t( 'căutare după codul biletului', array( $sr_b['id'] ) === $sr_ids( strtolower( $sr_b['ticket_code'] ) ), $sr_b['ticket_code'] );
lbb_t( 'căutare după telefon scris local, cu spații', in_array( $sr_b['id'], $sr_ids( $sr_local ), true ), $sr_local );
$sr_intl0 = '+373 0' . substr( $sr_b['phone'], 4, 2 ) . ' ' . substr( $sr_b['phone'], 6 ); // „+373 06x xxxxxx”
lbb_t( 'căutare după telefon internațional scris cu 0 după prefix („+373 069 …”)', in_array( $sr_b['id'], $sr_ids( $sr_intl0 ), true ), $sr_intl0 );
lbb_t( 'căutare după email', in_array( $sr_b['id'], $sr_ids( 'hp@example.com' ), true ) );
// Fusul WordPress ca decalaj fix „UTC+2” (ca pe site-ul live): ora creării din Rezervări e cea din Chișinău
// (vara UTC+3), nu cu o oră în urmă.
$tz_keep = array( get_option( 'timezone_string' ), get_option( 'gmt_offset' ), $sr_b['created_at'] );
update_option( 'timezone_string', '' );
update_option( 'gmt_offset', 2 );
$wpdb->update( LBB_Bookings::table(), array( 'created_at' => '2026-07-15 10:00:00' ), array( 'id' => $sr_b['id'] ) );
$_GET = array( 'page' => 'lbb-bookings', 'q' => $sr_b['ticket_code'] );
ob_start();
LBB_Admin::page_bookings();
$tz_html = ob_get_clean();
$_GET = array();
$wpdb->update( LBB_Bookings::table(), array( 'created_at' => $tz_keep[2] ), array( 'id' => $sr_b['id'] ) );
update_option( 'timezone_string', $tz_keep[0] );
update_option( 'gmt_offset', $tz_keep[1] );
lbb_t( 'Rezervări: ora creării în ora Chișinăului și vara cu fusul WordPress „UTC+2” (15.07 10:00 UTC → 13:00)', false !== strpos( $tz_html, '15.07.2026 13:00' ) && false === strpos( $tz_html, '15.07.2026 12:00' ), preg_match( '/15\.07\.2026 \d\d:\d\d/', $tz_html, $tz_m ) ? $tz_m[0] : '' );
lbb_t( 'căutare fără rezultat', array() === $sr_ids( 'zzqq-nimic' ) );
$sr_h = LBB_Bookings::create_hold( LBB_Routes::get( $hp_rid ), $tomorrow, '10:00', 1, 0, array( 'Țîrdea Ștefan' ), '+37369555444', 'x@example.com' );
lbb_t( 'căutare după nume cu diacritice', ! is_wp_error( $sr_h ) && in_array( (int) $sr_h['id'], $sr_ids( 'Țîrdea', 'hold' ), true ), $sr_h );
lbb_t( 'căutarea păstrează filtrul de stare', ! is_wp_error( $sr_h ) && ! in_array( (int) $sr_h['id'], $sr_ids( 'Țîrdea', 'confirmed' ), true ) );
// Numărul comenzii WooCommerce (din emailul de plată / Paynet), cu sau fără „#”.
if ( ! is_wp_error( $sr_h ) ) {
	$wpdb->update( LBB_Bookings::table(), array( 'order_id' => 987654321 ), array( 'id' => $sr_h['id'] ) );
}
lbb_t( 'căutare după numărul comenzii WooCommerce, cu sau fără #', ! is_wp_error( $sr_h ) && array( (int) $sr_h['id'] ) === $sr_ids( '#987654321', 'hold' ) && array( (int) $sr_h['id'] ) === $sr_ids( '987654321', 'hold' ) && ! in_array( (int) $sr_h['id'], $sr_ids( '#98765432', 'hold' ), true ), $sr_h );
// O rezervare a cărei rută a fost ștearsă (după ultima cursă) apare cu „(rută ștearsă)”, nu cu „ → ” gol.
$rd_rt = LBB_Routes::save( array( 'origin' => 'StearsaA', 'destination' => 'StearsaB', 'departures' => '10:00', 'price' => 50, 'currency' => 'MDL', 'active' => 1 ) );
$rd_h  = is_int( $rd_rt ) ? LBB_Bookings::create_hold( LBB_Routes::get( $rd_rt ), $tomorrow, '10:00', 1, 0, array( 'Ruta Stearsa' ), '+37369000071', 'rd@example.com', 'MDL' ) : null;
if ( is_int( $rd_rt ) ) {
	LBB_Routes::delete( $rd_rt );
}
$_GET   = array( 'page' => 'lbb-bookings', 'status' => 'hold', 'q' => '69000071' );
ob_start();
LBB_Admin::page_bookings();
$rd_html = ob_get_clean();
$_GET    = array();
if ( is_array( $rd_h ) ) {
	$wpdb->delete( LBB_Bookings::table(), array( 'id' => $rd_h['id'] ) );
}
lbb_t( 'Rezervări: o rezervare pe o rută ștearsă arată „(rută ștearsă)”, nu „ → ” gol', is_array( $rd_h ) && false !== strpos( $rd_html, '(rută ștearsă)' ) && false === strpos( $rd_html, '<td> → ' ) && 'Bălți → Iași' === LBB_Bookings::route_name( array( 'origin' => 'Bălți', 'destination' => 'Iași' ) ), substr( wp_strip_all_tags( $rd_html ), -300 ) );
// Filtrele din Rezervări arată câte rezervări are fiecare stare, pentru aceeași căutare („Toate” fără coșuri).
$sc_name = LBB_Bookings::status_counts( 'Țîrdea' );
$sc_code = LBB_Bookings::status_counts( $sr_b['ticket_code'] );
$_GET    = array( 'page' => 'lbb-bookings', 'q' => $sr_b['ticket_code'] );
ob_start();
LBB_Admin::page_bookings();
$sc_html = ob_get_clean();
$_GET    = array();
lbb_t( 'Rezervări: filtrele arată numărul pe stare, pentru căutarea curentă', 1 === ( $sc_name['hold'] ?? 0 ) && 0 === $sc_name[''] && 1 === $sc_code[''] && 1 === ( $sc_code[ $sr_b['status'] ] ?? 0 ) && false !== strpos( $sc_html, 'Toate <span class="count">(1)</span>' ), array( $sc_name, $sc_code ) );
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
$_GET = array( 'page' => 'lbb-routes', 'add' => 1 );
ob_start();
LBB_Admin::page_routes();
$im_route = ob_get_clean();
$_GET = array();
// Ruta respinsă la salvare: formularul revine cu ce s-a scris (o singură dată), nu gol.
$rd_user = get_current_user_id();
wp_set_current_user( 1 );
LBB_Admin::remember_route_draft( array( 'id' => 0, 'origin' => 'Ciorna A', 'destination' => 'Ciorna B', 'departures' => '08:45, 8h99', 'price' => '120', 'child_price' => '', 'currency' => 'RON', 'capacity' => '18', 'page_url' => '', 'boarding' => 'Peronul 2', 'notes' => "rând 1\nrând 2", 'days' => array( '1', '5' ) ) );
$_GET = array( 'page' => 'lbb-routes', 'add' => 1 );
ob_start();
LBB_Admin::page_routes();
$rd_1 = ob_get_clean();
ob_start();
LBB_Admin::page_routes();
$rd_2 = ob_get_clean();
$_GET = array();
wp_set_current_user( $rd_user );
lbb_t( 'ruta respinsă la salvare: formularul revine cu datele scrise (orele exact cum au fost scrise), o singură dată', false !== strpos( $rd_1, 'name="origin" class="regular-text" list="lbb-cities" maxlength="100" required value="Ciorna A"' ) && false !== strpos( $rd_1, 'value="08:45, 8h99"' ) && false !== strpos( $rd_1, 'value="Peronul 2"' ) && false !== strpos( $rd_1, 'name="price" type="number" inputmode="decimal" step="0.01" min="0" required value="120"' ) && (bool) preg_match( '#<option +selected=.selected.>RON</option>#', $rd_1 ) && (bool) preg_match( '#value="5"[^>]* checked=.checked.#', $rd_1 ) && ! preg_match( '#value="2"[^>]* checked=.checked.#', $rd_1 ) && false === strpos( $rd_2, 'Ciorna A' ), substr( wp_strip_all_tags( $rd_1 ), 0, 300 ) );
lbb_t( 'ruta: căsuțele zilelor („Lu”, „Sâ”) au numele întreg pentru cititorul de ecran („luni”, „sâmbătă”)', (bool) preg_match( '/name="days\[\]" value="1" aria-label="luni"[^>]*> Lu</u', $im_route ) && (bool) preg_match( '/name="days\[\]" value="6" aria-label="sâmbătă"[^>]*> Sâ</u', $im_route ), preg_match( '/<td class="lbb-days">.{0,300}/u', $im_route, $dn_m ) ? $dn_m[0] : '' );
lbb_t( 'ruta: căsuțele zilelor sunt grupate (fieldset cu legenda „Zile de circulație”), ca cititorul de ecran să spună la ce se referă „luni”', (bool) preg_match( '/<td class="lbb-days"><fieldset><legend class="screen-reader-text">Zile de circulație<\/legend>(?:(?!<\/fieldset>).)*name="days\[\]" value="7"(?:(?!<\/fieldset>).)*<\/label><\/fieldset><\/td>/us', $im_route ), preg_match( '/<td class="lbb-days">.{0,300}/u', $im_route, $fs_m ) ? $fs_m[0] : '' );
lbb_t( 'ruta: bifa „Se vinde online” are și numele rândului („Activă”) pentru cititorul de ecran, ca bifele din Setări', (bool) preg_match( '/<td><fieldset><legend class="screen-reader-text">Activă<\/legend><label><input type="checkbox" name="active" value="1"[^>]*> Se vinde online<\/label><\/fieldset><\/td>/u', $im_route ), preg_match( '/.{0,120}name="active".{0,120}/u', $im_route, $ac_m ) ? $ac_m[0] : '' );
lbb_t( 'setări: căsuțele monedelor de plată sunt grupate (fieldset cu legenda „Clientul poate plăti în”), nu doar „MDL, bifat”', (bool) preg_match( '/<fieldset><legend class="screen-reader-text">Clientul poate plăti în<\/legend>(?:(?!<\/fieldset>).)*name="pay_currencies\[\]" value="MDL"(?:(?!<\/fieldset>).)*name="pay_currencies\[\]" value="RON"(?:(?!<\/fieldset>).)*<\/fieldset>/us', $form_html ), preg_match( '/.{0,200}name="pay_currencies\[\]" value="MDL".{0,100}/u', $form_html, $pc_m ) ? $pc_m[0] : '' );
$ck_all  = preg_match_all( '/type="checkbox" value="1"/', $form_html );
$ck_good = preg_match_all( '/<td><fieldset><legend class="screen-reader-text">[^<]+<\/legend><label><input name="[a-z0-9_]+" type="checkbox" value="1"[^>]*>[^<]*<\/label><\/fieldset><\/td>/u', $form_html );
lbb_t( 'setări: fiecare căsuță are numele setării ca legendă (ex. „Plata online cu cardul”), nu doar explicația lungă', $ck_all >= 10 && $ck_all === $ck_good && false !== strpos( $form_html, '<legend class="screen-reader-text">Plata online cu cardul</legend><label><input name="allow_pay"' ), array( $ck_all, $ck_good ) );
lbb_t( 'ruta: „Ore de plecare” are limită de lungime și explicația legată de câmp', (bool) preg_match( '/<input type="text" id="lbb-times" name="departures"[^>]* maxlength="255"[^>]* aria-describedby="lbb-times-desc"/', $im_route ) && false !== strpos( $im_route, '<p class="description" id="lbb-times-desc">' ) );
lbb_t( 'pe telefon, câmpurile numerice deschid tastatura cu cifre (cu zecimale la prețuri și cursuri)', false !== strpos( $form_html, 'id="lbb-max_passengers" name="max_passengers" type="number" inputmode="numeric"' ) && false !== strpos( $form_html, 'id="lbb-rate_RON" name="rate_RON" type="number" inputmode="decimal"' ) && false !== strpos( $im_route, 'name="price" type="number" inputmode="decimal"' ) && false !== strpos( $im_route, 'name="child_price" type="number" inputmode="decimal"' ) && false !== strpos( $im_route, 'name="capacity" type="number" inputmode="numeric"' ) );
// Pe telefon adminul primește tastatura potrivită: telefon, @ pentru email, cifre pentru IDNO.
lbb_t( 'Setări: telefonul, emailul și IDNO-ul firmei au tastatura potrivită pe telefon', false !== strpos( $form_html, '<input type="tel" id="lbb-support_phone"' ) && false !== strpos( $form_html, '<input type="email" id="lbb-company_email"' ) && (bool) preg_match( '/<input type="text" id="lbb-company_idno"[^>]* inputmode="numeric"/', $form_html ) );
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
// Minimele din formular sunt cele aplicate la salvare (altfel câmpul acceptă 3 minute, iar serverul pune 5 pe tăcute).
$mn_found = array();
$mn_xpath = new DOMXPath( $dom );
foreach ( array( 'default_capacity', 'max_passengers', 'max_days_ahead', 'cart_hold_minutes', 'payment_minutes', 'cutoff_minutes', 'reserve_limit' ) as $mn_key ) {
	$mn_el                = $mn_xpath->query( '//input[@name="' . $mn_key . '"]' )->item( 0 );
	$mn_found[ $mn_key ] = $mn_el ? $mn_el->getAttribute( 'min' ) . ( $mn_el->hasAttribute( 'required' ) ? '!' : '' ) : null;
}
$mx_attr = array();
foreach ( array( 'cart_hold_minutes', 'payment_minutes', 'max_days_ahead', 'cutoff_minutes' ) as $mx_key ) {
	$mx_el              = $mn_xpath->query( '//input[@name="' . $mx_key . '"]' )->item( 0 );
	$mx_attr[ $mx_key ] = $mx_el ? $mx_el->getAttribute( 'max' ) : null;
}
lbb_t( 'Setări: câmpurile plafonate au și maximul pe câmp (browserul îl spune direct)', array( 'cart_hold_minutes' => '120', 'payment_minutes' => '240', 'max_days_ahead' => '365', 'cutoff_minutes' => '2880' ) === $mx_attr, $mx_attr );
lbb_t( 'Setări: câmpurile numerice au minimul real (1 loc/pasager/zi, 5 și 10 minute), 0 permis la închidere și limită', array( 'default_capacity' => '1!', 'max_passengers' => '1!', 'max_days_ahead' => '1!', 'cart_hold_minutes' => '5!', 'payment_minutes' => '10!', 'cutoff_minutes' => '0', 'reserve_limit' => '0' ) === $mn_found, $mn_found );

// Numerele din texte acordate ca în română: „30 de minute” în nota formularului, „1 rută activă” / „20 de rute active” în panou.
$mn_keep = get_option( 'lbb_settings', array() );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'allow_pay' => 1, 'cart_hold_minutes' => 30 ) ) );
$mn_30 = wp_strip_all_tags( LBB_Frontend::shortcode( array() ) );
update_option( 'lbb_settings', array_merge( LBB_Settings::all(), array( 'cart_hold_minutes' => 15 ) ) );
$mn_15 = wp_strip_all_tags( LBB_Frontend::shortcode( array() ) );
update_option( 'lbb_settings', $mn_keep );
$mn_rt = null;
foreach ( LBB_Admin::checklist() as $mn_it ) {
	if ( 'routes' === $mn_it['id'] ) {
		$mn_rt = $mn_it['detail'];
	}
}
$mn_n = count( array_filter( LBB_Routes::all( true ), function ( $r ) {
	return $r['price'] > 0;
} ) );
lbb_t( 'nota de plată spune „30 de minute” / „15 minute”, iar panoul „N (de) rute active” acordat', false !== strpos( $mn_30, 'se păstrează 30 de minute cât' ) && false !== strpos( $mn_15, 'se păstrează 15 minute cât' ) && LBB_Settings::ro_count( $mn_n, 'rută activă', 'rute active' ) === $mn_rt && '1 rută activă' === LBB_Settings::ro_count( 1, 'rută activă', 'rute active' ), array( $mn_rt, $mn_n ) );
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
// Panoul verifică și IDNO-ul firmei: 13 cifre (spațiile nu contează).
$co_keep = LBB_Settings::all();
$co_item = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'company' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
update_option( 'lbb_settings', array_merge( $co_keep, array( 'company_name' => 'SRL Test', 'company_address' => 'Bălți', 'company_idno' => '100360004' ) ) );
$co_bad = $co_item();
update_option( 'lbb_settings', array_merge( $co_keep, array( 'company_name' => 'SRL Test', 'company_address' => 'Bălți', 'company_idno' => '1003 6000 41234' ) ) );
$co_good = $co_item();
update_option( 'lbb_settings', $co_keep );
lbb_t( 'panou: IDNO cu alt număr de cifre decât 13 e semnalat, cel corect trece', $co_bad && false === $co_bad['ok'] && false !== strpos( $co_bad['detail'], '13 cifre' ) && $co_good && true === $co_good['ok'], array( $co_bad, $co_good ) );
// Panoul avertizează când finalizarea comenzii folosește blocul „Checkout” (unde lipsesc plățile doar clasice).
$cb_item = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'checkout_page' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
$cb_page    = wc_get_page_id( 'checkout' );
$cb_keep    = get_post_field( 'post_content', $cb_page );
$cb_classic = $cb_item();
wp_update_post( array( 'ID' => $cb_page, 'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' ) );
$cb_block = $cb_item();
wp_update_post( array( 'ID' => $cb_page, 'post_content' => $cb_keep ) );
lbb_t( 'panou: pagina de finalizare cu blocul „Checkout” e semnalată, cea clasică trece', $cb_classic && true === $cb_classic['ok'] && $cb_block && 'warn' === $cb_block['ok'] && false !== strpos( $cb_block['detail'], '[woocommerce_checkout]' ) && $cb_keep === get_post_field( 'post_content', $cb_page ), array( $cb_classic, $cb_block ) );
// Panoul semnalează produsul intern stricat dintr-o editare: stoc gestionat cu 0 bucăți sau fără „Virtual”.
$pp_prod = wc_get_product( LBB_Install::product_id() );
$pp_item = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'product' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
$pp_ok = $pp_item();
$pp_prod->set_manage_stock( true );
$pp_prod->set_stock_quantity( 0 );
$pp_prod->set_virtual( false );
$pp_prod->save();
$pp_bad = $pp_item();
$pp_prod->set_manage_stock( false );
$pp_prod->set_stock_status( 'instock' );
$pp_prod->set_virtual( true );
$pp_prod->save();
$pp_back = $pp_item();
lbb_t( 'panou: produsul intern fără stoc sau fără „Virtual” e semnalat, cu ce trebuie schimbat', $pp_ok && true === $pp_ok['ok'] && $pp_bad && false === $pp_bad['ok'] && false !== strpos( $pp_bad['detail'], 'stoc epuizat' ) && false !== strpos( $pp_bad['detail'], 'Virtual' ) && $pp_back && true === $pp_back['ok'], array( $pp_ok, $pp_bad, $pp_back ) );

// Panoul semnalează când WooCommerce cere cont pentru plată: fără cont deloc (roșu) sau doar cu cont nou la plată (atenție).
$gc_keep = array( get_option( 'woocommerce_enable_guest_checkout' ), get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) );
$gc_item = function ( $guest, $signup ) {
	update_option( 'woocommerce_enable_guest_checkout', $guest );
	update_option( 'woocommerce_enable_signup_and_login_from_checkout', $signup );
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'guest_checkout' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
$gc_yes = $gc_item( 'yes', 'no' );
$gc_reg = $gc_item( 'no', 'yes' );
$gc_no  = $gc_item( 'no', 'no' );
update_option( 'woocommerce_enable_guest_checkout', $gc_keep[0] );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', $gc_keep[1] );
lbb_t( 'panou: plata fără cont e verificată (permisă / doar cu cont nou / imposibilă fără cont)', $gc_yes && true === $gc_yes['ok'] && $gc_reg && 'warn' === $gc_reg['ok'] && $gc_no && false === $gc_no['ok'] && false !== strpos( $gc_no['detail'], 'nu pot plăti' ) && 'yes' === get_option( 'woocommerce_enable_guest_checkout' ), array( $gc_yes, $gc_reg, $gc_no, $gc_keep ) );

// Panoul arată când curățarea automată e programată, dar n-a mai rulat de peste o zi (WP-Cron oprit sau fără vizite).
$lc_keep = get_option( 'lbb_last_cleanup' );
$lc_item = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'cron' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
LBB_Install::ensure_cron();
update_option( 'lbb_last_cleanup', time() - 3 * DAY_IN_SECONDS, false );
$lc_old = $lc_item();
LBB_Bookings::cleanup();
$lc_ran = (int) get_option( 'lbb_last_cleanup' );
$lc_new = $lc_item();
if ( false === $lc_keep ) {
	delete_option( 'lbb_last_cleanup' );
} else {
	update_option( 'lbb_last_cleanup', $lc_keep, false );
}
lbb_t( 'panou: curățarea automată care n-a mai rulat de 3 zile e semnalată cu data ultimei rulări; după o rulare trece', $lc_old && 'warn' === $lc_old['ok'] && false !== strpos( $lc_old['detail'], 'Ultima rulare: ' . wp_date( 'd.m.Y', time() - 3 * DAY_IN_SECONDS ) ) && abs( $lc_ran - time() ) < 60 && $lc_new && true === $lc_new['ok'], array( $lc_old, $lc_new ) );
// Panoul arată o trimitere de email eșuată din ultimele 7 zile (biletele nu ajung la clienți).
$mf_keep = get_option( 'lbb_mail_failed' );
$mf_item = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'mail' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
delete_option( 'lbb_mail_failed' );
$mf_none = $mf_item();
do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Could not instantiate mail function.' ) );
$mf_now = $mf_item();
do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: The following recipients failed: ion.popescu@example.com' ) );
$mf_pii = get_option( 'lbb_mail_failed' );
update_option( 'lbb_mail_failed', array( 'time' => time() - 8 * DAY_IN_SECONDS, 'message' => 'veche' ), false );
$mf_old = $mf_item();
false === $mf_keep ? delete_option( 'lbb_mail_failed' ) : update_option( 'lbb_mail_failed', $mf_keep, false );
lbb_t( 'testele nu lasă în panou erori de email false (cele provocate intenționat sunt șterse)', false === $mf_keep, $mf_keep );
lbb_t( 'eroarea de email salvată nu păstrează adresa clientului', is_array( $mf_pii ) && false === strpos( $mf_pii['message'], 'ion.popescu' ) && false !== strpos( $mf_pii['message'], 'recipients failed: [email]' ), $mf_pii );
lbb_t( 'panou: un email eșuat recent apare ca avertisment cu mesajul, unul mai vechi de 7 zile nu', $mf_none && true === $mf_none['ok'] && $mf_now && 'warn' === $mf_now['ok'] && false !== strpos( $mf_now['detail'], 'Could not instantiate mail function' ) && $mf_old && true === $mf_old['ok'], array( $mf_none, $mf_now, $mf_old ) );
// Panoul arată dacă WooCommerce anonimizează comenzile finalizate (politica promite 3 ani).
$or_keep = get_option( 'woocommerce_anonymize_completed_orders' );
$or_item = function () {
	foreach ( LBB_Admin::checklist() as $it ) {
		if ( 'order_retention' === $it['id'] ) {
			return $it;
		}
	}
	return null;
};
update_option( 'woocommerce_anonymize_completed_orders', array( 'number' => '', 'unit' => 'months' ) );
$it = $or_item();
lbb_t( 'panou: comenzile finalizate păstrate la nesfârșit apar ca avertisment', $it && 'warn' === $it['ok'] && false !== strpos( $it['detail'], '3 ani' ), $it );
update_option( 'woocommerce_anonymize_completed_orders', array( 'number' => 3, 'unit' => 'years' ) );
$it = $or_item();
lbb_t( 'panou: anonimizarea după 3 ani apare bifată', $it && true === $it['ok'] && false !== strpos( $it['detail'], '3 ani' ), $it );
update_option( 'woocommerce_anonymize_completed_orders', $or_keep );
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
// Fără prefixul țării numărul nu se formează din România: avertisment, cu numărul în detalii.
foreach ( array( '069 184 111', '0691 84 111' ) as $ph_loc ) {
	update_option( 'lbb_settings', array_merge( $cl_keep, array( 'support_phone' => $ph_loc ) ) );
	$it = $ph_item();
	lbb_t( 'panou: telefonul „' . $ph_loc . '” fără prefix apare ca avertisment', $it && 'warn' === $it['ok'] && false !== strpos( $it['detail'], $ph_loc ) && false !== strpos( $it['detail'], '+373' ), $it );
}
// Linkul de apel primește oricum prefixul țării, ca un client din România să sune la birou, nu în rețeaua lui.
$ph_links = array();
foreach ( array( '069 184 111' => 'tel:+37369184111', '+373 691 84 111' => 'tel:+37369184111', '00373 691 84 111' => 'tel:+37369184111', '+373 (0)69 184 111' => 'tel:+37369184111' ) as $ph_in => $ph_want ) {
	update_option( 'lbb_settings', array_merge( $cl_keep, array( 'support_phone' => $ph_in ) ) );
	$ph_links[ $ph_in ] = array( LBB_Settings::phone_href(), LBB_Settings::phone_link() );
	$ph_ok_link         = $ph_want === $ph_links[ $ph_in ][0] && false !== strpos( $ph_links[ $ph_in ][1], 'href="' . $ph_want . '"' ) && false !== strpos( $ph_links[ $ph_in ][1], str_replace( ' ', "\u{00A0}", $ph_in ) );
	if ( ! $ph_ok_link ) {
		break;
	}
}
lbb_t( 'telefonul de suport scris local are link de apel cu +373, textul rămâne cum l-a scris biroul', $ph_ok_link, $ph_links );
update_option( 'lbb_settings', array_merge( $cl_keep, array( 'support_phone' => '00373 691 84 111' ) ) );
$it = $ph_item();
lbb_t( 'panou: telefonul cu prefixul 00373 apare ca bifat', $it && true === $it['ok'], $it );
update_option( 'lbb_settings', $cl_keep );

// Copiii sunt ultimii în lista de pasageri: pe bilet, în lista pentru șofer și în CSV se văd ca „(copil)”.
$kids = array( 'passengers' => array( 'Ion Popescu', 'Maria Popescu', 'Ana Popescu' ), 'children' => 1, 'seats' => 3 );
lbb_t( 'pasageri: copilul e marcat „(copil)”', array( 'Ion Popescu', 'Maria Popescu', 'Ana Popescu (copil)' ) === LBB_Bookings::passenger_labels( $kids ), LBB_Bookings::passenger_labels( $kids ) );
lbb_t( 'pasageri: fără copii numele rămân la fel', array( 'Ion Popescu' ) === LBB_Bookings::passenger_labels( array( 'passengers' => array( 'Ion Popescu' ), 'children' => 0, 'seats' => 1 ) ) );
lbb_t( 'pasageri: mai mulți copii decât locuri (date stricate) nu blochează pagina, toți sunt „(copil)”', array( 'Ion Pop (copil)', 'Ana Pop (copil)' ) === LBB_Bookings::passenger_labels( array( 'passengers' => array( 'Ion Pop', 'Ana Pop' ), 'children' => 3, 'seats' => 2 ) ) );
lbb_t( 'pasageri: fără nume pentru toate locurile nu ghicim cine e copil', array( 'Ion Popescu' ) === LBB_Bookings::passenger_labels( array( 'passengers' => array( 'Ion Popescu' ), 'children' => 1, 'seats' => 2 ) ) );
$kid_desc = LBB_WooCommerce::describe( array_merge( $rv, $kids, array( 'status' => 'confirmed' ) ) );
lbb_t( 'biletul arată care pasager e copil', false !== strpos( $kid_desc['Pasageri'], 'Ana Popescu (copil)' ), $kid_desc );
lbb_t( 'biletul scrie „1 copil”, nu „1 copii”', '3 (din care 1 copil)' === $kid_desc['Locuri'], $kid_desc['Locuri'] );

// Formularele din admin primesc date măsluite (camp[]=…, liste în liste, octet nul): fără avertismente sau eroare fatală.
$fz_w    = array();
$fz_keep = get_option( 'lbb_settings' );
set_error_handler(
	function ( $no, $msg, $file, $line ) use ( &$fz_w ) {
		if ( false !== strpos( $file, 'libertbus-bilete' ) ) {
			$fz_w[] = $msg . ' @' . basename( $file ) . ':' . $line;
		}
		return false;
	}
);
$fz_base = array( 'origin' => 'FuzzA', 'destination' => 'FuzzB', 'price' => '100', 'currency' => 'MDL', 'departures' => '08:00', 'days' => array( 1, 2 ), 'active' => 1 );
$fz_vals = array( array( 'x' ), array( array( 'y' ) ), "\0", '-1' );
try {
	foreach ( array( 'origin', 'destination', 'price', 'child_price', 'currency', 'departures', 'days', 'capacity', 'page_url', 'boarding', 'notes', 'active' ) as $fz_k ) {
		foreach ( $fz_vals as $fz_v ) {
			$fz_res = LBB_Routes::save( array_merge( $fz_base, array( $fz_k => $fz_v ) ) );
			if ( is_int( $fz_res ) && $fz_res ) {
				$wpdb->delete( LBB_Routes::table(), array( 'id' => $fz_res ) );
			}
		}
	}
	foreach ( array_keys( LBB_Settings::all() ) as $fz_k ) {
		foreach ( $fz_vals as $fz_v ) {
			LBB_Settings::save( array_merge( LBB_Settings::all(), array( $fz_k => $fz_v ) ) );
			update_option( 'lbb_settings', $fz_keep );
		}
	}
} catch ( Throwable $e ) {
	$fz_w[] = get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine();
}
restore_error_handler();
update_option( 'lbb_settings', $fz_keep );
lbb_t( 'admin: rute și setări cu date măsluite fără avertismente sau eroare fatală', ! $fz_w, array_slice( array_unique( $fz_w ), 0, 5 ) );

// Atributele scrise de mână în shortcode: majuscule/spații la mode și compact="false" sunt înțelese ca atare.
$sa_keep = get_option( 'lbb_settings' );
update_option( 'lbb_settings', array_merge( $sa_keep, array( 'allow_pay' => 1, 'allow_reserve' => 1 ) ) );
$sa_pay  = do_shortcode( '[libertbus_rezervare mode="Pay "]' );
$sa_res  = do_shortcode( '[libertbus_rezervare mode="RESERVE"]' );
$sa_off  = do_shortcode( '[libertbus_rezervare compact="false"]' ) . do_shortcode( '[libertbus_rezervare compact="nu"]' );
$sa_on   = do_shortcode( '[libertbus_rezervare compact="da"]' );
update_option( 'lbb_settings', $sa_keep );
lbb_t( 'shortcode: mode="Pay " lasă doar „Achit online”', false !== strpos( $sa_pay, 'value="pay" class="lbb-submit"' ) && false === strpos( $sa_pay, 'value="reserve" class="lbb-submit' ) );
lbb_t( 'shortcode: mode="RESERVE" lasă doar „Rezerv”', false !== strpos( $sa_res, 'value="reserve" class="lbb-submit' ) && false === strpos( $sa_res, 'value="pay" class="lbb-submit"' ) );
lbb_t( 'shortcode: compact="false" / "nu" nu face formularul compact', false === strpos( $sa_off, 'lbb-compact' ) && false !== strpos( $sa_on, 'lbb-compact' ) );

// [lbb_firma] scris de mână cu majuscule sau în română arată câmpul cerut, nu numele firmei.
$fc_keep = get_option( 'lbb_settings' );
update_option( 'lbb_settings', array_merge( $fc_keep, array( 'company_name' => 'Firma Test SRL', 'company_idno' => '1001600000000', 'company_address' => 'str. Testului 1', 'company_email' => 'birou@example.com', 'support_phone' => '+373 69 000 111' ) ) );
$fc_got = array(
	do_shortcode( '[lbb_firma camp="Phone"]' ),
	do_shortcode( '[lbb_firma camp=" telefon "]' ),
	do_shortcode( '[lbb_firma camp="Adresă"]' ),
	do_shortcode( '[lbb_firma camp="E-mail"]' ),
	do_shortcode( '[lbb_firma camp="IDNO"]' ),
	do_shortcode( '[lbb_firma camp="nume"]' ),
);
update_option( 'lbb_settings', $fc_keep );
lbb_t( 'shortcode [lbb_firma]: camp cu majuscule sau în română arată câmpul cerut', array( '+373 69 000 111', '+373 69 000 111', 'str. Testului 1', 'birou@example.com', '1001600000000', 'Firma Test SRL' ) === $fc_got, $fc_got );

// Fiecare acțiune din admin (admin_post_lbb_*) refuză o cerere fără nonce (link măsluit trimis adminului) și un
// abonat chiar cu nonce valid: nu redirecționează, nu șterge ruta, nu schimbă setările.
global $wp_filter;
$ap_user  = get_current_user_id();
$ap_keep  = array( $_GET, $_POST, $_REQUEST );
$ap_set   = get_option( 'lbb_settings' );
$ap_rid   = LBB_Routes::save( array( 'origin' => 'NonceA', 'destination' => 'NonceB', 'price' => '10', 'departures' => '09:00', 'active' => 0 ) );
$ap_sub   = wp_insert_user( array( 'user_login' => 'lbb_ap_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$ap_hooks = array();
foreach ( array_keys( $wp_filter ) as $ap_h ) {
	if ( 0 === strpos( $ap_h, 'admin_post_lbb_' ) ) {
		$ap_hooks[] = $ap_h;
	}
}
$ap_die   = function () {
	return function () {
		throw new Exception( 'die' );
	};
};
$ap_redir = function () {
	throw new Exception( 'redirect' );
};
add_filter( 'wp_die_handler', $ap_die );
add_filter( 'wp_redirect', $ap_redir, 1 );
$ap_bad = array();
foreach ( $ap_hooks as $ap_h ) {
	$ap_action = substr( $ap_h, strlen( 'admin_post_' ) );
	$ap_nonce  = 'lbb_cancel_booking' === $ap_action ? 'lbb_cancel_booking_999999' : $ap_action;
	foreach ( array( 'admin fără nonce' => array( 1, false ), 'abonat cu nonce' => array( is_int( $ap_sub ) ? $ap_sub : 0, true ) ) as $ap_who => $ap_case ) {
		wp_set_current_user( $ap_case[0] );
		$_POST    = array_merge( LBB_Settings::all(), array( 'action' => $ap_action, 'id' => 'lbb_cancel_booking' === $ap_action ? 999999 : $ap_rid, 'company_name' => 'Măsluit SRL' ) );
		$_GET     = array( 'action' => $ap_action, 'date' => LBB_Settings::today() );
		$_REQUEST = $_POST + ( $ap_case[1] ? array( '_wpnonce' => wp_create_nonce( $ap_nonce ) ) : array() );
		ob_start();
		$ap_end = 'nimic';
		try {
			do_action( $ap_h );
		} catch ( Exception $e ) {
			$ap_end = $e->getMessage();
		}
		ob_end_clean();
		if ( 'die' !== $ap_end ) {
			$ap_bad[] = $ap_action . ' / ' . $ap_who . ': ' . $ap_end;
		}
	}
}
remove_filter( 'wp_die_handler', $ap_die );
remove_filter( 'wp_redirect', $ap_redir, 1 );
$ap_route_left = is_int( $ap_rid ) && LBB_Routes::get( $ap_rid );
$ap_set_same   = get_option( 'lbb_settings' ) === $ap_set;
list( $_GET, $_POST, $_REQUEST ) = $ap_keep;
update_option( 'lbb_settings', $ap_set );
if ( is_int( $ap_rid ) ) {
	$wpdb->delete( LBB_Routes::table(), array( 'id' => $ap_rid ) );
}
if ( is_int( $ap_sub ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $ap_sub );
}
wp_set_current_user( $ap_user );
lbb_t( 'admin: toate cele ' . count( $ap_hooks ) . ' acțiuni lbb_* refuză cererea fără nonce și abonatul; ruta și setările rămân', count( $ap_hooks ) >= 7 && ! $ap_bad && $ap_route_left && $ap_set_same, array( $ap_hooks, $ap_bad, $ap_route_left, $ap_set_same ) );

// lbb/v1/status (versiunea, lista „Gata de plăți?”, statisticile vânzărilor) e doar pentru birou: vizitatorul și
// abonatul primesc refuz, administratorul primește datele.
$st_user = get_current_user_id();
$st_sub  = wp_insert_user( array( 'user_login' => 'lbb_st_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$st_get  = function ( $uid ) {
	wp_set_current_user( $uid );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/lbb/v1/status' ) );
	return array( $res->get_status(), $res->get_data() );
};
$st_guest = $st_get( 0 );
$st_subr  = $st_get( is_int( $st_sub ) ? $st_sub : 0 );
$st_admin = $st_get( 1 );
if ( is_int( $st_sub ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $st_sub );
}
wp_set_current_user( $st_user );
lbb_t( 'REST lbb/v1/status: refuzat pentru vizitator și abonat, fără statistici; administratorul primește versiunea', 401 === $st_guest[0] && 403 === $st_subr[0] && ! isset( $st_guest[1]['stats'] ) && ! isset( $st_subr[1]['stats'] ) && 200 === $st_admin[0] && LBB_VERSION === $st_admin[1]['version'] && isset( $st_admin[1]['stats'] ), array( $st_guest[0], $st_subr[0], $st_admin[0] ) );

// Pagina „Biletul nu a fost găsit” (link greșit sau tăiat) nu e un drum închis: are link spre formularul de rezervare.
$nf_page  = wp_remote_retrieve_body( wp_remote_get( add_query_arg( array( 'lbb_bilet' => 'LB-ZZZZZZ', 'k' => 'gresit' ), home_url( '/' ) ), array( 'timeout' => 20 ) ) );
$nf_pages = LBB_Admin::pages_with_form();
$nf_want  = $nf_pages ? get_permalink( $nf_pages[0]->ID ) : home_url( '/' );
lbb_t( 'bilet negăsit: link spre formularul de rezervare (sau site), ca buton', false !== strpos( $nf_page, 'Biletul nu a fost găsit' ) && false !== strpos( $nf_page, '<div class="actions"><a class="again" href="' . esc_url( $nf_want ) . '">' ), substr( wp_strip_all_tags( $nf_page ), -300 ) );

// Lista de pasageri stricată în baza de date (liste, obiecte, null în loc de nume): pe bilet și în comandă rămân doar
// numele, fără „Array” și fără avertismente PHP.
$hy_w  = array();
$hy_id = (int) $wpdb->get_var( 'SELECT id FROM ' . LBB_Bookings::table() . " WHERE ticket_code <> '' ORDER BY id DESC LIMIT 1" );
$hy_k  = $wpdb->get_var( $wpdb->prepare( 'SELECT passengers FROM ' . LBB_Bookings::table() . ' WHERE id = %d', $hy_id ) );
$wpdb->update( LBB_Bookings::table(), array( 'passengers' => '[["Ion"],{"a":1},null,"Ana Pop"]' ), array( 'id' => $hy_id ) );
set_error_handler(
	function ( $no, $msg, $file, $line ) use ( &$hy_w ) {
		if ( false !== strpos( $file, 'libertbus-bilete' ) ) {
			$hy_w[] = $msg . ' @' . basename( $file ) . ':' . $line;
		}
		return false;
	}
);
$hy_b    = LBB_Bookings::get( $hy_id );
$hy_html = $hy_b ? LBB_Tickets::html( $hy_b, true ) . implode( ' ', LBB_WooCommerce::describe( $hy_b ) ) . implode( ' ', LBB_Bookings::passenger_labels( $hy_b ) ) : '';
restore_error_handler();
$wpdb->update( LBB_Bookings::table(), array( 'passengers' => $hy_k ), array( 'id' => $hy_id ) );
lbb_t( 'pasageri stricați în baza de date: pe bilet doar numele, fără „Array” și fără avertismente', $hy_b && array( 'Ana Pop' ) === $hy_b['passengers'] && false !== strpos( $hy_html, 'Ana Pop' ) && false === strpos( $hy_html, 'Array' ) && ! $hy_w, array( $hy_b ? $hy_b['passengers'] : null, array_unique( $hy_w ) ) );

// O rută cu notițele NULL (adăugată direct în baza de date): pagina de editare fără avertismente/deprecări PHP 8.1+.
$nn_w  = array();
$nn_id = (int) $wpdb->get_var( 'SELECT id FROM ' . LBB_Routes::table() . ' ORDER BY id LIMIT 1' );
$nn_k  = $wpdb->get_var( $wpdb->prepare( 'SELECT notes FROM ' . LBB_Routes::table() . ' WHERE id = %d', $nn_id ) );
$nn_u  = get_current_user_id();
$nn_g  = $_GET;
$wpdb->query( $wpdb->prepare( 'UPDATE ' . LBB_Routes::table() . ' SET notes = NULL WHERE id = %d', $nn_id ) );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/admin.php';
$_GET = array( 'edit' => $nn_id );
$nn_note = LBB_Routes::get( $nn_id )['notes'];
set_error_handler(
	function ( $no, $msg, $file, $line ) use ( &$nn_w ) {
		$nn_w[] = $msg . ' @' . basename( $file ) . ':' . $line;
		return true;
	}
);
ob_start();
LBB_Admin::page_routes();
$nn_html = ob_get_clean();
restore_error_handler();
$_GET = $nn_g;
wp_set_current_user( $nn_u );
$wpdb->update( LBB_Routes::table(), array( 'notes' => (string) $nn_k ), array( 'id' => $nn_id ) );
lbb_t( 'rută cu notițele NULL în baza de date: notițe goale, pagina de editare fără avertismente', '' === $nn_note && false !== strpos( $nn_html, 'id="lbb-notes"' ) && ! $nn_w, array( $nn_note, array_unique( $nn_w ) ) );

// Două formulare pe aceeași pagină (ex. în antetul Elementor și în pagină) și setările de cookies: id-uri unice, iar
// fiecare for= / aria-describedby / aria-labelledby / aria-controls arată spre un element care există.
$du_html = do_shortcode( '[libertbus_rezervare]' ) . do_shortcode( '[libertbus_rezervare compact="1"]' ) . do_shortcode( '[lbb_cookie_settings]' );
preg_match_all( '/\sid="([^"]+)"/', $du_html, $du_ids );
preg_match_all( '/\s(?:for|aria-describedby|aria-labelledby|aria-controls)="([^"]+)"/', $du_html, $du_refs );
$du_dupes   = array_keys( array_filter( array_count_values( $du_ids[1] ), function ( $n ) {
	return $n > 1;
} ) );
$du_targets = preg_split( '/\s+/', implode( ' ', $du_refs[1] ), -1, PREG_SPLIT_NO_EMPTY );
$du_missing = array_values( array_diff( $du_targets, $du_ids[1] ) );
lbb_t( 'două formulare pe o pagină: id-uri unice, etichetele și descrierile arată spre elemente existente', $du_ids[1] && ! $du_dupes && ! $du_missing, array( $du_dupes, $du_missing ) );

// WooCommerce oprit (WordPress 6.4 nu oprește plugin-ul singur): pagina rutei arată telefonul, nu „[libertbus_rezervare …]”.
$nw_keep  = get_option( 'active_plugins' );
$nw_page  = LBB_Admin::pages_with_form();
$nw_url   = $nw_page ? get_permalink( $nw_page[0]->ID ) : '';
update_option( 'active_plugins', array_values( array_filter( $nw_keep, function ( $p ) {
	return 'woocommerce/woocommerce.php' !== $p;
} ) ) );
$nw_html = $nw_url ? wp_remote_retrieve_body( wp_remote_get( $nw_url, array( 'timeout' => 20 ) ) ) : '';
// Biletele deja vândute se deschid în continuare din linkul din email, cu codul QR.
// Rezervarea proprie a testului (nu una lăsată de alte teste, care poate lipsi), anulată la final.
$nw_r    = LBB_Routes::find( 'Bălți', 'Iași' );
$nw_h    = $nw_r ? LBB_Bookings::create_hold( $nw_r, $tomorrow, $nw_r['times'][0], 1, 0, array( 'Fara Woo' ), '+37369000087', 'fara-woo@example.com', 'MDL' ) : null;
$nw_b    = is_array( $nw_h ) ? LBB_Bookings::reserve( $nw_h['token'] ) : null;
$nw_code = $nw_b ? $nw_b['ticket_code'] : '';
$nw_tick = $nw_code ? wp_remote_get( LBB_Tickets::url( $nw_code ), array( 'timeout' => 20 ) ) : null;
// Avizul din admin spune și ce văd clienții între timp (cerere ca administrator, cu cookie-ul de autentificare).
// Pagina plugin-ului, nu Panoul WordPress: acolo widgetul „Activitate” listează comenzile vechi, al căror tip
// (shop_order) nu mai există fără WooCommerce, cu notificări WordPress care nu țin de plugin.
$nw_adm  = wp_remote_retrieve_body( wp_remote_get( admin_url( 'admin.php?page=lbb-settings' ), array(
	'timeout' => 20,
	'cookies' => array(
		new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( 1, time() + 600, 'auth' ) ) ),
		new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( 1, time() + 600, 'logged_in' ) ) ),
	),
) ) );
update_option( 'active_plugins', $nw_keep );
lbb_t( 'WooCommerce oprit: avizul din admin spune că vânzarea e oprită și biletele vândute rămân valabile', false !== strpos( $nw_adm, 'are nevoie de WooCommerce activ' ) && false !== strpos( $nw_adm, 'biletele deja vândute se deschid în continuare' ), substr( wp_strip_all_tags( $nw_adm ), 0, 200 ) );
if ( $nw_b ) {
	LBB_Bookings::cancel( $nw_b['id'] );
}
lbb_t( 'WooCommerce oprit: biletul vândut se deschide din linkul din email, cu codul QR', $nw_tick && 200 === wp_remote_retrieve_response_code( $nw_tick ) && false !== strpos( wp_remote_retrieve_body( $nw_tick ), 'lbb-ticket-qr' ) && false !== strpos( wp_remote_retrieve_body( $nw_tick ), (string) $nw_code ), $nw_code );
lbb_t( 'WooCommerce oprit: bannerul de cookies apare, iar Google Analytics rămâne blocat până la acord (Legea 195/2024)', false !== strpos( $nw_html, 'id="lbb-cc"' ) && false !== strpos( $nw_html, 'data-lbb-consent="statistics"' ) && ! preg_match( '#<script(?![^>]*type="text/plain")[^>]*src="https://www\.googletagmanager\.com#', $nw_html ), $nw_url );
lbb_t( 'WooCommerce oprit: pagina cu formularul arată „Rezervarea online nu este disponibilă” cu telefonul, nu shortcode-ul brut', '' !== $nw_html && false !== strpos( $nw_html, 'Rezervarea online nu este disponibilă momentan' ) && false !== strpos( $nw_html, 'href="tel:' ) && ! preg_match( '/\[(libertbus_rezervare|lbb_booking)/', $nw_html ), $nw_url );

// Produsul intern „Bilet autocar” mutat la Gunoi din greșeală revine singur (același produs, aceeași adresă, nu un
// al doilea), iar șters definitiv se recreează; în ambele cazuri biletul se poate cumpăra.
$tp_old  = LBB_Install::product_id();
wp_trash_post( $tp_old );
$tp_back = LBB_Install::product_id();
$tp_ok   = $tp_back === $tp_old && 'publish' === get_post_status( $tp_old ) && 'bilet-autocar-libertbus' === get_post_field( 'post_name', $tp_old ) && wc_get_product( $tp_old )->is_purchasable();
lbb_t( 'produsul „Bilet autocar” mutat la Gunoi revine singur, același produs și aceeași adresă', $tp_ok, array( $tp_old, $tp_back, get_post_status( $tp_old ), get_post_field( 'post_name', $tp_old ) ) );
// Șters definitiv: se face altul, publicat, cu aceeași adresă (pagina lui trimite tot la formular).
wp_delete_post( $tp_old, true );
$tp_new = LBB_Install::product_id();
$tp_np  = $tp_new ? wc_get_product( $tp_new ) : null;
lbb_t( 'produsul „Bilet autocar” șters definitiv se recreează: publicat, virtual, ascuns din catalog, aceeași adresă', $tp_new && $tp_new !== $tp_old && $tp_np && 'publish' === $tp_np->get_status() && $tp_np->is_virtual() && 'hidden' === $tp_np->get_catalog_visibility() && 'bilet-autocar-libertbus' === $tp_np->get_slug() && $tp_np->is_purchasable(), array( $tp_old, $tp_new, $tp_np ? $tp_np->get_slug() : null ) );

// Produsul intern trecut pe ciornă (nu e la Gunoi, deci nu revine singur): panoul spune ce s-a întâmplat și ce de făcut.
$pd_id = LBB_Install::product_id();
wp_update_post( array( 'ID' => $pd_id, 'post_status' => 'draft' ) );
$pd_row = null;
foreach ( LBB_Admin::checklist() as $pd_it ) {
	if ( 'product' === $pd_it['id'] ) {
		$pd_row = $pd_it;
	}
}
wp_update_post( array( 'ID' => $pd_id, 'post_status' => 'publish' ) );
lbb_t( 'panou: produsul „Bilet autocar” pus pe ciornă e roșu și spune „nu e publicat … Publică”', $pd_row && false === $pd_row['ok'] && false !== strpos( $pd_row['detail'], 'nu e publicat' ) && false === strpos( $pd_row['detail'], 'Se recreează' ) && 'publish' === get_post_status( $pd_id ), $pd_row );

// Versiunea din antetul plugin-ului (cea din lista de pluginuri) e aceeași cu LBB_VERSION.
$lbb_header = get_file_data( LBB_DIR . 'libertbus-bilete.php', array( 'v' => 'Version' ) );
lbb_t( 'versiunea din antet e aceeași cu LBB_VERSION', LBB_VERSION === $lbb_header['v'], array( LBB_VERSION, $lbb_header['v'] ) );

// Versiunea JS/CSS se schimbă odată cu fișierul, ca o actualizare să nu rămână cu JS vechi în cache.
LBB_Frontend::register_assets();
$js_ver = wp_scripts()->registered['lbb']->ver;
$css_ver = wp_styles()->registered['lbb']->ver;
lbb_t( 'versiunea lbb.js conține data fișierului', LBB_VERSION . '.' . filemtime( LBB_DIR . 'assets/lbb.js' ) === $js_ver, $js_ver );
lbb_t( 'versiunea lbb.css conține data fișierului', LBB_VERSION . '.' . filemtime( LBB_DIR . 'assets/lbb.css' ) === $css_ver, $css_ver );
// Și biblioteca pentru codul QR de pe pagina biletului (nu o versiune fixă, care rămânea în cache după înlocuire).
$qr_r    = LBB_Routes::find( 'Bălți', 'Iași' );
$qr_h    = $qr_r ? LBB_Bookings::create_hold( $qr_r, $tomorrow, $qr_r['times'][0], 1, 0, array( 'Cod Qr' ), '+37369000089', 'qr@example.com', 'MDL' ) : null;
$qr_b    = is_array( $qr_h ) ? LBB_Bookings::reserve( $qr_h['token'] ) : null;
$qr_page = $qr_b ? wp_remote_retrieve_body( wp_remote_get( LBB_Tickets::url( $qr_b['ticket_code'] ), array( 'timeout' => 20 ) ) ) : '';
if ( $qr_b ) {
	LBB_Bookings::cancel( $qr_b['id'] );
}
lbb_t( 'versiunea qrcode.min.js pe pagina biletului conține data fișierului', false !== strpos( $qr_page, 'qrcode.min.js?ver=' . LBB_VERSION . '.' . filemtime( LBB_DIR . 'assets/qrcode.min.js' ) ), $qr_b ? '' : 'fără rezervare' );

echo "\n" . $GLOBALS['lbb_ok'] . ' ok, ' . $GLOBALS['lbb_fail'] . " eșuate\n";
if ( $GLOBALS['lbb_fail'] ) {
	exit( 1 );
}
