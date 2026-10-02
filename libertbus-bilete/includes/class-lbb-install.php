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
		if ( ! wp_next_scheduled( 'lbb_cleanup' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'lbb_cleanup' );
		}
		// Produsul se creează la prima încărcare cu WooCommerce activ.
		update_option( 'lbb_needs_product', 1 );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'lbb_cleanup' );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'lbb_db_version' ) !== LBB_DB_VERSION ) {
			self::create_tables();
			update_option( 'lbb_db_version', LBB_DB_VERSION );
		}
		if ( get_option( 'lbb_needs_product' ) && class_exists( 'WooCommerce' ) ) {
			add_action( 'init', array( __CLASS__, 'ensure_product' ), 20 );
		}
		add_action( 'lbb_cleanup', array( 'LBB_Bookings', 'cleanup' ) );
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
			expires_at datetime NULL,
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
			$id = $product->save();
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
