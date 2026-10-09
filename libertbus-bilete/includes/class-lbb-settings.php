<?php
/**
 * Setările plugin-ului, păstrate într-o singură opțiune.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Settings {

	const OPTION = 'lbb_settings';

	/**
	 * Valorile implicite.
	 *
	 * Cursurile valutare spun cât valorează 1 unitate din moneda respectivă în MDL.
	 */
	public static function defaults() {
		return array(
			'default_capacity'   => 20,
			'cart_hold_minutes'  => 15,
			'payment_minutes'    => 30,
			'cutoff_minutes'     => 60,
			'max_days_ahead'     => 90,
			'max_passengers'     => 8,
			'rate_MDL'           => 1,
			'rate_RON'           => 3.9,
			'rate_EUR'           => 19.5,
			'rate_USD'           => 17.5,
			'pay_currencies'     => 'MDL',
			'show_approx'        => 1,
			'replace_cf7'        => '',
			'preview_cf7'        => '',
			'accent_color'       => '#00875a',
			'replace_cf7_routes' => 0,
			'allow_pay'          => 0,
			'allow_reserve'      => 1,
			'cookie_banner'      => 1,
			'footer_links'       => 1,
			'reserve_limit'      => 3,
			'simple_checkout'    => 1,
			'autocomplete'       => 1,
			'test_gateway'       => 0,
			'require_names'      => 1,
			'ticket_notes'       => "Prezentați-vă la urcare cu 15 minute înainte de plecare.\nPentru cursele internaționale aveți nevoie de pașaport sau buletin valabil.",
			'support_phone'      => '+373 691 84 111',
			'company_name'       => '',
			'company_idno'       => '',
			'company_address'    => '',
			'company_email'      => '',
			'delete_on_uninstall' => 0,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function save( array $values ) {
		$defaults = self::defaults();
		$clean    = array();
		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $values ) ) {
				$clean[ $key ] = is_int( $default ) && in_array( $key, self::checkboxes(), true ) ? 0 : $default;
				continue;
			}
			$value = $values[ $key ];
			if ( 'pay_currencies' === $key ) {
				$list  = is_array( $value ) ? $value : explode( ',', (string) $value );
				$list  = array_values( array_intersect( self::currencies(), array_map( 'strtoupper', array_map( 'trim', $list ) ) ) );
				$value = implode( ',', $list ? $list : array( 'MDL' ) );
			} elseif ( 'accent_color' === $key ) {
				$value = sanitize_hex_color( $value ) ? sanitize_hex_color( $value ) : $default;
			} elseif ( 'replace_cf7' === $key || 'preview_cf7' === $key ) {
				$value = implode( ',', array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) $value ) ) ) );
			} elseif ( 0 === strpos( $key, 'rate_' ) ) {
				// Un câmp golit din greșeală sau 0 nu devine curs 0,0001 (un bilet de 60 RON ar costa 0,01 MDL):
				// rămâne cursul de dinainte.
				$value = (float) str_replace( ',', '.', (string) $value );
				if ( $value <= 0 ) {
					$value = (float) self::get( $key ) > 0 ? (float) self::get( $key ) : $default;
				}
			} elseif ( is_int( $default ) ) {
				$value = max( 0, (int) $value );
			} elseif ( 'ticket_notes' === $key || 'company_address' === $key ) {
				$value = sanitize_textarea_field( $value );
			} elseif ( 'company_email' === $key ) {
				$value = sanitize_email( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
			$clean[ $key ] = $value;
		}
		$clean['rate_MDL'] = 1;
		// „Locuri online pe cursă” golit din greșeală sau 0 ar opri vânzarea pe toate rutele fără număr propriu
		// de locuri (toate cursele „complet”): rămâne numărul de dinainte.
		if ( $clean['default_capacity'] < 1 ) {
			$clean['default_capacity'] = (int) self::get( 'default_capacity' ) > 0 ? (int) self::get( 'default_capacity' ) : $defaults['default_capacity'];
		}
		$clean['max_passengers'] = max( 1, $clean['max_passengers'] );
		$clean['max_days_ahead'] = max( 1, $clean['max_days_ahead'] );
		$clean['cart_hold_minutes'] = max( 5, $clean['cart_hold_minutes'] );
		$clean['payment_minutes'] = max( 10, $clean['payment_minutes'] );
		update_option( self::OPTION, $clean );
	}

	public static function checkboxes() {
		return array( 'simple_checkout', 'autocomplete', 'test_gateway', 'require_names', 'delete_on_uninstall', 'allow_pay', 'allow_reserve', 'show_approx', 'replace_cf7_routes', 'cookie_banner', 'footer_links' );
	}

	/**
	 * Cheia linkului secret de previzualizare (/?lbb_preview=CHEIE), creată la prima folosire.
	 */
	public static function preview_token( $regenerate = false ) {
		$token = (string) get_option( 'lbb_preview_token', '' );
		if ( $regenerate || strlen( $token ) < 16 ) {
			$token = wp_generate_password( 24, false, false );
			update_option( 'lbb_preview_token', $token, false );
		}
		return $token;
	}

	public static function preview_url( $path = '/' ) {
		return add_query_arg( 'lbb_preview', self::preview_token(), home_url( $path ) );
	}

	/**
	 * Telefonul de suport fără ruperi de rând (spații nedespărțitoare), ca text.
	 */
	public static function phone_text() {
		return str_replace( ' ', "\u{00A0}", trim( (string) self::get( 'support_phone' ) ) );
	}

	/**
	 * Telefonul de suport ca link de apel, pe un singur rând.
	 */
	public static function phone_link() {
		$phone = trim( (string) self::get( 'support_phone' ) );
		return '<a class="lbb-phone" href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ) . '">' . esc_html( self::phone_text() ) . '</a>';
	}

	/**
	 * Fusul orar al orarului. Dacă în WordPress e doar un decalaj fix de +2 sau +3 (ex. „UTC+2”, ca pe
	 * libertbus.md), folosim Europe/Chisinau: altfel vara ora ar fi greșită cu o oră și vânzarea s-ar închide
	 * prea târziu. Un oraș ales în WordPress are întâietate.
	 */
	public static function tz() {
		if ( get_option( 'timezone_string' ) ) {
			return wp_timezone();
		}
		return in_array( (float) get_option( 'gmt_offset' ), array( 2.0, 3.0 ), true ) ? new DateTimeZone( 'Europe/Chisinau' ) : wp_timezone();
	}

	/**
	 * Data de azi (Y-m-d) în fusul orar al orarului.
	 */
	public static function today() {
		return ( new DateTimeImmutable( 'now', self::tz() ) )->format( 'Y-m-d' );
	}

	public static function currencies() {
		return array( 'MDL', 'RON', 'EUR', 'USD' );
	}

	/**
	 * Monedele în care clientul poate plăti (alege în formular).
	 */
	public static function pay_currencies() {
		$list = array_values( array_intersect( self::currencies(), explode( ',', (string) self::get( 'pay_currencies' ) ) ) );
		return $list ? $list : array( 'MDL' );
	}

	/**
	 * Monedele afișate informativ („≈ 62 RON”) lângă suma de plată.
	 *
	 * @param string $pay   Moneda de plată.
	 * @param string $route Moneda rutei.
	 */
	public static function approx_currencies( $pay, $route = '' ) {
		if ( ! self::get( 'show_approx' ) ) {
			return array();
		}
		return array_values( array_diff( array_unique( array_filter( array( 'MDL', 'RON', $route ) ) ), array( $pay ) ) );
	}

	/**
	 * Moneda implicită de plată pentru o rută: moneda rutei, dacă e acceptată.
	 */
	public static function default_pay_currency( $route_currency ) {
		$list = self::pay_currencies();
		return in_array( $route_currency, $list, true ) ? $route_currency : $list[0];
	}

	/**
	 * Convertește o sumă din moneda rutei în moneda magazinului WooCommerce.
	 */
	public static function convert( $amount, $from, $to = null ) {
		$to = $to ? $to : ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'MDL' );
		if ( $from === $to ) {
			return (float) $amount;
		}
		$rate_from = (float) self::get( 'rate_' . $from );
		$rate_to   = (float) self::get( 'rate_' . $to );
		if ( $rate_from <= 0 || $rate_to <= 0 ) {
			return (float) $amount;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return round( $amount * $rate_from / $rate_to, $decimals );
	}
}
