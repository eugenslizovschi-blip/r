<?php
/**
 * Formularul de rezervare: [libertbus_rezervare from="Bălți" to="Iași"].
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Frontend {

	/** @var WP_Error|null Eroarea din ultima trimitere, afișată în formular. */
	private static $error = null;

	public static function init() {
		add_shortcode( 'libertbus_rezervare', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'lbb_booking', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_submit' ) );
	}

	public static function register_assets() {
		wp_register_style( 'lbb', LBB_URL . 'assets/lbb.css', array(), LBB_VERSION );
		wp_register_script( 'lbb', LBB_URL . 'assets/lbb.js', array(), LBB_VERSION, true );
	}

	/**
	 * Trimiterea formularului: ține locurile, pune biletul în coș, trimite la plată.
	 */
	public static function handle_submit() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['lbb_action'] ) ) { // phpcs:ignore WordPress.Security
			return;
		}
		if ( ! isset( $_POST['lbb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lbb_nonce'] ) ), 'lbb_book' ) ) {
			self::$error = new WP_Error( 'lbb_nonce', __( 'Sesiunea a expirat. Reîncărcați pagina și încercați din nou.', 'libertbus-bilete' ) );
			return;
		}
		if ( ! empty( $_POST['lbb_website'] ) ) { // Câmp-capcană pentru roboți.
			self::$error = new WP_Error( 'lbb_bot', __( 'Cererea nu a putut fi procesată.', 'libertbus-bilete' ) );
			return;
		}

		$data   = wp_unslash( $_POST );
		$result = self::book( $data );
		if ( is_wp_error( $result ) ) {
			self::$error = $result;
			return;
		}
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Validează datele, ține locurile și adaugă biletul în coș.
	 *
	 * @param array $data Datele din formular, fără slash-uri.
	 * @return true|WP_Error
	 */
	public static function book( array $data ) {
		$route = LBB_Routes::get( isset( $data['lbb_route'] ) ? (int) $data['lbb_route'] : 0 );
		if ( ! $route || ! $route['active'] || $route['price'] <= 0 ) {
			return new WP_Error( 'lbb_route', __( 'Alegeți ruta.', 'libertbus-bilete' ) );
		}
		$date = isset( $data['lbb_date'] ) ? sanitize_text_field( $data['lbb_date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'lbb_date', __( 'Alegeți data călătoriei.', 'libertbus-bilete' ) );
		}
		$time = isset( $data['lbb_time'] ) ? sanitize_text_field( $data['lbb_time'] ) : '';
		if ( ! in_array( $time, $route['times'], true ) ) {
			return new WP_Error( 'lbb_time', __( 'Alegeți ora de plecare.', 'libertbus-bilete' ) );
		}
		$adults   = isset( $data['lbb_adults'] ) ? (int) $data['lbb_adults'] : 1;
		$children = isset( $data['lbb_children'] ) ? (int) $data['lbb_children'] : 0;
		$seats    = max( 0, $adults ) + max( 0, $children );

		$names = array();
		foreach ( isset( $data['lbb_names'] ) ? (array) $data['lbb_names'] : array() as $name ) {
			$name = trim( sanitize_text_field( $name ) );
			if ( '' !== $name ) {
				$names[] = mb_substr( $name, 0, 80 );
			}
		}
		if ( LBB_Settings::get( 'require_names' ) && count( $names ) < $seats ) {
			return new WP_Error( 'lbb_names', __( 'Scrieți numele și prenumele fiecărui pasager (ca în pașaport).', 'libertbus-bilete' ) );
		}
		$names = array_slice( $names, 0, $seats );

		$phone = isset( $data['lbb_phone'] ) ? preg_replace( '/[^\d+]/', '', $data['lbb_phone'] ) : '';
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 8 ) {
			return new WP_Error( 'lbb_phone', __( 'Introduceți un număr de telefon valid, cu prefixul țării (+373 sau +40).', 'libertbus-bilete' ) );
		}
		$email = isset( $data['lbb_email'] ) ? sanitize_email( $data['lbb_email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'lbb_email', __( 'Introduceți o adresă de email validă. Acolo primiți biletul.', 'libertbus-bilete' ) );
		}

		$booking = LBB_Bookings::create_hold( $route, $date, $time, $adults, $children, $names, $phone, $email );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		$added = WC()->cart->add_to_cart( LBB_Install::product_id(), 1, 0, array(), array(
			'lbb' => array( 'token' => $booking['token'] ),
		) );
		if ( ! $added ) {
			LBB_Bookings::release( $booking['token'] );
			return new WP_Error( 'lbb_cart', __( 'Biletul nu a putut fi adăugat în coș. Încercați din nou.', 'libertbus-bilete' ) );
		}
		WC()->session->set( 'lbb_contact', array(
			'name'  => isset( $names[0] ) ? $names[0] : '',
			'phone' => $phone,
			'email' => $email,
		) );
		return true;
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'from' => '', 'to' => '', 'title' => '' ), $atts, 'libertbus_rezervare' );
		$map  = LBB_Routes::public_map();
		if ( ! $map ) {
			return '<p class="lbb-empty">' . esc_html__( 'Momentan nu sunt curse disponibile pentru rezervare online.', 'libertbus-bilete' ) . '</p>';
		}

		wp_enqueue_style( 'lbb' );
		wp_enqueue_script( 'lbb' );

		$tz        = wp_timezone();
		$today     = new DateTimeImmutable( 'now', $tz );
		$posted    = self::$error ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$preset    = array(
			'from'     => isset( $posted['lbb_from'] ) ? sanitize_text_field( $posted['lbb_from'] ) : self::match_city( $atts['from'], array_keys( $map ) ),
			'route'    => isset( $posted['lbb_route'] ) ? (int) $posted['lbb_route'] : 0,
			'to'       => self::match_city( $atts['to'], self::all_destinations( $map ) ),
			'date'     => isset( $posted['lbb_date'] ) ? sanitize_text_field( $posted['lbb_date'] ) : '',
			'time'     => isset( $posted['lbb_time'] ) ? sanitize_text_field( $posted['lbb_time'] ) : '',
			'adults'   => isset( $posted['lbb_adults'] ) ? (int) $posted['lbb_adults'] : 1,
			'children' => isset( $posted['lbb_children'] ) ? (int) $posted['lbb_children'] : 0,
			'names'    => isset( $posted['lbb_names'] ) ? array_map( 'sanitize_text_field', (array) $posted['lbb_names'] ) : array(),
		);
		$config = array(
			'map'           => $map,
			'preset'        => $preset,
			'restUrl'       => esc_url_raw( rest_url( 'lbb/v1/departures' ) ),
			'today'         => $today->format( 'Y-m-d' ),
			'maxDate'       => $today->modify( '+' . (int) LBB_Settings::get( 'max_days_ahead' ) . ' days' )->format( 'Y-m-d' ),
			'maxPassengers' => (int) LBB_Settings::get( 'max_passengers' ),
			'requireNames'  => (bool) LBB_Settings::get( 'require_names' ),
			'currency'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'currencyCode'  => get_woocommerce_currency(),
			'decimals'      => wc_get_price_decimals(),
			'i18n'          => array(
				'chooseFrom'  => __( 'Alegeți orașul de plecare', 'libertbus-bilete' ),
				'chooseTo'    => __( 'Alegeți destinația', 'libertbus-bilete' ),
				'chooseTime'  => __( 'Alegeți ora', 'libertbus-bilete' ),
				'loading'     => __( 'Se verifică locurile…', 'libertbus-bilete' ),
				'noDeparture' => __( 'În ziua aleasă nu sunt plecări pe această rută. Alegeți altă dată.', 'libertbus-bilete' ),
				'free'        => __( 'locuri libere', 'libertbus-bilete' ),
				'full'        => __( 'complet', 'libertbus-bilete' ),
				'closed'      => __( 'vânzare închisă', 'libertbus-bilete' ),
				'passenger'   => __( 'Pasager', 'libertbus-bilete' ),
				'child'       => __( 'copil', 'libertbus-bilete' ),
				'namePh'      => __( 'Nume și prenume, ca în pașaport', 'libertbus-bilete' ),
				'error'       => __( 'Nu am putut verifica locurile. Încercați din nou.', 'libertbus-bilete' ),
				'total'       => __( 'Total de plată', 'libertbus-bilete' ),
				'approx'      => __( 'Prețul de bază', 'libertbus-bilete' ),
			),
		);

		$uid       = wp_unique_id( 'lbb-' );
		$has_child = false;
		foreach ( $map as $routes ) {
			foreach ( $routes as $r ) {
				$has_child = $has_child || null !== $r['child_price'];
			}
		}

		ob_start();
		?>
		<div class="lbb-booking" id="<?php echo esc_attr( $uid ); ?>" data-lbb="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<?php if ( $atts['title'] ) : ?>
				<h3 class="lbb-title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<?php endif; ?>
			<?php if ( self::$error ) : ?>
				<div class="lbb-alert" role="alert"><?php echo esc_html( self::$error->get_error_message() ); ?></div>
			<?php endif; ?>
			<noscript><p class="lbb-alert"><?php echo esc_html( sprintf( __( 'Pentru rezervare online activați JavaScript sau sunați la %s.', 'libertbus-bilete' ), LBB_Settings::get( 'support_phone' ) ) ); ?></p></noscript>
			<form method="post" class="lbb-form" novalidate>
				<input type="hidden" name="lbb_action" value="book">
				<input type="hidden" name="lbb_nonce" value="<?php echo esc_attr( wp_create_nonce( 'lbb_book' ) ); ?>">
				<div class="lbb-hp" aria-hidden="true"><label>Website <input type="text" name="lbb_website" tabindex="-1" autocomplete="off"></label></div>

				<div class="lbb-grid">
					<label class="lbb-field"><span><?php esc_html_e( 'De unde plecați', 'libertbus-bilete' ); ?></span>
						<select name="lbb_from" data-lbb="from" required></select>
					</label>
					<label class="lbb-field"><span><?php esc_html_e( 'Unde mergeți', 'libertbus-bilete' ); ?></span>
						<select name="lbb_route" data-lbb="route" required></select>
					</label>
					<label class="lbb-field"><span><?php esc_html_e( 'Data plecării', 'libertbus-bilete' ); ?></span>
						<input type="date" name="lbb_date" data-lbb="date" required>
					</label>
					<label class="lbb-field"><span><?php esc_html_e( 'Ora plecării', 'libertbus-bilete' ); ?></span>
						<select name="lbb_time" data-lbb="time" required></select>
					</label>
				</div>
				<p class="lbb-status" data-lbb="status" aria-live="polite"></p>

				<div class="lbb-grid lbb-grid-2">
					<label class="lbb-field"><span><?php esc_html_e( 'Adulți', 'libertbus-bilete' ); ?></span>
						<select name="lbb_adults" data-lbb="adults"></select>
					</label>
					<?php if ( $has_child ) : ?>
					<label class="lbb-field" data-lbb="children-wrap"><span><?php esc_html_e( 'Copii', 'libertbus-bilete' ); ?></span>
						<select name="lbb_children" data-lbb="children"></select>
					</label>
					<?php endif; ?>
				</div>

				<fieldset class="lbb-passengers" data-lbb="names">
					<legend><?php esc_html_e( 'Pasageri', 'libertbus-bilete' ); ?></legend>
				</fieldset>

				<div class="lbb-grid lbb-grid-2">
					<label class="lbb-field"><span><?php esc_html_e( 'Telefon (cu +373 sau +40)', 'libertbus-bilete' ); ?></span>
						<input type="tel" name="lbb_phone" required autocomplete="tel" inputmode="tel" placeholder="+373" value="<?php echo esc_attr( isset( $posted['lbb_phone'] ) ? sanitize_text_field( $posted['lbb_phone'] ) : '' ); ?>">
					</label>
					<label class="lbb-field"><span><?php esc_html_e( 'Email (aici primiți biletul)', 'libertbus-bilete' ); ?></span>
						<input type="email" name="lbb_email" required autocomplete="email" value="<?php echo esc_attr( isset( $posted['lbb_email'] ) ? sanitize_email( $posted['lbb_email'] ) : '' ); ?>">
					</label>
				</div>

				<div class="lbb-summary" data-lbb="summary" hidden></div>

				<button type="submit" class="lbb-submit" data-lbb="submit" disabled><?php esc_html_e( 'Continuă spre plată cu cardul', 'libertbus-bilete' ); ?></button>
				<p class="lbb-note"><?php echo esc_html( sprintf( __( 'Locurile se păstrează %d minute cât finalizați plata. Întrebări: %s', 'libertbus-bilete' ), LBB_Settings::get( 'cart_hold_minutes' ), LBB_Settings::get( 'support_phone' ) ) ); ?></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function all_destinations( array $map ) {
		$out = array();
		foreach ( $map as $routes ) {
			foreach ( $routes as $r ) {
				$out[] = $r['to'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Potrivește „Balti” cu „Bălți”: ignoră diacriticele și majusculele.
	 */
	public static function match_city( $needle, array $cities ) {
		$needle = self::fold( $needle );
		if ( '' === $needle ) {
			return '';
		}
		foreach ( $cities as $city ) {
			if ( self::fold( $city ) === $needle ) {
				return $city;
			}
		}
		return '';
	}

	public static function fold( $text ) {
		$text = strtolower( remove_accents( (string) $text ) );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
	}
}
