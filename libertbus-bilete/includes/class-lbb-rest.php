<?php
/**
 * REST: plecările și locurile libere pentru formular.
 *
 * @package LibertBus_Bilete
 */

defined( 'ABSPATH' ) || exit;

class LBB_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_cache_errors' ), 10, 3 );
	}

	/**
	 * Și erorile API-ului („Ruta nu există”, dată greșită) sunt fără cache: altfel un cache de pagini
	 * ar păstra 404-ul și după ce ruta e pornită din nou, iar formularul ar arăta „ruta nu există” ore întregi.
	 */
	public static function no_cache_errors( $response, $server, $request ) {
		if ( $response instanceof WP_HTTP_Response && 0 === strpos( (string) $request->get_route(), '/lbb/v1/' ) ) {
			$response->header( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	public static function routes() {
		register_rest_route( 'lbb/v1', '/departures', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'departures' ),
			'args'                => array(
				'route_id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				'date'     => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
			),
		) );
	}

	public static function departures( WP_REST_Request $request ) {
		$route = LBB_Routes::get( (int) $request['route_id'] );
		// Ca în formular: o rută fără preț nu e de vânzare, deci nu-i arătăm nici orele.
		if ( ! $route || ! $route['active'] || $route['price'] <= 0 ) {
			return new WP_Error( 'lbb_not_found', __( 'Ruta nu există.', 'libertbus-bilete' ), array( 'status' => 404 ) );
		}
		$response = rest_ensure_response( array(
			'route_id'   => $route['id'],
			'date'       => $request['date'],
			'departures' => LBB_Routes::departures_on( $route, $request['date'] ),
			// Zi fără curse din Setări: formularul spune „zi liberă”, nu „ruta circulă doar: …”.
			'closed'     => in_array( $request['date'], LBB_Settings::closed_dates(), true ),
			// Pagina cu formularul poate veni dintr-un cache de pagini mai vechi de 12–24 de ore, cu o cheie expirată
			// („Sesiunea a expirat” la trimitere). Răspunsul acesta nu se păstrează în cache: dă o cheie proaspătă,
			// pe care formularul o folosește doar pentru vizitatorii nelogați (cererea REST e mereu fără utilizator).
			'nonce'      => wp_create_nonce( 'lbb_book' ),
			// Tot pentru pagina din cache: „azi” scris în ea poate fi ieri; formularul ia ziua de azi de aici.
			// Și prețul: o pagină din cache ar arăta în rezumat prețul vechi, iar la plată s-ar cere cel nou.
			'route'      => LBB_Routes::public_entry( $route ),
			'today'      => LBB_Settings::today(),
			'maxDate'    => ( new DateTimeImmutable( LBB_Settings::today(), LBB_Settings::tz() ) )->modify( '+' . (int) LBB_Settings::get( 'max_days_ahead' ) . ' days' )->format( 'Y-m-d' ),
		) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
