<?php
/**
 * Paginile cerute de bănci: termeni, anulare și rambursare, plata online.
 * Se creează ca ciorne, cu datele firmei luate din Setări prin [lbb_firma camp="..."].
 *
 * Textele sunt un punct de plecare, nu consultanță juridică: verificați-le cu juristul/contabilul.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Legal {

	const OPTION = 'lbb_legal_pages';

	public static function init() {
		add_shortcode( 'lbb_firma', array( __CLASS__, 'company_shortcode' ) );
		add_action( 'admin_post_lbb_create_legal', array( __CLASS__, 'create' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'link_terms' ), 10, 3 );
	}

	public static function pages() {
		return array(
			'terms'   => array( 'title' => __( 'Termeni și condiții', 'libertbus-bilete' ), 'slug' => 'termeni-si-conditii' ),
			'refund'  => array( 'title' => __( 'Politica de anulare și rambursare', 'libertbus-bilete' ), 'slug' => 'politica-de-anulare-si-rambursare' ),
			'payment' => array( 'title' => __( 'Plata online cu cardul', 'libertbus-bilete' ), 'slug' => 'plata-online' ),
			'privacy' => array( 'title' => __( 'Politica de confidențialitate', 'libertbus-bilete' ), 'slug' => 'politica-de-confidentialitate' ),
		);
	}

	public static function page_id( $key ) {
		if ( 'privacy' === $key ) {
			$wp_privacy = (int) get_option( 'wp_page_for_privacy_policy' );
			if ( $wp_privacy && 'publish' === get_post_status( $wp_privacy ) ) {
				$saved = get_option( self::OPTION, array() );
				if ( empty( $saved['privacy'] ) || 'publish' !== get_post_status( (int) $saved['privacy'] ) ) {
					return $wp_privacy;
				}
			}
		}
		$saved = get_option( self::OPTION, array() );
		$id    = isset( $saved[ $key ] ) ? (int) $saved[ $key ] : 0;
		return $id && get_post_status( $id ) && 'trash' !== get_post_status( $id ) ? $id : 0;
	}

	public static function company_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'camp' => 'name' ), $atts );
		$map  = array(
			'name'    => 'company_name',
			'idno'    => 'company_idno',
			'address' => 'company_address',
			'email'   => 'company_email',
			'phone'   => 'support_phone',
		);
		$key   = isset( $map[ $atts['camp'] ] ) ? $map[ $atts['camp'] ] : 'company_name';
		$value = (string) LBB_Settings::get( $key );
		if ( 'company_email' === $key && ! $value ) {
			$value = get_option( 'admin_email' );
		}
		return '' === $value ? '<mark>[' . esc_html__( 'completați în LibertBus → Setări', 'libertbus-bilete' ) . ']</mark>' : esc_html( $value );
	}

	public static function settings_box() {
		?>
		<hr id="legal">
		<h2><?php esc_html_e( 'Pagini legale (cerute de bancă)', 'libertbus-bilete' ); ?></h2>
		<p><?php esc_html_e( 'Butonul creează ciorne cu texte-model. Completați întâi datele firmei mai sus, citiți textele, ajustați-le cu juristul și publicați-le. Le legați apoi în meniul de jos al site-ului.', 'libertbus-bilete' ); ?></p>
		<table class="widefat striped" style="max-width:700px"><tbody>
		<?php foreach ( self::pages() as $key => $page ) : ?>
			<?php $id = self::page_id( $key ); ?>
			<tr><td><?php echo esc_html( $page['title'] ); ?></td><td>
				<?php if ( $id ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a> — <?php echo esc_html( get_post_status_object( get_post_status( $id ) )->label ); ?>
				<?php else : ?>
					<em><?php esc_html_e( 'nu există', 'libertbus-bilete' ); ?></em>
				<?php endif; ?>
			</td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
			<input type="hidden" name="action" value="lbb_create_legal">
			<?php wp_nonce_field( 'lbb_create_legal' ); ?>
			<?php submit_button( __( 'Creează ciornele care lipsesc', 'libertbus-bilete' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function create() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nu aveți acces.', 'libertbus-bilete' ) );
		}
		check_admin_referer( 'lbb_create_legal' );
		$saved   = get_option( self::OPTION, array() );
		$created = 0;
		foreach ( self::pages() as $key => $page ) {
			if ( self::page_id( $key ) ) {
				continue;
			}
			$id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $page['title'],
				'post_name'    => $page['slug'],
				'post_content' => self::content( $key ),
			) );
			if ( $id && ! is_wp_error( $id ) ) {
				$saved[ $key ] = $id;
				$created++;
			}
		}
		update_option( self::OPTION, $saved );
		wp_safe_redirect( add_query_arg( array(
			'page'    => 'lbb-settings',
			'lbb_msg' => rawurlencode( sprintf( __( 'Ciorne create: %d.', 'libertbus-bilete' ), $created ) ),
		), admin_url( 'admin.php' ) ) . '#legal' );
		exit;
	}

	/**
	 * Când pagina noastră de termeni e publicată, devine pagina de termeni din WooCommerce
	 * (doar dacă WooCommerce nu are deja una).
	 */
	public static function link_terms( $new, $old, $post ) {
		if ( 'publish' !== $new || 'page' !== $post->post_type ) {
			return;
		}
		$saved = get_option( self::OPTION, array() );
		if ( ! empty( $saved['terms'] ) && (int) $saved['terms'] === (int) $post->ID ) {
			$current = (int) get_option( 'woocommerce_terms_page_id' );
			if ( ! $current || 'publish' !== get_post_status( $current ) ) {
				update_option( 'woocommerce_terms_page_id', $post->ID );
			}
		}
	}

	private static function content( $key ) {
		$firma   = '[lbb_firma camp="name"]';
		$idno    = '[lbb_firma camp="idno"]';
		$adresa  = '[lbb_firma camp="address"]';
		$email   = '[lbb_firma camp="email"]';
		$telefon = '[lbb_firma camp="phone"]';
		$site    = home_url( '/' );
		$cutoff  = (int) LBB_Settings::get( 'cutoff_minutes' );

		$date = "<h2>Date de identificare</h2>\n<p>$firma, IDNO $idno, adresa: $adresa.<br>Telefon: $telefon, email: $email.</p>";

		switch ( $key ) {
			case 'terms':
				return <<<HTML
<p>Acești termeni se aplică biletelor de autocar cumpărate pe <a href="$site">$site</a>. Prin plasarea comenzii confirmați că i-ați citit și îi acceptați.</p>
$date
<h2>Serviciul</h2>
<p>Vindem bilete pentru transportul regulat de pasageri pe rutele publicate în secțiunea „Orar”. Biletul dă dreptul la un loc pe cursa, data și ora alese, între punctele de îmbarcare și debarcare indicate.</p>
<h2>Prețuri și plată</h2>
<p>Prețurile sunt afișate pe site înainte de plată și includ toate taxele. Plata se face online cu cardul bancar (Visa, Mastercard) prin procesatorul de plăți al băncii partenere. Comanda se confirmă doar după autorizarea plății.</p>
<h2>Emiterea biletului</h2>
<p>După confirmarea plății primiți biletul electronic pe email (cod de bilet și link cu cod QR). Biletul se poate arăta de pe telefon sau tipărit. Vânzarea online se închide cu $cutoff de minute înainte de plecare.</p>
<h2>Obligațiile pasagerului</h2>
<ul>
<li>să fie la locul de îmbarcare cu cel puțin 15 minute înainte de plecare;</li>
<li>să aibă act de identitate valabil (pașaport sau buletin) și documentele cerute pentru trecerea frontierei;</li>
<li>să respecte regulile de siguranță și indicațiile șoferului.</li>
</ul>
<p>Copiii minori călătoresc însoțiți și cu documentele cerute de legislația privind ieșirea minorilor din țară.</p>
<h2>Bagaje</h2>
<p>Fiecare pasager are dreptul la un bagaj de cală și un bagaj de mână. Bagajele suplimentare sau voluminoase se transportă doar cu acordul transportatorului.</p>
<h2>Anulare și rambursare</h2>
<p>Condițiile sunt descrise în <a href="{$site}politica-de-anulare-si-rambursare/">Politica de anulare și rambursare</a>.</p>
<h2>Răspundere</h2>
<p>Transportatorul nu răspunde pentru întârzieri cauzate de controlul la frontieră, condiții meteo, trafic sau alte situații în afara controlului său. Pasagerul care nu se prezintă la timp la îmbarcare pierde dreptul la călătorie fără rambursare.</p>
<h2>Reclamații</h2>
<p>Reclamațiile se trimit la $email sau la $telefon. Răspundem în cel mult 15 zile lucrătoare. Litigiile se soluționează amiabil, iar în lipsa unei înțelegeri de instanțele competente din Republica Moldova.</p>
HTML;
			case 'refund':
				return <<<HTML
<p>Această politică descrie cum puteți anula un bilet cumpărat online și cum primiți banii înapoi.</p>
$date
<h2>Anularea de către pasager</h2>
<ul>
<li>cu mai mult de 24 de ore înainte de plecare: rambursare integrală;</li>
<li>între 24 de ore și 3 ore înainte de plecare: rambursare 50%;</li>
<li>cu mai puțin de 3 ore înainte de plecare sau neprezentare: fără rambursare.</li>
</ul>
<p>Pentru anulare scrieți la $email sau sunați la $telefon și comunicați codul biletului (ex. LB-ABC123). Puteți schimba gratuit data călătoriei cu mai mult de 24 de ore înainte, în limita locurilor libere.</p>
<h2>Anularea de către transportator</h2>
<p>Dacă anulăm cursa, vă oferim un loc pe altă cursă sau rambursarea integrală, la alegerea dumneavoastră.</p>
<h2>Cum se face rambursarea</h2>
<p>Banii se întorc pe același card cu care s-a plătit, în 5–10 zile lucrătoare de la aprobare, în funcție de banca emitentă. Nu facem rambursări în numerar pentru plățile online.</p>
HTML;
			case 'payment':
				return <<<HTML
<p>Pe <a href="$site">$site</a> puteți plăti biletele online cu cardul bancar Visa sau Mastercard.</p>
$date
<h2>Cum plătiți</h2>
<ol>
<li>Alegeți ruta, data, ora și numărul de pasageri în formularul de rezervare.</li>
<li>Completați numele, telefonul și emailul și apăsați „Continuă spre plată cu cardul”.</li>
<li>Sunteți redirecționat pe pagina securizată a procesatorului de plăți, unde introduceți datele cardului.</li>
<li>După confirmarea plății primiți biletul pe email.</li>
</ol>
<h2>Siguranța plății</h2>
<p>Datele cardului se introduc doar pe pagina procesatorului de plăți al băncii și nu ajung la noi și nu sunt stocate pe site. Conexiunea este criptată (HTTPS), iar plățile sunt protejate prin 3-D Secure (Visa Secure, Mastercard Identity Check): banca emitentă vă poate cere o confirmare suplimentară.</p>
<h2>Moneda</h2>
<p>Plata se face în moneda afișată la finalizarea comenzii. Dacă moneda cardului este alta, conversia o face banca emitentă după cursul ei.</p>
<h2>Probleme cu plata</h2>
<p>Dacă plata nu a reușit, locurile se păstrează pentru scurt timp și puteți încerca din nou. Pentru orice întrebare sunați la $telefon sau scrieți la $email.</p>
HTML;
			case 'privacy':
				return <<<HTML
<p>Această politică explică ce date personale colectăm la rezervarea biletelor și cum le folosim, conform Legii nr. 133/2011 privind protecția datelor cu caracter personal.</p>
$date
<h2>Ce date colectăm</h2>
<p>Numele pasagerilor, telefonul, emailul, datele călătoriei și istoricul comenzilor. Datele cardului nu le primim și nu le stocăm: plata este procesată de bancă.</p>
<h2>De ce le folosim</h2>
<ul>
<li>pentru emiterea biletului și lista de îmbarcare;</li>
<li>pentru a vă anunța despre schimbări ale cursei;</li>
<li>pentru obligațiile contabile și fiscale.</li>
</ul>
<h2>Cui le transmitem</h2>
<p>Doar băncii/procesatorului de plăți, autorităților la cerere legală și, la trecerea frontierei, autorităților vamale și de frontieră. Nu vindem datele.</p>
<h2>Cât le păstrăm</h2>
<p>Datele comenzilor se păstrează pe durata cerută de legislația contabilă. Celelalte date se șterg la cerere.</p>
<h2>Drepturile dumneavoastră</h2>
<p>Puteți cere acces, corectare sau ștergere scriind la $email.</p>
HTML;
		}
		return '';
	}
}
