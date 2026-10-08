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
		add_action( 'template_redirect', array( __CLASS__, 'preview_headers' ), 1 );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'replace_cf7' ), 20, 2 );
	}

	/**
	 * Înlocuiește la afișare formularele Contact Form 7 de rezervare cu formularul nostru,
	 * fără să modifice paginile (se anulează din Setări):
	 *  - formularele cu ID-urile din „replace_cf7” → formularul cu toate rutele;
	 *  - cu „replace_cf7_routes”, un formular cu titlul „Balti - Iasi” → formularul rutei Bălți → Iași.
	 */
	public static function replace_cf7( $output, $tag ) {
		if ( 'contact-form-7' !== $tag && 'contact-form' !== $tag ) {
			return $output;
		}
		if ( ! is_string( $output ) || ! preg_match( '/wpcf7-f(\d+)-/', $output, $m ) ) {
			return $output;
		}
		$id  = (int) $m[1];
		$ids = array_map( 'intval', explode( ',', (string) LBB_Settings::get( 'replace_cf7' ) . ( self::is_preview() ? ',' . LBB_Settings::get( 'preview_cf7' ) : '' ) ) );
		if ( in_array( $id, $ids, true ) ) {
			return self::shortcode( array( 'compact' => '1' ) );
		}
		if ( LBB_Settings::get( 'replace_cf7_routes' ) || self::is_preview() ) {
			$route = self::route_for_title( get_the_title( $id ) );
			if ( $route ) {
				return self::shortcode( array( 'from' => $route['origin'], 'to' => $route['destination'], 'compact' => '1' ) );
			}
		}
		return $output;
	}

	/**
	 * „Cluj - Balti” → ruta activă „Cluj-Napoca → Bălți” (fără diacritice, potrivire de început).
	 */
	public static function route_for_title( $title ) {
		$parts = preg_split( '/\s+[-–—]\s+|\s*[–—]\s*/u', html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$from = self::fold( $parts[0] );
		$to   = self::fold( $parts[1] );
		if ( '' === $from || '' === $to ) {
			return null;
		}
		$found = null;
		foreach ( LBB_Routes::all( true ) as $route ) {
			$o = self::fold( $route['origin'] );
			$d = self::fold( $route['destination'] );
			if ( $route['price'] > 0 && 0 === strpos( $o, $from ) && 0 === strpos( $d, $to ) ) {
				// Preferăm potrivirea exactă („Iasi” → „Iași”, nu „Iași Aeroport”).
				if ( ! $found || ( $o === $from && $d === $to ) ) {
					$found = $route;
				}
			}
		}
		return $found;
	}

	/**
	 * Previzualizare doar pentru administratori: pe o pagină privată (sau cu ?lbb_preview=1)
	 * formularele se înlocuiesc și butonul de plată apare, fără să se schimbe nimic pentru clienți.
	 */
	public static function is_preview() {
		$key = isset( $_GET['lbb_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['lbb_preview'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		// Linkul secret (/?lbb_preview=CHEIE) merge și fără login: pentru telefon sau ca să-l arătați cuiva.
		if ( '' !== $key && hash_equals( LBB_Settings::preview_token(), $key ) ) {
			return true;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		if ( '' !== $key ) {
			return true;
		}
		$post = get_queried_object();
		return $post instanceof WP_Post && 'private' === $post->post_status;
	}

	/**
	 * Paginile deschise cu linkul de previzualizare nu se pun în cache și nu se indexează.
	 */
	public static function preview_headers() {
		if ( isset( $_GET['lbb_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}

	private static function can_pay_online() {
		$admin_test = ! empty( $_POST['lbb_preview'] ) && current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.Security.NonceVerification
		return LBB_Settings::get( 'allow_pay' ) || self::is_preview() || $admin_test;
	}

	/**
	 * Versiunea unui fișier din assets/: se schimbă la fiecare modificare a fișierului,
	 * ca browserul și cache-ul hostingului să nu păstreze JS/CSS vechi după o actualizare.
	 */
	public static function asset_ver( $file ) {
		$mtime = @filemtime( LBB_DIR . 'assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return LBB_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	public static function register_assets() {
		wp_register_style( 'lbb', LBB_URL . 'assets/lbb.css', array(), self::asset_ver( 'lbb.css' ) );
		$accent = LBB_Settings::get( 'accent_color' );
		if ( $accent ) {
			wp_add_inline_style( 'lbb', '.lbb-booking{--lbb-accent:' . $accent . '}' );
		}
		wp_register_script( 'lbb', LBB_URL . 'assets/lbb.js', array(), self::asset_ver( 'lbb.js' ), true );
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
		wp_safe_redirect( is_string( $result ) ? $result : wc_get_checkout_url() );
		exit;
	}

	/**
	 * Aduce telefonul la forma internațională, ca același număr scris local
	 * (069…, 07…, 00373…) să conteze o singură dată la limita de rezervări.
	 */
	public static function normalize_phone( $raw ) {
		$phone = preg_replace( '/[^\d+]/', '', (string) $raw );
		$phone = '+' === substr( $phone, 0, 1 ) ? '+' . str_replace( '+', '', $phone ) : str_replace( '+', '', $phone );
		if ( 0 === strpos( $phone, '00' ) ) {
			return '+' . substr( $phone, 2 );
		}
		if ( preg_match( '/^0(\d{8})$/', $phone, $m ) ) {
			return '+373' . $m[1]; // Moldova: 069 123 456, 0231 12 345.
		}
		if ( preg_match( '/^0(7\d{8})$/', $phone, $m ) ) {
			return '+40' . $m[1]; // România: 07xx xxx xxx.
		}
		if ( preg_match( '/^(373\d{8}|407\d{8})$/', $phone ) ) {
			return '+' . $phone;
		}
		return $phone;
	}

	/**
	 * Validează datele, ține locurile și adaugă biletul în coș.
	 *
	 * Cu lbb_mode=reserve locurile se rezervă fără plată și se întoarce linkul rezervării.
	 *
	 * @param array $data Datele din formular, fără slash-uri.
	 * @return true|string|WP_Error true = bilet în coș; string = linkul rezervării.
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
			// „.”, „-” sau „1” nu sunt nume: cerem cel puțin două litere (orice alfabet).
			if ( preg_match_all( '/\p{L}/u', $name ) >= 2 ) {
				$names[] = mb_substr( $name, 0, 80 );
			}
		}
		if ( LBB_Settings::get( 'require_names' ) && count( $names ) < $seats ) {
			return new WP_Error( 'lbb_names', __( 'Scrieți numele și prenumele fiecărui pasager (ca în pașaport).', 'libertbus-bilete' ) );
		}
		$names = array_slice( $names, 0, $seats );

		$phone = self::normalize_phone( isset( $data['lbb_phone'] ) ? $data['lbb_phone'] : '' );
		$digits = strlen( preg_replace( '/\D/', '', $phone ) );
		// Un număr internațional are cel mult 15 cifre (E.164); mai lung e o greșeală de tastare.
		if ( $digits < 8 || $digits > 15 ) {
			return new WP_Error( 'lbb_phone', __( 'Introduceți un număr de telefon valid, cu prefixul țării (+373 sau +40).', 'libertbus-bilete' ) );
		}
		$email = isset( $data['lbb_email'] ) ? sanitize_email( $data['lbb_email'] ) : '';
		// Coloana din baza de date are 190 de caractere; peste ar da „nu s-a putut salva” la nesfârșit.
		if ( ! is_email( $email ) || strlen( $email ) > 190 ) {
			return new WP_Error( 'lbb_email', __( 'Introduceți o adresă de email validă. Acolo primiți biletul.', 'libertbus-bilete' ) );
		}

		$currency = isset( $data['lbb_currency'] ) ? strtoupper( sanitize_text_field( $data['lbb_currency'] ) ) : '';
		if ( ! in_array( $currency, LBB_Settings::pay_currencies(), true ) ) {
			$currency = LBB_Settings::default_pay_currency( $route['currency'] );
		}
		$reserve = isset( $data['lbb_mode'] ) && 'reserve' === $data['lbb_mode'];
		if ( ! $reserve && ! self::can_pay_online() ) {
			return new WP_Error( 'lbb_mode', __( 'Plata online nu este încă disponibilă. Folosiți „Rezerv, achit la urcare”.', 'libertbus-bilete' ) );
		}
		if ( $reserve ) {
			if ( ! LBB_Settings::get( 'allow_reserve' ) ) {
				return new WP_Error( 'lbb_mode', __( 'Rezervarea fără plată nu este disponibilă. Achitați online.', 'libertbus-bilete' ) );
			}
			$limit = (int) LBB_Settings::get( 'reserve_limit' );
			if ( $limit && LBB_Bookings::active_reservations( $phone ) >= $limit ) {
				/* translators: %s: telefon suport */
				return new WP_Error( 'lbb_limit', sprintf( __( 'Aveți deja rezervări neachitate pe acest număr. Achitați online sau sunați la %s.', 'libertbus-bilete' ), LBB_Settings::phone_text() ) );
			}
		}

		$booking = LBB_Bookings::create_hold( $route, $date, $time, $adults, $children, $names, $phone, $email, $currency );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		if ( $reserve ) {
			$booking = LBB_Bookings::reserve( $booking['token'] );
			if ( ! $booking ) {
				return new WP_Error( 'lbb_db', __( 'Rezervarea nu a putut fi salvată. Încercați din nou.', 'libertbus-bilete' ) );
			}
			LBB_Tickets::send_reservation_emails( $booking );
			return LBB_Tickets::url( $booking['ticket_code'] );
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
		WC()->session->set( 'lbb_currency', $currency );
		WC()->session->set( 'lbb_contact', array(
			'name'  => isset( $names[0] ) ? $names[0] : '',
			'phone' => $phone,
			'email' => $email,
		) );
		return true;
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'from' => '', 'to' => '', 'title' => '', 'mode' => 'both', 'compact' => '' ), $atts, 'libertbus_rezervare' );
		$map  = LBB_Routes::public_map();
		if ( ! $map ) {
			return '<p class="lbb-empty">' . esc_html__( 'Momentan nu sunt curse disponibile pentru rezervare online.', 'libertbus-bilete' ) . '</p>';
		}

		$preview     = self::is_preview();
		$can_pay     = ( LBB_Settings::get( 'allow_pay' ) || $preview ) && 'reserve' !== $atts['mode'];
		$can_reserve = LBB_Settings::get( 'allow_reserve' ) && 'pay' !== $atts['mode'];
		if ( ! $can_pay && ! $can_reserve ) {
			/* translators: %s: telefon */
			return '<p class="lbb-empty">' . sprintf( esc_html__( 'Rezervarea online nu este disponibilă momentan. Sunați la %s.', 'libertbus-bilete' ), LBB_Settings::phone_link() ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		wp_enqueue_style( 'lbb' );
		wp_enqueue_script( 'lbb' );

		$tz        = LBB_Settings::tz();
		$today     = new DateTimeImmutable( 'now', $tz );
		// Cu mai multe formulare pe pagină, eroarea și datele trimise aparțin doar celui trimis.
		$form_key  = substr( md5( wp_json_encode( $atts ) ), 0, 10 );
		$error     = self::$error && ( empty( $_POST['lbb_form'] ) || $form_key === $_POST['lbb_form'] ) ? self::$error : null; // phpcs:ignore WordPress.Security.NonceVerification
		$posted    = $error ? wp_unslash( $_POST ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$preset    = array(
			'from'     => isset( $posted['lbb_from'] ) ? sanitize_text_field( $posted['lbb_from'] ) : self::match_city( $atts['from'], array_keys( $map ) ),
			'route'    => isset( $posted['lbb_route'] ) ? (int) $posted['lbb_route'] : 0,
			'to'       => self::match_city( $atts['to'], self::all_destinations( $map ) ),
			'date'     => isset( $posted['lbb_date'] ) ? sanitize_text_field( $posted['lbb_date'] ) : '',
			'time'     => isset( $posted['lbb_time'] ) ? sanitize_text_field( $posted['lbb_time'] ) : '',
			'adults'   => isset( $posted['lbb_adults'] ) ? (int) $posted['lbb_adults'] : 1,
			'children' => isset( $posted['lbb_children'] ) ? (int) $posted['lbb_children'] : 0,
			'currency' => isset( $posted['lbb_currency'] ) ? sanitize_text_field( $posted['lbb_currency'] ) : '',
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
			'currencies'    => LBB_Settings::pay_currencies(),
			'showApprox'    => (bool) LBB_Settings::get( 'show_approx' ),
			'i18n'          => array(
				'chooseFrom'  => __( 'Alegeți orașul de plecare', 'libertbus-bilete' ),
				'chooseTo'    => __( 'Alegeți destinația', 'libertbus-bilete' ),
				'chooseTime'  => __( 'Alegeți ora', 'libertbus-bilete' ),
				'loading'     => __( 'Se verifică locurile…', 'libertbus-bilete' ),
				'noDeparture' => __( 'În ziua aleasă nu sunt plecări pe această rută. Alegeți altă dată.', 'libertbus-bilete' ),
				/* translators: %s: ultima dată la care se poate rezerva online. */
				'dateRange'   => sprintf( __( 'Online se poate rezerva de azi până pe %s. Alegeți o dată din acest interval.', 'libertbus-bilete' ), $today->modify( '+' . (int) LBB_Settings::get( 'max_days_ahead' ) . ' days' )->format( 'd.m.Y' ) ),
				/* translators: %s: telefonul de suport */
				'noneOpen'    => sprintf( __( 'Pentru ziua aleasă nu mai sunt locuri online. Alegeți altă dată sau sunați la %s.', 'libertbus-bilete' ), LBB_Settings::phone_text() ),
				'free'        => __( 'locuri libere', 'libertbus-bilete' ),
				'full'        => __( 'complet', 'libertbus-bilete' ),
				'closed'      => __( 'vânzare închisă', 'libertbus-bilete' ),
				'passenger'   => __( 'Pasager', 'libertbus-bilete' ),
				'child'       => __( 'copil', 'libertbus-bilete' ),
				'adult'       => __( 'adult', 'libertbus-bilete' ),
				'nameInvalid' => __( 'Scrieți numele și prenumele ca în pașaport (cel puțin două litere).', 'libertbus-bilete' ),
				'namePh'      => __( 'Nume și prenume', 'libertbus-bilete' ),
				'error'       => __( 'Nu am putut verifica locurile. Încercați din nou.', 'libertbus-bilete' ),
				'total'       => __( 'Total de plată', 'libertbus-bilete' ),
				'approxNote'  => __( 'echivalent orientativ, plata se face în moneda afișată', 'libertbus-bilete' ),
				'payBoard'    => __( 'Se achită la urcare', 'libertbus-bilete' ),
				'dialogLabel' => __( 'Datele pasagerilor și plata', 'libertbus-bilete' ),
				'sendingPay'  => __( 'Vă ducem la plată… Nu închideți pagina.', 'libertbus-bilete' ),
				'sendingRes'  => __( 'Se trimite rezervarea… Nu închideți pagina.', 'libertbus-bilete' ),
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
		<?php $compact = '' !== $atts['compact'] && '0' !== $atts['compact']; ?>
		<div class="lbb-booking<?php echo $compact ? ' lbb-compact' : ''; ?>" data-step="<?php echo $error ? '2' : '1'; ?>" id="<?php echo esc_attr( $uid ); ?>" data-lbb-config="<?php echo esc_attr( base64_encode( wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ); ?>">
			<?php if ( $atts['title'] ) : ?>
				<h3 class="lbb-title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="lbb-alert" role="alert" tabindex="-1" data-lbb="alert" id="<?php echo esc_attr( $uid ); ?>-alert" data-lbb-field="<?php echo esc_attr( preg_replace( '/^lbb_/', '', (string) $error->get_error_code() ) ); ?>"><?php echo esc_html( $error->get_error_message() ); ?></div>
			<?php endif; ?>
			<noscript><p class="lbb-alert"><?php echo sprintf( esc_html__( 'Pentru rezervare online activați JavaScript sau sunați la %s.', 'libertbus-bilete' ), LBB_Settings::phone_link() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p></noscript>
			<form method="post" class="lbb-form" novalidate>
				<input type="hidden" name="lbb_action" value="book">
				<?php if ( $preview ) : ?>
					<input type="hidden" name="lbb_preview" value="1">
					<p class="lbb-preview-note"><?php esc_html_e( 'Previzualizare: formularul nou și butonul de plată online se văd doar cu acest link. Ceilalți vizitatori văd site-ul ca până acum.', 'libertbus-bilete' ); ?></p>
				<?php endif; ?>
				<input type="hidden" name="lbb_mode" value="<?php echo $can_pay ? 'pay' : 'reserve'; ?>" data-lbb="mode">
				<input type="hidden" name="lbb_nonce" value="<?php echo esc_attr( wp_create_nonce( 'lbb_book' ) ); ?>">
				<input type="hidden" name="lbb_form" value="<?php echo esc_attr( $form_key ); ?>">
				<div class="lbb-hp" aria-hidden="true"><label>Website <input type="text" name="lbb_website" tabindex="-1" autocomplete="off"></label></div>

				<div class="lbb-step1" data-lbb="step1">
				<div class="lbb-grid">
					<label class="lbb-field"><span><?php esc_html_e( 'De unde plecați', 'libertbus-bilete' ); ?></span>
						<select name="lbb_from" data-lbb="from" required></select>
					</label>
					<label class="lbb-field"><span><?php esc_html_e( 'Unde mergeți', 'libertbus-bilete' ); ?></span>
						<select name="lbb_route" data-lbb="route" required></select>
					</label>
					<label class="lbb-field"><span><?php esc_html_e( 'Data plecării', 'libertbus-bilete' ); ?></span>
						<input type="date" name="lbb_date" data-lbb="date" required min="<?php echo esc_attr( $config['today'] ); ?>" max="<?php echo esc_attr( $config['maxDate'] ); ?>">
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
				</div>

				<div class="lbb-summary" data-lbb="summary" aria-live="polite" aria-atomic="true" hidden></div>

				<?php if ( $compact ) : ?>
					<div class="lbb-next-wrap" data-lbb="next-wrap">
						<button type="button" class="lbb-submit" data-lbb="next" disabled><?php esc_html_e( 'Continuă', 'libertbus-bilete' ); ?></button>
					</div>
				<?php endif; ?>

				<div class="lbb-step2" data-lbb="step2">
				<?php if ( $compact ) : ?>
					<button type="button" class="lbb-back" data-lbb="back">← <?php esc_html_e( 'Schimbă cursa', 'libertbus-bilete' ); ?></button>
				<?php endif; ?>
				<fieldset class="lbb-passengers" data-lbb="names">
					<legend><?php esc_html_e( 'Pasageri', 'libertbus-bilete' ); ?> <span class="lbb-legend-hint"><?php esc_html_e( '(numele ca în pașaport)', 'libertbus-bilete' ); ?></span></legend>
				</fieldset>

				<div class="lbb-grid lbb-grid-2">
					<label class="lbb-field"><span><?php esc_html_e( 'Telefon (cu +373 sau +40)', 'libertbus-bilete' ); ?></span>
						<input type="tel" name="lbb_phone" required autocomplete="tel" inputmode="tel" maxlength="30" placeholder="+373" value="<?php echo esc_attr( isset( $posted['lbb_phone'] ) ? sanitize_text_field( $posted['lbb_phone'] ) : '' ); ?>">
					</label>
					<label class="lbb-field"><span><?php esc_html_e( 'Email (aici primiți biletul)', 'libertbus-bilete' ); ?></span>
						<input type="email" name="lbb_email" required autocomplete="email" maxlength="190" value="<?php echo esc_attr( isset( $posted['lbb_email'] ) ? sanitize_email( $posted['lbb_email'] ) : '' ); ?>">
					</label>
				</div>

				<?php $currencies = LBB_Settings::pay_currencies(); ?>
				<fieldset class="lbb-currency"<?php echo count( $currencies ) < 2 ? ' hidden' : ''; ?>>
					<legend><?php esc_html_e( 'Plătesc în', 'libertbus-bilete' ); ?></legend>
					<?php foreach ( $currencies as $cur ) : ?>
						<label class="lbb-chip"><input type="radio" name="lbb_currency" value="<?php echo esc_attr( $cur ); ?>" data-lbb="currency"> <span><?php echo esc_html( $cur ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>

				<div class="lbb-actions">
					<?php if ( $can_pay ) : ?>
						<button type="submit" value="pay" class="lbb-submit" data-lbb-submit disabled><?php esc_html_e( 'Achit online cu cardul', 'libertbus-bilete' ); ?></button>
					<?php endif; ?>
					<?php if ( $can_reserve ) : ?>
						<button type="submit" value="reserve" class="lbb-submit lbb-submit-alt" data-lbb-submit disabled><?php esc_html_e( 'Rezerv, achit la urcare', 'libertbus-bilete' ); ?></button>
					<?php endif; ?>
				</div>
				<p class="lbb-sending" data-lbb="sending" role="status" aria-live="polite"></p>
				<p class="lbb-note">
					<?php
					if ( $can_pay ) {
						/* translators: 1: minute, 2: telefon */
						echo sprintf( esc_html__( 'La plata online locurile se păstrează %1$d minute cât finalizați plata. Întrebări: %2$s', 'libertbus-bilete' ), (int) LBB_Settings::get( 'cart_hold_minutes' ), LBB_Settings::phone_link() ); // phpcs:ignore WordPress.Security.EscapeOutput
					} else {
						/* translators: %s: telefon */
						echo sprintf( esc_html__( 'Plata se face la urcare. Primiți confirmarea pe email. Întrebări: %s', 'libertbus-bilete' ), LBB_Settings::phone_link() ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</p>
				<?php
				$privacy = LBB_Legal::page_id( 'privacy' );
				if ( $privacy && 'publish' === get_post_status( $privacy ) ) :
					?>
					<p class="lbb-note lbb-privacy-note">
						<?php
						/* translators: %s: link spre Politica de confidențialitate */
						echo sprintf( esc_html__( 'Datele se folosesc doar pentru rezervare și călătorie. Detalii: %s.', 'libertbus-bilete' ), '<a href="' . esc_url( get_permalink( $privacy ) ) . '">' . esc_html__( 'Politica de confidențialitate', 'libertbus-bilete' ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput
						?>
					</p>
				<?php endif; ?>
				</div>
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
