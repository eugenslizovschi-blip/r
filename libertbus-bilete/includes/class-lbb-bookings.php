<?php
/**
 * Rezervările: locuri ținute temporar, locuri plătite, coduri de bilet.
 *
 * Stări:
 *  - hold      locurile sunt în coș, expiră după „cart_hold_minutes”;
 *  - pending   comanda e creată și se așteaptă plata, expiră după „payment_minutes”;
 *  - confirmed plata a trecut (sau comanda e „on-hold”), locurile sunt vândute;
 *  - reserved  rezervare fără plată online, se achită la urcare; ocupă locurile;
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
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- numele tabelului e fix (self::table()).
			'SELECT dep_time, SUM(seats) AS taken FROM ' . self::table() . "
			WHERE route_id = %d AND travel_date = %s AND id <> %d
			AND ( status IN ('confirmed','reserved') OR ( status IN ('hold','pending') AND expires_at > %s ) )
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
	public static function create_hold( array $route, $date, $time, $adults, $children, array $passengers, $phone, $email, $pay_currency = '' ) {
		global $wpdb;
		$adults   = max( 0, (int) $adults );
		$children = max( 0, (int) $children );
		$seats    = $adults + $children;
		if ( $seats < 1 ) {
			return new WP_Error( 'lbb_seats', __( 'Alegeți cel puțin un pasager.', 'libertbus-bilete' ) );
		}
		if ( $seats > (int) LBB_Settings::get( 'max_passengers' ) ) {
			/* translators: 1: număr maxim de pasageri, 2: telefonul de suport */
			return new WP_Error( 'lbb_seats', sprintf( __( 'Online se pot rezerva cel mult %1$d locuri odată. Pentru grupuri sunați la %2$s.', 'libertbus-bilete' ), LBB_Settings::get( 'max_passengers' ), LBB_Settings::phone_text() ) );
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
				return new WP_Error( 'lbb_departure', trim( __( 'Nu există plecare la ora aleasă în ziua aceasta.', 'libertbus-bilete' ) . ' ' . LBB_Routes::running_days_note( $route, $date ) ) );
			}
			if ( 'closed' === $departure['reason'] ) {
				/* translators: %s: telefonul de suport */
				return new WP_Error( 'lbb_departure', sprintf( __( 'Vânzarea online pentru această plecare s-a încheiat. Pentru locuri sunați la %s.', 'libertbus-bilete' ), LBB_Settings::phone_text() ) );
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
				'pay_currency' => $pay_currency ? $pay_currency : LBB_Settings::default_pay_currency( $route['currency'] ),
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
		if ( in_array( $booking['status'], array( 'confirmed', 'reserved' ), true ) ) {
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

	/**
	 * Rezervare fără plată online: locurile rămân ocupate, plata se face la urcare.
	 */
	public static function reserve( $token ) {
		global $wpdb;
		$booking = self::get_by_token( $token );
		if ( ! $booking || 'hold' !== $booking['status'] ) {
			return null;
		}
		$wpdb->update( self::table(), array(
			'status'      => 'reserved',
			'ticket_code' => self::new_code(),
			'expires_at'  => null,
			'updated_at'  => self::now_utc(),
		), array( 'id' => $booking['id'] ) );
		return self::get( $booking['id'] );
	}

	/**
	 * Câte rezervări neplătite are un telefon pentru curse viitoare (limită anti-abuz).
	 */
	public static function active_reservations( $phone ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE phone = %s AND status = 'reserved' AND travel_date >= %s", $phone, LBB_Settings::today() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function cancel( $id ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'cancelled', updated_at = %s WHERE id = %d AND status IN ('reserved','hold','pending')", self::now_utc(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Numele pasagerilor, cu „(copil)” la copii. Formularul îi pune pe copii la final; dacă nu sunt nume
	 * pentru toate locurile, nu ghicim cine e copil.
	 */
	public static function passenger_labels( array $booking ) {
		$names    = array_values( (array) $booking['passengers'] );
		$children = (int) $booking['children'];
		if ( $children <= 0 || count( $names ) !== (int) $booking['seats'] ) {
			return $names;
		}
		for ( $i = count( $names ) - $children; $i < count( $names ); $i++ ) {
			/* translators: %s: numele copilului */
			$names[ $i ] = sprintf( __( '%s (copil)', 'libertbus-bilete' ), $names[ $i ] );
		}
		return $names;
	}

	/**
	 * Suma de plată în moneda aleasă de client.
	 */
	public static function pay_amount( array $booking ) {
		$cur = $booking['pay_currency'] ? $booking['pay_currency'] : $booking['currency'];
		return array( LBB_Settings::convert( $booking['amount'], $booking['currency'], $cur ), $cur );
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
	public static function manifest( $date, $route_id = 0, $statuses = array( 'confirmed', 'reserved' ) ) {
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

	/**
	 * Ultimele rezervări, opțional filtrate după stare și căutate după cod de bilet, telefon, email sau nume
	 * (ex. clientul sună: „am codul LB-…” sau „am rezervat pe 069…”).
	 */
	public static function recent( $limit = 50, $status = '', $search = '' ) {
		global $wpdb;
		$where  = $status ? $wpdb->prepare( 'WHERE b.status = %s', $status ) : "WHERE b.status <> 'hold'";
		$search = trim( (string) $search );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// Numele sunt în JSON, unde diacriticele apar ca \u021a…: căutăm și forma aceasta.
			$json = '%' . $wpdb->esc_like( trim( wp_json_encode( $search ), '"' ) ) . '%';
			$or   = $wpdb->prepare( 'b.ticket_code LIKE %s OR b.email LIKE %s OR b.passengers LIKE %s OR b.passengers LIKE %s', $like, $like, $like, $json );
			// Telefonul e salvat ca +373…/+40…: „069 184 111” se găsește după cifrele fără 0-ul de la început.
			$digits = ltrim( preg_replace( '/\D/', '', $search ), '0' );
			if ( strlen( $digits ) >= 4 ) {
				$or .= $wpdb->prepare( ' OR b.phone LIKE %s', '%' . $wpdb->esc_like( $digits ) . '%' );
			}
			$where .= " AND ( $or )";
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT b.*, r.origin, r.destination FROM ' . self::table() . ' b LEFT JOIN ' . LBB_Routes::table() . " r ON r.id = b.route_id $where ORDER BY b.id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- $where e construit cu $wpdb->prepare() mai sus.
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Numele pasagerilor copiate pe rândurile comenzii WooCommerce („Pasageri”) se șterg: nici ștergerea, nici
	 * anonimizarea făcută de WooCommerce nu le ating, pentru că sunt câmpuri ale plugin-ului.
	 */
	public static function erase_order_passengers( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$labels  = array_unique( array( 'Pasageri', __( 'Pasageri', 'libertbus-bilete' ) ) );
		$changed = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item->get_meta( '_lbb_token' ) ) {
				continue;
			}
			foreach ( $labels as $label ) {
				if ( '' !== (string) $item->get_meta( $label ) ) {
					$item->delete_meta_data( $label );
					$item->save();
					$changed = true;
				}
			}
		}
		if ( $changed ) {
			$order->add_order_note( __( 'Numele pasagerilor au fost șterse din comandă (date personale).', 'libertbus-bilete' ) );
		}
	}

	/**
	 * WooCommerce a anonimizat o comandă (la cererea clientului sau după perioada setată): la fel și rezervările ei.
	 */
	public static function on_order_anonymized( $order ) {
		global $wpdb;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		self::erase_order_passengers( $order );
		$wpdb->update( self::table(), array( 'passengers' => '[]', 'phone' => '', 'email' => '', 'updated_at' => self::now_utc() ), array( 'order_id' => $order->get_id() ) );
	}

	/**
	 * Rezervările unui client după email (pentru exportul și ștergerea datelor personale).
	 */
	private static function by_email( $email ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT b.*, r.origin, r.destination FROM ' . self::table() . ' b LEFT JOIN ' . LBB_Routes::table() . " r ON r.id = b.route_id WHERE b.email = %s AND b.status <> 'hold' ORDER BY b.id", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * Unelte → Exportă datele personale: rezervările făcute cu adresa de email a clientului.
	 */
	public static function privacy_export( $email, $page = 1 ) {
		$items  = array();
		$states = array(
			'confirmed' => __( 'Plătită', 'libertbus-bilete' ),
			'reserved'  => __( 'Rezervată, cu plata la urcare', 'libertbus-bilete' ),
			'pending'   => __( 'Așteaptă plata', 'libertbus-bilete' ),
			'cancelled' => __( 'Anulată', 'libertbus-bilete' ),
		);
		foreach ( self::by_email( $email ) as $b ) {
			$items[] = array(
				'group_id'    => 'libertbus-bookings',
				'group_label' => __( 'Rezervări LibertBus', 'libertbus-bilete' ),
				'item_id'     => 'libertbus-booking-' . $b['id'],
				'data'        => array(
					array( 'name' => __( 'Cod', 'libertbus-bilete' ), 'value' => $b['ticket_code'] ),
					array( 'name' => __( 'Ruta', 'libertbus-bilete' ), 'value' => trim( $b['origin'] . ' → ' . $b['destination'], ' →' ) ),
					array( 'name' => __( 'Data și ora', 'libertbus-bilete' ), 'value' => $b['travel_date'] . ' ' . $b['dep_time'] ),
					array( 'name' => __( 'Pasageri', 'libertbus-bilete' ), 'value' => implode( ', ', self::passenger_labels( $b ) ) ),
					array( 'name' => __( 'Telefon', 'libertbus-bilete' ), 'value' => $b['phone'] ),
					array( 'name' => 'Email', 'value' => $b['email'] ),
					array( 'name' => __( 'Stare', 'libertbus-bilete' ), 'value' => isset( $states[ $b['status'] ] ) ? $states[ $b['status'] ] : $b['status'] ),
				),
			);
		}
		return array( 'data' => $items, 'done' => true );
	}

	/**
	 * Unelte → Șterge datele personale: numele, telefonul și emailul dispar din rezervări. Un bilet valabil
	 * pentru o cursă care n-a avut loc încă rămâne (altfel clientul n-ar mai putea călători); se șterge după cursă.
	 */
	public static function privacy_erase( $email, $page = 1 ) {
		global $wpdb;
		$removed  = false;
		$retained = false;
		$today    = LBB_Settings::today();
		foreach ( self::by_email( $email ) as $b ) {
			if ( in_array( $b['status'], array( 'confirmed', 'reserved', 'pending' ), true ) && $b['travel_date'] >= $today ) {
				$retained = true;
				continue;
			}
			$wpdb->update( self::table(), array( 'passengers' => '[]', 'phone' => '', 'email' => '', 'updated_at' => self::now_utc() ), array( 'id' => $b['id'] ) );
			if ( $b['order_id'] && function_exists( 'wc_get_order' ) ) {
				self::erase_order_passengers( wc_get_order( $b['order_id'] ) );
			}
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Rezervările LibertBus pentru curse viitoare au fost păstrate până după cursă (biletul trebuie să rămână valabil).', 'libertbus-bilete' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * Cron orar: șterge coșurile abandonate mai vechi de o zi. O rezervare care a avut cod de bilet (ex. cu
	 * plata la urcare, anulată de birou) rămâne: apare la „Anulate”, iar linkul clientului spune „Bilet anulat”.
	 */
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE status IN ('hold','cancelled') AND order_id = 0 AND ticket_code = '' AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// Politica de confidențialitate: datele pasagerilor se păstrează 3 ani de la data călătoriei. După aceea rămân
		// doar codul, ruta, data și locurile (pentru statistici); numele, telefonul și emailul se șterg.
		$years = max( 1, (int) apply_filters( 'lbb_retention_years', 3 ) );
		$until = ( new DateTimeImmutable( LBB_Settings::today(), LBB_Settings::tz() ) )->modify( '-' . $years . ' years' )->format( 'Y-m-d' );
		$todo  = " AND ( phone <> '' OR email <> '' OR passengers IS NULL OR passengers <> '[]' )";
		// Cu comandă: întâi numele de pe comandă (câte 50 pe rulare), apoi rezervarea; restul rămân pentru rularea următoare.
		$done  = array( 0 );
		if ( function_exists( 'wc_get_order' ) ) {
			$orders = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT order_id FROM ' . self::table() . ' WHERE order_id > 0 AND travel_date < %s' . $todo . ' LIMIT 50', $until ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $orders as $order_id ) {
				self::erase_order_passengers( wc_get_order( (int) $order_id ) );
				$done[] = (int) $order_id;
			}
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET passengers = '[]', phone = '', email = '' WHERE travel_date < %s" . $todo . ' AND order_id IN (' . implode( ',', array_map( 'intval', $done ) ) . ')', $until ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
