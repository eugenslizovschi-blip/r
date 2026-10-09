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
				<div class="lbb-ticket-qr" role="img" aria-label="<?php /* translators: %s: codul biletului */ echo esc_attr( sprintf( __( 'Cod QR al biletului %s', 'libertbus-bilete' ), $booking['ticket_code'] ) ); ?>" data-qr="<?php echo esc_attr( self::url( $booking['ticket_code'] ) ); ?>"></div>
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
		$office  = LBB_Settings::get( 'company_email' );
		$office  = is_email( $office ) ? $office : get_option( 'admin_email' );
		// Expeditor „LibertBus” (nu „WordPress”); răspunsul clientului ajunge la birou.
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from_header(), 'Reply-To: ' . $office );
		$body    = '<p>' . esc_html__( 'Rezervarea dumneavoastră este confirmată. Plata se face la urcare, la șofer.', 'libertbus-bilete' ) . '</p>'
			. self::html( $booking ) . self::notes_html();
		if ( is_email( $booking['email'] ) ) {
			// Codul în subiect: clientul îl găsește direct în lista de emailuri când sună la birou.
			/* translators: 1: codul rezervării, 2: ruta, 3: data și ora */
			self::mail_html( $booking['email'], sprintf( __( 'Rezervare %1$s — %2$s, %3$s', 'libertbus-bilete' ), $booking['ticket_code'], $name, $when ), $body, $headers );
		}
		$admin  = '<p>' . esc_html__( 'Rezervare nouă cu plata la urcare.', 'libertbus-bilete' ) . '</p>' . self::html( $booking )
			. '<p>' . esc_html__( 'Telefon', 'libertbus-bilete' ) . ': ' . esc_html( $booking['phone'] ) . '<br>Email: ' . esc_html( $booking['email'] ) . '</p>'
			// Linkul deschide direct rezervarea aceasta (căutare după cod), nu lista cu ultimele 200.
			. '<p><a href="' . esc_url( add_query_arg( array( 'page' => 'lbb-bookings', 'q' => rawurlencode( $booking['ticket_code'] ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Deschide rezervarea în admin', 'libertbus-bilete' ) . '</a></p>';
		/* translators: 1: cod, 2: ruta, 3: data și ora */
		// Biroul răspunde direct clientului.
		$office_headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from_header() );
		if ( is_email( $booking['email'] ) ) {
			$office_headers[] = 'Reply-To: ' . $booking['email'];
		}
		self::mail_html( $office, sprintf( __( '[LibertBus] Rezervare %1$s — %2$s, %3$s', 'libertbus-bilete' ), $booking['ticket_code'], $name, $when ), $admin, $office_headers );
	}

	/**
	 * Biroul a anulat o rezervare cu plata la urcare: clientul află, ca să nu vină degeaba la autocar.
	 */
	public static function send_cancellation_email( array $booking ) {
		if ( ! is_email( $booking['email'] ) ) {
			return false;
		}
		$route  = LBB_Routes::get( $booking['route_id'] );
		$name   = $route ? $route['origin'] . ' → ' . $route['destination'] : '';
		$when   = wp_date( 'd.m.Y', strtotime( $booking['travel_date'] . ' 12:00' ) ) . ' ' . $booking['dep_time'];
		$office = LBB_Settings::get( 'company_email' );
		$office = is_email( $office ) ? $office : get_option( 'admin_email' );
		$body   = '<p>' . sprintf(
			/* translators: 1: codul rezervării, 2: ruta, 3: data și ora */
			esc_html__( 'Rezervarea %1$s pentru %2$s, %3$s a fost anulată. Locurile nu mai sunt păstrate.', 'libertbus-bilete' ),
			'<strong>' . esc_html( $booking['ticket_code'] ) . '</strong>',
			esc_html( $name ),
			esc_html( $when )
		) . '</p>';
		if ( trim( (string) LBB_Settings::get( 'support_phone' ) ) ) {
			/* translators: %s: telefon */
			$body .= '<p>' . sprintf( esc_html__( 'Pentru o rezervare nouă sau întrebări sunați la %s.', 'libertbus-bilete' ), LBB_Settings::phone_link() ) . '</p>';
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from_header(), 'Reply-To: ' . $office );
		/* translators: 1: codul rezervării, 2: ruta, 3: data și ora */
		return self::mail_html( $booking['email'], sprintf( __( 'Rezervare anulată %1$s — %2$s, %3$s', 'libertbus-bilete' ), $booking['ticket_code'], $name, $when ), $body, $headers );
	}

	/**
	 * Email HTML cu varianta text alăturată (multipart/alternative): unele servicii de email privesc cu
	 * suspiciune mesajele doar HTML, iar unele aplicații de pe telefon le arată prost.
	 */
	public static function mail_html( $to, $subject, $html, $headers ) {
		$text = self::plain_text( $html );
		$alt  = function ( $mailer ) use ( $text ) {
			$mailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( $to, $subject, $html, $headers );
		remove_action( 'phpmailer_init', $alt );
		return $sent;
	}

	/**
	 * Textul unui email HTML: rânduri păstrate, „etichetă valoare” din tabele, linkurile ca „text (adresă)”.
	 */
	public static function plain_text( $html ) {
		$text = preg_replace_callback( '#<a\s[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is', function ( $m ) {
			$label = trim( wp_strip_all_tags( $m[3] ) );
			$url   = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
			return ( 0 === strpos( $url, 'tel:' ) || $label === $url ) ? $label : $label . ' (' . $url . ')';
		}, preg_replace( '/\s+/u', ' ', (string) $html ) );
		$text = preg_replace( '#<(br|/p|/div|/tr|/h[1-6]|/li)\b[^>]*>#i', "$0\n", $text );
		$text = preg_replace( '#</t[hd]>#i', '$0 ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( array( '/[ \t]+/', '/ *\n */', '/\n{3,}/' ), array( ' ', "\n", "\n\n" ), $text );
		return trim( $text );
	}

	/**
	 * „LibertBus <adresa obișnuită a site-ului>”: doar numele se schimbă, adresa rămâne cea a WordPress
	 * (sau a unui plugin SMTP), ca emailurile să nu ajungă în spam.
	 */
	public static function from_header() {
		$host = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$host = $host && 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$from = apply_filters( 'wp_mail_from', 'wordpress@' . $host );
		$name = trim( preg_replace( '/[\r\n"<>]+/', ' ', (string) LBB_Settings::get( 'company_name' ) ) );
		return ( '' !== $name ? $name : 'LibertBus' ) . ' <' . $from . '>';
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
			// Email text, nu HTML: fără entități (WooCommerce șterge „&#039;”, deci „D'Angelo” ar deveni „DAngelo”),
			// iar majusculele țin cont de diacritice („DUMNEAVOASTRĂ”, nu „DUMNEAVOASTRă”).
			$title = __( 'Biletele dumneavoastră', 'libertbus-bilete' );
			echo "\n" . wp_strip_all_tags( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $title, 'UTF-8' ) : strtoupper( $title ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			foreach ( $tickets as $booking ) {
				echo wp_strip_all_tags( $booking['ticket_code'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( LBB_WooCommerce::describe( $booking ) as $label => $value ) {
					echo wp_strip_all_tags( $label . ': ' . $value ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo esc_url_raw( self::url( $booking['ticket_code'] ) ) . "\n\n";
			}
			echo wp_strip_all_tags( (string) LBB_Settings::get( 'ticket_notes' ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
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
			// Confirmarea băncii poate veni la câteva secunde după revenirea pe site: clientul are ce apăsa.
			echo '<div class="woocommerce-info lbb-awaiting">' . esc_html__( 'Biletele se emit imediat după confirmarea plății și vă vin pe email. De obicei durează câteva secunde.', 'libertbus-bilete' )
				. ' <a class="button" href="' . esc_url( $order->get_checkout_order_received_url() ) . '">' . esc_html__( 'Verifică din nou', 'libertbus-bilete' ) . '</a></div>';
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
	/**
	 * Starea arătată sus pe pagina biletului. Un bilet al unei curse dintr-o zi trecută nu mai apare verde
	 * „valabil”: șoferul care scanează un bilet vechi vede imediat că acea cursă a avut loc. În ziua cursei
	 * biletul rămâne valabil toată ziua (autocarul poate pleca cu întârziere).
	 *
	 * @return array{class:string,text:string}
	 */
	public static function state( array $booking ) {
		if ( in_array( $booking['status'], array( 'hold', 'pending' ), true ) ) {
			// Ex. card refuzat, apoi clientul reîncearcă plata: biletul are deja cod, dar nu e anulat.
			return array( 'class' => 'bad', 'text' => __( 'Plata nu e finalizată — biletul nu e încă valabil', 'libertbus-bilete' ) );
		}
		if ( ! in_array( $booking['status'], array( 'confirmed', 'reserved' ), true ) ) {
			return array( 'class' => 'bad', 'text' => __( 'Bilet anulat', 'libertbus-bilete' ) );
		}
		if ( $booking['travel_date'] < LBB_Settings::today() ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $booking['travel_date'], LBB_Settings::tz() );
			/* translators: %s: data cursei, ex. 05.10.2026 */
			return array( 'class' => 'past', 'text' => sprintf( __( 'Cursa a avut loc pe %s', 'libertbus-bilete' ), $date ? $date->format( 'd.m.Y' ) : $booking['travel_date'] ) );
		}
		if ( 'reserved' === $booking['status'] ) {
			return array( 'class' => 'ok', 'text' => __( 'Rezervare confirmată — achitați la urcare', 'libertbus-bilete' ) );
		}
		return array( 'class' => 'ok', 'text' => __( 'Bilet valabil — achitat', 'libertbus-bilete' ) );
	}

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
		// Date personale: nimic în cache (nici la hosting), fără indexare, iar linkul cu cheia k= nu pleacă în Referer.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		status_header( $booking ? 200 : 404 );

		$state = $booking ? self::state( $booking ) : null;
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $booking ? $booking['ticket_code'] . ' — ' . get_bloginfo( 'name' ) : __( 'Bilet negăsit', 'libertbus-bilete' ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( LBB_URL . 'assets/lbb.css?ver=' . LBB_Frontend::asset_ver( 'lbb.css' ) ); ?>">
<style>
/* La printare starea rămâne: un bilet anulat, neplătit sau expirat nu trebuie să arate pe hârtie ca unul valabil. */
body{margin:0;padding:16px;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1d2733}
.wrap{max-width:560px;margin:0 auto}
.state{padding:10px 14px;border-radius:8px;margin-bottom:12px;font-weight:700}
.ok{background:#e7f6ec;color:#16632f}.bad{background:#fdecea;color:#8a1c13}.past{background:#eceff3;color:#3a4552}
.actions{display:flex;gap:8px;margin-top:8px}.actions button{flex:1;min-height:44px;border:1px solid #d7dbe0;border-radius:8px;background:#fff;font:inherit;cursor:pointer}
@media print{.actions{display:none}body{background:#fff}.state{border:2px solid currentColor}}
</style>
</head>
<body>
<div class="wrap">
	<p><strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong></p>
	<?php if ( ! $booking ) : ?>
		<div class="state bad"><?php esc_html_e( 'Biletul nu a fost găsit. Verificați linkul din email.', 'libertbus-bilete' ); ?></div>
		<?php /* translators: %s: telefonul pentru clienți */ ?>
		<p><?php echo sprintf( esc_html__( 'Dacă nu găsiți emailul, sunați-ne la %s și vă ajutăm după numele sau telefonul de la rezervare.', 'libertbus-bilete' ), LBB_Settings::phone_link() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
	<?php else : ?>
		<div class="state <?php echo esc_attr( $state['class'] ); ?>"><?php echo esc_html( $state['text'] ); ?></div>
		<?php echo self::html( $booking, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php echo self::notes_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="actions"><button type="button" onclick="window.print()"><?php esc_html_e( 'Printează', 'libertbus-bilete' ); ?></button></div>
		<script src="<?php echo esc_url( LBB_URL . 'assets/qrcode.min.js?ver=1.0.0' ); ?>"></script>
		<script>
		document.querySelectorAll('[data-qr]').forEach(function(el){
			if (window.QRCode) { new QRCode(el, {text: el.getAttribute('data-qr'), width: 132, height: 132, correctLevel: QRCode.CorrectLevel.M}); }
			// Textul e pe container (role="img"); imaginea și canvasul generate nu se mai citesc o dată.
			el.querySelectorAll('img').forEach(function(i){ i.setAttribute('alt', ''); });
			el.querySelectorAll('canvas').forEach(function(c){ c.setAttribute('aria-hidden', 'true'); });
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
