<?php
/**
 * Administrare: panoul „Gata de plăți?”, rute, pasageri, rezervări, setări.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Admin {

	const CAP = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_lbb_save_route', array( __CLASS__, 'save_route' ) );
		add_action( 'admin_post_lbb_delete_route', array( __CLASS__, 'delete_route' ) );
		add_action( 'admin_post_lbb_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_lbb_manifest_csv', array( __CLASS__, 'manifest_csv' ) );
		add_action( 'admin_post_lbb_cancel_booking', array( __CLASS__, 'cancel_booking' ) );
		add_action( 'admin_post_lbb_new_preview_link', array( __CLASS__, 'new_preview_link' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LBB_FILE ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=lbb' ) ) . '">' . esc_html__( 'Panou', 'libertbus-bilete' ) . '</a>' );
			return $links;
		} );
	}

	private static function cap() {
		return current_user_can( self::CAP ) ? self::CAP : 'manage_options';
	}

	public static function menu() {
		$cap = self::cap();
		add_menu_page( 'LibertBus', 'LibertBus', $cap, 'lbb', array( __CLASS__, 'page_dashboard' ), 'dashicons-tickets-alt', 56 );
		add_submenu_page( 'lbb', __( 'Gata de plăți?', 'libertbus-bilete' ), __( 'Panou', 'libertbus-bilete' ), $cap, 'lbb', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'lbb', __( 'Rute și orar', 'libertbus-bilete' ), __( 'Rute și orar', 'libertbus-bilete' ), $cap, 'lbb-routes', array( __CLASS__, 'page_routes' ) );
		add_submenu_page( 'lbb', __( 'Lista de pasageri', 'libertbus-bilete' ), __( 'Pasageri', 'libertbus-bilete' ), $cap, 'lbb-manifest', array( __CLASS__, 'page_manifest' ) );
		add_submenu_page( 'lbb', __( 'Rezervări', 'libertbus-bilete' ), __( 'Rezervări', 'libertbus-bilete' ), $cap, 'lbb-bookings', array( __CLASS__, 'page_bookings' ) );
		add_submenu_page( 'lbb', __( 'Setări', 'libertbus-bilete' ), __( 'Setări', 'libertbus-bilete' ), $cap, 'lbb-settings', array( __CLASS__, 'page_settings' ) );
	}

	/* ---------- Verificarea „gata de plăți” ---------- */

	/**
	 * Lista de verificări. Fiecare: ok (true/false/'warn'), titlu, detalii, link de rezolvare.
	 */
	public static function checklist() {
		$items = array();
		$add   = function ( $id, $ok, $title, $detail = '', $fix = '' ) use ( &$items ) {
			$items[] = compact( 'id', 'ok', 'title', 'detail', 'fix' );
		};

		$wc = class_exists( 'WooCommerce' );
		$add( 'woocommerce', $wc, __( 'WooCommerce este activ', 'libertbus-bilete' ), '', admin_url( 'plugins.php' ) );

		$add( 'ssl', 0 === strpos( home_url(), 'https://' ), __( 'Site-ul folosește HTTPS', 'libertbus-bilete' ), __( 'Băncile cer certificat SSL.', 'libertbus-bilete' ), admin_url( 'options-general.php' ) );

		$tz = get_option( 'timezone_string' );
		$add( 'timezone', $tz ? true : 'warn', __( 'Fusul orar e setat pe un oraș', 'libertbus-bilete' ),
			$tz ? $tz : __( 'Acum e un decalaj fix (ex. UTC+2), care greșește cu o oră vara. Orarul biletelor folosește deja ora Chișinăului, dar alegeți „Chișinău” ca să fie corecte și comenzile și emailurile WooCommerce.', 'libertbus-bilete' ),
			admin_url( 'options-general.php' ) );

		if ( $wc ) {
			$currency = get_woocommerce_currency();
			$add( 'currency', 'MDL' === $currency ? true : 'warn', __( 'Moneda magazinului', 'libertbus-bilete' ),
				'MDL' === $currency ? 'MDL' : sprintf( __( 'Acum: %s. Băncile din Moldova (Paynet, MAIB, Victoriabank) încasează de regulă în MDL. Prețurile în RON se convertesc automat după cursul din Setări.', 'libertbus-bilete' ), $currency ),
				admin_url( 'admin.php?page=wc-settings&tab=general' ) );

			$pay = LBB_Settings::pay_currencies();
			$add( 'pay_currencies', count( $pay ) > 1 ? 'warn' : true, __( 'Monede de plată pentru clienți', 'libertbus-bilete' ),
				implode( ', ', $pay ) . ( count( $pay ) > 1 ? ' — ' . __( 'verificați că procesatorul încasează în fiecare dintre ele. Dacă banca primește doar MDL, lăsați doar MDL: clientul cu card în RON plătește oricum, conversia o face banca lui.', 'libertbus-bilete' ) : '' ),
				admin_url( 'admin.php?page=lbb-settings' ) );

			$real = array();
			$test = false;
			foreach ( WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array() as $gateway ) {
				if ( 'yes' !== $gateway->enabled ) {
					continue;
				}
				if ( 'lbb_test' === $gateway->id ) {
					$test = true;
				} elseif ( ! in_array( $gateway->id, array( 'bacs', 'cheque', 'cod' ), true ) ) {
					$real[] = $gateway->get_method_title() ? $gateway->get_method_title() : $gateway->id;
				}
			}
			$add( 'gateway', (bool) $real, __( 'O metodă de plată cu cardul este activă', 'libertbus-bilete' ),
				$real ? implode( ', ', $real ) : __( 'Instalați plugin-ul WooCommerce al băncii (Paynet, MAIB, Victoriabank, BT iPay), introduceți datele primite de la bancă și activați-l.', 'libertbus-bilete' ),
				admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
			$add( 'allow_pay', LBB_Settings::get( 'allow_pay' ) ? true : 'warn', __( 'Butonul „Achit online cu cardul” e pornit', 'libertbus-bilete' ),
				LBB_Settings::get( 'allow_pay' ) ? '' : __( 'Acum clienții pot doar rezerva cu plata la urcare. Porniți butonul din Setări după ce plata cu cardul e activă.', 'libertbus-bilete' ),
				admin_url( 'admin.php?page=lbb-settings' ) );
			if ( $test ) {
				$add( 'test_gateway', 'warn', __( 'Plata de test este pornită', 'libertbus-bilete' ), __( 'O văd doar administratorii. Opriți-o după verificări.', 'libertbus-bilete' ), admin_url( 'admin.php?page=lbb-settings' ) );
			}

			$terms = (int) wc_terms_and_conditions_page_id();
			$add( 'terms', $terms && 'publish' === get_post_status( $terms ), __( 'Pagina „Termeni și condiții” e publicată și setată în WooCommerce', 'libertbus-bilete' ),
				__( 'WooCommerce → Setări → Avansat → Pagina de termeni.', 'libertbus-bilete' ), admin_url( 'admin.php?page=wc-settings&tab=advanced' ) );

			$product = wc_get_product( LBB_Install::product_id() );
			$add( 'product', $product && 'publish' === $product->get_status(), __( 'Produsul intern „Bilet autocar” există', 'libertbus-bilete' ), __( 'Se recreează automat dacă lipsește.', 'libertbus-bilete' ) );
		}

		foreach ( LBB_Legal::pages() as $key => $page ) {
			if ( 'terms' === $key && $wc ) {
				continue; // Verificată mai sus, ca pagină de termeni WooCommerce.
			}
			$id = LBB_Legal::page_id( $key );
			$ok = $id && 'publish' === get_post_status( $id );
			$add( 'legal_' . $key, $ok ? true : ( $id ? 'warn' : false ), sprintf( __( 'Pagina „%s” e publicată', 'libertbus-bilete' ), $page['title'] ),
				$ok ? '' : ( $id ? __( 'Există ca ciornă: completați datele firmei și publicați-o.', 'libertbus-bilete' ) : __( 'Creați-o din Setări → Pagini legale.', 'libertbus-bilete' ) ),
				$id ? get_edit_post_link( $id, 'raw' ) : admin_url( 'admin.php?page=lbb-settings#legal' ) );
		}

		$company = LBB_Settings::get( 'company_name' ) && LBB_Settings::get( 'company_idno' ) && LBB_Settings::get( 'company_address' );
		$add( 'company', (bool) $company, __( 'Datele firmei sunt completate', 'libertbus-bilete' ), __( 'Denumire, IDNO și adresă. Banca le verifică pe site.', 'libertbus-bilete' ), admin_url( 'admin.php?page=lbb-settings' ) );

		// Numărul apare în formular, pe bilet și în emailuri, ca link de apel: cu mai puțin de 8 cifre nu sună nicăieri.
		$phone = trim( (string) LBB_Settings::get( 'support_phone' ) );
		$add( 'phone', strlen( preg_replace( '/\D/', '', $phone ) ) >= 8, __( 'Telefonul pentru clienți e complet', 'libertbus-bilete' ),
			'' === $phone
				? __( 'Lipsește. Clienții îl văd în formular, pe bilet și în emailuri.', 'libertbus-bilete' )
				/* translators: %s: numărul de telefon din setări */
				: sprintf( __( 'Acum: %s. Clienții îl văd în formular, pe bilet și în emailuri, cu prefixul țării (ex. +373 691 84 111).', 'libertbus-bilete' ), $phone ),
			admin_url( 'admin.php?page=lbb-settings' ) );

		$add( 'cookies', (bool) LBB_Settings::get( 'cookie_banner' ), __( 'Bannerul de cookies e pornit', 'libertbus-bilete' ),
			LBB_Settings::get( 'cookie_banner' )
				? __( 'Google Analytics și celelalte scripturi de statistică pornesc doar după acord.', 'libertbus-bilete' )
				: __( 'E oprit: Google Analytics pune cookies fără acord, ceea ce Legea nr. 195/2024 nu permite. Porniți „Banner cookies” din Setări.', 'libertbus-bilete' ),
			admin_url( 'admin.php?page=lbb-settings' ) );

		// Rezervările plugin-ului se anonimizează singure după 3 ani; comenzile WooCommerce (cu datele de facturare)
		// doar dacă e setat în WooCommerce. Politica de confidențialitate promite 3 ani.
		if ( $wc && function_exists( 'wc_parse_relative_date_option' ) ) {
			$anon  = wc_parse_relative_date_option( get_option( 'woocommerce_anonymize_completed_orders' ) );
			$units = array( 'days' => __( 'zile', 'libertbus-bilete' ), 'weeks' => __( 'săptămâni', 'libertbus-bilete' ), 'months' => __( 'luni', 'libertbus-bilete' ), 'years' => __( 'ani', 'libertbus-bilete' ) );
			$set   = ! empty( $anon['number'] );
			$add( 'order_retention', $set ? true : 'warn', __( 'Comenzile finalizate se anonimizează automat', 'libertbus-bilete' ),
				$set
					/* translators: 1: număr, 2: unitate (ani, luni…) */
					? sprintf( __( 'După %1$d %2$s.', 'libertbus-bilete' ), (int) $anon['number'], isset( $units[ $anon['unit'] ] ) ? $units[ $anon['unit'] ] : $anon['unit'] )
					: __( 'Acum: niciodată. Politica de confidențialitate promite păstrarea datelor 3 ani: în WooCommerce → Setări → Conturi și confidențialitate, la „Comenzi finalizate”, alegeți 3 ani.', 'libertbus-bilete' ),
				admin_url( 'admin.php?page=wc-settings&tab=account' ) );
		}

		$routes = array_filter( LBB_Routes::all( true ), function ( $r ) {
			return $r['price'] > 0;
		} );
		$add( 'routes', count( $routes ) > 0, __( 'Există rute active cu preț', 'libertbus-bilete' ), sprintf( __( '%d rute active', 'libertbus-bilete' ), count( $routes ) ), admin_url( 'admin.php?page=lbb-routes' ) );

		$form_pages = self::pages_with_form();
		$add( 'form', $form_pages ? true : false, __( 'Formularul de rezervare e pus pe o pagină publicată', 'libertbus-bilete' ),
			$form_pages ? implode( ', ', wp_list_pluck( $form_pages, 'post_title' ) ) : __( 'Adăugați [libertbus_rezervare] pe o pagină (sau [libertbus_rezervare from="Bălți" to="Iași"] pe paginile de rută).', 'libertbus-bilete' ),
			admin_url( 'edit.php?post_type=page' ) );

		$add( 'cron', (bool) wp_next_scheduled( 'lbb_cleanup' ), __( 'Curățarea automată a rezervărilor expirate e programată', 'libertbus-bilete' ), '' );

		return $items;
	}

	public static function pages_with_form() {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '[libertbus_rezervare' ) . '%';
		$like2 = '%' . $wpdb->esc_like( '[lbb_booking' ) . '%';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE p.post_status = 'publish' AND p.post_type IN ('page','post')
			AND ( p.post_content LIKE %s OR p.post_content LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s ) LIMIT 20",
			$like, $like2, $like, $like2
		) );
	}

	public static function ready() {
		foreach ( self::checklist() as $item ) {
			if ( false === $item['ok'] ) {
				return false;
			}
		}
		return true;
	}

	public static function rest() {
		register_rest_route( 'lbb/v1', '/status', array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( self::cap() );
			},
			'callback'            => function () {
				return array(
					'version'   => LBB_VERSION,
					'ready'     => self::ready(),
					'checklist' => self::checklist(),
					'stats'     => self::stats(),
				);
			},
		) );
	}

	public static function stats() {
		global $wpdb;
		$t = LBB_Bookings::table();
		return array(
			'routes_active'      => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LBB_Routes::table() . ' WHERE active = 1' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'tickets_confirmed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status = 'confirmed'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'seats_confirmed'    => (int) $wpdb->get_var( "SELECT COALESCE(SUM(seats),0) FROM $t WHERE status = 'confirmed'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'seats_upcoming'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(seats),0) FROM $t WHERE status = 'confirmed' AND travel_date >= %s", LBB_Settings::today() ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'seats_reserved'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(seats),0) FROM $t WHERE status = 'reserved' AND travel_date >= %s", LBB_Settings::today() ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'pending_payment'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = 'pending' AND expires_at > %s", gmdate( 'Y-m-d H:i:s' ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/* ---------- Pagini ---------- */

	private static function header( $title ) {
		echo '<div class="wrap lbb-admin"><h1>' . esc_html( $title ) . '</h1>';
		if ( isset( $_GET['lbb_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg  = sanitize_text_field( wp_unslash( $_GET['lbb_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$type = isset( $_GET['lbb_err'] ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<style>.lbb-admin .lbb-check td{vertical-align:top}.lbb-ok{color:#16632f;font-weight:700}.lbb-warn{color:#996800;font-weight:700}.lbb-bad{color:#b32d2e;font-weight:700}.lbb-admin .lbb-cards{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0}.lbb-admin .lbb-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 16px;min-width:150px}.lbb-admin .lbb-card b{display:block;font-size:22px}.lbb-admin .lbb-days label{margin-right:8px}@media print{#adminmenumain,#wpadminbar,#wpfooter,.update-nag,.lbb-noprint,.notice{display:none!important}#wpcontent{margin:0!important}}</style>';
	}

	private static function redirect( $page, $msg, $error = false, $extra = array() ) {
		$args = array_merge( array( 'page' => $page, 'lbb_msg' => rawurlencode( $msg ) ), $extra );
		if ( $error ) {
			$args['lbb_err'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page_dashboard() {
		self::header( __( 'LibertBus — gata de plăți cu cardul?', 'libertbus-bilete' ) );
		$stats = self::stats();
		echo '<div class="lbb-cards">';
		foreach ( array(
			'routes_active'     => __( 'Rute active', 'libertbus-bilete' ),
			'seats_upcoming'    => __( 'Locuri achitate online (curse viitoare)', 'libertbus-bilete' ),
			'seats_reserved'    => __( 'Locuri rezervate, plata la urcare', 'libertbus-bilete' ),
			'tickets_confirmed' => __( 'Bilete emise (total)', 'libertbus-bilete' ),
			'pending_payment'   => __( 'Așteaptă plata acum', 'libertbus-bilete' ),
		) as $key => $label ) {
			echo '<div class="lbb-card"><b>' . esc_html( $stats[ $key ] ) . '</b>' . esc_html( $label ) . '</div>';
		}
		echo '</div>';

		$ready = self::ready();
		echo '<h2>' . ( $ready ? '✅ ' . esc_html__( 'Totul e pregătit pentru plăți', 'libertbus-bilete' ) : esc_html__( 'Ce mai trebuie făcut', 'libertbus-bilete' ) ) . '</h2>';
		echo '<table class="widefat striped lbb-check"><tbody>';
		foreach ( self::checklist() as $item ) {
			$cls  = true === $item['ok'] ? 'lbb-ok' : ( 'warn' === $item['ok'] ? 'lbb-warn' : 'lbb-bad' );
			$icon = true === $item['ok'] ? '✔' : ( 'warn' === $item['ok'] ? '!' : '✘' );
			echo '<tr><td style="width:24px" class="' . esc_attr( $cls ) . '">' . esc_html( $icon ) . '</td><td><strong>' . esc_html( $item['title'] ) . '</strong>';
			if ( $item['detail'] ) {
				echo '<br><span class="description">' . esc_html( $item['detail'] ) . '</span>';
			}
			echo '</td><td style="width:110px">';
			if ( true !== $item['ok'] && $item['fix'] ) {
				echo '<a class="button" href="' . esc_url( $item['fix'] ) . '">' . esc_html__( 'Rezolvă', 'libertbus-bilete' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		?>
		<h2><?php esc_html_e( 'Cum conectați banca', 'libertbus-bilete' ); ?></h2>
		<p><?php esc_html_e( 'Plugin-ul folosește orice metodă de plată WooCommerce. Pașii sunt aceiași pentru orice bancă:', 'libertbus-bilete' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Semnați contractul de acceptare a cardurilor pe internet (e-commerce) cu banca sau procesatorul.', 'libertbus-bilete' ); ?></li>
			<li><?php esc_html_e( 'Instalați plugin-ul lor pentru WooCommerce (Module → Adaugă) și introduceți datele de acces primite (ID comerciant, chei, certificate).', 'libertbus-bilete' ); ?></li>
			<li><?php esc_html_e( 'Activați-l în WooCommerce → Setări → Plăți. Faceți întâi o plată în modul test al băncii, apoi treceți pe modul real.', 'libertbus-bilete' ); ?></li>
		</ol>
		<table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e( 'Procesator', 'libertbus-bilete' ); ?></th><th><?php esc_html_e( 'Plugin WooCommerce', 'libertbus-bilete' ); ?></th><th><?php esc_html_e( 'Ce cereți de la ei', 'libertbus-bilete' ); ?></th></tr></thead><tbody>
			<tr><td>Paynet (MD)</td><td><?php esc_html_e( 'Modulul WooCommerce oferit de Paynet la semnarea contractului', 'libertbus-bilete' ); ?></td><td>Merchant Code, Secret Key, User/Password API, <?php esc_html_e( 'mediu de test', 'libertbus-bilete' ); ?></td></tr>
			<tr><td>maib (MD)</td><td>„maib Payment Gateway for WooCommerce” (wordpress.org)</td><td>Project ID, Project Secret, Signature Key (maib ecomm)</td></tr>
			<tr><td>Victoriabank (MD, grupul BT)</td><td>„Victoriabank Payment Gateway for WooCommerce” (wordpress.org)</td><td>Merchant ID, Terminal ID, <?php esc_html_e( 'chei/certificate', 'libertbus-bilete' ); ?></td></tr>
			<tr><td>Banca Transilvania (RO)</td><td><?php esc_html_e( 'Plugin BT iPay pentru WooCommerce', 'libertbus-bilete' ); ?></td><td><?php esc_html_e( 'Utilizator și parolă API iPay (necesită firmă/cont în România, încasare în RON)', 'libertbus-bilete' ); ?></td></tr>
		</tbody></table>
		<p class="description"><?php esc_html_e( 'Pentru verificări fără bancă: Setări → „Plată de test”, apoi faceți o rezervare ca administrator.', 'libertbus-bilete' ); ?></p>
		</div>
		<?php
	}

	public static function page_routes() {
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$adding  = isset( $_GET['add'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $edit_id || $adding ) {
			self::route_form( $edit_id ? LBB_Routes::get( $edit_id ) : null );
			return;
		}
		self::header( __( 'Rute și orar', 'libertbus-bilete' ) );
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'MDL';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=lbb-routes&add=1' ) ) . '">' . esc_html__( 'Adaugă rută', 'libertbus-bilete' ) . '</a> ';
		echo '<span class="description">' . esc_html( sprintf( __( 'Prețurile se încasează în %s; cele în altă monedă se convertesc după cursul din Setări.', 'libertbus-bilete' ), $currency ) ) . '</span></p>';
		self::stack_table_style( 'lbb-routes-table', array( __( 'Ruta', 'libertbus-bilete' ), __( 'Ore', 'libertbus-bilete' ), __( 'Zile', 'libertbus-bilete' ), __( 'Preț', 'libertbus-bilete' ), __( 'Locuri/cursă', 'libertbus-bilete' ), __( 'Stare', 'libertbus-bilete' ) ) );
		echo '<table class="widefat striped lbb-routes-table"><thead><tr><th>' . esc_html__( 'Ruta', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Ore', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Zile', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Preț', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Locuri/cursă', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Stare', 'libertbus-bilete' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Acțiuni', 'libertbus-bilete' ) . '</span></th></tr></thead><tbody>';
		$day_names = self::day_names();
		foreach ( LBB_Routes::all() as $r ) {
			$days = 7 === count( $r['days'] ) ? __( 'zilnic', 'libertbus-bilete' ) : implode( ', ', array_map( function ( $d ) use ( $day_names ) {
				return $day_names[ $d ];
			}, $r['days'] ) );
			$price = wc_format_decimal( $r['price'], 2 ) . ' ' . $r['currency'];
			if ( $r['currency'] !== $currency ) {
				$price .= ' ≈ ' . wc_format_decimal( LBB_Settings::convert( $r['price'], $r['currency'] ), 2 ) . ' ' . $currency;
			}
			$edit = admin_url( 'admin.php?page=lbb-routes&edit=' . $r['id'] );
			echo '<tr><td><a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $r['origin'] . ' → ' . $r['destination'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( implode( ', ', $r['times'] ) ) . '</td><td>' . esc_html( $days ) . '</td><td>' . esc_html( $price ) . '</td>';
			echo '<td>' . esc_html( LBB_Routes::capacity( $r ) . ( $r['capacity'] ? '' : ' ' . __( '(implicit)', 'libertbus-bilete' ) ) ) . '</td>';
			echo '<td>' . ( $r['active'] && $r['price'] > 0 ? '<span class="lbb-ok">' . esc_html__( 'se vinde', 'libertbus-bilete' ) . '</span>' : '<span class="lbb-warn">' . esc_html__( 'oprită', 'libertbus-bilete' ) . '</span>' ) . '</td>';
			echo '<td><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Editează', 'libertbus-bilete' ) . '</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function day_names() {
		return array( 1 => 'Lu', 2 => 'Ma', 3 => 'Mi', 4 => 'Jo', 5 => 'Vi', 6 => 'Sâ', 7 => 'Du' );
	}

	private static function route_form( $route ) {
		$route = $route ? $route : array(
			'id' => 0, 'origin' => '', 'destination' => '', 'departures' => '', 'days' => array( 1, 2, 3, 4, 5, 6, 7 ),
			'price' => '', 'child_price' => null, 'currency' => 'MDL', 'capacity' => 0, 'active' => 1, 'page_url' => '', 'notes' => '',
		);
		self::header( $route['id'] ? __( 'Editează ruta', 'libertbus-bilete' ) : __( 'Rută nouă', 'libertbus-bilete' ) );
		$cities = array();
		foreach ( LBB_Routes::all() as $r ) {
			$cities[] = $r['origin'];
			$cities[] = $r['destination'];
		}
		$cities = array_unique( $cities );
		sort( $cities );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lbb_save_route">
			<input type="hidden" name="id" value="<?php echo esc_attr( $route['id'] ); ?>">
			<?php wp_nonce_field( 'lbb_save_route' ); ?>
			<datalist id="lbb-cities"><?php foreach ( $cities as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"><?php endforeach; ?></datalist>
			<table class="form-table">
				<tr><th><label for="lbb-origin"><?php esc_html_e( 'Plecare', 'libertbus-bilete' ); ?></label></th><td><input type="text" id="lbb-origin" name="origin" class="regular-text" list="lbb-cities" required value="<?php echo esc_attr( $route['origin'] ); ?>"></td></tr>
				<tr><th><label for="lbb-dest"><?php esc_html_e( 'Destinație', 'libertbus-bilete' ); ?></label></th><td><input type="text" id="lbb-dest" name="destination" class="regular-text" list="lbb-cities" required value="<?php echo esc_attr( $route['destination'] ); ?>"></td></tr>
				<tr><th><label for="lbb-times"><?php esc_html_e( 'Ore de plecare', 'libertbus-bilete' ); ?></label></th><td><input type="text" id="lbb-times" name="departures" class="regular-text" required value="<?php echo esc_attr( implode( ', ', LBB_Routes::parse_times( $route['departures'] ) ) ); ?>"><p class="description"><?php esc_html_e( 'Separate prin virgulă, ex. 08:45, 17:45', 'libertbus-bilete' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Zile', 'libertbus-bilete' ); ?></th><td class="lbb-days"><?php foreach ( self::day_names() as $n => $label ) : ?><label><input type="checkbox" name="days[]" value="<?php echo esc_attr( $n ); ?>" <?php checked( in_array( $n, $route['days'], true ) ); ?>> <?php echo esc_html( $label ); ?></label><?php endforeach; ?></td></tr>
				<tr><th><label for="lbb-price"><?php esc_html_e( 'Preț adult', 'libertbus-bilete' ); ?></label></th><td><input id="lbb-price" name="price" type="number" step="0.01" min="0" required value="<?php echo esc_attr( $route['price'] ); ?>">
					<select name="currency" aria-label="<?php esc_attr_e( 'Moneda prețului', 'libertbus-bilete' ); ?>"><?php foreach ( LBB_Settings::currencies() as $cur ) : ?><option <?php selected( $route['currency'], $cur ); ?>><?php echo esc_html( $cur ); ?></option><?php endforeach; ?></select>
					<p class="description"><?php esc_html_e( 'Cu prețul 0 ruta nu se vinde online.', 'libertbus-bilete' ); ?></p></td></tr>
				<tr><th><label for="lbb-child"><?php esc_html_e( 'Preț copil', 'libertbus-bilete' ); ?></label></th><td><input id="lbb-child" name="child_price" type="number" step="0.01" min="0" value="<?php echo esc_attr( null === $route['child_price'] ? '' : $route['child_price'] ); ?>"><p class="description"><?php esc_html_e( 'Gol = copiii plătesc ca adulții.', 'libertbus-bilete' ); ?></p></td></tr>
				<tr><th><label for="lbb-cap"><?php esc_html_e( 'Locuri de vândut online pe cursă', 'libertbus-bilete' ); ?></label></th><td><input id="lbb-cap" name="capacity" type="number" min="0" value="<?php echo esc_attr( $route['capacity'] ); ?>"><p class="description"><?php echo esc_html( sprintf( __( '0 = valoarea implicită din Setări (%d).', 'libertbus-bilete' ), LBB_Settings::get( 'default_capacity' ) ) ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Activă', 'libertbus-bilete' ); ?></th><td><label><input type="checkbox" name="active" value="1" <?php checked( $route['active'] ); ?>> <?php esc_html_e( 'Se vinde online', 'libertbus-bilete' ); ?></label></td></tr>
				<tr><th><label for="lbb-url"><?php esc_html_e( 'Pagina rutei', 'libertbus-bilete' ); ?></label></th><td><input id="lbb-url" name="page_url" type="url" class="regular-text" value="<?php echo esc_attr( $route['page_url'] ); ?>"></td></tr>
				<tr><th><label for="lbb-notes"><?php esc_html_e( 'Notițe interne', 'libertbus-bilete' ); ?></label></th><td><textarea id="lbb-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea( $route['notes'] ); ?></textarea></td></tr>
			</table>
			<?php submit_button( __( 'Salvează ruta', 'libertbus-bilete' ) ); ?>
		</form>
		<?php if ( $route['id'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Ștergeți ruta? Rezervările existente rămân în listă.', 'libertbus-bilete' ) ); ?>');">
				<input type="hidden" name="action" value="lbb_delete_route">
				<input type="hidden" name="id" value="<?php echo esc_attr( $route['id'] ); ?>">
				<?php wp_nonce_field( 'lbb_delete_route' ); ?>
				<button class="button-link button-link-delete" type="submit"><?php esc_html_e( 'Șterge ruta', 'libertbus-bilete' ); ?></button>
			</form>
			<p><?php esc_html_e( 'Formular doar pentru această rută:', 'libertbus-bilete' ); ?> <code>[libertbus_rezervare from="<?php echo esc_html( $route['origin'] ); ?>" to="<?php echo esc_html( $route['destination'] ); ?>"]</code></p>
		<?php endif; ?>
		</div>
		<?php
	}

	public static function save_route() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'lbb_save_route' ) ) {
			wp_die( esc_html__( 'Nu aveți acces.', 'libertbus-bilete' ) );
		}
		$data   = wp_unslash( $_POST );
		$id     = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$data['days'] = isset( $data['days'] ) ? (array) $data['days'] : array();
		$result = LBB_Routes::save( $data, $id );
		if ( is_wp_error( $result ) ) {
			self::redirect( 'lbb-routes', $result->get_error_message(), true, $id ? array( 'edit' => $id ) : array( 'add' => 1 ) );
		}
		self::redirect( 'lbb-routes', __( 'Ruta a fost salvată.', 'libertbus-bilete' ) );
	}

	public static function delete_route() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'lbb_delete_route' ) ) {
			wp_die( esc_html__( 'Nu aveți acces.', 'libertbus-bilete' ) );
		}
		LBB_Routes::delete( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
		self::redirect( 'lbb-routes', __( 'Ruta a fost ștearsă.', 'libertbus-bilete' ) );
	}

	public static function page_manifest() {
		$date  = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : LBB_Settings::today(); // phpcs:ignore WordPress.Security.NonceVerification
		$route = isset( $_GET['route'] ) ? (int) $_GET['route'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = LBB_Settings::today();
		}
		self::header( sprintf( __( 'Pasageri — %s', 'libertbus-bilete' ), wp_date( 'd.m.Y', strtotime( $date . ' 12:00' ) ) ) );
		?>
		<form method="get" class="lbb-noprint" style="margin:12px 0">
			<input type="hidden" name="page" value="lbb-manifest">
			<input type="date" name="date" value="<?php echo esc_attr( $date ); ?>" aria-label="<?php esc_attr_e( 'Data cursei', 'libertbus-bilete' ); ?>">
			<select name="route" aria-label="<?php esc_attr_e( 'Ruta', 'libertbus-bilete' ); ?>"><option value="0"><?php esc_html_e( 'Toate rutele', 'libertbus-bilete' ); ?></option>
				<?php foreach ( LBB_Routes::all() as $r ) : ?><option value="<?php echo esc_attr( $r['id'] ); ?>" <?php selected( $route, $r['id'] ); ?>><?php echo esc_html( $r['origin'] . ' → ' . $r['destination'] ); ?></option><?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Arată', 'libertbus-bilete' ); ?></button>
			<button type="button" class="button" onclick="window.print()"><?php esc_html_e( 'Printează', 'libertbus-bilete' ); ?></button>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lbb_manifest_csv&date=' . $date . '&route=' . $route ), 'lbb_manifest_csv' ) ); ?>">CSV</a>
		</form>
		<?php
		$rows = LBB_Bookings::manifest( $date, $route );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nu sunt bilete vândute pentru această zi.', 'libertbus-bilete' ) . '</p></div>';
			return;
		}
		$total = 0;
		self::stack_table_style( 'lbb-manifest-table', array( __( 'Ora', 'libertbus-bilete' ), __( 'Ruta', 'libertbus-bilete' ), __( 'Bilet', 'libertbus-bilete' ), __( 'Locuri', 'libertbus-bilete' ), __( 'Pasageri', 'libertbus-bilete' ), __( 'Telefon', 'libertbus-bilete' ), __( 'Plată', 'libertbus-bilete' ), __( 'Comanda', 'libertbus-bilete' ) ) );
		echo '<table class="widefat striped lbb-manifest-table"><thead><tr><th>' . esc_html__( 'Ora', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Ruta', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Bilet', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Locuri', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Pasageri', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Telefon', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Plată', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Comanda', 'libertbus-bilete' ) . '</th></tr></thead><tbody>';
		$due = array();
		foreach ( $rows as $b ) {
			$total += $b['seats'];
			$order  = $b['order_id'] ? wc_get_order( $b['order_id'] ) : null;
			echo '<tr><td><strong>' . esc_html( $b['dep_time'] ) . '</strong></td><td>' . esc_html( $b['origin'] . ' → ' . $b['destination'] ) . '</td><td>' . self::ticket_code_html( $b['ticket_code'] ) . '</td><td>' . esc_html( $b['seats'] ) . '</td><td>' . esc_html( implode( ', ', LBB_Bookings::passenger_labels( $b ) ) ) . '</td><td><a href="tel:' . esc_attr( $b['phone'] ) . '">' . esc_html( $b['phone'] ) . '</a></td><td>' . self::payment_label( $b, $due ) . '</td><td>';
			if ( $order ) {
				echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
			}
			echo '</td></tr>';
		}
		$due_text = array();
		foreach ( $due as $cur => $sum ) {
			$due_text[] = LBB_WooCommerce::money( $sum, $cur );
		}
		echo '</tbody><tfoot><tr><th colspan="3">' . esc_html__( 'Total locuri', 'libertbus-bilete' ) . '</th><th>' . esc_html( $total ) . '</th><td colspan="2"></td><th colspan="2">' . ( $due_text ? esc_html__( 'De încasat la urcare', 'libertbus-bilete' ) . ': ' . esc_html( implode( ' + ', $due_text ) ) : '' ) . '</th></tr></tfoot></table></div>';
	}

	/**
	 * Codul biletului; rezervările fără cod (coș abandonat, anulate înainte de plată) arată „—”.
	 */
	private static function ticket_code_html( $code ) {
		return '' === (string) $code ? '—' : '<code>' . esc_html( $code ) . '</code>';
	}

	/**
	 * Pe telefon (șoferul, dispecerul) un tabel lat devine carduri: fiecare rând pe un bloc, cu eticheta
	 * coloanei în fața valorii, fără derulare laterală. La printare rămâne tabel.
	 */
	private static function stack_table_style( $class, array $labels ) {
		$t   = '.' . $class;
		$css = $t . ' code{white-space:nowrap}' . $t . ' td a{text-decoration:underline}@media screen and (max-width:782px){'
			. $t . ' thead{display:none}'
			. $t . ',' . $t . ' tbody,' . $t . ' tfoot,' . $t . ' tr,' . $t . ' td,' . $t . ' tfoot th{display:block;width:auto!important;box-sizing:border-box}'
			. $t . ' tr{padding:8px 0;border-bottom:1px solid #dcdcde}'
			. $t . ' td,' . $t . ' tfoot th{padding:3px 12px!important;text-align:left}'
			. $t . ' td:empty,' . $t . ' tfoot th:empty{display:none}'
			. $t . ' td::before{font-weight:600;color:#50575e;margin-right:4px}';
		foreach ( array_values( $labels ) as $i => $label ) {
			if ( '' !== $label ) {
				$css .= $t . ' td:nth-child(' . ( $i + 1 ) . ')::before{content:"' . str_replace( array( '\\', '"', '<' ), array( '\\\\', '\\"', '' ), $label ) . ':"}';
			}
		}
		$css .= $t . ' td[colspan]::before{content:none}'; // rândul „nicio rezervare” nu e o coloană
		echo '<style>' . $css . '}</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * „achitat online” sau „la urcare: 480 MDL”; adună sumele de încasat pe monedă.
	 */
	private static function payment_label( array $b, array &$due ) {
		if ( 'reserved' !== $b['status'] ) {
			return '<span class="lbb-ok">' . esc_html__( 'achitat online', 'libertbus-bilete' ) . '</span>';
		}
		list( $amount, $cur ) = LBB_Bookings::pay_amount( $b );
		$due[ $cur ] = ( isset( $due[ $cur ] ) ? $due[ $cur ] : 0 ) + $amount;
		return '<span class="lbb-warn">' . esc_html( sprintf( __( 'la urcare: %s', 'libertbus-bilete' ), LBB_WooCommerce::money( $amount, $cur ) ) ) . '</span>';
	}

	public static function new_preview_link() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'lbb_new_preview_link' ) ) {
			wp_die( esc_html__( 'Nu aveți acces.', 'libertbus-bilete' ) );
		}
		LBB_Settings::preview_token( true );
		self::redirect( 'lbb-settings', __( 'Link nou de previzualizare creat. Linkul vechi nu mai funcționează.', 'libertbus-bilete' ) );
	}

	public static function cancel_booking() {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'lbb_cancel_booking_' . $id ) ) {
			wp_die( esc_html__( 'Nu aveți acces.', 'libertbus-bilete' ) );
		}
		$ok = LBB_Bookings::cancel( $id );
		$sent = $ok && LBB_Tickets::send_cancellation_email( LBB_Bookings::get( $id ) );
		// Înapoi unde era biroul: același filtru și aceeași căutare (ex. după telefonul clientului care a sunat).
		$back_status = isset( $_POST['back_status'] ) ? sanitize_key( wp_unslash( $_POST['back_status'] ) ) : 'reserved';
		$back_q      = isset( $_POST['back_q'] ) ? sanitize_text_field( wp_unslash( $_POST['back_q'] ) ) : '';
		$back        = array();
		if ( in_array( $back_status, array( 'confirmed', 'reserved', 'pending', 'cancelled', 'hold' ), true ) ) {
			$back['status'] = $back_status;
		}
		if ( '' !== $back_q ) {
			$back['q'] = rawurlencode( $back_q );
		}
		self::redirect( 'lbb-bookings', $ok ? __( 'Rezervarea a fost anulată, locurile sunt libere.', 'libertbus-bilete' ) . ( $sent ? ' ' . __( 'Clientul a fost anunțat prin email.', 'libertbus-bilete' ) : '' ) : __( 'Rezervarea nu a putut fi anulată (poate e deja plătită sau anulată).', 'libertbus-bilete' ), ! $ok, $back );
	}

	public static function manifest_csv() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'lbb_manifest_csv' ) ) {
			wp_die( esc_html__( 'Nu aveți acces.', 'libertbus-bilete' ) );
		}
		$date  = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : LBB_Settings::today();
		$route = isset( $_GET['route'] ) ? (int) $_GET['route'] : 0;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			wp_die( 'Data invalidă' );
		}
		// Cu ruta aleasă, numele fișierului o spune (ex. pasageri-2026-10-21-balti-iasi.csv): listele mai multor
		// rute din aceeași zi nu se mai confundă în „Descărcări”.
		$name = 'pasageri-' . $date;
		$r    = $route ? LBB_Routes::get( $route ) : null;
		if ( $r ) {
			$name .= '-' . sanitize_title( remove_accents( $r['origin'] . ' ' . $r['destination'] ) );
		}
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		// Separator, ghilimele și fără caracter de escape (RFC 4180), explicit: implicitul se schimbă în PHP 8.4+.
		fputcsv( $out, array( 'Data', 'Ora', 'Plecare', 'Destinatie', 'Bilet', 'Locuri', 'Pasageri', 'Telefon', 'Email', 'Plata', 'Comanda' ), ',', '"', '' );
		foreach ( LBB_Bookings::manifest( $date, $route ) as $b ) {
			list( $amount, $cur ) = LBB_Bookings::pay_amount( $b );
			$plata = 'reserved' === $b['status'] ? 'la urcare ' . $amount . ' ' . $cur : 'online';
			fputcsv( $out, array_map( array( __CLASS__, 'csv_safe' ), array( $date, $b['dep_time'], $b['origin'], $b['destination'], $b['ticket_code'], $b['seats'], implode( '; ', LBB_Bookings::passenger_labels( $b ) ), $b['phone'], $b['email'], $plata, $b['order_id'] ) ), ',', '"', '' );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Previne formulele în Excel când un client își scrie numele „=…”.
	 * Un „+” la început rămâne doar la un telefon curat (+373 69 …); „+1+cmd|…” e tot formulă.
	 */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) && ! preg_match( '/^\+[\d\s().\-]+$/', $value ) ? "'" . $value : $value;
	}

	public static function page_bookings() {
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		self::header( __( 'Rezervări', 'libertbus-bilete' ) );
		$labels = array(
			''          => __( 'Toate', 'libertbus-bilete' ),
			'confirmed' => __( 'Plătite', 'libertbus-bilete' ),
			'reserved'  => __( 'Rezervate (plata la urcare)', 'libertbus-bilete' ),
			'pending'   => __( 'Așteaptă plata', 'libertbus-bilete' ),
			'cancelled' => __( 'Anulate', 'libertbus-bilete' ),
			'hold'      => __( 'În coș', 'libertbus-bilete' ),
		);
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $labels as $key => $label ) {
			// Filtrul păstrează căutarea: „Anulate” după ce ai căutat un telefon arată anulările acelui client.
			$args = array( 'page' => 'lbb-bookings' );
			if ( $key ) {
				$args['status'] = $key;
			}
			if ( '' !== $search ) {
				$args['q'] = rawurlencode( $search );
			}
			$links[] = '<a href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '"' . ( $status === $key ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		// Separatorul „|” în interiorul <li>, ca în listele WordPress (o listă nu poate avea text direct).
		echo '<li>' . implode( ' |</li><li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
		// Căutare pentru telefon: clientul sună cu codul biletului, numărul de telefon sau numele.
		echo '<style>.lbb-search{float:right;margin:8px 0}@media screen and (max-width:782px){.lbb-search{float:none;clear:both;display:flex;gap:6px;width:100%;margin:8px 0 12px}.lbb-search input[type=search]{flex:1;min-width:0}}</style>';
		echo '<form method="get" class="lbb-search"><input type="hidden" name="page" value="lbb-bookings">';
		if ( $status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		}
		echo '<label class="screen-reader-text" for="lbb-q">' . esc_html__( 'Caută după cod, telefon, nume sau email', 'libertbus-bilete' ) . '</label>'
			. '<input type="search" id="lbb-q" name="q" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Cod, telefon, nume, email', 'libertbus-bilete' ) . '"> '
			. '<button class="button">' . esc_html__( 'Caută', 'libertbus-bilete' ) . '</button></form><br class="clear">';
		$rows = LBB_Bookings::recent( 200, $status, $search );
		self::stack_table_style( 'lbb-bookings-table', array( '#', __( 'Cursa', 'libertbus-bilete' ), __( 'Locuri', 'libertbus-bilete' ), __( 'Stare', 'libertbus-bilete' ), __( 'Bilet', 'libertbus-bilete' ), __( 'Client', 'libertbus-bilete' ), __( 'Comanda', 'libertbus-bilete' ), __( 'Creată', 'libertbus-bilete' ) ) );
		echo '<table class="widefat striped lbb-bookings-table"><thead><tr><th>#</th><th>' . esc_html__( 'Cursa', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Locuri', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Stare', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Bilet', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Client', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Comanda', 'libertbus-bilete' ) . '</th><th>' . esc_html__( 'Creată', 'libertbus-bilete' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Acțiuni', 'libertbus-bilete' ) . '</span></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="9">' . esc_html( '' !== $search ? __( 'Nicio rezervare găsită pentru această căutare.', 'libertbus-bilete' ) : __( 'Nicio rezervare.', 'libertbus-bilete' ) ) . '</td></tr>';
		}
		foreach ( $rows as $b ) {
			$order = $b['order_id'] && function_exists( 'wc_get_order' ) ? wc_get_order( $b['order_id'] ) : null;
			echo '<tr><td>' . esc_html( $b['id'] ) . '</td><td>' . esc_html( $b['origin'] . ' → ' . $b['destination'] ) . '<br>' . esc_html( wp_date( 'd.m.Y', strtotime( $b['travel_date'] . ' 12:00' ) ) . ' ' . $b['dep_time'] ) . '</td><td>' . esc_html( $b['seats'] ) . '</td><td>' . esc_html( isset( $labels[ $b['status'] ] ) ? $labels[ $b['status'] ] : $b['status'] ) . '</td><td>' . self::ticket_code_html( $b['ticket_code'] ) . '</td><td>' . esc_html( implode( ', ', LBB_Bookings::passenger_labels( $b ) ) ) . '<br>' . esc_html( $b['phone'] . ' ' . $b['email'] ) . '</td><td>';
			if ( $order ) {
				echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> (' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . ')';
			}
			echo '</td><td>' . esc_html( get_date_from_gmt( $b['created_at'], 'd.m.Y H:i' ) ) . '</td><td>';
			if ( 'reserved' === $b['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( is_email( $b['email'] ) ? __( 'Anulați rezervarea? Locurile devin libere, iar clientul primește un email că rezervarea e anulată.', 'libertbus-bilete' ) : __( 'Anulați rezervarea? Locurile devin libere. Clientul nu are email: anunțați-l la telefon.', 'libertbus-bilete' ) ) . '\');"><input type="hidden" name="action" value="lbb_cancel_booking"><input type="hidden" name="id" value="' . esc_attr( $b['id'] ) . '">'
					. '<input type="hidden" name="back_status" value="' . esc_attr( $status ) . '"><input type="hidden" name="back_q" value="' . esc_attr( $search ) . '">';
				wp_nonce_field( 'lbb_cancel_booking_' . $b['id'] );
				echo '<button class="button button-small">' . esc_html__( 'Anulează', 'libertbus-bilete' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_settings() {
		self::header( __( 'Setări LibertBus', 'libertbus-bilete' ) );
		$s = LBB_Settings::all();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'MDL';
		$num = function ( $key, $label, $help = '', $step = '1' ) use ( $s ) {
			echo '<tr><th><label for="lbb-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input id="lbb-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="number" min="0" step="' . esc_attr( $step ) . '" value="' . esc_attr( $s[ $key ] ) . '">';
			if ( $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		};
		$text = function ( $key, $label, $help = '' ) use ( $s ) {
			echo '<tr><th><label for="lbb-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" id="lbb-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="regular-text" value="' . esc_attr( $s[ $key ] ) . '">';
			if ( $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		};
		$check = function ( $key, $label, $help = '' ) use ( $s ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input name="' . esc_attr( $key ) . '" type="checkbox" value="1" ' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $help ) . '</label></td></tr>';
		};
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="lbb_save_settings">
			<?php wp_nonce_field( 'lbb_save_settings' ); ?>
			<h2><?php esc_html_e( 'Vânzare', 'libertbus-bilete' ); ?></h2>
			<table class="form-table">
				<?php
				$num( 'default_capacity', __( 'Locuri online pe cursă (implicit)', 'libertbus-bilete' ), __( 'Câte locuri se pot vinde online la fiecare plecare, dacă ruta nu are altă valoare.', 'libertbus-bilete' ) );
				$num( 'max_passengers', __( 'Maxim pasageri pe o rezervare', 'libertbus-bilete' ) );
				$num( 'cutoff_minutes', __( 'Închide vânzarea cu (minute) înainte de plecare', 'libertbus-bilete' ) );
				$num( 'max_days_ahead', __( 'Vânzare cu cel mult (zile) înainte', 'libertbus-bilete' ) );
				$num( 'cart_hold_minutes', __( 'Locurile se țin în coș (minute)', 'libertbus-bilete' ) );
				$num( 'payment_minutes', __( 'Locurile se țin cât se așteaptă plata (minute)', 'libertbus-bilete' ), __( 'După acest timp, dacă banca nu a confirmat plata, locurile se eliberează.', 'libertbus-bilete' ) );
				$check( 'allow_pay', __( 'Plata online cu cardul', 'libertbus-bilete' ), __( 'Arată butonul „Achit online cu cardul”. Porniți-l doar după ce metoda de plată a băncii (ex. Paynet) e activă și testată.', 'libertbus-bilete' ) );
				$check( 'allow_reserve', __( 'Rezervare fără plată', 'libertbus-bilete' ), __( 'Butonul „Rezerv, achit la urcare” lângă „Achit online cu cardul”.', 'libertbus-bilete' ) );
				$num( 'reserve_limit', __( 'Rezervări neachitate pe un telefon', 'libertbus-bilete' ), __( 'Câte rezervări fără plată poate avea un număr de telefon în același timp (0 = fără limită). Oprește blocarea locurilor de către glumeți.', 'libertbus-bilete' ) );
				echo '<tr><th>' . esc_html__( 'Clientul poate plăti în', 'libertbus-bilete' ) . '</th><td><input type="hidden" name="pay_currencies[]" value="">';
				foreach ( LBB_Settings::currencies() as $cur ) {
					echo '<label style="margin-right:12px"><input type="checkbox" name="pay_currencies[]" value="' . esc_attr( $cur ) . '" ' . checked( in_array( $cur, LBB_Settings::pay_currencies(), true ), true, false ) . '> ' . esc_html( $cur ) . '</label>';
				}
				echo '<p class="description">' . esc_html__( 'Implicit se propune moneda rutei (MDL spre România, RON spre Moldova); clientul poate schimba. Prețul se convertește după cursurile de mai jos.', 'libertbus-bilete' ) . '</p></td></tr>';
				$text( 'replace_cf7', __( 'Înlocuiește formularele Contact Form 7 (ID-uri)', 'libertbus-bilete' ), __( 'ID-urile formularelor de rezervare (ex. de pe pagina principală) care se afișează ca formularul de rezervare cu plată. Paginile nu se modifică; ștergeți ID-ul ca să reveniți.', 'libertbus-bilete' ) );
				$text( 'preview_cf7', __( 'Previzualizare: formulare Contact Form 7 (ID-uri)', 'libertbus-bilete' ), __( 'În previzualizare (pagini private pentru admin sau linkul secret de mai jos) aceste formulare și cele de rută se înlocuiesc, cu butonul de plată vizibil. Ceilalți vizitatori nu văd nimic schimbat.', 'libertbus-bilete' ) );
				$preview = LBB_Settings::preview_url();
				echo '<tr><th>' . esc_html__( 'Link de previzualizare (fără login)', 'libertbus-bilete' ) . '</th><td><input type="text" class="large-text code" readonly onclick="this.select()" aria-label="' . esc_attr__( 'Link de previzualizare (fără login)', 'libertbus-bilete' ) . '" value="' . esc_attr( $preview ) . '"> <p class="description">' . esc_html__( 'Deschideți-l pe telefon sau trimiteți-l cuiva. Merge pe orice pagină: adăugați ?lbb_preview=… și la paginile de rută. Butonul de mai jos (sub formular) creează un link nou și îl anulează pe cel vechi.', 'libertbus-bilete' ) . ' <a href="' . esc_url( $preview ) . '" target="_blank" rel="noopener">' . esc_html__( 'Deschide', 'libertbus-bilete' ) . '</a></p></td></tr>';
				$text( 'accent_color', __( 'Culoarea butoanelor', 'libertbus-bilete' ), __( 'Cod hex, ex. #00875a (verdele site-ului, mai închis ca textul alb să se citească bine).', 'libertbus-bilete' ) );
				$check( 'replace_cf7_routes', __( 'Formularele de rută', 'libertbus-bilete' ), __( 'Formularele Contact Form 7 cu titlul „Oraș - Oraș” (ex. „Balti - Iasi”) devin formularul rutei respective, dacă ruta există.', 'libertbus-bilete' ) );
				$check( 'show_approx', __( 'Echivalent în altă monedă', 'libertbus-bilete' ), __( 'Arată lângă preț „≈ 62 RON” (sau „≈ 234 MDL”), doar informativ.', 'libertbus-bilete' ) );
				$check( 'require_names', __( 'Numele pasagerilor', 'libertbus-bilete' ), __( 'Obligatoriu numele fiecărui pasager (util la vamă).', 'libertbus-bilete' ) );
				$check( 'simple_checkout', __( 'Plată simplificată', 'libertbus-bilete' ), __( 'Fără adresă poștală la plata biletelor: doar nume, telefon, email, țară.', 'libertbus-bilete' ) );
				$check( 'autocomplete', __( 'Finalizare automată', 'libertbus-bilete' ), __( 'Comanda plătită devine „Finalizată” și clientul primește imediat emailul cu biletul.', 'libertbus-bilete' ) );
				$check( 'test_gateway', __( 'Plată de test', 'libertbus-bilete' ), __( 'Metodă de plată falsă, vizibilă doar administratorilor, pentru verificări.', 'libertbus-bilete' ) );
				$check( 'cookie_banner', __( 'Banner cookies', 'libertbus-bilete' ), __( 'Întreabă vizitatorii („Accept toate” / „Doar necesare”) și blochează Google Analytics și alte scripturi de statistică până la acord. Un link spre #lbb-cookies (ex. în meniul de jos) redeschide bannerul.', 'libertbus-bilete' ) );
				$check( 'footer_links', __( 'Linkuri în subsol', 'libertbus-bilete' ), __( 'Sub textul de copyright: paginile legale publicate și „Setări cookies”.', 'libertbus-bilete' ) );
				?>
			</table>
			<h2><?php esc_html_e( 'Cursuri valutare', 'libertbus-bilete' ); ?></h2>
			<p class="description"><?php echo esc_html( sprintf( __( 'Câți lei moldovenești (MDL) valorează 1 unitate. Moneda de încasare a magazinului: %s.', 'libertbus-bilete' ), $currency ) ); ?></p>
			<table class="form-table">
				<?php
				foreach ( array( 'RON', 'EUR', 'USD' ) as $cur ) {
					$num( 'rate_' . $cur, '1 ' . $cur . ' = … MDL', '', '0.0001' );
				}
				?>
			</table>
			<h2><?php esc_html_e( 'Firma (apare pe paginile legale)', 'libertbus-bilete' ); ?></h2>
			<table class="form-table">
				<?php
				$text( 'company_name', __( 'Denumirea firmei', 'libertbus-bilete' ), __( 'ex. „Libert Tur” SRL', 'libertbus-bilete' ) );
				$text( 'company_idno', __( 'IDNO / cod fiscal', 'libertbus-bilete' ) );
				$text( 'company_address', __( 'Adresa juridică', 'libertbus-bilete' ) );
				$text( 'company_email', __( 'Email pentru clienți', 'libertbus-bilete' ) );
				$text( 'support_phone', __( 'Telefon pentru clienți', 'libertbus-bilete' ) );
				?>
				<tr><th><label for="lbb-ticket_notes"><?php esc_html_e( 'Text pe bilet', 'libertbus-bilete' ); ?></label></th><td><textarea id="lbb-ticket_notes" name="ticket_notes" rows="4" class="large-text"><?php echo esc_textarea( $s['ticket_notes'] ); ?></textarea></td></tr>
			</table>
			<h2><?php esc_html_e( 'Dezinstalare', 'libertbus-bilete' ); ?></h2>
			<table class="form-table"><?php $check( 'delete_on_uninstall', __( 'Șterge datele', 'libertbus-bilete' ), __( 'La ștergerea plugin-ului se șterg și rutele și rezervările.', 'libertbus-bilete' ) ); ?></table>
			<?php submit_button(); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:-8px 0 16px">
			<input type="hidden" name="action" value="lbb_new_preview_link">
			<?php wp_nonce_field( 'lbb_new_preview_link' ); ?>
			<?php submit_button( __( 'Link nou de previzualizare', 'libertbus-bilete' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php LBB_Legal::settings_box(); ?>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( self::cap() ) || ! check_admin_referer( 'lbb_save_settings' ) ) {
			wp_die( esc_html__( 'Nu aveți acces.', 'libertbus-bilete' ) );
		}
		LBB_Settings::save( wp_unslash( $_POST ) );
		self::redirect( 'lbb-settings', __( 'Setările au fost salvate.', 'libertbus-bilete' ) );
	}
}
