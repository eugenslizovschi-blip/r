<?php
/**
 * Acordul pentru cookies: un banner mic („Accept toate” / „Doar necesare”) și blocarea
 * scripturilor de statistică și marketing (Google Analytics, sursa vizitei din WooCommerce etc.)
 * până când vizitatorul acceptă.
 *
 * Scripturile se blochează în pagină (type="text/plain"), nu pe server după cookie, ca să meargă
 * și cu pagini puse în cache. La „Accept toate” scriptul din browser le pornește.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Cookies {

	const COOKIE = 'lbb_cookie_consent';

	/**
	 * Scripturi externe de statistică (după adresa fișierului).
	 */
	const STATS_SRC = '#googletagmanager\.com|google-analytics\.com|static\.hotjar\.com|clarity\.ms|mc\.yandex\.ru#i';

	/**
	 * Scripturi externe de marketing: reclame și sursa vizitei din WooCommerce.
	 */
	const MARKETING_SRC = '#googleadservices\.com|doubleclick\.net|connect\.facebook\.net|analytics\.tiktok\.com|sourcebuster|order-attribution#i';

	/**
	 * Cod scris direct în pagină care pornește aceleași servicii.
	 */
	const STATS_INLINE     = '#\bgtag\s*\(|_hjSettings|\bym\s*\(\s*\d|clarity\s*\(#';
	const MARKETING_INLINE = '#\bfbq\s*\(|\bsbjs\.init|\bttq\.#';

	/** @var int|null Nivelul de buffer deschis de noi. */
	private static $level = null;

	public static function init() {
		add_shortcode( 'lbb_cookie_settings', array( __CLASS__, 'settings_shortcode' ) );
		if ( LBB_Settings::get( 'footer_links' ) ) {
			add_action( 'wp_footer', array( __CLASS__, 'footer_links' ), 4 );
		}
		if ( ! LBB_Settings::get( 'cookie_banner' ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'wp_head', 'wp_footer' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'buffer_start' ), -99999 );
			add_action( $hook, array( __CLASS__, 'buffer_end' ), PHP_INT_MAX );
		}
		add_action( 'wp_footer', array( __CLASS__, 'banner' ), 5 );
	}

	/**
	 * Bannerul apare pe site, nu în admin, în editorul Elementor sau în feed-uri.
	 */
	public static function enabled() {
		if ( ! LBB_Settings::get( 'cookie_banner' ) || is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		return ! isset( $_GET['elementor-preview'] ) && ! isset( $_GET['customize_changeset_uuid'] );
	}

	public static function assets() {
		if ( ! self::enabled() ) {
			return;
		}
		wp_enqueue_style( 'lbb-cookies', LBB_URL . 'assets/cookies.css', array(), LBB_Frontend::asset_ver( 'cookies.css' ) );
		wp_enqueue_script( 'lbb-cookies', LBB_URL . 'assets/cookies.js', array(), LBB_Frontend::asset_ver( 'cookies.js' ), true );
	}

	public static function buffer_start() {
		if ( ! self::enabled() ) {
			return;
		}
		ob_start();
		self::$level = ob_get_level();
	}

	public static function buffer_end() {
		// Dacă alt plugin a lăsat un buffer deschis, nu stricăm ordinea: lăsăm totul cum e.
		if ( null === self::$level || ob_get_level() !== self::$level ) {
			self::$level = null;
			return;
		}
		self::$level = null;
		echo self::block_scripts( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Transformă scripturile de statistică/marketing în type="text/plain" (browserul nu le rulează
	 * și nu le descarcă) până la acord.
	 */
	public static function block_scripts( $html ) {
		return preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $m ) {
				$attrs = $m[1];
				$body  = $m[2];
				if ( false !== strpos( $attrs, 'data-lbb-consent' ) ) {
					return $m[0];
				}
				// JSON-LD, șabloane etc. nu sunt cod de rulat.
				if ( preg_match( '#\btype\s*=\s*["\']?([^"\'\s>]+)#i', $attrs, $t ) && ! preg_match( '#^(text/javascript|application/javascript|module)$#i', $t[1] ) ) {
					return $m[0];
				}
				$src = preg_match( '#\bsrc\s*=\s*["\']([^"\']+)#i', $attrs, $s ) ? $s[1] : '';
				if ( $src ? preg_match( self::MARKETING_SRC, $src ) : preg_match( self::MARKETING_INLINE, $body ) ) {
					$cat = 'marketing';
				} elseif ( $src ? preg_match( self::STATS_SRC, $src ) : preg_match( self::STATS_INLINE, $body ) ) {
					$cat = 'statistics';
				} else {
					return $m[0];
				}
				$attrs = preg_replace( '#\s+type\s*=\s*(["\'])[^"\']*\1|\s+type\s*=\s*[^\s>]+#i', '', $attrs );
				return '<script type="text/plain" data-lbb-consent="' . $cat . '"' . $attrs . '>' . $body . '</script>';
			},
			$html
		);
	}

	/**
	 * Bannerul (ascuns până verifică scriptul dacă vizitatorul a ales deja).
	 */
	public static function banner() {
		if ( ! self::enabled() ) {
			return;
		}
		$page = LBB_Legal::page_id( 'cookies' );
		$link = $page && 'publish' === get_post_status( $page ) ? get_permalink( $page ) : '';
		?>
		<div class="lbb-cc" id="lbb-cc" role="region" aria-label="<?php esc_attr_e( 'Cookies', 'libertbus-bilete' ); ?>" hidden>
			<p class="lbb-cc-text">
				<?php esc_html_e( 'Folosim cookies necesare pentru funcționarea site-ului. Cu acordul dumneavoastră folosim și cookies de statistică (Google Analytics) și de marketing.', 'libertbus-bilete' ); ?>
				<?php if ( $link ) : ?>
					<a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Detalii', 'libertbus-bilete' ); ?></a>
				<?php endif; ?>
			</p>
			<div class="lbb-cc-panel" data-lbb-cc-panel hidden>
				<label class="lbb-cc-opt"><input type="checkbox" checked disabled> <span><strong><?php esc_html_e( 'Necesare', 'libertbus-bilete' ); ?></strong> — <?php esc_html_e( 'coșul, rezervarea, securitatea. Mereu active.', 'libertbus-bilete' ); ?></span></label>
				<label class="lbb-cc-opt"><input type="checkbox" value="statistics"> <span><strong><?php esc_html_e( 'Statistică', 'libertbus-bilete' ); ?></strong> — <?php esc_html_e( 'Google Analytics: ce pagini sunt vizitate.', 'libertbus-bilete' ); ?></span></label>
				<label class="lbb-cc-opt"><input type="checkbox" value="marketing"> <span><strong><?php esc_html_e( 'Marketing', 'libertbus-bilete' ); ?></strong> — <?php esc_html_e( 'reclame Google și de unde a venit vizita.', 'libertbus-bilete' ); ?></span></label>
				<button type="button" class="lbb-cc-btn lbb-cc-save" data-lbb-cc="save"><?php esc_html_e( 'Salvez alegerea', 'libertbus-bilete' ); ?></button>
			</div>
			<div class="lbb-cc-btns">
				<button type="button" class="lbb-cc-btn" data-lbb-cc="necessary"><?php esc_html_e( 'Doar necesare', 'libertbus-bilete' ); ?></button>
				<button type="button" class="lbb-cc-btn" data-lbb-cc="settings"><?php esc_html_e( 'Setări', 'libertbus-bilete' ); ?></button>
				<button type="button" class="lbb-cc-btn lbb-cc-accept" data-lbb-cc="all"><?php esc_html_e( 'Accept toate', 'libertbus-bilete' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Linkurile spre paginile legale și „Setări cookies”, mutate sub textul de copyright din subsol
	 * (tema Betheme: #Footer .copyright). Fără subsol recunoscut rămân la capătul paginii.
	 */
	public static function footer_links() {
		if ( is_admin() || is_feed() || is_embed() || isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$links = array();
		foreach ( array( 'terms', 'privacy', 'cookies' ) as $key ) {
			$id = LBB_Legal::page_id( $key );
			if ( $id && 'publish' === get_post_status( $id ) ) {
				$links[] = '<a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
			}
		}
		if ( LBB_Settings::get( 'cookie_banner' ) ) {
			$links[] = '<a href="#lbb-cookies">' . esc_html__( 'Setări cookies', 'libertbus-bilete' ) . '</a>';
		}
		if ( ! $links ) {
			return;
		}
		echo '<p class="lbb-legal-links" id="lbb-legal-links">' . implode( ' <span aria-hidden="true">·</span> ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		// Selectorii pe rând, în ordinea preferinței (o listă „a,b,c” ar lua primul element din pagină,
		// ex. <footer> dintr-un citat); la final, ultimul <footer> care nu e în conținut.
		echo "<script>(function(){var n=document.getElementById('lbb-legal-links');if(!n)return;var s=['#Footer .copyright','#colophon .site-info','.site-footer .site-info','body>footer','.wp-site-blocks>footer','footer.site-footer','#colophon'],t=null,i,f;for(i=0;i<s.length&&!t;i++){t=document.querySelector(s[i]);}if(!t){f=document.querySelectorAll('footer');for(i=f.length-1;i>=0&&!t;i--){if(!f[i].closest('blockquote,article,figure,aside'))t=f[i];}}if(t){t.appendChild(n);n.className+=' is-in-footer';}})();</script>\n";
		echo '<style>.lbb-legal-links{margin:2px auto 0 !important;padding:0 12px;font-size:13px !important;line-height:1.8 !important;text-align:center;color:inherit}.lbb-legal-links a{color:inherit !important;text-decoration:underline !important;white-space:nowrap}.lbb-legal-links span{margin:0 6px;opacity:.6}.lbb-legal-links:not(.is-in-footer){max-width:1200px}</style>' . "\n";
	}

	/**
	 * [lbb_cookie_settings] — buton care redeschide bannerul, ca vizitatorul să-și schimbe alegerea.
	 * Același efect are orice link către „#lbb-cookies” (ex. în meniul de jos).
	 */
	public static function settings_shortcode() {
		return '<p><a class="lbb-cc-open" href="#lbb-cookies">' . esc_html__( 'Schimbă preferințele pentru cookies', 'libertbus-bilete' ) . '</a></p>';
	}
}
