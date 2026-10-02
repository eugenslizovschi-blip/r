<?php
/**
 * Metodă de plată de test, vizibilă doar administratorilor.
 * Simulează o plată reușită ca să verificați tot drumul până la bilet, fără bancă.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Gateway_Test extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'lbb_test';
		$this->method_title       = __( 'LibertBus — plată de test', 'libertbus-bilete' );
		$this->method_description = __( 'Doar pentru verificări. O văd numai administratorii logați, iar comanda se marchează plătită fără bani reali. Se pornește din LibertBus → Setări.', 'libertbus-bilete' );
		$this->title              = __( 'Plată de test (doar administratori)', 'libertbus-bilete' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->enabled            = LBB_Settings::get( 'test_gateway' ) ? 'yes' : 'no';
	}

	public function is_available() {
		return 'yes' === $this->enabled && current_user_can( 'manage_woocommerce' );
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wc_add_notice( __( 'Plata de test e permisă doar administratorilor.', 'libertbus-bilete' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$order->add_order_note( __( 'Plată de test LibertBus (fără bani reali).', 'libertbus-bilete' ) );
		$order->payment_complete( 'TEST-' . time() );
		WC()->cart->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
