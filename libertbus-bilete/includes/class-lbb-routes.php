<?php
/**
 * Rutele: citire, salvare, calculul plecărilor pentru o zi.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Routes {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lbb_routes';
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function all( $only_active = false ) {
		global $wpdb;
		$where = $only_active ? 'WHERE active = 1' : '';
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " $where ORDER BY origin, destination", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- $where e o constantă din cod.
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	public static function find( $origin, $destination ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE origin = %s AND destination = %s AND active = 1', $origin, $destination ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( array $row ) {
		$row['id']          = (int) $row['id'];
		$row['price']       = (float) $row['price'];
		// Ca la salvare: „ron” (import, phpMyAdmin) e RON, nu o monedă fără curs, la care prețul rămânea neconvertit
		// (100 RON apăreau „100 MDL”); o monedă necunoscută e MDL.
		$row['currency']    = strtoupper( trim( (string) $row['currency'] ) );
		$row['currency']    = in_array( $row['currency'], LBB_Settings::currencies(), true ) ? $row['currency'] : 'MDL';
		$row['child_price'] = null === $row['child_price'] || '' === $row['child_price'] ? null : (float) $row['child_price'];
		$row['capacity']    = (int) $row['capacity'];
		$row['active']      = (int) $row['active'];
		$row['times']       = self::parse_times( $row['departures'] );
		// Doar zilele 1–7; o listă goală sau stricată (rută din baza de date, versiune veche) înseamnă zilnic, ca valoarea
		// implicită a coloanei, nu o rută care nu mai circulă niciodată fără niciun mesaj.
		$days               = array_values( array_intersect( array( 1, 2, 3, 4, 5, 6, 7 ), array_map( 'intval', explode( ',', (string) $row['weekdays'] ) ) ) );
		$row['days']        = $days ? $days : array( 1, 2, 3, 4, 5, 6, 7 );
		$row['boarding']    = isset( $row['boarding'] ) ? (string) $row['boarding'] : '';
		// Coloana „notes” acceptă NULL (rută adăugată direct în baza de date): text gol, nu null în esc_textarea() (PHP 8.1+).
		$row['notes']       = isset( $row['notes'] ) ? (string) $row['notes'] : '';
		return $row;
	}

	/**
	 * Bucățile din câmpul „Ore de plecare” care nu sunt o oră validă (ex. „25:00”, „8-45”): se ignoră la
	 * salvare, deci biroul trebuie să afle, altfel cursa lipsește fără să știe nimeni.
	 */
	public static function unrecognized_times( $text ) {
		$bad = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			// „19:00 - 21:00”, „07:00 și 15:30”: cuvintele și semnele fără cifre doar leagă orele, nu sunt ore greșite.
			if ( preg_match( '/\d/', $part ) && ! self::parse_times( $part ) ) {
				$bad[] = $part;
			}
		}
		return $bad;
	}

	/**
	 * „08:45, 9:30 17:45” → ['08:45','09:30','17:45'], sortate și fără duplicate.
	 */
	public static function parse_times( $text ) {
		// O dată scrisă din greșeală („10.11.2026”, „2026.10.11”) nu e o oră: altfel ar apărea o cursă la 10:11.
		preg_match_all( '/\b(?<!\d\.)([01]?\d|2[0-3])[:.]([0-5]\d)\b(?!\.\d)/', (string) $text, $m, PREG_SET_ORDER );
		$times = array();
		foreach ( $m as $match ) {
			$times[] = sprintf( '%02d:%02d', $match[1], $match[2] );
		}
		$times = array_values( array_unique( $times ) );
		sort( $times );
		return $times;
	}

	public static function capacity( array $route ) {
		return $route['capacity'] > 0 ? $route['capacity'] : (int) LBB_Settings::get( 'default_capacity' );
	}

	/**
	 * Salvează o rută. Întoarce ID-ul sau WP_Error.
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		// O listă unde se așteaptă text (cerere măsluită: currency[]=…) devine gol, nu „Array” sau o eroare fatală;
		// doar zilele de circulație sunt o listă, și ea doar cu valori simple.
		foreach ( $data as $key => $value ) {
			if ( 'days' === $key ) {
				$data[ $key ] = array_filter( (array) $value, 'is_scalar' );
			} elseif ( ! is_scalar( $value ) && null !== $value ) {
				$data[ $key ] = '';
			}
		}
		$origin      = sanitize_text_field( isset( $data['origin'] ) ? $data['origin'] : '' );
		$destination = sanitize_text_field( isset( $data['destination'] ) ? $data['destination'] : '' );
		if ( '' === $origin || '' === $destination ) {
			return new WP_Error( 'lbb_route', __( 'Completați plecarea și destinația.', 'libertbus-bilete' ) );
		}
		$origin      = self::known_city( $origin, $id );
		$destination = self::known_city( $destination, $id );
		if ( self::city_key( $origin ) === self::city_key( $destination ) ) {
			return new WP_Error( 'lbb_route', __( 'Plecarea și destinația trebuie să fie diferite.', 'libertbus-bilete' ) );
		}
		$times = self::parse_times( isset( $data['departures'] ) ? $data['departures'] : '' );
		if ( ! $times ) {
			return new WP_Error( 'lbb_route', __( 'Introduceți cel puțin o oră de plecare (ex. 08:45).', 'libertbus-bilete' ) );
		}
		$currency = strtoupper( isset( $data['currency'] ) ? $data['currency'] : 'MDL' );
		if ( ! in_array( $currency, LBB_Settings::currencies(), true ) ) {
			$currency = 'MDL';
		}
		$days = isset( $data['days'] ) ? (array) $data['days'] : array( 1, 2, 3, 4, 5, 6, 7 );
		$days = array_values( array_intersect( array( 1, 2, 3, 4, 5, 6, 7 ), array_map( 'intval', $days ) ) );
		if ( ! $days ) {
			return new WP_Error( 'lbb_route', __( 'Alegeți cel puțin o zi a săptămânii.', 'libertbus-bilete' ) );
		}
		$child = isset( $data['child_price'] ) && '' !== trim( (string) $data['child_price'] ) ? max( 0, self::parse_amount( $data['child_price'] ) ) : null;

		$existing = self::find_any( $origin, $destination );
		if ( $existing && (int) $existing['id'] !== (int) $id ) {
			return new WP_Error( 'lbb_route', __( 'Ruta aceasta există deja.', 'libertbus-bilete' ) );
		}

		$row = array(
			'origin'      => $origin,
			'destination' => $destination,
			'departures'  => implode( ',', $times ),
			'weekdays'    => implode( ',', $days ),
			'price'       => max( 0, self::parse_amount( isset( $data['price'] ) ? $data['price'] : 0 ) ),
			'child_price' => $child,
			'currency'    => $currency,
			'capacity'    => max( 0, (int) ( isset( $data['capacity'] ) ? $data['capacity'] : 0 ) ),
			'active'      => empty( $data['active'] ) ? 0 : 1,
			'page_url'    => esc_url_raw( isset( $data['page_url'] ) ? $data['page_url'] : '' ),
			'boarding'    => sanitize_text_field( isset( $data['boarding'] ) ? $data['boarding'] : '' ),
			'notes'       => sanitize_textarea_field( isset( $data['notes'] ) ? $data['notes'] : '' ),
			'updated_at'  => current_time( 'mysql', true ),
		);

		// Coloanele au lungimi fixe: un text mai lung face ca baza de date să refuze tot rândul, iar biroul ar vedea
		// „Ruta a fost salvată” fără să se fi salvat nimic.
		$limits = array(
			'origin'      => array( 100, __( 'Plecarea', 'libertbus-bilete' ) ),
			'destination' => array( 100, __( 'Destinația', 'libertbus-bilete' ) ),
			'boarding'    => array( 255, __( 'Locul de urcare', 'libertbus-bilete' ) ),
			'page_url'    => array( 255, __( 'Pagina rutei', 'libertbus-bilete' ) ),
			'departures'  => array( 255, __( 'Orele de plecare', 'libertbus-bilete' ) ),
		);
		foreach ( $limits as $field => $limit ) {
			if ( mb_strlen( (string) $row[ $field ] ) > $limit[0] ) {
				/* translators: 1: numele câmpului, 2: numărul maxim de caractere */
				return new WP_Error( 'lbb_route', sprintf( __( '%1$s e prea lung (cel mult %2$d de caractere).', 'libertbus-bilete' ), $limit[1], $limit[0] ) );
			}
		}

		if ( $id ) {
			if ( false === $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) ) ) {
				return new WP_Error( 'lbb_route', __( 'Ruta nu a putut fi salvată. Încercați din nou.', 'libertbus-bilete' ) );
			}
			return (int) $id;
		}
		$row['created_at'] = $row['updated_at'];
		if ( ! $wpdb->insert( self::table(), $row ) ) {
			return new WP_Error( 'lbb_route', __( 'Ruta nu a putut fi salvată. Încercați din nou.', 'libertbus-bilete' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Un preț scris de om: „1 200” sau „1.200” (mii) = 1200, „240,50” = „240.50” = 240.5, „1.200,50” = „1,200.50” = 1200.5.
	 * Altfel (float) „1 200” dă 1 și biletul s-ar vinde cu 1 MDL.
	 */
	public static function parse_amount( $value ) {
		$v = preg_replace( '/[\s\x{00A0}\x{202F}]+/u', '', (string) $value );
		$v = preg_replace( '/[^\d.,-]/', '', (string) $v );
		$dot   = strrpos( $v, '.' );
		$comma = strrpos( $v, ',' );
		if ( false !== $dot && false !== $comma ) {
			// Ultimul semn e cel zecimal; celălalt separă miile.
			$v = $dot > $comma ? str_replace( ',', '', $v ) : str_replace( ',', '.', str_replace( '.', '', $v ) );
		} elseif ( false !== $comma ) {
			$v = str_replace( ',', '.', $v );
		}
		// „1.200” / „12.500.000”: puncte urmate de exact 3 cifre, ca separator de mii.
		if ( preg_match( '/^-?\d{1,3}(\.\d{3})+$/', $v ) ) {
			$v = str_replace( '.', '', $v );
		}
		return (float) $v;
	}

	/**
	 * Cheia de comparare a unui oraș: fără majuscule și fără diacritice („Chisinau” = „Chișinău”).
	 */
	private static function city_key( $city ) {
		return self::lower( remove_accents( $city ) );
	}

	/**
	 * Ordinea alfabetică a orașelor ca în română: „Bălți” după „Bacău”, nu după „Buzău” (sortarea pe octeți
	 * pune literele cu diacritice la urmă). Același rezultat pe orice server, cu sau fără extensia intl.
	 */
	public static function compare_cities( $a, $b ) {
		$cmp = strcmp( self::sort_key( $a ), self::sort_key( $b ) );
		return 0 !== $cmp ? $cmp : strcmp( $a, $b );
	}

	/**
	 * Cheia de sortare: fiecare literă + rangul ei în alfabetul românesc (a < ă < â, i < î, s < ș, t < ț).
	 */
	private static function sort_key( $city ) {
		$ro  = array(
			'ă' => 'a1',
			'â' => 'a2',
			'î' => 'i1',
			'ș' => 's1',
			'ş' => 's1',
			'ț' => 't1',
			'ţ' => 't1',
		);
		$key = '';
		foreach ( (array) preg_split( '//u', self::lower( $city ), -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$key .= isset( $ro[ $char ] ) ? $ro[ $char ] : remove_accents( $char ) . '0';
		}
		return $key;
	}

	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Un oraș scris altfel decât pe celelalte rute („chisinau”) primește scrierea existentă („Chișinău”),
	 * ca să nu apară de două ori în lista de plecări. Ruta editată nu contează (așa se poate corecta scrierea).
	 */
	private static function known_city( $city, $id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT origin, destination FROM ' . self::table() . ' WHERE id <> %d ORDER BY id', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$key  = self::city_key( $city );
		foreach ( (array) $rows as $row ) {
			foreach ( array( $row['origin'], $row['destination'] ) as $known ) {
				if ( self::city_key( $known ) === $key ) {
					return $known;
				}
			}
		}
		return $city;
	}

	private static function find_any( $origin, $destination ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE origin = %s AND destination = %s', $origin, $destination ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Câte bilete și rezervări valabile are ruta pentru azi și zilele următoare. O rută cu călători așteptați nu
	 * se șterge (ar rămâne fără rută în lista pentru șofer și pe bilet): i se oprește vânzarea.
	 */
	public static function upcoming_bookings( $id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LBB_Bookings::table() . " WHERE route_id = %d AND travel_date >= %s AND status IN ('confirmed','reserved','pending')", $id, LBB_Settings::today() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Cursele viitoare cu bilete sau rezervări care nu mai sunt în orarul rutei (ora sau ziua săptămânii a fost
	 * scoasă la editare): [ 'zz.ll.aaaa HH:MM' => câte ]. Rezervările rămân valabile, dar cursa nu mai apare în
	 * vânzare, deci biroul trebuie să afle și să anunțe pasagerii (sau să pună ora la loc).
	 */
	public static function stranded_bookings( array $route ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT travel_date, dep_time, COUNT(*) AS n FROM ' . LBB_Bookings::table() . " WHERE route_id = %d AND travel_date >= %s AND status IN ('confirmed','reserved','pending') GROUP BY travel_date, dep_time ORDER BY travel_date, dep_time", $route['id'], LBB_Settings::today() ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$day = (int) gmdate( 'N', strtotime( $row['travel_date'] . ' 12:00 UTC' ) );
			if ( ! in_array( $row['dep_time'], $route['times'], true ) || ! in_array( $day, $route['days'], true ) ) {
				$out[ gmdate( 'd.m.Y', strtotime( $row['travel_date'] . ' 12:00 UTC' ) ) . ' ' . $row['dep_time'] ] = (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * Prețul pentru copii mai mare decât cel pentru adulți e aproape sigur o greșeală de tastare (ex. 2400 în loc
	 * de 240): mesajul pentru birou, sau '' când prețurile arată normal.
	 */
	public static function child_price_warning( array $route ) {
		if ( null === $route['child_price'] || $route['child_price'] <= $route['price'] ) {
			return '';
		}
		// Prețurile în aceeași formă ca în lista de rute și pe bilet: „2.400 MDL”, nu „2400.00 MDL”.
		/* translators: 1: prețul pentru copii, cu moneda, 2: prețul pentru adulți, cu moneda */
		return sprintf( __( 'Atenție: prețul pentru copii (%1$s) e mai mare decât cel pentru adulți (%2$s). Verificați prețurile.', 'libertbus-bilete' ), LBB_WooCommerce::money( $route['child_price'], $route['currency'] ), LBB_WooCommerce::money( $route['price'], $route['currency'] ) );
	}

	/**
	 * Zilele săptămânii, 1 = luni … 7 = duminică (ca date( 'N' )).
	 */
	public static function day_names() {
		return array( 1 => __( 'luni', 'libertbus-bilete' ), 2 => __( 'marți', 'libertbus-bilete' ), 3 => __( 'miercuri', 'libertbus-bilete' ), 4 => __( 'joi', 'libertbus-bilete' ), 5 => __( 'vineri', 'libertbus-bilete' ), 6 => __( 'sâmbătă', 'libertbus-bilete' ), 7 => __( 'duminică', 'libertbus-bilete' ) );
	}

	/**
	 * „Ruta circulă doar: luni, joi.” când ruta nu circulă în ziua săptămânii a datei Y-m-d; altfel ''.
	 */
	public static function running_days_note( array $route, $date ) {
		$names = self::day_names();
		$time  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? strtotime( $date . ' 12:00 UTC' ) : false;
		if ( ! $time || count( $route['days'] ) >= 7 || in_array( (int) gmdate( 'N', $time ), $route['days'], true ) ) {
			return '';
		}
		$list = array();
		foreach ( $route['days'] as $d ) {
			$list[] = $names[ $d ];
		}
		/* translators: %s: zilele săptămânii în care circulă ruta, ex. „luni, joi” */
		return sprintf( __( 'Ruta circulă doar: %s.', 'libertbus-bilete' ), implode( ', ', $list ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Plecările unei rute într-o zi, cu locurile libere și dacă se mai pot vinde.
	 *
	 * @param array  $route Ruta.
	 * @param string $date  Y-m-d, în fusul orar al site-ului.
	 */
	public static function departures_on( array $route, $date ) {
		$tz  = LBB_Settings::tz();
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		if ( ! $day || $day->format( 'Y-m-d' ) !== $date ) {
			return array();
		}
		if ( ! in_array( (int) $day->format( 'N' ), $route['days'], true ) || in_array( $date, LBB_Settings::closed_dates(), true ) ) {
			return array();
		}
		$now      = new DateTimeImmutable( 'now', $tz );
		$cutoff   = $now->modify( '+' . (int) LBB_Settings::get( 'cutoff_minutes' ) . ' minutes' );
		$last_day = $now->setTime( 0, 0 )->modify( '+' . (int) LBB_Settings::get( 'max_days_ahead' ) . ' days' );
		if ( $day > $last_day ) {
			return array();
		}
		$capacity = self::capacity( $route );
		$taken    = LBB_Bookings::taken_by_time( $route['id'], $date );
		$out      = array();
		foreach ( $route['times'] as $time ) {
			list( $h, $i ) = array_map( 'intval', explode( ':', $time ) );
			$when  = $day->setTime( $h, $i );
			$free  = max( 0, $capacity - ( isset( $taken[ $time ] ) ? $taken[ $time ] : 0 ) );
			$out[] = array(
				'time'     => $time,
				'free'     => $free,
				'bookable' => $when > $cutoff && $free > 0,
				'reason'   => $when <= $cutoff ? 'closed' : ( $free > 0 ? '' : 'full' ),
			);
		}
		return $out;
	}

	/**
	 * Ce vede formularul despre o rută: prețuri în fiecare monedă de plată, ore, zile, loc de urcare.
	 *
	 * @param array $route Ruta.
	 */
	public static function public_entry( array $route ) {
		$prices = array();
		foreach ( array_unique( array_merge( LBB_Settings::pay_currencies(), array( 'MDL', 'RON', $route['currency'] ) ) ) as $cur ) {
			$prices[ $cur ] = array(
				LBB_Settings::convert( $route['price'], $route['currency'], $cur ),
				null === $route['child_price'] ? null : LBB_Settings::convert( $route['child_price'], $route['currency'], $cur ),
			);
		}
		return array(
			'id'          => $route['id'],
			'to'          => $route['destination'],
			'prices'      => $prices,
			'child_price' => $route['child_price'],
			'orig_price'  => $route['price'],
			'orig_cur'    => $route['currency'],
			'pay_cur'     => LBB_Settings::default_pay_currency( $route['currency'] ),
			'times'       => $route['times'],
			'days'        => $route['days'],
			'boarding'    => $route['boarding'],
		);
	}

	/**
	 * Lista pentru formular: plecare → destinații, cu prețuri în moneda magazinului.
	 */
	public static function public_map() {
		$map = array();
		foreach ( self::all( true ) as $route ) {
			if ( $route['price'] <= 0 ) {
				continue;
			}
			$map[ $route['origin'] ][] = self::public_entry( $route );
		}
		uksort( $map, array( __CLASS__, 'compare_cities' ) );
		foreach ( $map as $origin => $list ) {
			usort(
				$map[ $origin ],
				function ( $a, $b ) {
					return self::compare_cities( $a['to'], $b['to'] );
				}
			);
		}
		return $map;
	}
}
