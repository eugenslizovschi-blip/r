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
foreach ( array( array( 'TestA', 'TestB' ), array( 'TestA', 'TestC' ), array( 'TestA', 'Orașul Nou' ), array( 'StergeA', 'StergeB' ), array( 'LungA', 'LungB' ), array( 'ReamintA', 'ReamintB' ), array( 'UrcareA', 'UrcareB' ), array( 'PretA', 'PretB' ), array( 'AziA', 'AziB' ), array( 'InchisA', 'InchisB' ), array( 'OrarA', 'OrarB' ), array( 'ZileA', 'ZileB' ), array( 'CurseA', 'CurseB' ), array( 'Fălești, centru', 'Târgu Mureș' ), array( 'HpA', 'HpB' ), array( 'TzA', 'TzB' ), array( 'StearsaA', 'StearsaB' ) ) as $lbb_pair ) {
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
$fr_page = wp_remote_retrieve_body( wp_remote_get( LBB_Tickets::url( $fr['ticket_code'] ), array( 'timeout' => 20 ) ) );
lbb_t( 'comanda plătită și rambursată, trecută înapoi în „așteaptă plata”: biletul nu cere o nouă plată', false === strpos( $fr_page, 'Achită acum' ) && false === strpos( $fr_page, 'Adaugă în calendar' ), substr( wp_strip_all_tags( $fr_page ), 0, 300 ) );
$fo->update_status( 'cancelled' ); // eliberează locul ținut din nou, altfel testele de mai jos nu mai au locuri
$fo->delete( true );

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
$o2->payment_complete( 'TEST' );
$o2 = wc_get_order( $o2->get_id() );
$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $o2->get_id() ) ), 'content' );
lbb_t( 'plata întârziată: biletul se emite', 'confirmed' === LBB_Bookings::get_by_token( $late['token'] )['status'] );
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
lbb_t( 'pagina comenzii WooCommerce arată biletele comenzii (cod cu link, cursă, locuri, stare)', '' === $ot_none && '' === $ot_empty && false !== strpos( $ot_html, 'Bilete LibertBus' ) && false !== strpos( $ot_html, 'href="' . LBB_Tickets::url( $ot_late['ticket_code'] ) . '"' ) && false !== strpos( $ot_html, $route['origin'] . ' → ' . $route['destination'] . ', ' . wp_date( 'd.m.Y', strtotime( $tomorrow . ' 12:00' ) ) . ' 10:00, 3 locuri' ) && false !== strpos( $ot_html, LBB_Tickets::state( $ot_late )['text'] ), array( $ot_none, $ot_empty, wp_strip_all_tags( $ot_html ) ) );

