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
		if ( ! $route || ! $route['active'] ) {
			return new WP_Error( 'lbb_not_found', __( 'Ruta nu există.', 'libertbus-bilete' ), array( 'status' => 404 ) );
		}
		$response = rest_ensure_response( array(
			'route_id'   => $route['id'],
			'date'       => $request['date'],
			'departures' => LBB_Routes::departures_on( $route, $request['date'] ),
		) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
