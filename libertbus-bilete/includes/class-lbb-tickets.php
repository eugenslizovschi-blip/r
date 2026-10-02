<?php
/**
 * Biletele: în email, pe pagina de mulțumire, în contul clientului și pe pagina de bilet cu cod QR.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Tickets {

	public static function init() {
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'email' ), 5, 4 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 5 );
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'order_details' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'ticket_page' ), 5 );
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'lbb_bilet';
			return $vars;
		} );
	}

	public static function signature( $code ) {
		return substr( hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ), 0, 20 );
	}

	public static function url( $code ) {
		return add_query_arg( array(
			'lbb_bilet' => rawurlencode( $code ),
			'k'         => self::signature( $code ),
		), home_url( '/' ) );
	}

	private static function confirmed( $order ) {
		return array_values( array_filter( LBB_Bookings::by_order( $order->get_id() ), function ( $b ) {
			return 'confirmed' === $b['status'] && $b['ticket_code'];
		} ) );
	}

	/**
	 * Un bilet ca tabel HTML simplu, cu stiluri inline ca să arate bine și în email.
	 */
	public static function html( array $booking, $with_qr = false ) {
		$rows = LBB_WooCommerce::describe( $booking );
		ob_start();
		?>
		<div class="lbb-ticket" style="border:2px dashed #c9ced6;border-radius:12px;padding:16px;margin:0 0 16px;background:#fff;color:#1d2733;">
			<?php if ( $with_qr ) : ?>
				<div class="lbb-ticket-qr" data-qr="<?php echo esc_attr( self::url( $booking['ticket_code'] ) ); ?>"></div>
			<?php endif; ?>
			<div style="font-size:13px;color:#5f6b7a;"><?php echo 'reserved' === $booking['status'] ? esc_html__( 'Rezervare LibertBus', 'libertbus-bilete' ) : esc_html__( 'Bilet LibertBus', 'libertbus-bilete' ); ?></div>
			<div class="lbb-ticket-code" style="font-family:Menlo,Consolas,monospace;font-size:22px;font-weight:700;letter-spacing:1px;margin:2px 0 10px;"><?php echo esc_html( $booking['ticket_code'] ); ?></div>
			<table style="width:100%;border-collapse:collapse;">
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<th style="text-align:left;padding:3px 10px 3px 0;vertical-align:top;white-space:nowrap;"><?php echo esc_html( $label ); ?></th>
						<td style="text-align:left;padding:3px 0;vertical-align:top;"><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php if ( ! $with_qr ) : ?>
				<p style="margin:10px 0 0;"><a href="<?php echo esc_url( self::url( $booking['ticket_code'] ) ); ?>"><?php echo 'reserved' === $booking['status'] ? esc_html__( 'Deschide rezervarea cu cod QR', 'libertbus-bilete' ) : esc_html__( 'Deschide biletul cu cod QR', 'libertbus-bilete' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Rezervare fără plată: email clientului (cu linkul rezervării) și biroului.
	 */
	public static function send_reservation_emails( array $booking ) {
		$route   = LBB_Routes::get( $booking['route_id'] );
		$name    = $route ? $route['origin'] . ' → ' . $route['destination'] : '';
		$when    = wp_date( 'd.m.Y', strtotime( $booking['travel_date'] . ' 12:00' ) ) . ' ' . $booking['dep_time'];
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$body    = '<p>' . esc_html__( 'Rezervarea dumneavoastră este confirmată. Plata se face la urcare, la șofer.', 'libertbus-bilete' ) . '</p>'
			. self::html( $booking ) . self::notes_html();
		if ( is_email( $booking['email'] ) ) {
			/* translators: 1: ruta, 2: data și ora */
			wp_mail( $booking['email'], sprintf( __( 'Rezervare %1$s, %2$s', 'libertbus-bilete' ), $name, $when ), $body, $headers );
		}
		$office = LBB_Settings::get( 'company_email' );
		$office = is_email( $office ) ? $office : get_option( 'admin_email' );
		$admin  = '<p>' . esc_html__( 'Rezervare nouă cu plata la urcare.', 'libertbus-bilete' ) . '</p>' . self::html( $booking )
			. '<p>' . esc_html__( 'Telefon', 'libertbus-bilete' ) . ': ' . esc_html( $booking['phone'] ) . '<br>Email: ' . esc_html( $booking['email'] ) . '</p>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=lbb-bookings&status=reserved' ) ) . '">' . esc_html__( 'Vezi rezervările', 'libertbus-bilete' ) . '</a></p>';
		/* translators: 1: cod, 2: ruta, 3: data și ora */
		wp_mail( $office, sprintf( __( '[LibertBus] Rezervare %1$s — %2$s, %3$s', 'libertbus-bilete' ), $booking['ticket_code'], $name, $when ), $admin, $headers );
	}

	/**
	 * Textul de pe bilet plus telefonul de suport (pentru anulare sau schimbarea datei).
	 */
	public static function notes_html() {
		$notes = trim( (string) LBB_Settings::get( 'ticket_notes' ) );
		$html  = $notes ? '<p style="color:#5f6b7a;">' . nl2br( esc_html( $notes ) ) . '</p>' : '';
		if ( trim( (string) LBB_Settings::get( 'support_phone' ) ) ) {
			/* translators: %s: telefon */
			$html .= '<p style="color:#5f6b7a;">' . sprintf( esc_html__( 'Anulare sau schimbarea datei: %s', 'libertbus-bilete' ), LBB_Settings::phone_link() ) . '</p>';
		}
		return $html;
	}

	public static function email( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( ! $order instanceof WC_Order || ! $order->has_status( array( 'processing', 'completed', 'on-hold' ) ) ) {
			return;
		}
		$tickets = self::confirmed( $order );
		if ( ! $tickets ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html( strtoupper( __( 'Biletele dumneavoastră', 'libertbus-bilete' ) ) ) . "\n\n";
			foreach ( $tickets as $booking ) {
				echo esc_html( $booking['ticket_code'] ) . "\n";
				foreach ( LBB_WooCommerce::describe( $booking ) as $label => $value ) {
					echo esc_html( $label . ': ' . $value ) . "\n";
				}
				echo esc_url_raw( self::url( $booking['ticket_code'] ) ) . "\n\n";
			}
			echo esc_html( LBB_Settings::get( 'ticket_notes' ) ) . "\n\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Biletele dumneavoastră', 'libertbus-bilete' ) . '</h2>';
		foreach ( $tickets as $booking ) {
			echo self::html( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo self::notes_html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( '_lbb_tickets' ) ) {
			return;
		}
		wp_enqueue_style( 'lbb' );
		$tickets = self::confirmed( $order );
		if ( ! $tickets ) {
			echo '<div class="woocommerce-info">' . esc_html__( 'Biletele se emit imediat după confirmarea plății și vă vin pe email.', 'libertbus-bilete' ) . '</div>';
			return;
		}
		echo '<h2>' . esc_html__( 'Biletele dumneavoastră', 'libertbus-bilete' ) . '</h2>';
		echo '<p>' . esc_html__( 'Le-am trimis și pe email. Arătați codul șoferului la urcare.', 'libertbus-bilete' ) . '</p>';
		foreach ( $tickets as $booking ) {
			echo self::html( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo self::notes_html(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function order_details( $order ) {
		if ( is_order_received_page() || ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( self::confirmed( $order ) as $booking ) {
			echo self::html( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Pagina biletului: /?lbb_bilet=LB-XXXXXX&k=semnătură. Se poate printa sau arăta de pe telefon.
	 */
	public static function ticket_page() {
		$code = get_query_var( 'lbb_bilet' );
		if ( ! $code ) {
			$code = isset( $_GET['lbb_bilet'] ) ? sanitize_text_field( wp_unslash( $_GET['lbb_bilet'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! $code ) {
			return;
		}
		$sig     = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$booking = hash_equals( self::signature( $code ), $sig ) ? LBB_Bookings::get_by_code( $code ) : null;
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		status_header( $booking ? 200 : 404 );

		$valid    = $booking && in_array( $booking['status'], array( 'confirmed', 'reserved' ), true );
		$reserved = $booking && 'reserved' === $booking['status'];
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $booking ? $booking['ticket_code'] . ' — ' . get_bloginfo( 'name' ) : __( 'Bilet negăsit', 'libertbus-bilete' ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( LBB_URL . 'assets/lbb.css?ver=' . LBB_VERSION ); ?>">
<style>
body{margin:0;padding:16px;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1d2733}
.wrap{max-width:560px;margin:0 auto}
.state{padding:10px 14px;border-radius:8px;margin-bottom:12px;font-weight:700}
.ok{background:#e7f6ec;color:#16632f}.bad{background:#fdecea;color:#8a1c13}
.actions{display:flex;gap:8px;margin-top:8px}.actions button{flex:1;min-height:44px;border:1px solid #d7dbe0;border-radius:8px;background:#fff;font:inherit;cursor:pointer}
@media print{.actions,.state{display:none}body{background:#fff}}
</style>
</head>
<body>
<div class="wrap">
	<p><strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong></p>
	<?php if ( ! $booking ) : ?>
		<div class="state bad"><?php esc_html_e( 'Biletul nu a fost găsit. Verificați linkul din email.', 'libertbus-bilete' ); ?></div>
	<?php else : ?>
		<div class="state <?php echo $valid ? 'ok' : 'bad'; ?>">
			<?php
			if ( $reserved ) {
				esc_html_e( 'Rezervare confirmată — achitați la urcare', 'libertbus-bilete' );
			} elseif ( $valid ) {
				esc_html_e( 'Bilet valabil — achitat', 'libertbus-bilete' );
			} else {
				esc_html_e( 'Bilet anulat', 'libertbus-bilete' );
			}
			?>
		</div>
		<?php echo self::html( $booking, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php echo self::notes_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="actions"><button type="button" onclick="window.print()"><?php esc_html_e( 'Printează', 'libertbus-bilete' ); ?></button></div>
		<script src="<?php echo esc_url( LBB_URL . 'assets/qrcode.min.js?ver=1.0.0' ); ?>"></script>
		<script>
		document.querySelectorAll('[data-qr]').forEach(function(el){
			if (window.QRCode) { new QRCode(el, {text: el.getAttribute('data-qr'), width: 132, height: 132, correctLevel: QRCode.CorrectLevel.M}); }
		});
		</script>
	<?php endif; ?>
</div>
</body>
</html>
		<?php
		exit;
	}
}
