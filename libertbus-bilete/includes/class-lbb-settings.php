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
	 * Maximul unor setări numerice (aplicat la salvare și pus pe câmp în formular): o greșeală de tastare
	 * („1500” în loc de „15” minute) ar ține locurile blocate o zi și ar opri vânzarea cursei.
	 */
	const MAXES = array(
		'cart_hold_minutes' => 120,
		'payment_minutes'   => 240,
		'max_days_ahead'    => 365,
		// „6000” în loc de „60”: vânzarea s-ar închide pe tăcute cu peste 4 zile înainte de fiecare cursă.
		'cutoff_minutes'    => 2880,
	);

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
			'closed_dates'       => '',
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
			'csv_separator'      => ',',
			'allow_pay'          => 0,
			'allow_reserve'      => 1,
			'cookie_banner'      => 1,
			'footer_links'       => 1,
			'reserve_limit'      => 3,
			'simple_checkout'    => 1,
			'autocomplete'       => 1,
			'test_gateway'       => 0,
			'require_names'      => 1,
			'remind_day_before'  => 0,
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
			if ( 'csv_separator' === $key ) {
				$value = ';' === $value ? ';' : ',';
			} elseif ( 'pay_currencies' === $key ) {
				$list  = is_array( $value ) ? $value : explode( ',', (string) $value );
				$list  = array_values( array_intersect( self::currencies(), array_map( 'strtoupper', array_map( 'trim', $list ) ) ) );
				$value = implode( ',', $list ? $list : array( 'MDL' ) );
			} elseif ( 'accent_color' === $key ) {
				// Codul copiat fără „#” („1e40af”, des din programele de design) nu mai revine pe tăcute la verde.
				$value = trim( (string) $value );
				$value = preg_match( '/^([0-9a-f]{3}){1,2}$/i', $value ) ? '#' . $value : $value;
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
			} elseif ( 'closed_dates' === $key ) {
				$value = implode( ', ', array_map( function ( $d ) {
					return gmdate( 'd.m.Y', strtotime( $d . ' 12:00' ) );
				}, self::parse_dates( $value )['dates'] ) );
			} elseif ( 'company_idno' === $key ) {
				// IDNO-ul copiat dintr-un document vine des cu spații („1003 600 123 456”): se păstrează doar cifrele/literele.
				$value = preg_replace( '/[\s.\-]+/u', '', sanitize_text_field( $value ) );
			} elseif ( 'company_email' === $key ) {
				$value = sanitize_email( $value );
			} else {
				$value = sanitize_text_field( $value );
			}
			$clean[ $key ] = $value;
		}
		$clean['rate_MDL'] = 1;
		// Un număr golit din greșeală sau 0 rămâne cel de dinainte: „Locuri online pe cursă” 0 ar face toate cursele
		// „complet”, iar „Zile înainte” sau „Pasageri pe rezervare” 1 ar limita vânzarea la o zi sau un pasager.
		foreach ( array( 'default_capacity', 'max_passengers', 'max_days_ahead', 'cart_hold_minutes', 'payment_minutes' ) as $key ) {
			if ( $clean[ $key ] < 1 ) {
				$clean[ $key ] = (int) self::get( $key ) > 0 ? (int) self::get( $key ) : $defaults[ $key ];
			}
		}
		$clean['cart_hold_minutes'] = max( 5, $clean['cart_hold_minutes'] );
		$clean['payment_minutes'] = max( 10, $clean['payment_minutes'] );
		foreach ( self::MAXES as $key => $max ) {
			$clean[ $key ] = min( $max, $clean[ $key ] );
		}
		update_option( self::OPTION, $clean );
	}

	/**
	 * „25.12.2026, 1/1/2027 2027-01-07” → datele valabile (Y-m-d, sortate, fără cele trecute) și ce n-a fost înțeles.
	 *
	 * @return array{dates:string[],bad:string[]}
	 */
	public static function parse_dates( $text ) {
		$dates = array();
		$bad   = array();
		$today = self::today();
		foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
			// „25.12.” / „01.01.2027.”: punctul de la sfârșit se scrie des din obișnuință; „26-12-2026” ca în formular.
			$part = rtrim( $part, '.' );
			if ( preg_match( '/^(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{4}|\d{2})$/', $part, $m ) ) {
				// Anul și cu două cifre („25.12.26” = 2026).
				list( $y, $mo, $d ) = array( 2 === strlen( $m[3] ) ? 2000 + (int) $m[3] : (int) $m[3], (int) $m[2], (int) $m[1] );
			} elseif ( preg_match( '/^(\d{1,2})[.\/](\d{1,2})$/', $part, $m ) ) {
				// Fără an („25.12, 1.1”, cum se scriu sărbătorile): următoarea dată cu ziua și luna asta.
				list( $mo, $d ) = array( (int) $m[2], (int) $m[1] );
				$y = (int) substr( $today, 0, 4 );
				if ( sprintf( '%04d-%02d-%02d', $y, $mo, $d ) < $today || ! checkdate( $mo, $d, $y ) ) {
					$y++;
				}
			} elseif ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $part, $m ) ) {
				list( $y, $mo, $d ) = array( (int) $m[1], (int) $m[2], (int) $m[3] );
			} else {
				$bad[] = $part;
				continue;
			}
			if ( ! checkdate( $mo, $d, $y ) ) {
				$bad[] = $part;
				continue;
			}
			$date = sprintf( '%04d-%02d-%02d', $y, $mo, $d );
			if ( $date >= $today ) {
				$dates[] = $date;
			}
		}
		$dates = array_values( array_unique( $dates ) );
		sort( $dates );
		return array( 'dates' => $dates, 'bad' => $bad );
	}

	/**
	 * Zilele fără curse (sărbători, curse anulate): în acestea nu se vinde nimic, pe nicio rută.
	 *
	 * @return string[] Y-m-d
	 */
	public static function closed_dates() {
		return self::parse_dates( self::get( 'closed_dates' ) )['dates'];
	}

	public static function checkboxes() {
		return array( 'simple_checkout', 'autocomplete', 'test_gateway', 'require_names', 'remind_day_before', 'delete_on_uninstall', 'allow_pay', 'allow_reserve', 'show_approx', 'replace_cf7_routes', 'cookie_banner', 'footer_links' );
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
	 * Luminanța relativă (WCAG 2.x) a unei culori hex, sau null dacă nu e o culoare.
	 *
	 * @param string $hex Culoarea, ex. #00875a sau #fd0.
	 */
	private static function luminance( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return null;
		}
		$lum = 0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $w ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$lum += $w * ( $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 ) );
		}
		return $lum;
	}

	/**
	 * Culoarea textului de pe butoane pentru culoarea aleasă în Setări: alb pe o culoare închisă, aproape negru pe
	 * una deschisă (ex. galben), ca „Rezerv” și „Achit online” să se citească. Alege varianta cu contrastul mai mare.
	 *
	 * @param string $hex Culoarea, ex. #00875a sau #fd0.
	 */
	public static function accent_ink( $hex ) {
		$lum = self::luminance( $hex );
		if ( null === $lum ) {
			return '#fff';
		}
		// #1d2733 are luminanța ≈ 0,019; contrastul cu albul e (1 + 0,05) / (L + 0,05), cu textul închis (L + 0,05) / 0,069.
		return ( 1.05 / ( $lum + 0.05 ) ) >= ( ( $lum + 0.05 ) / 0.069 ) ? '#fff' : '#1d2733';
	}

	/**
	 * Culoarea pentru text și contururi pe fundal alb (butonul secundar, „Înapoi”, câmpul selectat): culoarea aleasă,
	 * dacă se citește pe alb (contrast ≥ 4,5:1), altfel #1d2733.
	 *
	 * @param string $hex Culoarea, ex. #00875a sau #fd0.
	 */
	public static function accent_text( $hex ) {
		$lum = self::luminance( $hex );
		return null !== $lum && 1.05 / ( $lum + 0.05 ) >= 4.5 ? '#' . ltrim( (string) $hex, '#' ) : '#1d2733';
	}

	/**
	 * Numărul cu substantivul acordat ca în română: „1 loc liber”, „5 locuri libere”, „20 de locuri libere”
	 * („de” de la 20 în sus, când ultimele două cifre nu sunt 01–19). _n() știe doar singular/plural.
	 *
	 * @param int    $n    Numărul.
	 * @param string $one  Forma pentru 1, ex. „loc liber”.
	 * @param string $many Forma de plural, ex. „locuri libere”.
	 */
	public static function ro_count( $n, $one, $many ) {
		$n    = (int) $n;
		$last = abs( $n ) % 100;
		if ( 1 === $n ) {
			return '1 ' . $one;
		}
		return $n . ( abs( $n ) >= 20 && ( 0 === $last || $last >= 20 ) ? ' de ' : ' ' ) . $many;
	}

	/**
	 * Adresa biroului pentru emailurile interne (rezervare nouă, locuri depășite) și Reply-To: „Email pentru
	 * clienți” din Setări, altfel emailul administratorului WordPress.
	 */
	public static function office_email() {
		$office = (string) self::get( 'company_email' );
		return is_email( $office ) ? $office : (string) get_option( 'admin_email' );
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
		return '<a class="lbb-phone" href="' . esc_attr( self::phone_href() ) . '">' . esc_html( self::phone_text() ) . '</a>';
	}

	/**
	 * Linkul de apel pentru telefonul de suport, cu prefixul țării: scris local („069 184 111”) numărul se
	 * formează doar din Moldova, iar un client din România care apasă pe link ar suna în altă parte.
	 */
	public static function phone_href() {
		return LBB_Frontend::tel_href( trim( (string) self::get( 'support_phone' ) ) );
	}

	/**
	 * Limba în care e scris textul plugin-ului (atributul lang), pentru cititoarele de ecran. Textele sunt scrise
	 * în română; altă limbă doar dacă WordPress a încărcat traducerea lor. Pe un site în engleză fără traducere,
	 * „lang=en-US” ar face cititorul de ecran să pronunțe textul românesc ca pe unul englezesc.
	 */
	public static function text_lang() {
		return is_textdomain_loaded( 'libertbus-bilete' ) ? str_replace( '_', '-', get_locale() ) : 'ro-RO';
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
	 * Un moment (timestamp) afișat în fusul orar al orarului. Cu fusul WordPress pus ca decalaj fix „UTC+2”,
	 * get_date_from_gmt()/wp_date() ar arăta vara ora cu o oră în urmă față de Chișinău.
	 */
	public static function local_time( $format, $timestamp ) {
		return wp_date( $format, (int) $timestamp, self::tz() );
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
