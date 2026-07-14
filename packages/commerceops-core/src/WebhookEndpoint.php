<?php
/**
 * Lemon Squeezy webhook REST endpoint (license events).
 *
 * @package CommerceOps\Core
 */

namespace CommerceOps\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles LS webhooks for license_created / subscription events.
 */
final class WebhookEndpoint {

	/**
	 * Register routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			'commerceops/v1',
			'/lemonsqueezy-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( $request ) {
		$secret = defined( 'COMMERCEOPS_LS_WEBHOOK_SECRET' )
			? COMMERCEOPS_LS_WEBHOOK_SECRET
			: get_option( 'co_ls_webhook_secret', '' );

		$raw = $request->get_body();
		if ( $secret ) {
			$sig = $request->get_header( 'x-signature' );
			$expected = hash_hmac( 'sha256', $raw, $secret );
			if ( ! $sig || ! hash_equals( $expected, $sig ) ) {
				return new \WP_Error( 'co_invalid_sig', 'Invalid signature', array( 'status' => 401 ) );
			}
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'co_bad_json', 'Invalid JSON', array( 'status' => 400 ) );
		}

		$meta = isset( $data['meta']['event_name'] ) ? (string) $data['meta']['event_name'] : 'unknown';
		$log  = get_option( 'co_ls_webhook_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'event' => $meta,
				'ts'    => gmdate( 'c' ),
			)
		);
		update_option( 'co_ls_webhook_log', array_slice( $log, 0, 50 ), false );

		return new \WP_REST_Response( array( 'received' => true, 'event' => $meta ), 200 );
	}
}

WebhookEndpoint::register();
