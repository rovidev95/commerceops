<?php
/**
 * Lemon Squeezy API client.
 *
 * @package CommerceOps\Core
 */

namespace CommerceOps\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal Lemon Squeezy license API wrapper.
 */
final class LemonSqueezyClient {

	/** @var string */
	private $api_base = 'https://api.lemonsqueezy.com/v1/licenses';

	/**
	 * Activate license.
	 *
	 * @param string $license_key Key.
	 * @param string $instance_id Instance id.
	 * @param string $site_url Site URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function activate( $license_key, $instance_id, $site_url ) {
		return $this->request(
			'activate',
			array(
				'license_key' => $license_key,
				'instance_id' => $instance_id,
				'instance_name' => $site_url,
			)
		);
	}

	/**
	 * Deactivate license.
	 *
	 * @param string $license_key Key.
	 * @param string $instance_id Instance id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function deactivate( $license_key, $instance_id ) {
		return $this->request(
			'deactivate',
			array(
				'license_key' => $license_key,
				'instance_id' => $instance_id,
			)
		);
	}

	/**
	 * Validate license.
	 *
	 * @param string $license_key Key.
	 * @param string $instance_id Instance id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate( $license_key, $instance_id ) {
		return $this->request(
			'validate',
			array(
				'license_key' => $license_key,
				'instance_id' => $instance_id,
			)
		);
	}

	/**
	 * @param string               $action API action.
	 * @param array<string,string> $body Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( $action, array $body ) {
		$api_key = $this->get_api_key();

		// Dev mode: accept CO-DEV-* keys without remote call.
		if ( empty( $api_key ) && ! empty( $body['license_key'] ) && 0 === strpos( $body['license_key'], 'CO-DEV-' ) ) {
			return array(
				'valid'          => true,
				'expires_at'     => gmdate( 'c', time() + YEAR_IN_SECONDS ),
				'customer_email' => get_option( 'admin_email' ),
			);
		}

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'co_missing_api_key',
				__( 'Lemon Squeezy API key not configured. Set COMMERCEOPS_LS_API_KEY or co_ls_api_key option.', 'commerceops-core' )
			);
		}

		$response = wp_remote_post(
			trailingslashit( $this->api_base ) . $action,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$message = is_array( $data ) && ! empty( $data['error'] )
				? (string) $data['error']
				: __( 'License API request failed.', 'commerceops-core' );
			return new \WP_Error( 'co_license_api', $message, array( 'status' => $code ) );
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'co_license_parse', __( 'Invalid license API response.', 'commerceops-core' ) );
		}

		$meta = isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array();

		return array(
			'valid'          => ! empty( $meta['valid'] ) || ! empty( $data['activated'] ) || ! empty( $data['deactivated'] ),
			'expires_at'     => $meta['expires_at'] ?? null,
			'customer_email' => $meta['customer_email'] ?? '',
			'raw'            => $data,
		);
	}

	/**
	 * Resolve API key from constant or option.
	 *
	 * @return string
	 */
	private function get_api_key() {
		if ( defined( 'COMMERCEOPS_LS_API_KEY' ) && COMMERCEOPS_LS_API_KEY ) {
			return (string) COMMERCEOPS_LS_API_KEY;
		}

		return (string) get_option( 'co_ls_api_key', '' );
	}
}