// Emailul text al comenzii (clienți cu emailuri „doar text”): numele cu apostrof rămâne întreg după
// curățarea făcută de WooCommerce, iar titlul e cu majuscule corecte.
$wpdb->update( LBB_Bookings::table(), array( 'passengers' => wp_json_encode( array( "Ana D'Angelo", 'L', 'M' ) ) ), array( 'id' => $late['id'] ) );
ob_start();
LBB_Tickets::email( $o2, false, true );
$lbb_plain = ob_get_clean();
$lbb_wce   = new WC_Email();
$lbb_plain = preg_replace( $lbb_wce->plain_search, $lbb_wce->plain_replace, wp_strip_all_tags( $lbb_plain ) );
lbb_t( 'emailul text: „Ana D\'Angelo” rămâne întreg, titlul „BILETELE DUMNEAVOASTRĂ”', false !== strpos( $lbb_plain, "Ana D'Angelo" ) && false !== strpos( $lbb_plain, 'BILETELE DUMNEAVOASTRĂ' ), $lbb_plain );
lbb_t( 'emailul text al comenzii are și „Adaugă în calendar” cu un link utilizabil (& nu &#038;)', (bool) preg_match( '#Adaugă în calendar: https?://\S+[?&]lbb_bilet=\S+&k=\w+&ics=1\n#u', $lbb_plain ), $lbb_plain );

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
// O greșeală știută în domeniu („gmail.con”) e oprită cu adresa corectă propusă; domeniile corecte și cele necunoscute trec.
$r = LBB_Frontend::book( array_merge( $base, array( 'lbb_email' => 'Ion.Rusu@GMAIL.CON' ) ) );
$et_ok = array();
foreach ( array( 'a@gmail.com', 'a@yahoo.com', 'a@mail.ru', 'a@gmail.co.uk', 'a@firma.md', 'a@gmail.con.md', 'a@yandex.ru', 'a@hotmail.co.uk', 'a@outlook.com' ) as $et_e ) {
	$et_ok[ $et_e ] = LBB_Frontend::email_typo( $et_e );
}
lbb_t( 'email cu domeniu greșit („gmail.con”, „gmial.com”, „mail.r”) oprit cu adresa corectă propusă; adresele corecte trec', is_wp_error( $r ) && 'lbb_email' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'Ion.Rusu@gmail.com' ) && 'x@gmail.com' === LBB_Frontend::email_typo( 'x@gmial.com' ) && 'x@mail.ru' === LBB_Frontend::email_typo( 'x@mail.r' ) && 'x@hotmail.com' === LBB_Frontend::email_typo( 'x@hotmial.com' ) && ! array_filter( $et_ok ), array( $r, $et_ok ) );
lbb_t( 'greșeli dese în domeniu („gmail.co”, „gmail.cpm”, „gmal.com”, „yandex.r”, „hotmail.co”) au adresa corectă propusă', 'a@gmail.com' === LBB_Frontend::email_typo( 'a@gmail.co' ) && 'a@gmail.com' === LBB_Frontend::email_typo( 'a@gmail.cpm' ) && 'a@gmail.com' === LBB_Frontend::email_typo( 'a@gmal.com' ) && 'a@yandex.ru' === LBB_Frontend::email_typo( 'a@yandex.r' ) && 'a@hotmail.com' === LBB_Frontend::email_typo( 'a@hotmail.co' ) && '' === LBB_Frontend::email_typo( 'a@hotmail.co.uk' ) );
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
$to_office = array_values( array_filter( $lbb_mails, function ( $m ) {
	return 'client-mail@example.com' !== $m['to'];
} ) );
$office_plain = $to_office ? LBB_Tickets::plain_text( $to_office[0]['message'] ) : '';
lbb_t( 'varianta text a emailului către birou: telefon și email fără linkuri dublate', false !== strpos( $office_plain, 'Telefon: +37369000077' ) && false !== strpos( $office_plain, 'Email: client-mail@example.com' ) && false === strpos( $office_plain, 'mailto:' ) && false === strpos( $office_plain, 'tel:' ), $office_plain );
lbb_t( 'emailul către birou are telefonul și emailul clientului ca linkuri (apel / răspuns dintr-o atingere)', $to_office && false !== strpos( $to_office[0]['message'], 'href="tel:+37369000077"' ) && false !== strpos( $to_office[0]['message'], 'href="mailto:client-mail@example.com"' ), $to_office ? substr( $to_office[0]['message'], -400 ) : 'lipsă' );
lbb_t( 'emailurile plugin-ului au font fără serife (ca pagina biletului), pentru client și birou', 2 === count( array_filter( $lbb_mails, function ( $m ) {
	return 0 === strpos( $m['message'], '<div style="font-family:' ) && false !== strpos( substr( $m['message'], 0, 160 ), 'sans-serif' );
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
update_option( 'lbb_settings', $keep_name );
lbb_t( 'răspunsul clientului ajunge la birou (Reply-To)', false !== strpos( $h_client, 'Reply-To: ' . $office ), $h_client );
lbb_t( 'biroul răspunde direct clientului (Reply-To)', false !== strpos( $h_office, 'Reply-To: client-mail@example.com' ), $h_office );

// Biroul anulează rezervarea: clientul primește un email (să nu vină degeaba la autocar).
$lbb_mails = array();
add_filter( 'pre_wp_mail', $lbb_catch, 1, 2 );
$cx_sent = $em && LBB_Bookings::cancel( $em['id'] ) && LBB_Tickets::send_cancellation_email( LBB_Bookings::get( $em['id'] ) );
lbb_t( 'fără email valid nu se trimite nimic la anulare', false === LBB_Tickets::send_cancellation_email( array_merge( (array) $em, array( 'email' => '' ) ) ) );
$cx_past_n = count( $lbb_mails );
$cx_past   = $em ? LBB_Tickets::send_cancellation_email( array_merge( LBB_Bookings::get( $em['id'] ), array( 'travel_date' => gmdate( 'Y-m-d', strtotime( LBB_Settings::today() . ' -1 day' ) ) ) ) ) : true;
lbb_t( 'anularea unei rezervări pe o cursă trecută (clientul n-a venit) nu trimite email clientului', false === $cx_past && count( $lbb_mails ) === $cx_past_n );
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
$pv_res = isset( $pv_er['libertbus-bilete'] ) ? call_user_func( $pv_er['libertbus-bilete']['callback'], 'gdpr@example.com', 1 ) : array();
$pv_o   = $pv_old ? LBB_Bookings::get( $pv_old['id'] ) : null;
$pv_n   = $pv_new ? LBB_Bookings::get( $pv_new['id'] ) : null;
lbb_t( 'ștergere date personale: cursa trecută anonimizată, biletul pentru cursa viitoare păstrat cu mesaj', ! empty( $pv_res['items_removed'] ) && ! empty( $pv_res['items_retained'] ) && ! empty( $pv_res['messages'] )
	&& $pv_o && '' === $pv_o['email'] && '' === $pv_o['phone'] && ! array_filter( (array) $pv_o['passengers'] ) && $pv_n && 'gdpr@example.com' === $pv_n['email'], array( $pv_res, $pv_o, $pv_n ) );

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
// Plata refuzată: pagina nu mai promite bilete (WooCommerce arată deja „plata nu a reușit”).
$ty_o->update_status( 'failed' );
ob_start();
LBB_Tickets::thankyou( $ty_o->get_id() );
$ty_fail = ob_get_clean();
lbb_t( 'plată refuzată: pe pagina comenzii nu apare „biletele vin imediat”', false === strpos( $ty_fail, 'Biletele se emit' ) && false === strpos( $ty_fail, 'Verifică din nou' ), $ty_fail );
$ty_o->delete( true );

// Ore de plecare scrise greșit la o rută: le numim, ca biroul să afle că acea cursă n-a fost salvată.
lbb_t( 'orele neînțelese la o rută sunt semnalate (25:00, 8-45), cele bune nu', array( '25:00', '8-45' ) === LBB_Routes::unrecognized_times( "08:45, 25:00\n13.30; 8-45 17:05" ) && array() === LBB_Routes::unrecognized_times( '06:00, 7.15' ), LBB_Routes::unrecognized_times( "08:45, 25:00\n13.30; 8-45 17:05" ) );

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
if ( is_int( $rd_r ) ) {
	LBB_Routes::delete( $rd_r );
}
lbb_t( 'zi în care ruta nu circulă: mesajul spune „Ruta circulă doar: luni, joi.”', 'Ruta circulă doar: luni, joi.' === $rd_note && '' === $rd_on && '' === $rd_daily && is_wp_error( $rd_hold ) && false !== strpos( $rd_hold->get_error_message(), 'Ruta circulă doar: luni, joi.' ), array( $rd_note, $rd_on, $rd_daily, is_wp_error( $rd_hold ) ? $rd_hold->get_error_message() : $rd_hold ) );

// Preț pentru copii mai mare decât pentru adulți (greșeală de tastare): biroul e avertizat la salvare.
$cp_hi  = LBB_Routes::child_price_warning( array( 'price' => 240.0, 'child_price' => 2400.0, 'currency' => 'MDL' ) );
$cp_ok  = LBB_Routes::child_price_warning( array( 'price' => 240.0, 'child_price' => 150.0, 'currency' => 'MDL' ) );
$cp_eq  = LBB_Routes::child_price_warning( array( 'price' => 240.0, 'child_price' => 240.0, 'currency' => 'MDL' ) );
$cp_non = LBB_Routes::child_price_warning( array( 'price' => 240.0, 'child_price' => null, 'currency' => 'MDL' ) );
lbb_t( 'prețul de copil mai mare decât cel de adult e semnalat; egal, mai mic sau gol nu', false !== strpos( $cp_hi, '2400' ) && false !== strpos( $cp_hi, '240' ) && '' === $cp_ok && '' === $cp_eq && '' === $cp_non, array( $cp_hi, $cp_ok, $cp_eq, $cp_non ) );

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
$cr_res  = LBB_Admin::manifest_csv_row( '2026-10-21', array_merge( $dd_rows[0], $cr_base, array( 'passengers' => array( 'Ion Pop', 'Ana Pop' ), 'currency' => 'RON', 'pay_currency' => 'RON', 'amount' => 30.5 ) ) );
$cr_onl  = LBB_Admin::manifest_csv_row( '2026-10-21', array_merge( $dd_rows[1], $cr_base ) );
lbb_t( 'CSV-ul listei de pasageri are suma de încasat și moneda în coloane separate (goale la plata online)', 13 === count( $cr_res ) && 'la urcare 30.5 RON' === $cr_res[9] && 30.5 === $cr_res[11] && 'RON' === $cr_res[12] && 'online' === $cr_onl[9] && '' === $cr_onl[11] && '' === $cr_onl[12], array( $cr_res, $cr_onl ) );

// Zile fără curse (sărbători): în setări, cu date scrise ca 25.12.2026 / 2026-12-25; în aceste zile nu se vinde nimic.
$zc_p = LBB_Settings::parse_dates( '25.12.2099, 1/1/2100 2099-12-26 32.01.2099 abc 01.01.2000 25.12.2099' );
lbb_t( 'zile fără curse: datele se înțeleg în mai multe forme, cele greșite se semnalează, cele trecute se ignoră', array( '2099-12-25', '2099-12-26', '2100-01-01' ) === $zc_p['dates'] && array( '32.01.2099', 'abc' ) === $zc_p['bad'], $zc_p );
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
lbb_t( 'lista de rute arată locul de urcare sub rută și câte rute active nu-l au', false !== strpos( $bd_list, '<span class="description lbb-boarding">Urcare: Autogara Nord, peronul 3</span>' ) && (bool) preg_match( '#lbb-no-boarding">\\d+ rut#u', $bd_list ), substr( wp_strip_all_tags( $bd_list ), 0, 300 ) );
lbb_t( '„Trimite biletul” la o rezervare cu plata la urcare scrie „Rezervare”, ca pagina', 0 === strpos( LBB_Tickets::share_text( array_merge( $bd_b, array( 'status' => 'reserved' ) ) ), 'Rezervare LibertBus LB-URC1: ' ) );
lbb_t( '„Trimite biletul” are și textul cursei (cod, rută, zi, oră); o rută ștearsă nu lasă text gol', 'Bilet LibertBus LB-URC1: UrcareA → UrcareB, ' . LBB_WooCommerce::date_with_day( $tomorrow ) . ', 10:00' === $bd_s && false !== strpos( LBB_Tickets::share_text( array_merge( $bd_b, array( 'route_id' => 0 ) ) ), '(rută ștearsă)' ), $bd_s );
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
$_GET = array();
$rm_claim = $rm_b && is_array( $rm_b ) ? array( LBB_Bookings::claim_reminder( $rm_b['id'] ), LBB_Bookings::claim_reminder( $rm_b['id'] ) ) : array();
// Zi închisă sau oră scoasă din orar: fără „vă reamintim de cursa de mâine”.
$rm_c = $rm_rt ? LBB_Bookings::create_hold( $rm_rt, $tomorrow, '10:00', 1, 0, array( 'Ana Inchis' ), '+37369000097', 'rm3@example.com', 'MDL' ) : null;
$rm_c = is_array( $rm_c ) ? LBB_Bookings::reserve( $rm_c['token'] ) : $rm_c;
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

// Accesibilitate în admin: explicația de sub un câmp e legată de el (aria-describedby), fiecare id o singură dată.
ob_start();
LBB_Admin::page_settings();
$ad_set = ob_get_clean();
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
$bl_big   = $bl_page( 100000 );
lbb_t( 'Rezervări: la limita listei apare „Se arată doar ultimele…”, sub limită nu', false !== strpos( $bl_small, 'Se arată doar ultimele 1.' ) && false === strpos( $bl_big, 'Se arată doar ultimele' ), substr( wp_strip_all_tags( $bl_small ), -200 ) );

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
lbb_t( 'API: data în alt format e refuzată (400)', 400 === $api( array( 'route_id' => $rid2, 'date' => '07.10.2026' ) )->get_status() );
lbb_t( 'API: fără rută e refuzat (400)', 400 === $api( array( 'date' => $tomorrow ) )->get_status() );
lbb_t( 'API: ruta inexistentă dă 404', 404 === $api( array( 'route_id' => 999999, 'date' => $tomorrow ) )->get_status() );
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
lbb_t( 'Setări: câmpurile numerice au minimul real (1 loc/pasager/zi, 5 și 10 minute), 0 permis la închidere și limită', array( 'default_capacity' => '1!', 'max_passengers' => '1!', 'max_days_ahead' => '1!', 'cart_hold_minutes' => '5!', 'payment_minutes' => '10!', 'cutoff_minutes' => '0', 'reserve_limit' => '0' ) === $mn_found, $mn_found );

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
update_option( 'lbb_settings', array_merge( $cl_keep, array( 'support_phone' => '00373 691 84 111' ) ) );
$it = $ph_item();
lbb_t( 'panou: telefonul cu prefixul 00373 apare ca bifat', $it && true === $it['ok'], $it );
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
