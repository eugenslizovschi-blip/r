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
		add_filter( 'woocommerce_email_subject_customer_processing_order', array( __CLASS__, 'email_subject' ), 10, 3 );
		add_filter( 'woocommerce_email_subject_customer_completed_order', array( __CLASS__, 'email_subject' ), 10, 3 );
		add_filter( 'woocommerce_email_subject_new_order', array( __CLASS__, 'email_subject' ), 10, 3 );
		add_filter( 'woocommerce_email_subject_cancelled_order', array( __CLASS__, 'email_subject' ), 10, 3 );
		add_filter( 'woocommerce_email_subject_failed_order', array( __CLASS__, 'email_subject' ), 10, 3 );
		add_filter( 'woocommerce_email_subject_customer_refunded_order', array( __CLASS__, 'email_subject' ), 10, 3 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 5 );
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'order_details' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'ticket_page' ), 5 );
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'lbb_bilet';
			return $vars;
		} );
	}

	/**
	 * Textul trimis cu „Trimite biletul” (WhatsApp, Viber…): cine primește vede cursa fără să deschidă linkul.
	 * Ex. „Bilet LibertBus LB-AB12CD: Bălți → Iași, 13.10.2026 (marți), 08:45”.
	 */
	public static function share_text( array $booking ) {
		$route = LBB_Routes::get( $booking['route_id'] );
		$args  = array( $booking['ticket_code'], LBB_Bookings::route_name( $route ? $route : array() ), LBB_WooCommerce::date_with_day( $booking['travel_date'] ), $booking['dep_time'] );
		// Ca pe pagina biletului: rezervarea cu plata la urcare nu e numită „bilet” (încă nu e plătită).
		if ( isset( $booking['status'] ) && 'reserved' === $booking['status'] ) {
			/* translators: 1: codul rezervării, 2: ruta, 3: ziua și data, 4: ora */
			$text = vsprintf( __( 'Rezervare LibertBus %1$s: %2$s, %3$s, %4$s', 'libertbus-bilete' ), $args );
		} else {
			/* translators: 1: codul biletului, 2: ruta, 3: ziua și data, 4: ora */
			$text = vsprintf( __( 'Bilet LibertBus %1$s: %2$s, %3$s, %4$s', 'libertbus-bilete' ), $args );
		}
		// Cine primește biletul (ex. copilul căruia i l-a trimis părintele) află și de unde urcă.
		if ( $route && '' !== $route['boarding'] ) {
			/* translators: %s: locul de urcare, ex. Autogara Nord, peronul 3 */
			$text .= '. ' . sprintf( __( 'Urcare: %s', 'libertbus-bilete' ), $route['boarding'] );
		}
		// La rezervarea cu plata la urcare: și suma, ca pasagerul să aibă banii pregătiți (ca în calendar).
		if ( isset( $booking['status'] ) && 'reserved' === $booking['status'] && isset( $booking['amount'] ) ) {
			list( $amount, $cur ) = LBB_Bookings::pay_amount( $booking );
			/* translators: %s: suma, ex. 240 MDL */
			$text .= '. ' . sprintf( __( 'De achitat la urcare: %s', 'libertbus-bilete' ), LBB_WooCommerce::money( $amount, $cur ) );
		}
		return $text;
	}

	public static function signature( $code ) {
		return substr( hash_hmac( 'sha256', $code, self::key() ), 0, 20 );
	}

	/**
	 * Cheia proprie a plugin-ului pentru linkurile biletelor. Nu folosim direct cheile WordPress: dacă sunt
	 * schimbate (recomandat după o parolă expusă), toate biletele și codurile QR trimise ar deveni „negăsite”.
	 */
	private static function key() {
		$key = (string) get_option( 'lbb_ticket_key', '' );
		if ( strlen( $key ) < 32 ) {
			// add_option, nu update: dacă două cereri o creează deodată, prima câștigă și ambele o folosesc pe ea.
			add_option( 'lbb_ticket_key', wp_generate_password( 64, true, true ), '', 'no' );
			$key = (string) get_option( 'lbb_ticket_key', '' );
		}
		return $key;
	}

	/**
	 * Semnătura veche (până la 1.5.0), din cheile WordPress: biletele deja trimise rămân valabile cât timp
	 * cheile nu se schimbă.
	 */
	private static function legacy_signature( $code ) {
		return substr( hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ), 0, 20 );
	}

	/**
	 * Linkul biletului e autentic: semnat cu cheia plugin-ului sau, pentru biletele vechi, cu cea veche.
	 *
	 * @param string $code Codul biletului.
	 * @param string $sig  Parametrul k= din link.
	 */
	public static function valid_signature( $code, $sig ) {
		$sig = (string) $sig;
		return '' !== $sig && ( hash_equals( self::signature( $code ), $sig ) || hash_equals( self::legacy_signature( $code ), $sig ) );
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
	 * Rezervare cu plata la urcare (activă sau anulată de birou): fără comandă WooCommerce. Biletele plătite au
	 * mereu o comandă, așa că o rezervare anulată rămâne „rezervare”, nu devine „bilet anulat”.
	 */
	public static function is_reservation( array $booking ) {
		return 'reserved' === $booking['status'] || ( 'cancelled' === $booking['status'] && empty( $booking['order_id'] ) );
	}

	/**
	 * „Adaugă în calendar” doar pentru un bilet valabil al unei curse care n-a plecat încă: după plecare (aceeași zi)
	 * ar pune în calendar un eveniment deja trecut. Nici pentru o rută ștearsă din admin: fișierul de calendar
	 * n-ar avea ce cursă să descrie, iar linkul doar ar reîncărca pagina.
	 */
	public static function can_add_to_calendar( array $booking, $state = null ) {
		$state = $state ? $state : self::state( $booking );
		return 'ok' === $state['class'] && ! LBB_Bookings::departed( $booking ) && null !== LBB_Routes::get( $booking['route_id'] );
	}

	/**
	 * Un bilet ca tabel HTML simplu, cu stiluri inline ca să arate bine și în email.
	 */
	public static function html( array $booking, $with_qr = false ) {
		$rows  = LBB_WooCommerce::describe( $booking );
		$state = self::state( $booking );
		ob_start();
		// break-inside: la printarea mai multor bilete (pagina de mulțumire, emailul) un bilet nu se rupe între două pagini.
		?>
		<div class="lbb-ticket" style="border:2px dashed #c9ced6;border-radius:12px;padding:16px;margin:0 0 16px;background:#fff;color:#1d2733;page-break-inside:avoid;break-inside:avoid;">
			<?php if ( $with_qr ) : ?>
				<?php // Rolul „imagine” și textul le pune scriptul după ce desenează codul: fără JavaScript locul e gol, iar cititorul de ecran nu anunță un cod care nu există. ?>
				<div class="lbb-ticket-qr" data-qr-label="<?php /* translators: %s: codul biletului */ echo esc_attr( sprintf( __( 'Cod QR al biletului %s', 'libertbus-bilete' ), $booking['ticket_code'] ) ); ?>" data-qr="<?php echo esc_attr( self::url( $booking['ticket_code'] ) ); ?>"></div>
			<?php endif; ?>
			<div style="font-size:13px;color:#5f6b7a;"><?php echo self::is_reservation( $booking ) ? esc_html__( 'Rezervare LibertBus', 'libertbus-bilete' ) : esc_html__( 'Bilet LibertBus', 'libertbus-bilete' ); ?></div>
			<div class="lbb-ticket-code" style="font-family:Menlo,Consolas,monospace;font-size:22px;font-weight:700;letter-spacing:1px;margin:2px 0 10px;"><?php echo esc_html( $booking['ticket_code'] ); ?></div>
			<table style="width:100%;border-collapse:collapse;">
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<?php // Eticheta ia cel mult 38%: pe telefonul îngust („De achitat la urcare”) trece pe două rânduri și lasă loc valorii, și în email (fără media queries). ?>
						<th scope="row" style="text-align:left;padding:3px 10px 3px 0;vertical-align:top;width:38%;"><?php echo esc_html( $label ); ?></th>
						<?php // Un nume lung („Popescu-Vasilescu-Andronachescu”) se rupe pe telefonul îngust, nu iese din bilet. ?>
						<td style="text-align:left;padding:3px 0;vertical-align:top;word-break:break-word;overflow-wrap:anywhere;"><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php if ( ! $with_qr ) : ?>
				<?php // Linkuri ușor de atins pe telefon (cel puțin 28px): inline-block, ca atunci când trec pe două rânduri zonele lor să nu se suprapună. ?>
				<p style="margin:10px 0 0;"><a href="<?php echo esc_url( self::url( $booking['ticket_code'] ) ); ?>" style="display:inline-block;padding:6px 0;"><?php echo self::is_reservation( $booking ) ? esc_html__( 'Deschide rezervarea cu cod QR', 'libertbus-bilete' ) : esc_html__( 'Deschide biletul cu cod QR', 'libertbus-bilete' ); ?></a>
				<?php if ( self::can_add_to_calendar( $booking, $state ) ) : ?> · <a href="<?php echo esc_url( add_query_arg( 'ics', 1, self::url( $booking['ticket_code'] ) ) ); ?>" style="display:inline-block;padding:6px 0;"><?php esc_html_e( 'Adaugă în calendar', 'libertbus-bilete' ); ?></a><?php endif; ?></p>
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
		// Cu ziua săptămânii, ca pe bilet: „21.10.2026 (miercuri) 10:00” — în lista de emailuri se vede din subiect.
		$when    = LBB_WooCommerce::date_with_day( $booking['travel_date'] ) . ' ' . $booking['dep_time'];
		$office  = LBB_Settings::office_email();
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
			// Biroul sună sau scrie clientului dintr-o atingere, de pe telefon.
			. '<p>' . esc_html__( 'Telefon', 'libertbus-bilete' ) . ': <a href="' . esc_attr( LBB_Frontend::tel_href( $booking['phone'] ) ) . '">' . esc_html( $booking['phone'] ) . '</a>'
			. ( is_email( $booking['email'] ) ? '<br>' . esc_html__( 'Email', 'libertbus-bilete' ) . ': <a href="mailto:' . esc_attr( $booking['email'] ) . '">' . esc_html( $booking['email'] ) . '</a>' : '' ) . '</p>'
			// Linkul deschide direct rezervarea aceasta (căutare după cod), nu lista cu ultimele 200.
			. '<p><a href="' . esc_url( add_query_arg( array( 'page' => 'lbb-bookings', 'q' => rawurlencode( $booking['ticket_code'] ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Deschide rezervarea în admin', 'libertbus-bilete' ) . '</a></p>';
		// Biroul răspunde direct clientului. Auto-Submitted (RFC 3834): răspunsul automat de concediu al biroului nu
		// pleacă la client (Reply-To e adresa clientului).
		$office_headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from_header(), 'Auto-Submitted: auto-generated' );
		if ( is_email( $booking['email'] ) ) {
			$office_headers[] = 'Reply-To: ' . $booking['email'];
		}
		/* translators: 1: codul rezervării, 2: ruta, 3: data și ora */
		self::mail_html( $office, sprintf( __( '[LibertBus] Rezervare %1$s — %2$s, %3$s', 'libertbus-bilete' ), $booking['ticket_code'], $name, $when ), $admin, $office_headers );
	}

	/**
	 * Reamintirea din ziua dinaintea cursei (dacă e pornită în Setări), câte un email pe bilet, o singură dată.
	 * Rulează din cronul orar; doar între 9:00 și 21:00, ca nimeni să nu primească emailuri noaptea.
	 *
	 * @param int|null $hour Ora locală (pentru teste); implicit ora curentă din fusul firmei.
	 * @return int Câte emailuri au plecat.
	 */
	public static function send_reminders( $hour = null ) {
		global $wpdb;
		if ( ! LBB_Settings::get( 'remind_day_before' ) ) {
			return 0;
		}
		$now  = new DateTimeImmutable( 'now', LBB_Settings::tz() );
		$hour = null === $hour ? (int) $now->format( 'G' ) : (int) $hour;
		if ( $hour < 9 || $hour >= 21 ) {
			return 0;
		}
		$tomorrow = $now->modify( '+1 day' )->format( 'Y-m-d' );
		// Zi fără curse (sărbătoare, cursă anulată): un „vă reamintim de cursa de mâine” ar fi fals; biroul îi anunță.
		if ( in_array( $tomorrow, LBB_Settings::closed_dates(), true ) ) {
			return 0;
		}
		// Rezervată în ultimele 12 ore (ex. azi la prânz pentru mâine): clientul abia a primit biletul pe email, o
		// reamintire la câteva minute după ar fi un al doilea email aproape identic.
		$fresh    = gmdate( 'Y-m-d H:i:s', time() - 12 * HOUR_IN_SECONDS );
		$ids      = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LBB_Bookings::table() . " WHERE travel_date = %s AND status IN ('confirmed','reserved') AND ticket_code <> '' AND email <> '' AND reminded_at IS NULL AND created_at < %s ORDER BY id LIMIT 50", $tomorrow, $fresh ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sent     = 0;
		foreach ( $ids as $id ) {
			// Marcat înainte de trimitere (o eroare de email nu duce la reîncercări la fiecare oră) și atomic: dacă
			// WP-Cron pornește din două vizite deodată, doar una trimite.
			if ( ! LBB_Bookings::claim_reminder( (int) $id ) ) {
				continue;
			}
			$booking = LBB_Bookings::get( (int) $id );
			if ( ! $booking || ! is_email( $booking['email'] ) ) {
				continue;
			}
			$route  = LBB_Routes::get( $booking['route_id'] );
			// Ora sau ziua au fost scoase din orar după vânzare (biroul a fost avertizat la salvare): fără reamintire.
			if ( ! $route || ! in_array( $booking['dep_time'], $route['times'], true ) || ! in_array( (int) gmdate( 'N', strtotime( $tomorrow . ' 12:00 UTC' ) ), $route['days'], true ) ) {
				continue;
			}
			$name   = LBB_Bookings::route_name( $route );
			$office = LBB_Settings::office_email();
			$intro  = 'reserved' === $booking['status']
				? __( 'Vă reamintim de cursa de mâine. Plata se face la urcare, la șofer.', 'libertbus-bilete' )
				: __( 'Vă reamintim de cursa de mâine. Arătați biletul (codul QR) la urcare.', 'libertbus-bilete' );
			$body   = '<p>' . esc_html( $intro ) . '</p>' . self::html( $booking ) . self::notes_html();
			$heads  = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from_header(), 'Reply-To: ' . $office );
			/* translators: 1: ruta, 2: ora, 3: codul biletului */
			if ( self::mail_html( $booking['email'], sprintf( __( 'Mâine: %1$s, ora %2$s — %3$s', 'libertbus-bilete' ), $name, $booking['dep_time'], $booking['ticket_code'] ), $body, $heads ) ) {
				$sent++;
			}
		}
		return $sent;
	}

	/**
	 * Biroul a anulat o rezervare cu plata la urcare: clientul află, ca să nu vină degeaba la autocar.
	 */
	public static function send_cancellation_email( array $booking ) {
		// Cursa a plecat, inclusiv azi la o oră trecută (ex. biroul curăță rezervările la care clientul n-a venit):
		// „locurile nu mai sunt păstrate” după plecare doar l-ar încurca pe client.
		if ( ! is_email( $booking['email'] ) || LBB_Bookings::departed( $booking ) ) {
			return false;
		}
		$route  = LBB_Routes::get( $booking['route_id'] );
		$name   = LBB_Bookings::route_name( $route ? array( 'origin' => $route['origin'], 'destination' => $route['destination'] ) : array() );
		$when   = LBB_WooCommerce::date_with_day( $booking['travel_date'] ) . ' ' . $booking['dep_time'];
		$office = LBB_Settings::office_email();
		$body   = '<p>' . sprintf(
			/* translators: 1: codul rezervării, 2: ruta, 3: data și ora */
			esc_html__( 'Rezervarea %1$s pentru %2$s, %3$s a fost anulată. Locurile nu mai sunt păstrate.', 'libertbus-bilete' ),
			'<strong>' . esc_html( $booking['ticket_code'] ) . '</strong>',
			esc_html( $name ),
			esc_html( $when )
		) . '</p>';
		// Clientul poate rezerva din nou singur, nu doar la telefon.
		$again = self::booking_page_url( $route );
		if ( $again ) {
			$body .= '<p><a href="' . esc_url( $again ) . '">' . esc_html__( 'Rezervați din nou online', 'libertbus-bilete' ) . '</a></p>';
		}
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
		// Același font fără serife ca pe pagina biletului: altfel multe aplicații de email (și cele web) arată
		// emailul cu fontul implicit, cu serife.
		// lang: cititorul de ecran din aplicația de email citește textul cu pronunția limbii lui, nu în engleză.
		$html = '<div lang="' . esc_attr( LBB_Settings::text_lang() ) . '" style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2733;">' . $html . '</div>';
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
			return ( 0 === strpos( $url, 'tel:' ) || 0 === strpos( $url, 'mailto:' ) || $label === $url ) ? $label : $label . ' (' . $url . ')';
		}, preg_replace( '/\s+/u', ' ', (string) $html ) );
		$text = preg_replace( '#<(br|/p|/div|/tr|/h[1-6]|/li)\b[^>]*>#i', "$0\n", $text );
		$text = preg_replace( '#</t[hd]>#i', '$0 ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( array( '/[ \t]+/', '/ *\n */', '/\n{3,}/' ), array( ' ', "\n", "\n\n" ), $text );
		return trim( $text );
	}

	/**
	 * Stilul WooCommerce pentru emailuri pe telefon dă tuturor celulelor „padding: 10px !important”, iar în
	 * tabelul biletului valorile ar coborî sub etichete. O regulă mai specifică (are și o clasă), în același
	 * media query, le ține aliniate. Fără „tbody” în selector: procesorul de stiluri al WooCommerce păstrează
	 * doar regulile care se potrivesc în sursa emailului, iar acolo tabelul nu are tbody.
	 */
	public static function email_styles( $css ) {
		return $css . '@media screen and (max-width: 600px){#body_content .lbb-ticket td,#body_content .lbb-ticket th{padding: 3px 10px 3px 0 !important;}}';
	}

	/**
	 * Ține minte ultima trimitere de email eșuată (wp_mail_failed), ca biroul să afle din panou că
	 * biletele nu ajung la clienți.
	 */
	public static function mail_failed( $error ) {
		$message = is_wp_error( $error ) ? $error->get_error_message() : '';
		// Mesajul poate conține adresa clientului („recipients failed: ion@…”): nu o păstrăm în setări.
		$message = preg_replace( '/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '[email]', $message );
		update_option( 'lbb_mail_failed', array(
			'time'    => time(),
			'message' => mb_substr( sanitize_text_field( $message ), 0, 200 ),
		), false );
	}

	/**
	 * „LibertBus <adresa obișnuită a site-ului>”: doar numele se schimbă, adresa rămâne cea a WordPress
	 * (sau a unui plugin SMTP), ca emailurile să nu ajungă în spam.
	 */
	public static function from_header() {
		$host = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$host = $host && 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$from = apply_filters( 'wp_mail_from', 'wordpress@' . $host );
		// Fără caractere care ar strica antetul; denumirea scrisă cu ghilimele (SC "LibertBus" SRL) rămâne cu un singur
		// spațiu între cuvinte, nu „SC  LibertBus  SRL”.
		$name = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[\r\n"<>]+/', ' ', (string) LBB_Settings::get( 'company_name' ) ) ) );
		return ( '' !== $name ? $name : 'LibertBus' ) . ' <' . $from . '>';
	}

	/**
	 * Textul de pe bilet plus telefonul de suport (pentru anulare sau schimbarea datei).
	 */
	public static function notes_html() {
		$notes = trim( (string) LBB_Settings::get( 'ticket_notes' ) );
		$html  = $notes ? '<p style="color:#5f6b7a;">' . nl2br( esc_html( $notes ) ) . '</p>' : '';
		if ( trim( (string) LBB_Settings::get( 'support_phone' ) ) ) {
			// În email nu ajunge CSS-ul site-ului: zona de atingere a telefonului (ca pe pagina biletului) e scrisă direct pe link.
			$tel   = str_replace( '<a class="lbb-phone" ', '<a class="lbb-phone" style="padding:10px 0;" ', LBB_Settings::phone_link() );
			/* translators: %s: telefon */
			$html .= '<p style="color:#5f6b7a;">' . sprintf( esc_html__( 'Anulare sau schimbarea datei: %s', 'libertbus-bilete' ), $tel ) . '</p>';
		}
		return $html;
	}

	/**
	 * Subiectul emailului cu biletul spune biletul („Biletul dumneavoastră: LB-ABC123 · Bălți → Iași · 05.11.2026”), nu
	 * „Comanda a fost finalizată”: clientul îl găsește ușor în inbox în ziua cursei. Un subiect scris de administrator
	 * în WooCommerce → Setări → Emailuri rămâne neschimbat.
	 */
	public static function email_subject( $subject, $order, $email = null ) {
		// Subiectul salvat în setări, citit direct: get_option() al emailului ar întoarce subiectul implicit (WooCommerce îl
		// scrie în setări chiar înainte de acest filtru) și orice email ar părea personalizat.
		$saved  = $email instanceof WC_Email ? get_option( $email->get_option_key(), array() ) : array();
		$custom = is_array( $saved ) && isset( $saved['subject'] ) ? trim( (string) $saved['subject'] ) : '';
		if ( ! $order instanceof WC_Order || ( '' !== $custom && $custom !== $email->get_default_subject() ) ) {
			return $subject;
		}
		// Rambursare completă: biletul e anulat, iar clientul află care („Bilet anulat și rambursat: LB-… · ruta · data”).
		// La o rambursare parțială biletul rămâne valabil, deci subiectul WooCommerce rămâne. Subiectul scris de
		// administrator („subject_full”) rămâne și el.
		// După clasă, nu după id: WooCommerce schimbă id-ul în „customer_partially_refunded_order” la rambursarea parțială.
		if ( $email instanceof WC_Email_Customer_Refunded_Order ) {
			$full = is_array( $saved ) && isset( $saved['subject_full'] ) ? trim( (string) $saved['subject_full'] ) : '';
			$done = array_values( array_filter( LBB_Bookings::by_order( $order->get_id() ), function ( $b ) {
				return '' !== (string) $b['ticket_code'];
			} ) );
			if ( ! empty( $email->partial_refund ) || ! $done || ( '' !== $full && $full !== $email->get_default_subject() ) ) {
				return $subject;
			}
			return 1 === count( $done )
				/* translators: %s: biletul (cod · rută · dată — site) */
				? sprintf( __( 'Bilet anulat și rambursat: %s', 'libertbus-bilete' ), self::page_title( $done[0] ) )
				/* translators: 1: codurile biletelor, 2: numele site-ului */
				: sprintf( __( 'Bilete anulate și rambursate: %1$s — %2$s', 'libertbus-bilete' ), implode( ', ', wp_list_pluck( $done, 'ticket_code' ) ), self::site_name() );
		}
		// Comandă anulată sau plată eșuată (emailuri către birou): ce cursă era, ca biroul să poată suna clientul. La o
		// plată eșuată biletul nu are încă cod: rămân ruta, data și ora.
		if ( $email instanceof WC_Email && in_array( $email->id, array( 'cancelled_order', 'failed_order' ), true ) ) {
			$all = LBB_Bookings::by_order( $order->get_id() );
			if ( ! $all ) {
				return $subject;
			}
			$what = 1 === count( $all ) ? self::ticket_label( $all[0], true ) : LBB_Settings::ro_count( count( $all ), __( 'bilet', 'libertbus-bilete' ), __( 'bilete', 'libertbus-bilete' ) );
			return 'cancelled_order' === $email->id
				/* translators: 1: numele site-ului, 2: numărul comenzii, 3: cursa (cod · rută · dată, ora) sau numărul de bilete */
				? sprintf( __( '[%1$s] Comandă anulată #%2$s: %3$s', 'libertbus-bilete' ), self::site_name(), $order->get_order_number(), $what )
				/* translators: 1: numele site-ului, 2: numărul comenzii, 3: cursa (rută · dată, ora) sau numărul de bilete */
				: sprintf( __( '[%1$s] Plată eșuată #%2$s: %3$s', 'libertbus-bilete' ), self::site_name(), $order->get_order_number(), $what );
		}
		$tickets = self::confirmed( $order );
		// Emailul către birou („Comandă nouă”): numărul comenzii și biletul, ca biroul să vadă din inbox ce s-a vândut.
		if ( $tickets && $email instanceof WC_Email && 'new_order' === $email->id ) {
			$what = 1 === count( $tickets ) ? self::ticket_label( $tickets[0] ) : implode( ', ', wp_list_pluck( $tickets, 'ticket_code' ) );
			/* translators: 1: numele site-ului, 2: numărul comenzii, 3: biletul (cod · rută · dată) sau codurile biletelor */
			return sprintf( __( '[%1$s] Comandă nouă #%2$s: %3$s', 'libertbus-bilete' ), self::site_name(), $order->get_order_number(), $what );
		}
		if ( 1 === count( $tickets ) ) {
			/* translators: %s: codul, ruta și data biletului, ex. LB-ABC123 · Bălți → Iași · 05.11.2026 — LibertBus */
			return sprintf( __( 'Biletul dumneavoastră: %s', 'libertbus-bilete' ), self::page_title( $tickets[0] ) );
		}
		if ( $tickets ) {
			/* translators: 1: codurile biletelor, ex. LB-ABC123, LB-DEF456, 2: numele site-ului */
			return sprintf( __( 'Biletele dumneavoastră: %1$s — %2$s', 'libertbus-bilete' ), implode( ', ', wp_list_pluck( $tickets, 'ticket_code' ) ), self::site_name() );
		}
		return $subject;
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
			$title = _n( 'Biletul dumneavoastră', 'Biletele dumneavoastră', count( $tickets ), 'libertbus-bilete' );
			echo "\n" . wp_strip_all_tags( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $title, 'UTF-8' ) : strtoupper( $title ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			foreach ( $tickets as $booking ) {
				echo wp_strip_all_tags( $booking['ticket_code'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( LBB_WooCommerce::describe( $booking ) as $label => $value ) {
					echo wp_strip_all_tags( $label . ': ' . $value ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo esc_url_raw( self::url( $booking['ticket_code'] ) ) . "\n";
				if ( self::can_add_to_calendar( $booking ) ) {
					echo wp_strip_all_tags( __( 'Adaugă în calendar', 'libertbus-bilete' ) ) . ': ' . esc_url_raw( add_query_arg( 'ics', 1, self::url( $booking['ticket_code'] ) ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo "\n";
			}
			echo wp_strip_all_tags( (string) LBB_Settings::get( 'ticket_notes' ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		// Un singur bilet (cazul obișnuit): „Biletul dumneavoastră”, nu pluralul.
		echo '<h2>' . esc_html( _n( 'Biletul dumneavoastră', 'Biletele dumneavoastră', count( $tickets ), 'libertbus-bilete' ) ) . '</h2>';
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
			// Plată refuzată sau comandă anulată: WooCommerce spune deja „plata nu a reușit” și oferă „Plătește din nou”;
			// „biletele vin imediat” l-ar contrazice.
			if ( $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) ) {
				return;
			}
			// Confirmarea băncii poate veni la câteva secunde după revenirea pe site: pagina se reîncarcă singură la
			// 5 secunde, de cel mult 6 ori; apoi rămân butonul și telefonul biroului, fără reîncărcări la nesfârșit.
			$wait = isset( $_GET['lbb_wait'] ) ? absint( $_GET['lbb_wait'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$more = $wait < 6
				? esc_html__( 'Pagina se actualizează singură.', 'libertbus-bilete' )
				/* translators: %s: telefonul biroului (link de apel) */
				: sprintf( esc_html__( 'Dacă biletul nu apare în câteva minute, sunați la %s.', 'libertbus-bilete' ), LBB_Settings::phone_link() );
			// role="status": cititorul de ecran anunță că biletele încă se emit (altfel clientul nevăzător nu știe de ce lipsesc).
			echo '<div class="woocommerce-info lbb-awaiting" role="status">' . esc_html__( 'Biletele se emit imediat după confirmarea plății și vă vin pe email. De obicei durează câteva secunde.', 'libertbus-bilete' )
				. ' ' . $more // phpcs:ignore WordPress.Security.EscapeOutput -- escapat mai sus; phone_link() e HTML sigur.
				. ' <a class="button" href="' . esc_url( $order->get_checkout_order_received_url() ) . '">' . esc_html__( 'Verifică din nou', 'libertbus-bilete' ) . '</a></div>';
			if ( $wait < 6 ) {
				$next = add_query_arg( 'lbb_wait', $wait + 1, $order->get_checkout_order_received_url() );
				wp_print_inline_script_tag( 'setTimeout(function(){window.location.replace(' . wp_json_encode( esc_url_raw( $next ) ) . ');},5000);' );
			}
			return;
		}
		echo '<h2>' . esc_html( _n( 'Biletul dumneavoastră', 'Biletele dumneavoastră', count( $tickets ), 'libertbus-bilete' ) ) . '</h2>';
		echo '<p>' . esc_html( _n( 'L-am trimis și pe email. Arătați codul șoferului la urcare.', 'Le-am trimis și pe email. Arătați codul șoferului la urcare.', count( $tickets ), 'libertbus-bilete' ) ) . '</p>';
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
			return array( 'class' => 'bad', 'text' => self::is_reservation( $booking ) ? __( 'Rezervare anulată', 'libertbus-bilete' ) : __( 'Bilet anulat', 'libertbus-bilete' ) );
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

	/**
	 * Fișierul de calendar (.ics) al cursei: ora plecării și un memento cu 2 ore înainte. Pe telefon, clientul
	 * îl deschide de pe bilet și cursa intră în calendar.
	 */
	public static function ics( array $booking ) {
		$route = LBB_Routes::get( $booking['route_id'] );
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $booking['travel_date'] . ' ' . $booking['dep_time'], LBB_Settings::tz() );
		if ( ! $route || ! $start ) {
			return '';
		}
		// Un „\r” rămas în text (rând nou Windows, dintr-un import sau din phpMyAdmin) ar strica fișierul: unele
		// calendare (iPhone) îl refuză. Devine rând nou, ca „\n”.
		$esc   = function ( $text ) {
			return str_replace( array( '\\', ';', ',', "\r\n", "\r", "\n" ), array( '\\\\', '\\;', '\\,', '\\n', '\\n', '\\n' ), $text );
		};
		$utc   = new DateTimeZone( 'UTC' );
		$name  = $route['origin'] . ' → ' . $route['destination'];
		$url   = self::url( $booking['ticket_code'] );
		/* translators: 1: codul biletului, 2: numărul de locuri, 3: linkul biletului */
		$desc  = sprintf( __( 'Bilet %1$s, locuri: %2$d. Biletul cu cod QR: %3$s', 'libertbus-bilete' ), $booking['ticket_code'], (int) $booking['seats'], $url );
		// Rezervare cu plata la urcare: numită „rezervare” (ca pe pagină), iar suma apare și în calendar, ca
		// pasagerul să aibă banii pregătiți.
		if ( isset( $booking['status'] ) && 'reserved' === $booking['status'] ) {
			/* translators: 1: codul rezervării, 2: numărul de locuri, 3: linkul rezervării */
			$desc = sprintf( __( 'Rezervare %1$s, locuri: %2$d. Rezervarea cu cod QR: %3$s', 'libertbus-bilete' ), $booking['ticket_code'], (int) $booking['seats'], $url );
			list( $amount, $cur ) = LBB_Bookings::pay_amount( $booking );
			/* translators: %s: suma, ex. 240 MDL */
			$desc .= ' ' . sprintf( __( 'De achitat la urcare: %s.', 'libertbus-bilete' ), LBB_WooCommerce::money( $amount, $cur ) );
		}
		// Telefonul biroului, cu prefixul țării: în ziua cursei, pasagerul care întârzie îl găsește direct în calendar.
		$tel = substr( LBB_Settings::phone_href(), 4 );
		if ( strlen( preg_replace( '/\D/', '', $tel ) ) >= 8 ) {
			/* translators: %s: telefonul biroului, ex. +37369184111 */
			$desc .= ' ' . sprintf( __( 'Telefon: %s', 'libertbus-bilete' ), $tel );
		}
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//LibertBus Bilete//RO',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . $booking['ticket_code'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . $start->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
			'DTEND:' . $start->modify( '+30 minutes' )->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
			/* translators: 1: ruta, ex. Bălți → Iași, 2: codul biletului */
			'SUMMARY:' . $esc( sprintf( __( 'Autocar %1$s (%2$s)', 'libertbus-bilete' ), $name, $booking['ticket_code'] ) ),
			'LOCATION:' . $esc( '' !== $route['boarding'] ? $route['boarding'] : $route['origin'] ),
			'DESCRIPTION:' . $esc( $desc ),
			'URL:' . $url,
			'BEGIN:VALARM',
			'ACTION:DISPLAY',
			'TRIGGER:-PT2H',
			'DESCRIPTION:' . $esc( $name ),
			'END:VALARM',
			'END:VEVENT',
			'END:VCALENDAR',
		);
		// Rândurile mai lungi de 75 de octeți se împart (RFC 5545), fără a tăia o literă cu diacritice.
		$out = '';
		foreach ( $lines as $line ) {
			while ( strlen( $line ) > 75 ) {
				$cut = 75;
				// O literă UTF-8 are cel mult 3 octeți de continuare; un text stricat (octeți invalizi scriși direct în
				// baza de date) se taie oricum la 75, altfel tăietura ar ajunge la 0 și rândul ar crește la nesfârșit.
				while ( $cut > 72 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
					$cut--;
				}
				$out .= substr( $line, 0, $cut ) . "\r\n";
				$line = ' ' . substr( $line, $cut );
			}
			$out .= $line . "\r\n";
		}
		return $out;
	}

	/**
	 * Numele fișierului de calendar, ca la lista de pasageri: „LB-ABC123-balti-iasi-2026-10-25.ics” (fără diacritice,
	 * ca să se deschidă la fel pe orice telefon), nu doar codul.
	 *
	 * @param array $booking Rezervarea.
	 */
	public static function ics_filename( array $booking ) {
		$name  = $booking['ticket_code'];
		$route = LBB_Routes::get( $booking['route_id'] );
		if ( $route ) {
			$name .= '-' . sanitize_title( remove_accents( $route['origin'] . ' ' . $route['destination'] ) );
		}
		return sanitize_file_name( $name . '-' . $booking['travel_date'] . '.ics' );
	}

	/**
	 * Titlul paginii biletului. La „Printează” → „Salvare ca PDF” e și numele propus pentru fișier, deci are ruta și data:
	 * „LB-ABC123 · Bălți → Iași · 25.10.2026 — LibertBus”, ușor de găsit mai târziu în telefon.
	 *
	 * @param array|null $booking Rezervarea sau null (link greșit).
	 */
	public static function page_title( $booking ) {
		// Ca la biletul găsit: în fila browserului și în istoric se vede și al cui e site-ul.
		if ( ! $booking ) {
			return __( 'Bilet negăsit', 'libertbus-bilete' ) . ' — ' . self::site_name();
		}
		return self::ticket_label( $booking ) . ' — ' . self::site_name();
	}

	/**
	 * Numele site-ului ca text simplu, pentru subiectele emailurilor: WordPress îl păstrează cu entități HTML
	 * („Autocare &amp; Bilete”), care într-un subiect ar apărea așa, textual (ca WooCommerce cu {site_title}).
	 */
	private static function site_name() {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Biletul pe scurt: „LB-ABC123 · Bălți → Iași · 25.10.2026” (titlul paginii, subiectele emailurilor), cu ora la
	 * cerere („… · 25.10.2026 08:45”).
	 */
	public static function ticket_label( array $booking, $with_time = false ) {
		// Fără cod (plată neterminată): doar ruta și data.
		$parts = '' !== (string) $booking['ticket_code'] ? array( $booking['ticket_code'] ) : array();
		$route = LBB_Routes::get( $booking['route_id'] );
		if ( $route ) {
			$parts[] = $route['origin'] . ' → ' . $route['destination'];
		}
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $booking['travel_date'], LBB_Settings::tz() );
		if ( $day ) {
			$parts[] = $day->format( 'd.m.Y' ) . ( $with_time && ! empty( $booking['dep_time'] ) ? ' ' . $booking['dep_time'] : '' );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Unde se rezervă din nou: pagina rutei sau, dacă ruta nu are una, prima pagină publicată cu formularul.
	 *
	 * @param array|null $route Ruta biletului.
	 */
	public static function booking_page_url( $route ) {
		if ( $route && ! empty( $route['page_url'] ) ) {
			return $route['page_url'];
		}
		$pages = LBB_Admin::pages_with_form();
		return $pages ? (string) get_permalink( $pages[0]->ID ) : '';
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
		$booking = self::valid_signature( $code, $sig ) ? LBB_Bookings::get_by_code( $code ) : null;
		nocache_headers();
		// Date personale: nimic în cache (nici la hosting), fără indexare, iar linkul cu cheia k= nu pleacă în Referer.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		// Serverele LiteSpeed (ca cel al site-ului) au cache-ul lor, care ascultă de antetul lor și de plugin-ul LiteSpeed
		// Cache: un bilet anulat sau abia plătit nu trebuie să rămână servit cu starea veche.
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		do_action( 'litespeed_control_set_nocache', 'lbb ticket' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		// Butonul de plată și codul QR nu se pot încadra pe alt site (clickjacking); nici fișierul .ics nu se ghicește ca HTML.
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( "Content-Security-Policy: frame-ancestors 'self'" );
		header( 'X-Content-Type-Options: nosniff' );
		status_header( $booking ? 200 : 404 );

		$state = $booking ? self::state( $booking ) : null;
		// Plata neterminată: clientul o poate relua de pe bilet, dar doar pentru o comandă care n-a fost plătită
		// niciodată și mai are ceva de plată. O comandă plătită (sau rambursată) și trecută apoi înapoi în
		// „așteaptă plata” nu primește buton: ar cere a doua plată sau ar arăta o plată de 0.
		$pay_url = '';
		if ( $booking && in_array( $booking['status'], array( 'hold', 'pending' ), true ) && $booking['order_id'] && ! LBB_Bookings::departed( $booking ) && function_exists( 'wc_get_order' ) ) {
			$order   = wc_get_order( $booking['order_id'] );
			$unpaid  = $order && $order->needs_payment() && ! $order->get_date_paid() && (float) $order->get_total() - (float) $order->get_total_refunded() > 0;
			$pay_url = $unpaid ? $order->get_checkout_payment_url() : '';
		}
		// Bilet anulat sau cursă trecută: în loc de un bilet de nefolosit, drumul spre o rezervare nouă.
		$again_url = '';
		if ( $booking && ( 'past' === $state['class'] || ! in_array( $booking['status'], array( 'hold', 'pending', 'confirmed', 'reserved' ), true ) ) ) {
			$again_url = self::booking_page_url( LBB_Routes::get( $booking['route_id'] ) );
		}
		// &ics=1: cursa în calendarul telefonului, doar pentru un bilet valabil al unei curse care urmează.
		if ( $booking && self::can_add_to_calendar( $booking, $state ) && isset( $_GET['ics'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$ics = self::ics( $booking );
			if ( $ics ) {
				header( 'Content-Type: text/calendar; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . self::ics_filename( $booking ) . '"' );
				echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar, nu HTML.
				exit;
			}
		}
		?>
<!doctype html>
<html lang="<?php echo esc_attr( LBB_Settings::text_lang() ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<?php // Biletul se arată șoferului: fără „temă întunecată automată” (Chrome pe Android, Samsung Internet), care ar recolora pagina, starea biletului și chenarul codului QR. ?>
<meta name="color-scheme" content="only light">
<title><?php echo esc_html( self::page_title( $booking ) ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( LBB_URL . 'assets/lbb.css?ver=' . LBB_Frontend::asset_ver( 'lbb.css' ) ); ?>">
<style>
/* La printare starea rămâne: un bilet anulat, neplătit sau expirat nu trebuie să arate pe hârtie ca unul valabil. */
body{margin:0;padding:16px;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1d2733}
.wrap{max-width:560px;margin:0 auto}.site{font-size:1em;margin:0 0 1em}
.state{padding:10px 14px;border-radius:8px;margin-bottom:12px;font-weight:700}
.sr{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.ok{background:#e7f6ec;color:#16632f}.bad{background:#fdecea;color:#8a1c13}.past{background:#eceff3;color:#3a4552}
/* Spațiu cu margini, nu cu gap: flex gap lipsește în Safari < 14.1 (iPhone-uri încă folosite), butoanele s-ar lipi. */
.actions{display:flex;flex-wrap:wrap;margin:4px -4px 0}.actions [hidden]{display:none}.actions button,.actions a{flex:1 1 30%;margin:4px;min-height:44px;border:1px solid #d7dbe0;border-radius:8px;background:#fff;font:inherit;cursor:pointer;color:inherit;text-decoration:none;display:flex;align-items:center;justify-content:center;text-align:center;padding:4px 8px;box-sizing:border-box}
/* Telefonul biroului („Anulare sau schimbarea datei”) e în text, dar se atinge cu degetul: zona de atingere crește în sus și în jos fără să miște rândurile. */
.wrap a.lbb-phone{padding:10px 0}
@media print{.actions{display:none}body{background:#fff}.state{border:2px solid currentColor}}
</style>
</head>
<body>
<main class="wrap">
	<?php // Titlul paginii pentru cititorul de ecran (navigare după titluri): și ce bilet e, nu doar numele site-ului. ?>
	<h1 class="site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?><?php if ( $booking ) : ?><span class="sr"> — <?php echo esc_html( ( self::is_reservation( $booking ) ? __( 'Rezervare', 'libertbus-bilete' ) : __( 'Bilet', 'libertbus-bilete' ) ) . ' ' . $booking['ticket_code'] ); ?></span><?php endif; ?></h1>
	<?php if ( ! $booking ) : ?>
		<div class="state bad"><?php esc_html_e( 'Biletul nu a fost găsit. Verificați linkul din email.', 'libertbus-bilete' ); ?></div>
		<?php /* translators: %s: telefonul pentru clienți */ ?>
		<p><?php echo sprintf( esc_html__( 'Dacă nu găsiți emailul, sunați-ne la %s și vă ajutăm după numele sau telefonul de la rezervare.', 'libertbus-bilete' ), LBB_Settings::phone_link() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		<?php // Nu o pagină fără ieșire: spre formularul de rezervare sau, dacă nu e pus pe nicio pagină, spre site. ?>
		<?php $lost_url = self::booking_page_url( null ); ?>
		<div class="actions"><a class="again" href="<?php echo esc_url( $lost_url ? $lost_url : home_url( '/' ) ); ?>"><?php echo $lost_url ? esc_html__( 'Rezervați online', 'libertbus-bilete' ) : esc_html__( 'Pagina principală a site-ului', 'libertbus-bilete' ); ?></a></div>
	<?php else : ?>
		<div class="state <?php echo esc_attr( $state['class'] ); ?>"><?php echo esc_html( $state['text'] ); ?></div>
		<?php echo self::html( $booking, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php echo self::notes_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<div class="actions"><button type="button" class="print" hidden><?php esc_html_e( 'Printează', 'libertbus-bilete' ); ?></button>
			<?php if ( self::can_add_to_calendar( $booking, $state ) ) : ?><a class="cal" href="<?php echo esc_url( add_query_arg( 'ics', 1, self::url( $booking['ticket_code'] ) ) ); ?>"><?php esc_html_e( 'Adaugă în calendar', 'libertbus-bilete' ); ?></a>
			<button type="button" class="share" hidden data-share-title="<?php echo esc_attr( self::page_title( $booking ) ); ?>" data-share-text="<?php echo esc_attr( self::share_text( $booking ) ); ?>" data-share-url="<?php echo esc_url( self::url( $booking['ticket_code'] ) ); ?>"><?php esc_html_e( 'Trimite biletul', 'libertbus-bilete' ); ?></button>
			<button type="button" class="copy" hidden data-copied="<?php esc_attr_e( 'Linkul a fost copiat', 'libertbus-bilete' ); ?>"><?php esc_html_e( 'Copiază linkul biletului', 'libertbus-bilete' ); ?></button><?php endif; ?>
			<?php if ( $pay_url ) : ?><a class="pay" href="<?php echo esc_url( $pay_url ); ?>"><?php esc_html_e( 'Achită acum', 'libertbus-bilete' ); ?></a><?php endif; ?>
			<?php if ( $again_url ) : ?><a class="again" href="<?php echo esc_url( $again_url ); ?>"><?php esc_html_e( 'Rezervați din nou online', 'libertbus-bilete' ); ?></a><?php endif; ?></div>
		<?php // „Linkul a fost copiat”, pentru cititorul de ecran (în afara rândului de butoane, care e flex). ?>
		<p class="sr copy-status" aria-live="polite"></p>
		<script src="<?php echo esc_url( LBB_URL . 'assets/qrcode.min.js?ver=' . LBB_Frontend::asset_ver( 'qrcode.min.js' ) ); ?>"></script>
		<script>
		// „Printează” apare doar cu JavaScript (fără el butonul n-ar face nimic); din meniul browserului se poate printa oricum.
		(function(b){
			if (!b || !window.print) { return; }
			b.hidden = false;
			b.addEventListener('click', function(){ window.print(); });
		})(document.querySelector('.actions .print'));
		// „Trimite biletul” (WhatsApp, Viber, SMS…) doar unde telefonul știe să partajeze: ex. părintele trimite biletul copilului.
		(function(b){
			if (!b || !navigator.share) { return; }
			b.hidden = false;
			b.addEventListener('click', function(){
				navigator.share({title: b.getAttribute('data-share-title'), text: b.getAttribute('data-share-text'), url: b.getAttribute('data-share-url')}).catch(function(){});
			});
		})(document.querySelector('.share'));
		// Pe calculator (fără „Trimite”): textul biletului cu linkul, gata de lipit în WhatsApp Web, email sau Messenger.
		(function(b, s){
			if (!b || !s || navigator.share || !navigator.clipboard || !window.isSecureContext) { return; }
			var label = b.textContent;
			b.hidden = false;
			b.addEventListener('click', function(){
				var text = s.getAttribute('data-share-text') + ' ' + s.getAttribute('data-share-url');
				navigator.clipboard.writeText(text).then(function(){
					b.textContent = b.getAttribute('data-copied');
					document.querySelector('.copy-status').textContent = b.getAttribute('data-copied');
					setTimeout(function(){ b.textContent = label; }, 3000);
				}).catch(function(){
					// Copierea refuzată (permisiuni, browser în mod restrâns): apăsarea nu rămâne fără efect, textul se
					// arată selectat în fereastra browserului, gata de copiat cu Ctrl+C.
					window.prompt(label, text);
				});
			});
		})(document.querySelector('.actions .copy'), document.querySelector('.actions .share'));
		// Array.prototype.forEach, nu NodeList.forEach: pe telefoanele vechi (Android 4.4, iOS 9) acela lipsește și
		// codul QR, cel pe care îl scanează șoferul, nu s-ar mai desena.
		var each = function(list, fn){ Array.prototype.forEach.call(list, fn); };
		each(document.querySelectorAll('[data-qr]'), function(el){
			if (!window.QRCode) { return; }
			new QRCode(el, {text: el.getAttribute('data-qr'), width: 132, height: 132, correctLevel: QRCode.CorrectLevel.M});
			el.setAttribute('role', 'img');
			el.setAttribute('aria-label', el.getAttribute('data-qr-label'));
			// Textul e pe container (role="img"); imaginea și canvasul generate nu se mai citesc o dată.
			each(el.querySelectorAll('img'), function(i){ i.setAttribute('alt', ''); });
			each(el.querySelectorAll('canvas'), function(c){ c.setAttribute('aria-hidden', 'true'); });
		});
		</script>
	<?php endif; ?>
</main>
</body>
</html>
		<?php
		exit;
	}
}
