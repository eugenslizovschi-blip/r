<?php
/**
 * Rezervările: locuri ținute temporar, locuri plătite, coduri de bilet.
 *
 * Stări:
 *  - hold      locurile sunt în coș, expiră după „cart_hold_minutes”;
 *  - pending   comanda e creată și se așteaptă plata, expiră după „payment_minutes”;
 *  - confirmed plata a trecut (sau comanda e „on-hold”), locurile sunt vândute;
 *  - cancelled locurile sunt eliberate.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Bookings {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lbb_bookings';
	}

	private static function now_utc( $plus_minutes = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + 60 * (int) $plus_minutes );
	}

	/**
	 * Locuri ocupate pe fiecare oră dintr-o zi.
	 *
	 * @param int    $route_id   Ruta.
	 * @param string $date       Y-m-d.
	 * @param int    $exclude_id Rezervare de ignorat (la reînnoirea unei rezervări).
	 * @return array ['08:45' => 3, ...]
	 */
	public static function taken_by_time( $route_id, $date, $exclude_id = 0 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT dep_time, SUM(seats) AS taken FROM ' . self::table() . "
			WHERE route_id = %d AND travel_date = %s AND id <> %d
			AND ( status = 'confirmed' OR ( status IN ('hold','pending') AND expires_at > %s ) )
			GROUP BY dep_time",
			$route_id,
			$date,
			$exclude_id,
			self::now_utc()
		), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['dep_time'] ] = (int) $row['taken'];
		}
		return $out;
	}

	private static function lock( $route_id, $date, $time ) {
		global $wpdb;
		$name = 'lbb_' . md5( $wpdb->prefix . $route_id . $date . $time );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) );
		return '1' === (string) $got ? $name : '';
	}

	private static function unlock( $name ) {
		global $wpdb;
		if ( $name ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * Verifică datele și ține locurile pentru coș.
	 *
	 * @return array|WP_Error Rezervarea creată.
	 */
	public static function create_hold( array $route, $date, $time, $adults, $children, array $passengers, $phone, $email ) {
		global $wpdb;
		$adults   = max( 0, (int) $adults );
		$children = max( 0, (int) $children );
		$seats    = $adults + $children;
		if ( $seats < 1 ) {
			return new WP_Error( 'lbb_seats', __( 'Alegeți cel puțin un pasager.', 'libertbus-bilete' ) );
		}
		if ( $seats > (int) LBB_Settings::get( 'max_passengers' ) ) {
			/* translators: %d: număr maxim de pasageri */
			return new WP_Error( 'lbb_seats', sprintf( __( 'Online se pot rezerva cel mult %d locuri odată. Pentru grupuri sunați-ne.', 'libertbus-bilete' ), LBB_Settings::get( 'max_passengers' ) ) );
		}
		if ( $children && null === $route['child_price'] ) {
			$adults  += $children;
			$children = 0;
		}

		$lock = self::lock( $route['id'], $date, $time );
		try {
			$departure = null;
			foreach ( LBB_Routes::departures_on( $route, $date ) as $dep ) {
				if ( $dep['time'] === $time ) {
					$departure = $dep;
				}
			}
			if ( ! $departure ) {
				return new WP_Error( 'lbb_departure', __( 'Nu există plecare la ora aleasă în ziua aceasta.', 'libertbus-bilete' ) );
			}
			if ( 'closed' === $departure['reason'] ) {
				return new WP_Error( 'lbb_departure', __( 'Vânzarea online pentru această plecare s-a încheiat. Sunați-ne pentru locuri.', 'libertbus-bilete' ) );
			}
			if ( $departure['free'] < $seats ) {
				/* translators: %d: locuri libere */
				return new WP_Error( 'lbb_full', sprintf( _n( 'A mai rămas %d loc liber la această plecare.', 'Au mai rămas %d locuri libere la această plecare.', $departure['free'], 'libertbus-bilete' ), $departure['free'] ) );
			}

			$amount = $adults * $route['price'] + $children * ( null === $route['child_price'] ? $route['price'] : $route['child_price'] );
			$token  = wp_generate_password( 32, false, false );
			$now    = self::now_utc();
			$wpdb->insert( self::table(), array(
				'route_id'    => $route['id'],
				'travel_date' => $date,
				'dep_time'    => $time,
				'seats'       => $seats,
				'children'    => $children,
				'status'      => 'hold',
				'token'       => $token,
				'passengers'  => wp_json_encode( array_values( $passengers ) ),
				'phone'       => $phone,
				'email'       => $email,
				'amount'      => $amount,
				'currency'    => $route['currency'],
				'expires_at'  => self::now_utc( LBB_Settings::get( 'cart_hold_minutes' ) ),
				'created_at'  => $now,
				'updated_at'  => $now,
			) );
			if ( ! $wpdb->insert_id ) {
				return new WP_Error( 'lbb_db', __( 'Rezervarea nu a putut fi salvată. Încercați din nou.', 'libertbus-bilete' ) );
			}
			return self::get( $wpdb->insert_id );
		} finally {
			self::unlock( $lock );
		}
	}

	/**
	 * Prelungește o rezervare din coș. Dacă a expirat, o reia doar dacă mai sunt locuri.
	 */
	public static function refresh_hold( $token ) {
		global $wpdb;
		$booking = self::get_by_token( $token );
		if ( ! $booking ) {
			return false;
		}
		if ( 'confirmed' === $booking['status'] ) {
			return false;
		}
		if ( 'cancelled' !== $booking['status'] && strtotime( $booking['expires_at'] . ' UTC' ) > time() + 120 ) {
			return true;
		}
		$route = LBB_Routes::get( $booking['route_id'] );
		if ( ! $route || ! $route['active'] ) {
			return false;
		}
		$lock = self::lock( $route['id'], $booking['travel_date'], $booking['dep_time'] );
		try {
			$ok = false;
			foreach ( LBB_Routes::departures_on( $route, $booking['travel_date'] ) as $dep ) {
				if ( $dep['time'] === $booking['dep_time'] && 'closed' !== $dep['reason'] ) {
					$taken = self::taken_by_time( $route['id'], $booking['travel_date'], $booking['id'] );
					$used  = isset( $taken[ $dep['time'] ] ) ? $taken[ $dep['time'] ] : 0;
					$ok    = LBB_Routes::capacity( $route ) - $used >= $booking['seats'];
				}
			}
			if ( $ok ) {
				$wpdb->update( self::table(), array(
					'status'     => 'hold',
					'expires_at' => self::now_utc( LBB_Settings::get( 'cart_hold_minutes' ) ),
					'updated_at' => self::now_utc(),
				), array( 'id' => $booking['id'] ) );
			}
			return $ok;
		} finally {
			self::unlock( $lock );
		}
	}

	public static function release( $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'cancelled', updated_at = %s WHERE token = %s AND status IN ('hold','pending')", self::now_utc(), $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Comanda a fost creată: locurile așteaptă plata.
	 */
	public static function attach_to_order( $token, $order_id, $item_id ) {
		global $wpdb;
		$wpdb->update( self::table(), array(
			'status'     => 'pending',
			'order_id'   => (int) $order_id,
			'item_id'    => (int) $item_id,
			'expires_at' => self::now_utc( LBB_Settings::get( 'payment_minutes' ) ),
			'updated_at' => self::now_utc(),
		), array( 'token' => $token ) );
	}

	/**
	 * Plata a trecut: locurile devin vândute și primesc cod de bilet.
	 *
	 * @return array Rezervările care au depășit capacitatea (plată sosită după expirare).
	 */
	public static function confirm_order( $order_id ) {
		global $wpdb;
		$overbooked = array();
		foreach ( self::by_order( $order_id ) as $booking ) {
			if ( 'confirmed' === $booking['status'] ) {
				continue;
			}
			$route = LBB_Routes::get( $booking['route_id'] );
			if ( $route ) {
				$taken = self::taken_by_time( $route['id'], $booking['travel_date'], $booking['id'] );
				$used  = isset( $taken[ $booking['dep_time'] ] ) ? $taken[ $booking['dep_time'] ] : 0;
				if ( $used + $booking['seats'] > LBB_Routes::capacity( $route ) ) {
					$overbooked[] = $booking;
				}
			}
			$wpdb->update( self::table(), array(
				'status'      => 'confirmed',
				'ticket_code' => $booking['ticket_code'] ? $booking['ticket_code'] : self::new_code(),
				'expires_at'  => null,
				'updated_at'  => self::now_utc(),
			), array( 'id' => $booking['id'] ) );
		}
		return $overbooked;
	}

	public static function cancel_order( $order_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'cancelled', updated_at = %s WHERE order_id = %d", self::now_utc(), $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * O comandă „pending” la care clientul revine după expirare: reia locurile dacă se poate.
	 */
	public static function reopen_order( $order_id ) {
		global $wpdb;
		$ok = true;
		foreach ( self::by_order( $order_id ) as $booking ) {
			if ( 'confirmed' === $booking['status'] ) {
				continue;
			}
			if ( self::refresh_hold( $booking['token'] ) ) {
				$wpdb->update( self::table(), array(
					'status'     => 'pending',
					'expires_at' => self::now_utc( LBB_Settings::get( 'payment_minutes' ) ),
				), array( 'id' => $booking['id'] ) );
			} else {
				$ok = false;
			}
		}
		return $ok;
	}

	private static function new_code() {
		global $wpdb;
		// Fără caractere care se confundă la citire (0/O, 1/I/L).
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
		do {
			$code = 'LB-';
			for ( $i = 0; $i < 6; $i++ ) {
				$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
		} while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE ticket_code = %s', $code ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $code;
	}

	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'route_id', 'seats', 'children', 'order_id', 'item_id' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['amount']     = (float) $row['amount'];
		$names             = json_decode( (string) $row['passengers'], true );
		$row['passengers'] = is_array( $names ) ? $names : array();
		return $row;
	}

	public static function get( $id ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function get_by_code( $code ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ticket_code = %s', $code ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function by_order( $order_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d ORDER BY id', $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Lista de pasageri pentru o zi (și opțional o rută).
	 */
	public static function manifest( $date, $route_id = 0, $statuses = array( 'confirmed' ) ) {
		global $wpdb;
		$in   = implode( ',', array_map( function ( $s ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $s );
		}, $statuses ) );
		$sql  = $wpdb->prepare( 'SELECT b.*, r.origin, r.destination FROM ' . self::table() . ' b LEFT JOIN ' . LBB_Routes::table() . ' r ON r.id = b.route_id WHERE b.travel_date = %s', $date ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql .= " AND b.status IN ($in)";
		if ( $route_id ) {
			$sql .= $wpdb->prepare( ' AND b.route_id = %d', $route_id );
		}
		$sql .= ' ORDER BY b.dep_time, r.origin, r.destination, b.id';
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	public static function recent( $limit = 50, $status = '' ) {
		global $wpdb;
		$where = $status ? $wpdb->prepare( 'WHERE b.status = %s', $status ) : "WHERE b.status <> 'hold'";
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT b.*, r.origin, r.destination FROM ' . self::table() . ' b LEFT JOIN ' . LBB_Routes::table() . " r ON r.id = b.route_id $where ORDER BY b.id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Cron orar: șterge coșurile abandonate mai vechi de o zi.
	 */
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE status IN ('hold','cancelled') AND order_id = 0 AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
