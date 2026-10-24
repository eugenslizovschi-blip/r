<?php
/**
 * Plugin Name:       LibertBus Bilete
 * Description:       Vânzare online de bilete de autocar cu plata cu cardul prin WooCommerce: rute și orar, locuri disponibile, rezervare temporară, bilete pe email, listă de pasageri.
 * Version:           1.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            LibertBus
 * Text Domain:       libertbus-bilete
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

define( 'LBB_VERSION', '1.5.0' );
define( 'LBB_DB_VERSION', '6' );
define( 'LBB_FILE', __FILE__ );
define( 'LBB_DIR', plugin_dir_path( __FILE__ ) );
define( 'LBB_URL', plugin_dir_url( __FILE__ ) );

require_once LBB_DIR . 'includes/class-lbb-settings.php';
require_once LBB_DIR . 'includes/class-lbb-install.php';
require_once LBB_DIR . 'includes/class-lbb-routes.php';
require_once LBB_DIR . 'includes/class-lbb-bookings.php';
require_once LBB_DIR . 'includes/class-lbb-frontend.php';
require_once LBB_DIR . 'includes/class-lbb-rest.php';
require_once LBB_DIR . 'includes/class-lbb-woocommerce.php';
require_once LBB_DIR . 'includes/class-lbb-tickets.php';
require_once LBB_DIR . 'includes/class-lbb-admin.php';
require_once LBB_DIR . 'includes/class-lbb-legal.php';
require_once LBB_DIR . 'includes/class-lbb-cookies.php';

register_activation_hook( __FILE__, array( 'LBB_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LBB_Install', 'deactivate' ) );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', 'lbb_boot', 20 );

/**
 * Pornește plugin-ul. Fără WooCommerce rămâne doar notificarea din admin.
 */
function lbb_boot() {
	load_plugin_textdomain( 'libertbus-bilete', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	LBB_Install::maybe_upgrade();
	LBB_Admin::init();
	LBB_Legal::init();
	LBB_Cookies::init();

	if ( ! class_exists( 'WooCommerce' ) ) {
		// WooCommerce oprit (ex. temporar, la o depanare): pe paginile rutelor vizitatorul vede telefonul, nu textul
		// brut „[libertbus_rezervare …]”. WordPress < 6.5 (ca pe libertbus.md) nu oprește plugin-ul singur.
		$unavailable = function () {
			/* translators: %s: telefon */
			return '<p class="lbb-empty">' . sprintf( esc_html__( 'Rezervarea online nu este disponibilă momentan. Sunați la %s.', 'libertbus-bilete' ), LBB_Settings::phone_link() ) . '</p>';
		};
		add_shortcode( 'libertbus_rezervare', $unavailable );
		add_shortcode( 'lbb_booking', $unavailable );
		// Biletele deja vândute rămân deschise din linkul din email (codul QR se arată șoferului).
		add_action( 'template_redirect', array( 'LBB_Tickets', 'ticket_page' ), 5 );
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'lbb_bilet';
			return $vars;
		} );
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>LibertBus Bilete</strong>: ' . esc_html__( 'are nevoie de WooCommerce activ pentru a încasa plăți.', 'libertbus-bilete' ) . '</p></div>';
		} );
		return;
	}

	LBB_Frontend::init();
	LBB_Rest::init();
	LBB_WooCommerce::init();
	LBB_Tickets::init();

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		require_once LBB_DIR . 'includes/class-lbb-gateway-test.php';
		$gateways[] = 'LBB_Gateway_Test';
		return $gateways;
	} );
}
