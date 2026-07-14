<?php
/**
 * Lemon Squeezy license validation.
 *
 * @package CommerceOps\Core
 */

namespace CommerceOps\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Manages license activation against Lemon Squeezy License API.
 */
final class LicenseManager {

	/** @var ProductConfig */
	private $config;

	/** @var LemonSqueezyClient */
	private $client;

	/**
	 * @param ProductConfig $config Product config.
	 */
	public function __construct( ProductConfig $config ) {
		$this->config = $config;
		$this->client = new LemonSqueezyClient();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_commerceops_activate_license', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_commerceops_deactivate_license', array( $this, 'ajax_deactivate' ) );
		add_action( 'commerceops_daily_license_check', array( $this, 'maybe_validate_cached' ) );

		if ( ! wp_next_scheduled( 'commerceops_daily_license_check' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'commerceops_daily_license_check' );
		}
	}

	/**
	 * @return bool
	 */
	public function is_pro() {
		$data = $this->get_license_data();
		if ( empty( $data['key'] ) || empty( $data['status'] ) ) {
			return false;
		}

		if ( 'valid' !== $data['status'] ) {
			return false;
		}

		if ( ! empty( $data['expires_at'] ) ) {
			$expires = strtotime( (string) $data['expires_at'] );
			if ( $expires && $expires < time() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_license_data() {
		$stored = get_option( $this->config->license_option_key, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * @param array<string,mixed> $data License payload.
	 */
	private function save_license_data( array $data ) {
		update_option( $this->config->license_option_key, $data, false );
	}

	/**
	 * Activate license key.
	 *
	 * @param string $license_key License key.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function activate( $license_key ) {
		$license_key = sanitize_text( $license_key );
		if ( '' === $license_key ) {
			return new \WP_Error( 'co_empty_key', __( 'License key is required.', 'commerceops-core' ) );
		}

		$instance = $this->get_instance_id();
		$response = $this->client->activate(
			$license_key,
			$instance,
			home_url()
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->save_license_data(
			array(
				'key'         => $license_key,
				'instance_id' => $instance,
				'status'      => 'valid',
				'expires_at'  => $response['expires_at'] ?? null,
				'customer'    => $response['customer_email'] ?? '',
				'checked_at'  => gmdate( 'c' ),
				'provider'    => 'lemonsqueezy',
			)
		);

		return $response;
	}

	/**
	 * Deactivate license.
	 *
	 * @return true|\WP_Error
	 */
	public function deactivate() {
		$data = $this->get_license_data();
		if ( empty( $data['key'] ) ) {
			delete_option( $this->config->license_option_key );
			return true;
		}

		$response = $this->client->deactivate(
			$data['key'],
			$data['instance_id'] ?? $this->get_instance_id()
		);

		delete_option( $this->config->license_option_key );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Revalidate cached license periodically.
	 */
	public function maybe_validate_cached() {
		$data = $this->get_license_data();
		if ( empty( $data['key'] ) ) {
		 return;
		}

		$checked = ! empty( $data['checked_at'] ) ? strtotime( (string) $data['checked_at'] ) : 0;
		if ( $checked && ( time() - $checked ) < 12 * HOUR_IN_SECONDS ) {
			return;
		}

		$response = $this->client->validate(
			$data['key'],
			$data['instance_id'] ?? $this->get_instance_id()
		);

		if ( is_wp_error( $response ) ) {
			$data['status']     = 'invalid';
			$data['checked_at'] = gmdate( 'c' );
			$this->save_license_data( $data );
			return;
		}

		$data['status']     = ! empty( $response['valid'] ) ? 'valid' : 'invalid';
		$data['expires_at'] = $response['expires_at'] ?? ( $data['expires_at'] ?? null );
		$data['checked_at'] = gmdate( 'c' );
		$this->save_license_data( $data );
	}

	/**
	 * AJAX activate handler.
	 */
	public function ajax_activate() {
		check_ajax_referer( 'commerceops_license', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'commerceops-core' ) ), 403 );
		}

		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$result = $this->activate( $key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'License activated successfully.', 'commerceops-core' ) ) );
	}

	/**
	 * AJAX deactivate handler.
	 */
	public function ajax_deactivate() {
		check_ajax_referer( 'commerceops_license', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'commerceops-core' ) ), 403 );
		}

		$result = $this->deactivate();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'License deactivated.', 'commerceops-core' ) ) );
	}

	/**
	 * Stable instance id per site + product.
	 *
	 * @return string
	 */
	private function get_instance_id() {
		$seed = home_url() . '|' . $this->config->slug;
		return substr( hash( 'sha256', $seed ), 0, 32 );
	}

	/**
	 * Render license form partial.
	 */
	public function render_license_box() {
		$data   = $this->get_license_data();
		$is_pro = $this->is_pro();
		$buy    = $this->config->billing['checkout_url'] ?? '#';

		AdminPage::render_license_form(
			array(
				'is_pro'       => $is_pro,
				'license_key'  => $is_pro ? ( $data['key'] ?? '' ) : '',
				'expires_at'   => $data['expires_at'] ?? '',
				'checkout_url' => $buy,
				'nonce'        => wp_create_nonce( 'commerceops_license' ),
			)
		);
	}
}
