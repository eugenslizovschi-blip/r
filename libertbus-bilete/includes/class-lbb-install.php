<?php
/**
 * Instalare: tabele, orarul inițial, produsul „Bilet autocar”, cron.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Install {

	const PRODUCT_OPTION = 'lbb_ticket_product_id';

	public static function activate() {
		self::create_tables();
		self::seed_routes();
		update_option( 'lbb_db_version', LBB_DB_VERSION );
		self::ensure_cron();
		// Produsul se creează la prima încărcare cu WooCommerce activ.
		update_option( 'lbb_needs_product', 1 );
	}

	/**
	 * Curățarea orară (coșuri expirate, ștergerea datelor după 3 ani) se programează la activare. Dacă plugin-ul
	 * e actualizat prin suprascrierea fișierelor (fără reactivare) sau programarea se pierde, o refacem singuri.
	 */
	public static function ensure_cron() {
		if ( ! wp_next_scheduled( 'lbb_cleanup' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'lbb_cleanup' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'lbb_cleanup' );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'lbb_db_version' ) !== LBB_DB_VERSION ) {
			self::create_tables();
			update_option( 'lbb_db_version', LBB_DB_VERSION );
			// Versiunea 5: codurile biletelor și pe comenzile plătite înainte (căutarea din WooCommerce → Comenzi).
			add_action( 'init', array( __CLASS__, 'backfill_codes' ), 30 );
		}
		if ( get_option( 'lbb_needs_product' ) && class_exists( 'WooCommerce' ) ) {
			add_action( 'init', array( __CLASS__, 'ensure_product' ), 20 );
		}
		add_action( 'lbb_cleanup', array( 'LBB_Bookings', 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ) );
		// O trimitere de email eșuată (ex. hostingul blochează mail()) apare în panoul „Gata de plăți?”.
		add_action( 'wp_mail_failed', array( 'LBB_Tickets', 'mail_failed' ) );
		add_filter( 'woocommerce_email_styles', array( 'LBB_Tickets', 'email_styles' ) );
		// Unelte → Exportă / Șterge datele personale (cererile clienților, Legea 195/2024 și GDPR).
		add_filter( 'wp_privacy_personal_data_exporters', function ( $exporters ) {
			$exporters['libertbus-bilete'] = array( 'exporter_friendly_name' => __( 'Rezervări LibertBus', 'libertbus-bilete' ), 'callback' => array( 'LBB_Bookings', 'privacy_export' ) );
			return $exporters;
		} );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( 'LBB_Bookings', 'on_order_anonymized' ) );
		add_filter( 'wp_privacy_personal_data_erasers', function ( $erasers ) {
			$erasers['libertbus-bilete'] = array( 'eraser_friendly_name' => __( 'Rezervări LibertBus', 'libertbus-bilete' ), 'callback' => array( 'LBB_Bookings', 'privacy_erase' ) );
			return $erasers;
		} );
	}

	/**
	 * Codurile biletelor („LB-…”) puse pe comenzile plătite care nu le au încă (din versiunile dinainte), ca
	 * biroul să le găsească și în căutarea din WooCommerce → Comenzi. Comenzile care le au rămân neatinse.
	 */
	public static function backfill_codes() {
		global $wpdb;
		if ( ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}
		$rows = $wpdb->get_results( 'SELECT order_id, GROUP_CONCAT(ticket_code ORDER BY id SEPARATOR \' \') AS codes FROM ' . LBB_Bookings::table() . " WHERE order_id > 0 AND ticket_code <> '' GROUP BY order_id ORDER BY order_id DESC LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$done = 0;
		foreach ( (array) $rows as $row ) {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( $order && '' === (string) $order->get_meta( '_lbb_codes' ) ) {
				$order->update_meta_data( '_lbb_codes', (string) $row['codes'] );
				$order->save_meta_data();
				$done++;
			}
		}
		return $done;
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$wpdb->prefix}lbb_routes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			origin varchar(100) NOT NULL,
			destination varchar(100) NOT NULL,
			departures varchar(255) NOT NULL DEFAULT '',
			weekdays varchar(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
			price decimal(10,2) NOT NULL DEFAULT 0,
			child_price decimal(10,2) DEFAULT NULL,
			currency char(3) NOT NULL DEFAULT 'MDL',
			capacity smallint(5) unsigned NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			page_url varchar(255) NOT NULL DEFAULT '',
			boarding varchar(255) NOT NULL DEFAULT '',
			notes text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY origin (origin),
			KEY active (active)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}lbb_bookings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			route_id bigint(20) unsigned NOT NULL,
			travel_date date NOT NULL,
			dep_time char(5) NOT NULL,
			seats smallint(5) unsigned NOT NULL DEFAULT 1,
			children smallint(5) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'hold',
			token varchar(64) NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			item_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ticket_code varchar(20) NOT NULL DEFAULT '',
			passengers text NULL,
			phone varchar(40) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			amount decimal(10,2) NOT NULL DEFAULT 0,
			currency char(3) NOT NULL DEFAULT '',
			pay_currency char(3) NOT NULL DEFAULT '',
			expires_at datetime NULL,
			reminded_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY departure (route_id,travel_date,dep_time,status),
			KEY order_id (order_id),
			KEY ticket_code (ticket_code)
		) $charset;" );
	}

	/**
	 * Importă orarul inițial doar dacă tabela e goală.
	 */
	public static function seed_routes() {
		global $wpdb;
		$table = $wpdb->prefix . 'lbb_routes';
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		$rows = include LBB_DIR . 'data/routes-seed.php';
		$seen = array();
		foreach ( $rows as $row ) {
			list( $origin, $destination, $times, $price, $currency, $url ) = $row;
			$key = $origin . '|' . $destination;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			LBB_Routes::save( array(
				'origin'      => $origin,
				'destination' => $destination,
				'departures'  => implode( ',', $times ),
				'price'       => $price,
				'currency'    => $currency,
				'page_url'    => $url,
				'active'      => 1,
			) );
		}
	}

	/**
	 * Produsul virtual ascuns prin care trec biletele în coș.
	 */
	public static function ensure_product() {
		$id      = (int) get_option( self::PRODUCT_OPTION );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product || 'trash' === $product->get_status() ) {
			$product = new WC_Product_Simple();
			$product->set_name( __( 'Bilet autocar', 'libertbus-bilete' ) );
			$product->set_slug( 'bilet-autocar-libertbus' );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_virtual( true );
			$product->set_sold_individually( false );
			$product->set_regular_price( '0' );
			$product->set_tax_status( 'none' );
			$product->set_reviews_allowed( false );
			$product->set_short_description( __( 'Bilet pentru transport de pasageri. Se cumpără din formularul de rezervare.', 'libertbus-bilete' ) );
			// La creare WooCommerce citește descrierea pentru salvare prin filtrul care o ascunde clientului: fără
			// filtru cât timp se salvează, altfel nota pentru administrator s-ar salva goală.
			$hide = has_filter( 'woocommerce_product_get_short_description', array( 'LBB_WooCommerce', 'short_description' ) );
			if ( false !== $hide ) {
				remove_filter( 'woocommerce_product_get_short_description', array( 'LBB_WooCommerce', 'short_description' ), $hide );
			}
			$id = $product->save();
			if ( false !== $hide ) {
				add_filter( 'woocommerce_product_get_short_description', array( 'LBB_WooCommerce', 'short_description' ), $hide, 2 );
			}
			update_option( self::PRODUCT_OPTION, $id );
		}
		delete_option( 'lbb_needs_product' );
	}

	public static function product_id() {
		$id = (int) get_option( self::PRODUCT_OPTION );
		if ( ! $id && class_exists( 'WooCommerce' ) ) {
			self::ensure_product();
			$id = (int) get_option( self::PRODUCT_OPTION );
		}
		return $id;
	}
}
