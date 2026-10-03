<?php
/**
 * Paginile legale: termeni, anulare și rambursare, plata online (cerute de bănci), confidențialitate și cookies.
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
		add_shortcode( 'lbb_firma_date', array( __CLASS__, 'company_block' ) );
		add_action( 'admin_post_lbb_create_legal', array( __CLASS__, 'create' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'link_terms' ), 10, 3 );
	}

	public static function pages() {
		return array(
			'terms'   => array( 'title' => __( 'Termeni și condiții', 'libertbus-bilete' ), 'slug' => 'termeni-si-conditii' ),
			'refund'  => array( 'title' => __( 'Politica de anulare și rambursare', 'libertbus-bilete' ), 'slug' => 'politica-de-anulare-si-rambursare' ),
			'payment' => array( 'title' => __( 'Plata online cu cardul', 'libertbus-bilete' ), 'slug' => 'plata-online' ),
			'privacy' => array( 'title' => __( 'Politica de confidențialitate', 'libertbus-bilete' ), 'slug' => 'politica-de-confidentialitate' ),
			'cookies' => array( 'title' => __( 'Politica de cookies', 'libertbus-bilete' ), 'slug' => 'politica-de-cookies' ),
		);
	}

	/**
	 * Paginile care nu depind de contractul cu banca se publică direct (cerute pentru bannerul de cookies).
	 * Celelalte rămân ciorne până le citiți.
	 */
	public static function publish_now() {
		return array( 'privacy', 'cookies' );
	}

	/**
	 * O pagină de confidențialitate existentă contează doar dacă are text (nu doar un formular).
	 */
	public static function is_real_policy( $page_id ) {
		$post = $page_id ? get_post( $page_id ) : null;
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		return strlen( trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) ) ) > 300;
	}

	public static function page_id( $key ) {
		if ( 'privacy' === $key ) {
			$wp_privacy = (int) get_option( 'wp_page_for_privacy_policy' );
			if ( self::is_real_policy( $wp_privacy ) ) {
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

	/**
	 * [lbb_firma_date] — datele de contact cu ce e completat în Setări (fără goluri pe pagini publicate).
	 */
	public static function company_block() {
		$name    = (string) LBB_Settings::get( 'company_name' );
		$idno    = (string) LBB_Settings::get( 'company_idno' );
		$address = (string) LBB_Settings::get( 'company_address' );
		$email   = (string) LBB_Settings::get( 'company_email' );
		$email   = $email ? $email : (string) get_option( 'admin_email' );
		$parts   = array( esc_html( $name ? $name : 'LibertBus' ) );
		if ( $idno ) {
			$parts[] = 'IDNO ' . esc_html( $idno );
		}
		if ( $address ) {
			$parts[] = esc_html( $address );
		}
		return implode( ', ', $parts ) . '<br>' . esc_html__( 'Telefon', 'libertbus-bilete' ) . ': ' . LBB_Settings::phone_link() . ', email: <a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
	}

	public static function settings_box() {
		?>
		<hr id="legal">
		<h2><?php esc_html_e( 'Pagini legale (cerute de bancă)', 'libertbus-bilete' ); ?></h2>
		<p><?php esc_html_e( 'Butonul creează paginile care lipsesc, cu texte-model. Politica de confidențialitate și cea de cookies se publică imediat (bannerul de cookies trimite la ele); termenii, anularea și plata online rămân ciorne: completați întâi datele firmei mai sus, citiți textele, ajustați-le cu juristul și publicați-le. Le legați apoi în meniul de jos al site-ului.', 'libertbus-bilete' ); ?></p>
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
			<?php submit_button( __( 'Creează paginile care lipsesc', 'libertbus-bilete' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function create() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nu aveți acces.', 'libertbus-bilete' ) );
		}
		check_admin_referer( 'lbb_create_legal' );
		$created = self::create_missing();
		wp_safe_redirect( add_query_arg( array(
			'page'    => 'lbb-settings',
			'lbb_msg' => rawurlencode( sprintf( __( 'Pagini create: %d (confidențialitate și cookies publicate, celelalte ciorne).', 'libertbus-bilete' ), $created ) ),
		), admin_url( 'admin.php' ) ) . '#legal' );
		exit;
	}

	/**
	 * Creează paginile care lipsesc și întoarce câte s-au creat.
	 */
	public static function create_missing() {
		$saved   = get_option( self::OPTION, array() );
		$created = 0;
		foreach ( self::pages() as $key => $page ) {
			$existing = self::page_id( $key );
			if ( $existing ) {
				// O ciornă creată de o versiune veche și neatinsă primește textul nou și se publică.
				$post = get_post( $existing );
				if ( in_array( $key, self::publish_now(), true ) && 'draft' === $post->post_status && $post->post_modified_gmt === $post->post_date_gmt && ! empty( $saved[ $key ] ) && (int) $saved[ $key ] === $existing ) {
					wp_update_post( array( 'ID' => $existing, 'post_status' => 'publish', 'post_content' => self::content( $key ) ) );
					if ( 'privacy' === $key && ! self::is_real_policy( (int) get_option( 'wp_page_for_privacy_policy' ) ) ) {
						update_option( 'wp_page_for_privacy_policy', $existing );
					}
					$created++;
				}
				continue;
			}
			$id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => in_array( $key, self::publish_now(), true ) ? 'publish' : 'draft',
				'post_title'   => $page['title'],
				'post_name'    => $page['slug'],
				'post_content' => self::content( $key ),
			) );
			if ( $id && ! is_wp_error( $id ) ) {
				$saved[ $key ] = $id;
				update_option( self::OPTION, $saved );
				$created++;
				// WooCommerce și WordPress leagă „Politica de confidențialitate” din pagina de plată de această opțiune.
				if ( 'privacy' === $key && ! self::is_real_policy( (int) get_option( 'wp_page_for_privacy_policy' ) ) ) {
					update_option( 'wp_page_for_privacy_policy', $id );
				}
			}
		}
		update_option( self::OPTION, $saved );
		return $created;
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
				$cookies = home_url( '/politica-de-cookies/' );
				$updated = wp_date( 'd.m.Y' );
				return <<<HTML
<p>Această politică explică ce date personale prelucrăm pe <a href="$site">$site</a> și în legătură cu călătoriile, de ce, pe ce temei, cui le transmitem și cât le păstrăm. Respectăm Legea nr. 195/2024 privind protecția datelor cu caracter personal (aplicabilă din 23 august 2026) și, pentru persoanele din Uniunea Europeană, Regulamentul (UE) 2016/679 (GDPR).</p>
<h2>1. Operatorul datelor</h2>
<p>[lbb_firma_date]</p>
<h2>2. Ce date prelucrăm, de ce, pe ce temei și cât timp</h2>
<ul>
<li><strong>Rezervarea și biletul:</strong> numele și prenumele pasagerilor, telefonul, emailul, ruta, data și ora călătoriei, numărul de locuri, codul biletului. Scop: rezervarea locului, emiterea biletului, lista de îmbarcare, anunțarea schimbărilor de cursă. Temei: executarea contractului de transport. Păstrare: 3 ani de la data călătoriei, cu excepția documentelor contabile.</li>
<li><strong>Plata:</strong> suma, moneda, data și rezultatul plății, codul tranzacției. Datele cardului nu ajung la noi: le introduceți doar pe pagina procesatorului de plăți. Temei: executarea contractului și obligația legală (contabilitate, fiscalitate). Păstrare: cât cere legislația contabilă și fiscală.</li>
<li><strong>Mesajele trimise prin formularele site-ului, telefon sau Viber:</strong> numele, telefonul, emailul și conținutul mesajului. Scop: să vă răspundem și să pregătim rezervarea. Temei: demersuri la cererea dumneavoastră înainte de contract și interesul nostru legitim de a răspunde. Păstrare: cel mult 1 an.</li>
<li><strong>Securitatea site-ului:</strong> adresa IP, tipul browserului, paginile accesate, încercările de autentificare. Scop: protecția împotriva atacurilor și a abuzurilor. Temei: interes legitim. Păstrare: cel mult 90 de zile.</li>
<li><strong>Statistică (doar cu acordul dumneavoastră):</strong> Google Analytics — paginile vizitate, durata vizitei, dispozitivul, orașul aproximativ. Temei: consimțământ. Păstrare: 14 luni.</li>
<li><strong>Marketing (doar cu acordul dumneavoastră):</strong> de unde a venit vizita (ex. Google, Facebook) și măsurarea reclamelor Google. Temei: consimțământ. Păstrare: până la 3 luni.</li>
</ul>
<p>Datele copiilor care călătoresc le primim de la părintele sau însoțitorul care face rezervarea. Nu luăm decizii automate și nu facem profilare cu efecte juridice asupra dumneavoastră.</p>
<h2>3. Cui transmitem datele</h2>
<ul>
<li>băncii și procesatorului de plăți (pentru plata cu cardul);</li>
<li>firmei care găzduiește site-ul și furnizorului de email (stocare și trimiterea biletelor);</li>
<li>Google Ireland Limited și Google LLC (statistică și reclame, doar cu acord);</li>
<li>șoferilor și personalului nostru, doar lista de îmbarcare a cursei;</li>
<li>poliției de frontieră și autorităților vamale din Republica Moldova și România, la trecerea frontierei, și altor autorități, doar la cerere legală.</li>
</ul>
<p>Nu vindem și nu închiriem datele.</p>
<h2>4. Transferuri în alte țări</h2>
<p>Pentru cursele spre România, datele pasagerilor ajung la autoritățile de frontieră din România (Uniunea Europeană). Google poate prelucra datele de statistică și în SUA; transferul se face pe baza Cadrului UE–SUA privind protecția datelor (Data Privacy Framework) și a clauzelor contractuale standard ale Comisiei Europene.</p>
<h2>5. Drepturile dumneavoastră</h2>
<ul>
<li>să fiți informat și să aveți acces la datele dumneavoastră;</li>
<li>să cereți corectarea datelor greșite;</li>
<li>să cereți ștergerea datelor („dreptul de a fi uitat”);</li>
<li>să cereți restricționarea prelucrării;</li>
<li>să primiți datele într-un format structurat (portabilitate);</li>
<li>să vă opuneți prelucrării bazate pe interes legitim;</li>
<li>să nu faceți obiectul unei decizii bazate exclusiv pe prelucrare automată;</li>
<li>să vă retrageți oricând acordul pentru cookies, fără a afecta prelucrarea făcută până atunci.</li>
</ul>
<p>Scrieți-ne la datele de contact de la punctul 1. Răspundem în cel mult o lună (termenul se poate prelungi cu două luni pentru cereri complexe, cu anunțarea dumneavoastră).</p>
<h2>6. Plângeri</h2>
<p>Puteți depune plângere la Centrul Național pentru Protecția Datelor cu Caracter Personal: str. Serghei Lazo 48, mun. Chișinău, MD-2004, telefon +373 22 820 801, email centru@datepersonale.md, <a href="https://datepersonale.md" rel="noopener">datepersonale.md</a>. Dacă locuiți în Uniunea Europeană, vă puteți adresa și autorității de protecție a datelor din țara dumneavoastră.</p>
<h2>7. Siguranța datelor</h2>
<p>Site-ul folosește conexiune criptată (HTTPS), accesul la date îl au doar persoanele care se ocupă de rezervări, iar plata cu cardul se face pe pagina securizată a procesatorului de plăți.</p>
<h2>8. Cookies</h2>
<p>Detalii despre cookies și cum vă schimbați alegerea găsiți în <a href="$cookies">Politica de cookies</a>.</p>
<p><em>Versiunea 1.0, actualizată la $updated.</em></p>
HTML;
			case 'cookies':
				$privacy = self::page_id( 'privacy' ) ? get_permalink( self::page_id( 'privacy' ) ) : home_url( '/politica-de-confidentialitate/' );
				$updated = wp_date( 'd.m.Y' );
				return <<<HTML
<p>Cookies sunt fișiere mici pe care site-ul le salvează în browser. Pe <a href="$site">$site</a> folosim cookies necesare și, doar dacă vă dați acordul, cookies de statistică și de marketing. Până nu acceptați, scripturile de statistică și marketing nu se încarcă deloc. Respectăm Legea nr. 195/2024 privind protecția datelor cu caracter personal și Legea nr. 284/2004 privind comerțul electronic.</p>
<h2>Cookies necesare</h2>
<p>Fără ele site-ul nu funcționează, de aceea nu cer acord.</p>
<ul>
<li><strong>lbb_cookie_consent</strong> — ține minte alegerea dumneavoastră despre cookies și data ei. Durata: 6 luni.</li>
<li><strong>woocommerce_cart_hash, woocommerce_items_in_cart, wp_woocommerce_session_*</strong> — păstrează biletul în coș până la plată. Durata: sesiune / 2 zile.</li>
<li><strong>wordpress_*, wordfence_*</strong> — autentificarea și securitatea contului, doar pentru administratori. Durata: sesiune.</li>
</ul>
<p>La plata cu cardul, pagina băncii sau a procesatorului de plăți poate folosi propriile cookies, pentru siguranța plății și prevenirea fraudei.</p>
<h2>Cookies de statistică (cu acordul dumneavoastră)</h2>
<ul>
<li><strong>_ga, _ga_*</strong> — Google Analytics: numără vizitele și paginile văzute. Furnizor: Google Ireland Limited. Durata: 13 luni.</li>
</ul>
<h2>Cookies de marketing (cu acordul dumneavoastră)</h2>
<ul>
<li><strong>_gcl_au</strong> — Google: măsoară eficiența reclamelor. Furnizor: Google Ireland Limited. Durata: 3 luni.</li>
<li><strong>sbjs_*</strong> — WooCommerce: de unde a venit vizita (ex. Google, Facebook), legat de comandă. Durata: sesiune.</li>
</ul>
<p>Google poate prelucra datele și în SUA, pe baza Cadrului UE–SUA privind protecția datelor și a clauzelor contractuale standard: <a href="https://policies.google.com/privacy" rel="noopener">politica Google</a>.</p>
<p>Fonturile site-ului se încarcă de la Google Fonts; ele nu pun cookies, dar Google primește adresa IP a browserului pentru a trimite fonturile.</p>
<h2>Cum vă schimbați alegerea</h2>
<p>Puteți accepta sau refuza fiecare categorie din „Setări” și vă puteți retrage oricând acordul, la fel de ușor cum l-ați dat: folosiți linkul „Setări cookies” din subsolul fiecărei pagini sau butonul de mai jos. La retragere, cookies-urile de statistică și marketing se șterg.</p>
[lbb_cookie_settings]
<p>Puteți șterge sau bloca oricând cookies și din setările browserului. Mai multe despre datele personale găsiți în <a href="$privacy">Politica de confidențialitate</a>.</p>
<h2>Contact</h2>
<p>[lbb_firma_date]</p>
<p><em>Versiunea 1.0, actualizată la $updated.</em></p>
HTML;
		}
		return '';
	}
}
