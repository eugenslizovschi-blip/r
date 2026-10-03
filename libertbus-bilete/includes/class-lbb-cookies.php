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
	 * Scripturi externe care pun cookies de statistică sau marketing (după adresa fișierului).
	 */
	const TRACKER_SRC = '#googletagmanager\.com|google-analytics\.com|googleadservices\.com|doubleclick\.net|connect\.facebook\.net|static\.hotjar\.com|clarity\.ms|mc\.yandex\.ru|analytics\.tiktok\.com|sourcebuster|order-attribution#i';

	/**
	 * Scripturi scrise direct în pagină care pornesc aceleași servicii.
	 */
	const TRACKER_INLINE = '#\bgtag\s*\(|\bfbq\s*\(|_hjSettings|\bym\s*\(\s*\d|clarity\s*\(|\bsbjs\.init#';

	/** @var int|null Nivelul de buffer deschis de noi. */
	private static $level = null;

	public static function init() {
		add_shortcode( 'lbb_cookie_settings', array( __CLASS__, 'settings_shortcode' ) );
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
				$src     = preg_match( '#\bsrc\s*=\s*["\']([^"\']+)#i', $attrs, $s ) ? $s[1] : '';
				$tracker = $src ? preg_match( self::TRACKER_SRC, $src ) : preg_match( self::TRACKER_INLINE, $body );
				if ( ! $tracker ) {
					return $m[0];
				}
				$attrs = preg_replace( '#\s+type\s*=\s*(["\'])[^"\']*\1|\s+type\s*=\s*[^\s>]+#i', '', $attrs );
				return '<script type="text/plain" data-lbb-consent="statistics"' . $attrs . '>' . $body . '</script>';
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
				<?php esc_html_e( 'Folosim cookies necesare pentru funcționarea site-ului. Cu acordul dumneavoastră folosim și Google Analytics, ca să vedem ce pagini sunt utile.', 'libertbus-bilete' ); ?>
				<?php if ( $link ) : ?>
					<a href="<?php echo esc_url( $link ); ?>"><?php esc_html_e( 'Detalii', 'libertbus-bilete' ); ?></a>
				<?php endif; ?>
			</p>
			<div class="lbb-cc-btns">
				<button type="button" class="lbb-cc-btn" data-lbb-cc="necessary"><?php esc_html_e( 'Doar necesare', 'libertbus-bilete' ); ?></button>
				<button type="button" class="lbb-cc-btn lbb-cc-accept" data-lbb-cc="all"><?php esc_html_e( 'Accept toate', 'libertbus-bilete' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * [lbb_cookie_settings] — buton care redeschide bannerul, ca vizitatorul să-și schimbe alegerea.
	 * Același efect are orice link către „#lbb-cookies” (ex. în meniul de jos).
	 */
	public static function settings_shortcode() {
		return '<p><a class="lbb-cc-open" href="#lbb-cookies">' . esc_html__( 'Schimbă preferințele pentru cookies', 'libertbus-bilete' ) . '</a></p>';
	}
}
