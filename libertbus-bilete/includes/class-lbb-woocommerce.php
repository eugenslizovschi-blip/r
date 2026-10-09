<?php
/**
 * Legătura cu WooCommerce: coșul, prețul, comanda, stările plății.
 *
 * Plata o face orice metodă de plată WooCommerce activă (Paynet, MAIB,
 * Victoriabank, BT iPay etc.). Plugin-ul nu atinge datele cardului.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_WooCommerce {

	/** @var array Cache pentru rezervările citite într-o cerere. */
	private static $cache = array();

	public static function init() {
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'validate_cart' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'item_name' ), 10, 2 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'sold_individually' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_direct_add' ), 10, 2 );
		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'release_on_remove' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_permalink', array( __CLASS__, 'no_permalink' ), 10, 2 );
		add_filter( 'woocommerce_order_item_permalink', array( __CLASS__, 'no_order_permalink' ), 10, 2 );

		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_item_meta' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'attach_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'attach_order' ) );
		// Prioritatea 1: biletele se emit/anulează înaintea emailurilor WooCommerce (prioritatea 10).
		foreach ( array( 'processing', 'completed', 'on-hold' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'on_paid' ), 1, 2 );
		}
		foreach ( array( 'cancelled', 'failed', 'refunded' ) as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'on_cancelled' ), 1, 2 );
		}
		add_action( 'woocommerce_order_status_pending', array( __CLASS__, 'on_pending' ), 1 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'before_pay' ) );
		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'autocomplete' ), 10, 3 );

		add_filter( 'woocommerce_currency', array( __CLASS__, 'session_currency' ), 999 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ), 20 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill' ), 10, 2 );
	}

	public static function is_ticket_product( $product_id ) {
		return (int) $product_id && (int) $product_id === LBB_Install::product_id();
	}

	private static function booking( $token ) {
		if ( ! isset( self::$cache[ $token ] ) ) {
			self::$cache[ $token ] = LBB_Bookings::get_by_token( $token );
		}
		return self::$cache[ $token ];
	}

	public static function forget( $token ) {
		unset( self::$cache[ $token ] );
	}

	/**
	 * La fiecare încărcare a coșului: prelungește locurile sau scoate biletele expirate.
	 */
	public static function validate_cart( $cart ) {
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! self::is_ticket_product( $item['product_id'] ) ) {
				continue;
			}
			$token = isset( $item['lbb']['token'] ) ? $item['lbb']['token'] : '';
			$ok    = $token && LBB_Bookings::refresh_hold( $token );
			self::forget( $token );
			if ( ! $ok ) {
				unset( $cart->cart_contents[ $key ] );
				if ( function_exists( 'wc_add_notice' ) && ! wp_doing_cron() ) {
					wc_add_notice( __( 'Timpul de rezervare a expirat și locurile nu mai sunt disponibile. Alegeți din nou cursa.', 'libertbus-bilete' ), 'notice' );
				}
			}
		}
	}

	public static function set_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['lbb']['token'] ) ) {
				continue;
			}
			$booking = self::booking( $item['lbb']['token'] );
			if ( $booking ) {
				$item['data']->set_price( LBB_Settings::convert( $booking['amount'], $booking['currency'] ) );
			}
		}
	}

	/**
	 * Descrierea biletului: ruta, data, ora, pasagerii.
	 */
	/**
	 * „06.10.2026 (marți)”. Ziua e în română fix, nu după limba curentă: când biroul confirmă comanda din
	 * admin cu profilul în engleză, biletul clientului nu trebuie să apară cu „(Tuesday)”.
	 */
	public static function date_with_day( $ymd ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, new DateTimeZone( 'UTC' ) );
		if ( ! $d ) {
			return (string) $ymd;
		}
		$days = array(
			1 => __( 'luni', 'libertbus-bilete' ),
			2 => __( 'marți', 'libertbus-bilete' ),
			3 => __( 'miercuri', 'libertbus-bilete' ),
			4 => __( 'joi', 'libertbus-bilete' ),
			5 => __( 'vineri', 'libertbus-bilete' ),
			6 => __( 'sâmbătă', 'libertbus-bilete' ),
			7 => __( 'duminică', 'libertbus-bilete' ),
		);
		return $d->format( 'd.m.Y' ) . ' (' . $days[ (int) $d->format( 'N' ) ] . ')';
	}

	public static function describe( array $booking ) {
		$route = LBB_Routes::get( $booking['route_id'] );
		$lines = array(
			__( 'Ruta', 'libertbus-bilete' )      => $route ? $route['origin'] . ' → ' . $route['destination'] : '#' . $booking['route_id'],
			__( 'Data', 'libertbus-bilete' )      => self::date_with_day( $booking['travel_date'] ),
			__( 'Ora plecării', 'libertbus-bilete' ) => $booking['dep_time'],
			__( 'Locuri', 'libertbus-bilete' )    => $booking['children']
				/* translators: 1: total, 2: copii */
				? sprintf( _n( '%1$d (din care %2$d copil)', '%1$d (din care %2$d copii)', (int) $booking['children'], 'libertbus-bilete' ), $booking['seats'], $booking['children'] )
				: (string) $booking['seats'],
		);
		if ( $booking['passengers'] ) {
			$lines[ __( 'Pasageri', 'libertbus-bilete' ) ] = implode( ', ', LBB_Bookings::passenger_labels( $booking ) );
		}
		if ( in_array( $booking['status'], array( 'confirmed', 'reserved' ), true ) ) {
			list( $amount, $cur ) = LBB_Bookings::pay_amount( $booking );
			$label = 'reserved' === $booking['status'] ? __( 'De achitat la urcare', 'libertbus-bilete' ) : __( 'Achitat online', 'libertbus-bilete' );
			$lines[ $label ] = self::money( $amount, $cur );
		}
		return $lines;
	}

	public static function money( $amount, $currency ) {
		$decimals = floor( $amount ) == $amount ? 0 : 2; // phpcs:ignore Universal.Operators.StrictComparisons
		// Spațiu nedespărțitor: „2535 MDL” nu se rupe pe două rânduri.
		return number_format( (float) $amount, $decimals, ',', '.' ) . "\u{00A0}" . $currency;
	}

	public static function item_data( $data, $item ) {
		if ( empty( $item['lbb']['token'] ) ) {
			return $data;
		}
		$booking = self::booking( $item['lbb']['token'] );
		if ( ! $booking ) {
			return $data;
		}
		foreach ( self::describe( $booking ) as $label => $value ) {
			$data[] = array( 'key' => $label, 'value' => $value );
		}
		$cur    = get_woocommerce_currency();
		$approx = array();
		foreach ( LBB_Settings::approx_currencies( $cur, $booking['currency'] ) as $c ) {
			$approx[] = '≈ ' . self::money( round( LBB_Settings::convert( $booking['amount'], $booking['currency'], $c ) ), $c );
		}
		if ( $approx ) {
			$data[] = array( 'key' => __( 'Echivalent', 'libertbus-bilete' ), 'value' => implode( ', ', $approx ) );
		}
		return $data;
	}

	public static function item_name( $name, $item ) {
		if ( empty( $item['lbb']['token'] ) ) {
			return $name;
		}
		$booking = self::booking( $item['lbb']['token'] );
		$route   = $booking ? LBB_Routes::get( $booking['route_id'] ) : null;
		/* translators: 1: oraș de plecare, 2: destinație */
		return $route ? esc_html( sprintf( __( 'Bilet %1$s → %2$s', 'libertbus-bilete' ), $route['origin'], $route['destination'] ) ) : $name;
	}

	public static function sold_individually( $value, $product ) {
		return self::is_ticket_product( $product->get_id() ) ? true : $value;
	}

	public static function block_direct_add( $passed, $product_id ) {
		if ( self::is_ticket_product( $product_id ) ) {
			wc_add_notice( __( 'Biletele se cumpără din formularul de rezervare.', 'libertbus-bilete' ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function release_on_remove( $key, $cart ) {
		$item = isset( $cart->cart_contents[ $key ] ) ? $cart->cart_contents[ $key ] : null;
		if ( $item && ! empty( $item['lbb']['token'] ) ) {
			LBB_Bookings::release( $item['lbb']['token'] );
		}
	}

	public static function no_permalink( $link, $item ) {
		return empty( $item['lbb'] ) ? $link : '';
	}

	public static function no_order_permalink( $link, $item ) {
		return $item->get_meta( '_lbb_token' ) ? '' : $link;
	}

	public static function line_item_meta( $item, $key, $values ) {
		if ( empty( $values['lbb']['token'] ) ) {
			return;
		}
		$booking = self::booking( $values['lbb']['token'] );
		if ( ! $booking ) {
			return;
		}
		$item->add_meta_data( '_lbb_token', $booking['token'], true );
		$item->set_name( wp_strip_all_tags( self::item_name( $item->get_name(), $values ) ) );
		foreach ( self::describe( $booking ) as $label => $value ) {
			$item->add_meta_data( $label, $value, true );
		}
	}

	/**
	 * Comanda e creată: locurile trec din „coș” în „așteaptă plata”.
	 *
	 * @param WC_Order $order Comanda.
	 */
	public static function attach_order( $order ) {
		$has = false;
		foreach ( $order->get_items() as $item_id => $item ) {
			$token = $item->get_meta( '_lbb_token' );
			if ( $token ) {
				LBB_Bookings::attach_to_order( $token, $order->get_id(), $item_id );
				$has = true;
			}
		}
		if ( $has ) {
			$order->update_meta_data( '_lbb_tickets', 'yes' );
			$order->save_meta_data();
			// Dacă metoda de plată a confirmat deja comanda (ex. plată instant).
			if ( $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
				self::confirm( $order );
			}
		}
	}

	private static function has_tickets( $order ) {
		return $order && ( 'yes' === $order->get_meta( '_lbb_tickets' ) || LBB_Bookings::by_order( $order->get_id() ) );
	}

	public static function on_paid( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( self::has_tickets( $order ) ) {
			self::confirm( $order );
		}
	}

	public static function on_cancelled( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! self::has_tickets( $order ) ) {
			return;
		}
		$active = array_filter( LBB_Bookings::by_order( $order_id ), function ( $b ) {
			return 'cancelled' !== $b['status'];
		} );
		if ( $active ) {
			LBB_Bookings::cancel_order( $order_id );
			$order->add_order_note( __( 'LibertBus: locurile au fost eliberate.', 'libertbus-bilete' ) );
		}
	}

	public static function on_pending( $order_id ) {
		if ( self::has_tickets( wc_get_order( $order_id ) ) ) {
			LBB_Bookings::reopen_order( $order_id );
		}
	}

	private static function confirm( WC_Order $order ) {
		$fresh = array_filter( LBB_Bookings::by_order( $order->get_id() ), function ( $b ) {
			return 'confirmed' !== $b['status'];
		} );
		if ( ! $fresh ) {
			return;
		}
		$over  = LBB_Bookings::confirm_order( $order->get_id() );
		$codes = wp_list_pluck( LBB_Bookings::by_order( $order->get_id() ), 'ticket_code' );
		/* translators: %s: codurile biletelor, separate prin virgulă */
		$order->add_order_note( sprintf( __( 'LibertBus: bilete emise: %s', 'libertbus-bilete' ), implode( ', ', array_filter( $codes ) ) ) );
		if ( $over ) {
			$msg = __( 'ATENȚIE: plata a sosit după expirarea rezervării și cursa a depășit numărul de locuri. Verificați lista de pasageri și contactați clientul.', 'libertbus-bilete' );
			$order->add_order_note( 'LibertBus: ' . $msg );
			wp_mail( get_option( 'admin_email' ), sprintf( '[LibertBus] Locuri depășite — comanda #%s', $order->get_order_number() ), $msg . "\n\n" . $order->get_edit_order_url() );
		}
	}

	/**
	 * Plata unei comenzi mai vechi (pagina „Plătește comanda”): locurile trebuie să mai existe.
	 */
	public static function before_pay( $order ) {
		if ( 'yes' !== $order->get_meta( '_lbb_tickets' ) ) {
			return;
		}
		if ( ! LBB_Bookings::reopen_order( $order->get_id() ) ) {
			wc_add_notice( __( 'Locurile din această comandă nu mai sunt disponibile. Faceți o rezervare nouă.', 'libertbus-bilete' ), 'error' );
		}
	}

	public static function autocomplete( $status, $order_id, $order ) {
		if ( ! LBB_Settings::get( 'autocomplete' ) || ! $order || 'yes' !== $order->get_meta( '_lbb_tickets' ) ) {
			return $status;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! $item->get_meta( '_lbb_token' ) ) {
				return $status;
			}
		}
		return 'completed';
	}

	/**
	 * Moneda aleasă de client în formular devine moneda coșului și a comenzii,
	 * doar cât coșul conține numai bilete. În admin rămâne moneda magazinului.
	 */
	public static function session_currency( $currency ) {
		static $busy = false;
		if ( $busy || ( is_admin() && ! wp_doing_ajax() ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return $currency;
		}
		$busy   = true;
		$chosen = WC()->session->get( 'lbb_currency' );
		$cart   = WC()->session->get( 'cart' );
		$ok     = $chosen && in_array( $chosen, LBB_Settings::pay_currencies(), true ) && is_array( $cart ) && $cart;
		if ( $ok ) {
			$ticket = (int) get_option( LBB_Install::PRODUCT_OPTION );
			foreach ( $cart as $item ) {
				if ( empty( $item['product_id'] ) || (int) $item['product_id'] !== $ticket ) {
					$ok = false;
				}
			}
		}
		$busy = false;
		return $ok ? $chosen : $currency;
	}

	private static function cart_only_tickets() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['lbb'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Pentru bilete nu e nevoie de adresă poștală: rămân nume, telefon, email, țara.
	 */
	public static function checkout_fields( $fields ) {
		if ( ! LBB_Settings::get( 'simple_checkout' ) || ! self::cart_only_tickets() ) {
			return $fields;
		}
		foreach ( array( 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode' ) as $key ) {
			unset( $fields['billing'][ $key ] );
		}
		if ( isset( $fields['billing']['billing_phone'] ) ) {
			$fields['billing']['billing_phone']['required'] = true;
		}
		$fields['shipping'] = array();
		return $fields;
	}

	public static function prefill( $value, $input ) {
		if ( null !== $value && '' !== $value ) {
			return $value;
		}
		$contact = WC()->session ? WC()->session->get( 'lbb_contact' ) : null;
		if ( ! $contact ) {
			return $value;
		}
		$name = preg_split( '/\s+/', trim( $contact['name'] ), 2 );
		switch ( $input ) {
			case 'billing_first_name':
				return $name[0];
			case 'billing_last_name':
				return isset( $name[1] ) ? $name[1] : '';
			case 'billing_phone':
				return $contact['phone'];
			case 'billing_email':
				return $contact['email'];
			case 'billing_country':
				return 0 === strpos( $contact['phone'], '+40' ) ? 'RO' : 'MD';
		}
		return $value;
	}
}
