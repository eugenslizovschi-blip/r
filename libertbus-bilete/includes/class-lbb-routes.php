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
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " $where ORDER BY origin, destination", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
		$tz  = wp_timezone();
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
			$map[ $route['origin'] ][] = array(
				'id'          => $route['id'],
				'to'          => $route['destination'],
				'price'       => LBB_Settings::convert( $route['price'], $route['currency'] ),
				'child_price' => null === $route['child_price'] ? null : LBB_Settings::convert( $route['child_price'], $route['currency'] ),
				'orig_price'  => $route['price'],
				'orig_cur'    => $route['currency'],
				'times'       => $route['times'],
				'days'        => $route['days'],
			);
		}
		ksort( $map );
		return $map;
	}
}
