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
		$row['child_price'] = null === $row['child_price'] || '' === $row['child_price'] ? null : (float) $row['child_price'];
		$row['capacity']    = (int) $row['capacity'];
		$row['active']      = (int) $row['active'];
		$row['times']       = self::parse_times( $row['departures'] );
		$row['days']        = array_map( 'intval', array_filter( explode( ',', $row['weekdays'] ) ) );
		return $row;
	}

	/**
	 * Bucățile din câmpul „Ore de plecare” care nu sunt o oră validă (ex. „25:00”, „8-45”): se ignoră la
	 * salvare, deci biroul trebuie să afle, altfel cursa lipsește fără să știe nimeni.
	 */
	public static function unrecognized_times( $text ) {
		$bad = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			if ( ! self::parse_times( $part ) ) {
				$bad[] = $part;
			}
		}
		return $bad;
	}

	/**
	 * „08:45, 9:30 17:45” → ['08:45','09:30','17:45'], sortate și fără duplicate.
	 */
	public static function parse_times( $text ) {
		preg_match_all( '/\b([01]?\d|2[0-3])[:.]([0-5]\d)\b/', (string) $text, $m, PREG_SET_ORDER );
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
		$origin      = sanitize_text_field( isset( $data['origin'] ) ? $data['origin'] : '' );
		$destination = sanitize_text_field( isset( $data['destination'] ) ? $data['destination'] : '' );
		if ( '' === $origin || '' === $destination ) {
			return new WP_Error( 'lbb_route', __( 'Completați plecarea și destinația.', 'libertbus-bilete' ) );
		}
		if ( $origin === $destination ) {
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
		$child = isset( $data['child_price'] ) && '' !== trim( (string) $data['child_price'] ) ? max( 0, (float) str_replace( ',', '.', $data['child_price'] ) ) : null;

		$existing = self::find_any( $origin, $destination );
		if ( $existing && (int) $existing['id'] !== (int) $id ) {
			return new WP_Error( 'lbb_route', __( 'Ruta aceasta există deja.', 'libertbus-bilete' ) );
		}

		$row = array(
			'origin'      => $origin,
			'destination' => $destination,
			'departures'  => implode( ',', $times ),
			'weekdays'    => implode( ',', $days ),
			'price'       => max( 0, (float) str_replace( ',', '.', isset( $data['price'] ) ? $data['price'] : 0 ) ),
			'child_price' => $child,
			'currency'    => $currency,
			'capacity'    => max( 0, (int) ( isset( $data['capacity'] ) ? $data['capacity'] : 0 ) ),
			'active'      => empty( $data['active'] ) ? 0 : 1,
			'page_url'    => esc_url_raw( isset( $data['page_url'] ) ? $data['page_url'] : '' ),
			'notes'       => sanitize_textarea_field( isset( $data['notes'] ) ? $data['notes'] : '' ),
			'updated_at'  => current_time( 'mysql', true ),
		);

		if ( $id ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
			return (int) $id;
		}
		$row['created_at'] = $row['updated_at'];
		$wpdb->insert( self::table(), $row );
		return (int) $wpdb->insert_id;
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
		/* translators: 1: prețul pentru copii, 2: prețul pentru adulți, 3: moneda */
		return sprintf( __( 'Atenție: prețul pentru copii (%1$s %3$s) e mai mare decât cel pentru adulți (%2$s %3$s). Verificați prețurile.', 'libertbus-bilete' ), wc_format_decimal( $route['child_price'], 2 ), wc_format_decimal( $route['price'], 2 ), $route['currency'] );
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
		if ( ! in_array( (int) $day->format( 'N' ), $route['days'], true ) ) {
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
	 * Lista pentru formular: plecare → destinații, cu prețuri în moneda magazinului.
	 */
	public static function public_map() {
		$map = array();
		foreach ( self::all( true ) as $route ) {
			if ( $route['price'] <= 0 ) {
				continue;
			}
			$prices = array();
			foreach ( array_unique( array_merge( LBB_Settings::pay_currencies(), array( 'MDL', 'RON', $route['currency'] ) ) ) as $cur ) {
				$prices[ $cur ] = array(
					LBB_Settings::convert( $route['price'], $route['currency'], $cur ),
					null === $route['child_price'] ? null : LBB_Settings::convert( $route['child_price'], $route['currency'], $cur ),
				);
			}
			$map[ $route['origin'] ][] = array(
				'id'          => $route['id'],
				'to'          => $route['destination'],
				'prices'      => $prices,
				'child_price' => $route['child_price'],
				'orig_price'  => $route['price'],
				'orig_cur'    => $route['currency'],
				'pay_cur'     => LBB_Settings::default_pay_currency( $route['currency'] ),
				'times'       => $route['times'],
				'days'        => $route['days'],
			);
		}
		ksort( $map );
		return $map;
	}
}
