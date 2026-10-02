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
			'replace_cf7_routes' => 0,
			'allow_reserve'      => 1,
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
			} elseif ( 'replace_cf7' === $key ) {
				$value = implode( ',', array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) $value ) ) ) );
			} elseif ( 0 === strpos( $key, 'rate_' ) ) {
				$value = max( 0.0001, (float) str_replace( ',', '.', $value ) );
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
		$clean['max_passengers'] = max( 1, $clean['max_passengers'] );
		$clean['max_days_ahead'] = max( 1, $clean['max_days_ahead'] );
		$clean['cart_hold_minutes'] = max( 5, $clean['cart_hold_minutes'] );
		$clean['payment_minutes'] = max( 10, $clean['payment_minutes'] );
		update_option( self::OPTION, $clean );
	}

	public static function checkboxes() {
		return array( 'simple_checkout', 'autocomplete', 'test_gateway', 'require_names', 'delete_on_uninstall', 'allow_reserve', 'show_approx', 'replace_cf7_routes' );
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
